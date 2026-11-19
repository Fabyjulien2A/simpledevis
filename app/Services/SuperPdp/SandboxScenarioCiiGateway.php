<?php

namespace App\Services\SuperPdp;

use App\Models\Company;
use App\Models\SuperPdpConnection;
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

/** CLI-only documentary trial, independent of SQL invoices and all emission transports. */
final class SandboxScenarioCiiGateway
{
    private SuperPdpConnector $connector;

    private ?MockClient $mock = null;

    private ?string $credentialsHash = null;

    private ?string $convertedHash = null;

    private bool $conversionAttempted = false;

    private bool $validationAttempted = false;

    private array $httpStatuses = [];

    private readonly int $expiresAt;

    private readonly string $clientId;

    private readonly array $preparation;

    public function __construct(
        private readonly SandboxEmissionContext $context,
        private readonly Company $company,
        private readonly SuperPdpConnection $connection,
        private readonly SandboxInvoiceScenario $scenario,
        bool $authorizeNetwork = false,
        ?array $responses = null,
    ) {
        if (PHP_SAPI !== 'cli' || ($responses === null && ! $authorizeNetwork)
            || ($responses !== null && $authorizeNetwork)) {
            throw new RuntimeException('Explicit local CLI documentary authorization required.');
        }
        $this->expiresAt = now()->addMinutes(5)->timestamp;
        $this->clientId = (string) config('services.superpdp.client_id');
        $this->assertCurrent();
        $this->preparation = app(SuperPdpInvoicePayloadBuilder::class)->buildSandboxFromData(
            $scenario->data(), $context, $company, $scenario->frenchContext());
        if ($responses !== null) {
            foreach ($responses as $request => $response) {
                if (! in_array($request, [ConvertInvoiceRequest::class, CreateValidationReportRequest::class], true)
                    || ! $response instanceof MockResponse) {
                    throw new RuntimeException('Only in-memory documentary responses allowed.');
                }
            }
            $this->mock = new MockClient($responses);
        }
        // Both endpoints are public (security: [] in the bundled OpenAPI).
        // OAuth authorizes the local company context; never send tokens to public operations.
        $this->connector = new SuperPdpConnector(baseUrl: SandboxEmissionAuthorization::ORIGIN);
        if ($this->mock !== null) {
            $this->connector->withMockClient($this->mock);
        }
        $this->connector->tries = 1;
        $this->connector->config()->merge(['verify' => true, 'allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 5]);
        $this->connector->middleware()->onRequest(function (PendingRequest $request) {
            $this->assertCurrent();
            $path = match ($request->getRequest()::class) {
                ConvertInvoiceRequest::class => '/invoices/convert',
                CreateValidationReportRequest::class => '/validation_reports',
                default => throw new RuntimeException('Operation outside the documentary allowlist.'),
            };
            $query = $request->getRequest() instanceof ConvertInvoiceRequest ? ['from' => 'en16931', 'to' => 'cii'] : [];
            if ($request->getMethod()->value !== 'POST' || $request->getUrl() !== SandboxEmissionAuthorization::API_URL.$path
                || $request->query()->all() !== $query || $request->headers()->get('Authorization') !== null) {
                throw new RuntimeException('Unexpected documentary request.');
            }
            if ($request->getRequest() instanceof ConvertInvoiceRequest) {
                $request->headers()->add('Accept', 'application/xml');
            } elseif ($request->body() instanceof MultipartBodyRepository) {
                $request->headers()->add('Content-Type', 'multipart/form-data; boundary='.$request->body()->getBoundary());
            }
        });
        $this->connector->middleware()->onResponse(function (Response $response) {
            $key = $response->getPendingRequest()->getRequest() instanceof ConvertInvoiceRequest ? 'conversion' : 'validation';
            $this->httpStatuses[$key] = $response->status();
        });
    }

    public function assertCurrent(): void
    {
        try {
            $this->context->assertFor($this->company);
            $this->connection->assertOAuthContext('sandbox', $this->clientId, SandboxEmissionAuthorization::API_URL);
            if (now()->timestamp >= $this->expiresAt || $this->clientId === ''
                || config('services.superpdp.client_id') !== $this->clientId
                || ! $this->connection->exists || $this->connection->getKey() !== 1
                || $this->connection->company_id !== 3 || $this->connection->superpdp_company_id !== 18172
                || $this->connection->directory_identifier !== '000000002' || $this->connection->status !== 'connected'
                || ! in_array($this->connection->getConnectionName(), [null, 'superpdp_sandbox'], true)
                || $this->connection->tokenIsExpired()) {
                throw new RuntimeException;
            }
            $access = $this->connection->access_token;
            $refresh = $this->connection->refresh_token;
            if (! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '') {
                throw new RuntimeException;
            }
            $hash = hash('sha256', $access."\0".$refresh);
            if ($this->credentialsHash !== null && ! hash_equals($this->credentialsHash, $hash)) {
                throw new RuntimeException;
            }
            $this->credentialsHash ??= $hash;
        } catch (Throwable) {
            throw new RuntimeException('Sandbox OAuth scope, decryptability or expiry check failed. No refresh performed.');
        }
    }

    public function assertScope(SandboxEmissionContext $context, Company $company, SandboxInvoiceScenario $scenario): void
    {
        $this->assertCurrent();
        $context->assertFor($company);
        if ($company !== $this->company || $context !== $this->context
            || CiiPreparationEvidence::hash([$scenario->data(), $scenario->frenchContext()])
                !== CiiPreparationEvidence::hash([$this->scenario->data(), $this->scenario->frenchContext()])) {
            throw new RuntimeException('Documentary scenario scope mismatch.');
        }
    }

    public function convert(): VerificationObservation
    {
        $this->assertCurrent();
        if ($this->conversionAttempted) {
            throw new RuntimeException('No conversion replay or automatic retry allowed.');
        }
        $this->conversionAttempted = true;
        $json = json_encode($this->preparation['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = $this->request(fn () => $this->connector->invoice()->convert($json, 'en16931', 'cii', 'application/json'));
        if (strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])) !== 'application/xml'
            || strlen($response->body()) > 5242880) {
            throw new RuntimeException('Bounded application/xml response required.');
        }
        app(CiiDocumentChecks::class)->assertSandboxMatches($response->body(), $this->preparation, $this->context, $this->company);
        $this->assertCurrent();
        $this->convertedHash = hash('sha256', $response->body());

        return VerificationObservation::fromResponse(['operation' => 'conversion', 'xml' => $response->body(),
            'input_sha256' => hash('sha256', $json)], $response);
    }

    public function validate(string $xml): VerificationObservation
    {
        $this->assertCurrent();
        if ($this->validationAttempted || $this->convertedHash === null || ! hash_equals($this->convertedHash, hash('sha256', $xml))) {
            throw new RuntimeException('Validation requires the exact converted bytes, once only.');
        }
        $this->validationAttempted = true;
        app(CiiDocumentChecks::class)->assertSandboxMatches($xml, $this->preparation, $this->context, $this->company);
        $response = $this->request(fn () => $this->connector->invoice()->validate(['invoice.xml' => $xml]));
        if (strlen($response->body()) > 1048576) {
            throw new RuntimeException('Validation report too large.');
        }
        $report = EmissionJson::object($response->body());
        app(CiiDocumentChecks::class)->assertReport($report, $xml);
        $this->assertCurrent();

        return VerificationObservation::fromResponse(['operation' => 'validation', 'report' => $report, 'report_raw' => $response->body(),
            'input_sha256' => hash('sha256', $xml)], $response);
    }

    public function httpStatuses(): array
    {
        return $this->httpStatuses;
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
            throw new RuntimeException('Documentary API unavailable or refused; no retry or deposit.');
        }
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'sandbox_documentary_only', 'ready_for_emission' => false];
    }
}
