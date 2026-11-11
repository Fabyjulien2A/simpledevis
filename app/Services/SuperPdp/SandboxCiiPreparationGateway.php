<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Verification\VerificationObservation;
use Bluerock\SuperPdp\Requests\Invoices\ConvertInvoiceRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use RuntimeException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Repositories\Body\MultipartBodyRepository;
use Throwable;

/** Exactly two public API operations. No invoice deposit, OAuth refresh or token transmission. */
final class SandboxCiiPreparationGateway
{
    private SuperPdpConnector $connector;

    private ?MockClient $mock = null;

    private function __construct(
        private readonly int $userId,
        private readonly int $invoiceId,
        private readonly string $sourceHash,
        private readonly string $verificationReference,
        ?array $responses,
    ) {
        if ($responses !== null) {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Preparation mocks are restricted to testing.');
            }
            foreach ($responses as $request => $response) {
                if (! in_array($request, [ConvertInvoiceRequest::class, CreateValidationReportRequest::class], true)
                    || ! $response instanceof MockResponse) {
                    throw new RuntimeException('Only in-memory conversion and validation responses are allowed.');
                }
            }
            $this->mock = new MockClient($responses);
        }
        // Both operations have security: [] in the local official contract.
        $this->connector = new SuperPdpConnector(baseUrl: SandboxEmissionAuthorization::ORIGIN);
        if ($this->mock !== null) {
            $this->connector->withMockClient($this->mock);
        }
        $this->connector->tries = 1;
        $this->connector->config()->merge(['verify' => true, 'allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 5]);
        $this->connector->middleware()->onRequest(function (PendingRequest $request) {
            $this->context();
            $path = match ($request->getRequest()::class) {
                ConvertInvoiceRequest::class => '/invoices/convert',
                CreateValidationReportRequest::class => '/validation_reports',
                default => throw new RuntimeException('Preparation operation forbidden.'),
            };
            if ($request->getMethod()->value !== 'POST' || $request->getUrl() !== SandboxEmissionAuthorization::API_URL.$path
                || $request->headers()->get('Authorization') !== null) {
                throw new RuntimeException('Unexpected preparation URL method or authentication.');
            }
            if ($request->getRequest() instanceof ConvertInvoiceRequest) {
                $request->headers()->add('Accept', 'application/xml');
            } elseif ($request->body() instanceof MultipartBodyRepository) {
                $request->headers()->add('Content-Type', 'multipart/form-data; boundary='.$request->body()->getBoundary());
            }
        });
    }

    public static function forInvoice(User $actor, Invoice $invoice, string $sourceHash, string $verificationReference, ?array $responses = null): self
    {
        $stored = $invoice->newQuery()->findOrFail($invoice->id);
        abort_unless($actor->exists && $stored->user_id === $actor->id, 403);
        $gateway = new self($actor->id, $stored->id, $sourceHash, $verificationReference, $responses);
        $gateway->context();

        return $gateway;
    }

    public function assertScope(User $actor, Invoice $invoice, string $sourceHash, string $verificationReference): void
    {
        if ($actor->id !== $this->userId || $invoice->id !== $this->invoiceId || $sourceHash !== $this->sourceHash
            || $verificationReference !== $this->verificationReference) {
            throw new RuntimeException('Preparation gateway scope mismatch.');
        }
    }

    public function context(): SuperPdpConnection
    {
        if (! in_array(app()->environment(), ['local', 'staging', 'sandbox', 'testing'], true)
            || config('services.superpdp.environment') !== 'sandbox'
            || config('services.superpdp.api_url') !== SandboxEmissionAuthorization::API_URL
            || ! is_string($client = config('superpdp_preparation.sandbox_client_id')) || $client === ''
            || config('services.superpdp.client_id') !== $client) {
            throw new RuntimeException('Explicit sandbox preparation context required.');
        }
        $invoice = Invoice::findOrFail($this->invoiceId);
        abort_unless($invoice->user_id === $this->userId && $invoice->isIssued(), 403);
        $company = Company::where('user_id', $this->userId)->firstOrFail();
        $connection = $company->superPdpConnection()->firstOrFail();
        $connection->assertOAuthContext('sandbox', $client, SandboxEmissionAuthorization::API_URL);
        if (! $connection->isConnected() || $connection->tokenIsExpired() || ! $connection->access_token
            || ! is_int($connection->superpdp_company_id) || $connection->superpdp_company_id <= 0) {
            throw new RuntimeException('Qualified sandbox OAuth connection required; no refresh will be performed.');
        }
        $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
        if (CiiPreparationEvidence::hash($data) !== $this->sourceHash
            || ! app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice)['local_checks_passed']) {
            throw new RuntimeException('Historical invoice changed or is incomplete.');
        }
        $evidence = app(CiiPreparationEvidence::class)->assess(User::findOrFail($this->userId), $invoice, $data, $connection, $this->verificationReference);
        if ($this->mock === null) {
            $approval = config('superpdp_preparation.authorization');
            if (config('superpdp_preparation.live_enabled') !== true || ! $evidence['live'] || ! is_array($approval)
                || ! is_string($approval['approved_by'] ?? null) || trim($approval['approved_by']) === ''
                || ! is_int($approval['approved_at'] ?? null) || ! is_int($approval['expires_at'] ?? null)
                || $approval['approved_at'] > now()->timestamp || $approval['expires_at'] <= now()->timestamp
                || $approval['expires_at'] <= $approval['approved_at'] || $approval['expires_at'] - $approval['approved_at'] > 900
                || ($approval['user_id'] ?? null) !== $this->userId || ($approval['invoice_id'] ?? null) !== $this->invoiceId
                || ($approval['source_sha256'] ?? null) !== $this->sourceHash || ($approval['verification_reference'] ?? null) !== $this->verificationReference
                || ($approval['environment'] ?? null) !== 'sandbox' || ($approval['oauth_client_id'] ?? null) !== $client
                || ($approval['api_url'] ?? null) !== SandboxEmissionAuthorization::API_URL
                || ($approval['provider_company_id'] ?? null) !== $connection->superpdp_company_id) {
                throw new RuntimeException('Real preparation locked: separate scoped API authorization and live party evidence required.');
            }
        }

        return $connection;
    }

    public function convert(array $payload): VerificationObservation
    {
        $this->context();
        if (CiiPreparationEvidence::hash($payload) !== CiiPreparationEvidence::hash(app(SuperPdpInvoicePayloadBuilder::class)->build(Invoice::findOrFail($this->invoiceId)))) {
            throw new RuntimeException('Only the frozen historical payload may be converted.');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = $this->request(fn () => $this->connector->invoice()->convert($json, 'en16931', 'cii', 'application/json'));
        if (strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])) !== 'application/xml'
            || $response->body() === '' || strlen($response->body()) > 5242880) {
            throw new RuntimeException('Bounded application/xml conversion output required.');
        }

        return VerificationObservation::fromResponse(['operation' => 'conversion', 'input_sha256' => hash('sha256', $json), 'xml' => $response->body()], $response);
    }

    public function validate(string $xml): VerificationObservation
    {
        $this->context();
        app(CiiDocumentChecks::class)->assertMatches($xml, app(SuperPdpInvoicePayloadBuilder::class)->build(Invoice::findOrFail($this->invoiceId)));
        $response = $this->request(fn () => $this->connector->invoice()->validate(['invoice.xml' => $xml]));
        try {
            $report = EmissionJson::object($response->body());
        } catch (Throwable) {
            throw new RuntimeException('Malformed preparation validation report.');
        }

        return VerificationObservation::fromResponse(['operation' => 'validation', 'input_sha256' => hash('sha256', $xml), 'report' => $report], $response);
    }

    public function requests(): array
    {
        return array_map(function (Response $response) {
            $pending = $response->getPendingRequest();

            return ['class' => $pending->getRequest()::class, 'url' => $pending->getUrl(), 'method' => $pending->getMethod()->value,
                'query' => $pending->query()->all(), 'body' => $pending->body()?->all(), 'content_type' => $pending->headers()->get('Content-Type')];
        }, $this->mock?->getRecordedResponses() ?? []);
    }

    private function request(#[\SensitiveParameter] callable $send): Response
    {
        try {
            $response = $send();
            if ($response->status() !== 200) {
                throw new RuntimeException;
            }

            return $response;
        } catch (Throwable) {
            throw new RuntimeException('Preparation API unavailable or refused; no automatic retry and no deposit.');
        }
    }
}
