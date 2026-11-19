<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\Emission\EmissionJson;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxInvoiceScenario;
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

    private array $responseDiagnostics = [];

    private array $redactionValues = [];

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

    /** Separate CLI scope: no SQL invoice, no mutation of the historical preparation contract. */
    public static function forScenario(
        SandboxEmissionContext $context,
        Company $company,
        SuperPdpConnection $connection,
        SandboxInvoiceScenario $scenario,
        bool $authorizeNetwork = false,
        ?array $responses = null,
    ): SandboxScenarioCiiGateway {
        return new SandboxScenarioCiiGateway($context, $company, $connection, $scenario, $authorizeNetwork, $responses);
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

    public function reservationMode(): string
    {
        return $this->mock === null ? 'api' : 'simulation';
    }

    public function context(): SuperPdpConnection
    {
        $guards = app(CiiPreparationGuards::class);
        $client = $guards->configuration();
        $invoice = Invoice::findOrFail($this->invoiceId);
        abort_unless($invoice->user_id === $this->userId && $invoice->isIssued(), 403);
        $company = Company::where('user_id', $this->userId)->firstOrFail();
        $connection = $company->superPdpConnection()->firstOrFail();
        $guards->connection($connection, $client);
        $data = app(InvoiceExportDataBuilder::class)->buildElectronic($invoice);
        if (CiiPreparationEvidence::hash($data) !== $this->sourceHash
            || ! app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice)['local_checks_passed']) {
            throw new RuntimeException('Historical invoice changed or is incomplete.');
        }
        $evidence = app(CiiPreparationEvidence::class)->assess(User::findOrFail($this->userId), $invoice, $data, $connection, $this->verificationReference);
        if ($this->mock === null) {
            $guards->authorization($this->userId, $this->invoiceId, $this->sourceHash,
                $this->verificationReference, $connection, $client, $evidence['live']);
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
        $this->redactionValues = CiiRemoteErrorFilter::values($payload);
        $response = $this->request('conversion', fn () => $this->connector->invoice()->convert($json, 'en16931', 'cii', 'application/json'));
        if (strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])) !== 'application/xml'
            || $response->body() === '' || strlen($response->body()) > 5242880) {
            throw $this->responseFailure('conversion', 'unexpected_content', $response);
        }

        return VerificationObservation::fromResponse(['operation' => 'conversion', 'input_sha256' => hash('sha256', $json), 'xml' => $response->body()], $response);
    }

    public function validate(string $xml): VerificationObservation
    {
        $this->context();
        $invoice = Invoice::findOrFail($this->invoiceId);
        $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($invoice);
        $this->redactionValues = CiiRemoteErrorFilter::values($payload);
        app(CiiDocumentChecks::class)->assertInvoiceMatches($xml, $payload, $invoice);
        $response = $this->request('validation', fn () => $this->connector->invoice()->validate(['invoice.xml' => $xml]));
        try {
            $report = EmissionJson::object($response->body());
        } catch (Throwable) {
            throw $this->responseFailure('validation', 'unexpected_content', $response);
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

    /** Bounded response metadata only; no request payloads or raw response content. */
    public function responseDiagnostic(string $operation): array
    {
        return $this->responseDiagnostics[$operation] ?? [];
    }

    private function responseFailure(string $operation, string $kind, Response $response): CiiPreparationFailure
    {
        return new CiiPreparationFailure(['operation' => $operation,
            'failure_stage' => $operation.'.response', 'failure_kind' => $kind]
            + $this->responseDiagnostics[$operation]
            + CiiRemoteErrorFilter::extract($response->body(), $this->redactionValues));
    }

    private function request(string $operation, #[\SensitiveParameter] callable $send): Response
    {
        try {
            $response = $send();
        } catch (\Saloon\Exceptions\Request\RequestException $exception) {
            $response = $exception->getResponse();
        } catch (Throwable $exception) {
            throw CiiPreparationFailure::withoutResponse($operation, $exception);
        }
        $this->responseDiagnostics[$operation] = CiiPreparationFailure::responseMetadata($response);
        if ($response->status() !== 200) {
            throw $this->responseFailure($operation, 'http_non_200', $response);
        }
        return $response;
    }
}
