<?php

use App\Models\Company;
use App\Models\SuperPdpConnection;
use App\Models\User;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\Emission\SandboxInvoiceScenario;
use App\Services\SuperPdp\Emission\SandboxSellerEvidence;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\OfflinePreparationGateway;
use App\Services\SuperPdp\SandboxPreparationDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Bluerock\SuperPdp\Requests\Invoices\ConvertInvoiceRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Saloon\Config;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Http\Senders\GuzzleSender;
use Tests\Support\BurgerQueenSandboxCii;
use Tests\Support\PreparedCiiFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    // Guzzle still constructs a handler for mocked requests. Supply a throwing
    // handler so the suite can run with every PHP network primitive disabled.
    Config::setSenderResolver(fn () => new class extends GuzzleSender
    {
        protected function defaultHandlerStack(): HandlerStack
        {
            return HandlerStack::create(static fn () => throw new RuntimeException('Network forbidden in offline tests.'));
        }
    });
    Carbon::setTestNow(Carbon::parse('2026-11-13T17:00:00Z'));
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.client_id' => 'unit-client',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test']],
    ]);
    $this->company = (new Company)->forceFill(['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]);
    $this->company->exists = true;
    $this->actingAs((new User)->forceFill(['id' => 3]));
    $this->connection = (new SuperPdpConnection)->forceFill([
        'id' => 1, 'company_id' => 3, 'superpdp_company_id' => 18172, 'status' => 'connected',
        'environment' => 'sandbox', 'oauth_client_id' => 'unit-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
        'directory_identifier' => '000000002', 'access_token_expires_at' => now()->addHour(),
    ]);
    $this->connection->exists = true;
    $this->context = SandboxEmissionContext::forCompany($this->company);
    $this->scenario = SandboxInvoiceScenario::forContext($this->context, $this->company, '2026-11-13', '2026-12-13');
    $this->data = $this->scenario->data();
    $this->french = $this->scenario->frenchContext();
    $provider = ['id' => 18172, 'number_scheme' => 'sandbox', 'number' => '000000002', 'env' => 'sandbox', 'country' => 'FR'];
    $this->remote = [
        'company' => $provider,
        'session' => ['client_id' => 'unit-client', 'company_verification_status' => 'verified', 'user_identity_verification_status' => 'verified'],
        'directory' => ['data' => [['company' => $provider, 'identifier' => '0225:315143296_18172', 'directory' => 'peppol', 'status' => 'created']]],
    ];
    $this->proof = function (string $endpoint) {
        $path = ['company' => 'companies/me', 'session' => 'oauth2_sessions/me', 'directory' => 'directory_entries'][$endpoint];
        $request = new Request('GET', 'https://api.superpdp.tech/v1.beta/'.$path, ['Authorization' => 'Bearer unit-only-no-real-token']);
        $response = new Response(new PsrResponse(200, [], json_encode($this->remote[$endpoint], JSON_THROW_ON_ERROR)),
            $this->createMock(PendingRequest::class), $request);
        $response->setMocked(true);

        return SandboxSellerEvidence::fromResponse($response);
    };
    $this->companyProof = ($this->proof)('company');
    $this->sessionProof = ($this->proof)('session');
    $this->directoryProof = ($this->proof)('directory');
    $preparation = app(SuperPdpInvoicePayloadBuilder::class)->buildSandboxFromData($this->data, $this->context, $this->company, $this->french);
    $this->xml = BurgerQueenSandboxCii::xml($preparation);
    $this->gateway = fn (array $overrides = []) => new OfflinePreparationGateway($overrides + [
        ConvertInvoiceRequest::class => MockResponse::make($this->xml, 200, ['Content-Type' => 'application/xml']),
        CreateValidationReportRequest::class => MockResponse::make(PreparedCiiFixture::report($this->xml)),
    ]);
    $this->diagnose = fn () => app(SandboxPreparationDiagnostic::class)->diagnose($this->data, $this->context, $this->company,
        $this->french, $this->connection, $this->companyProof, $this->sessionProof, $this->directoryProof);
    $this->prepare = fn (OfflinePreparationGateway $gateway) => app(InvoiceCiiPreparation::class)->prepareSandbox(
        $this->data, $this->context, $this->company, $gateway, $this->french, $this->connection,
        $this->companyProof, $this->sessionProof, $this->directoryProof);
});

afterEach(function () {
    Carbon::setTestNow();
    Config::setSenderResolver(null);
    Config::clearGlobalMiddleware();
    Config::preventStrayRequests();
    Http::assertNothingSent();
});

it('prepares a complete immutable fictional scenario without touching a model or real numbering', function () {
    $copy = $this->scenario->data();
    $copy['seller']['name'] = 'Another seller';
    expect($this->scenario->data()['seller']['name'])->toBe('Burger Queen')
        ->and($this->scenario->data()['seller']['siret'])->toBeNull()
        ->and($this->scenario->data()['buyer']['siret'])->toBeNull()
        ->and($this->scenario->data()['invoice_number'])->toBe('SANDBOX-PREPARATION-BQ-TRICATEL')
        ->and((new ReflectionClass($this->scenario))->isReadOnly())->toBeTrue();
});

it('requires valid explicit scenario dates', function (string $date, string $due) {
    expect(fn () => SandboxInvoiceScenario::forContext($this->context, $this->company, $date, $due))
        ->toThrow(InvalidArgumentException::class);
})->with([['2026-02-30', '2026-12-13'], ['2026-11-13', '2026-13-13'], ['2026-11-13', '2026-11-12']]);

it('connects the builder CII checks SDK conversion and validation only through mock responses', function () {
    $before = [$this->data, $this->company->getAttributes(), $this->connection->getAttributes(), $this->context->toArray()];
    $gateway = ($this->gateway)();
    $result = ($this->prepare)($gateway);
    expect($result['status'])->toBe('prepared_simulation')
        ->and($result['document_validation'])->toBe('passed_simulation')
        ->and($result['ready_for_emission'])->toBeFalse()->and($result['deposit_performed'])->toBeFalse()
        ->and($result['artifact'])->toBeNull()->and($result['official_archive'])->toBeFalse()
        ->and($result['cii_sha256'])->toBe(hash('sha256', $this->xml))
        ->and($result['diagnostic']['routing']['api_verified'])->toBeFalse()
        ->and($result['diagnostic']['can_convert_live'])->toBeFalse()
        ->and($result['diagnostic']['seller']['verified'])->toBeFalse()
        ->and($result['diagnostic']['emission_blockers'])->toContain('live_buyer_destination_evidence_required', 'live_seller_evidence_required')
        ->and([$this->data, $this->company->getAttributes(), $this->connection->getAttributes(), $this->context->toArray()])->toBe($before);
    $requests = $gateway->requests();
    expect(array_column($requests, 'class'))->toBe([ConvertInvoiceRequest::class, CreateValidationReportRequest::class])
        ->and(array_column($requests, 'method'))->toBe(['POST', 'POST'])
        ->and($requests[0]['query'])->toBe(['from' => 'en16931', 'to' => 'cii'])
        ->and($requests[0]['accept'])->toBe('application/xml')
        ->and($requests[1]['content_type'])->toStartWith('multipart/form-data; boundary=');
});

it('uses the existing SDK mock sender for the exact conversion payload', function () {
    $diagnostic = ($this->diagnose)();
    $gateway = ($this->gateway)();
    expect($gateway->convert($diagnostic['preparation']['payload']))->toBe($this->xml);
});

it('reports missing seller proofs without manufacturing or refreshing them', function () {
    $result = app(SandboxPreparationDiagnostic::class)->diagnose($this->data, $this->context, $this->company, $this->french);
    expect($result['local_checks_passed'])->toBeTrue()->and($result['can_simulate_conversion'])->toBeFalse()
        ->and($result['can_convert_live'])->toBeFalse()->and($result['emission_blockers'])->toContain('seller_evidence_required');
});

it('blocks conversion before sending any simulated request when data or qualifications are insufficient', function (string $case) {
    match ($case) {
        'postal' => $this->data['buyer']['postal_code'] = '',
        'amount' => $this->data['totals']['total_ttc'] = '120.01',
        'siret' => $this->data['seller']['siret'] = '00000000200000',
        'missing_proof' => $this->companyProof = null,
        'expired_proofs' => Carbon::setTestNow(now()->addMinutes(16)),
        'expired_token' => $this->connection->access_token_expires_at = now()->addMinutes(4),
        'wrong_client' => $this->connection->oauth_client_id = 'another-client',
        'wrong_provider' => $this->connection->superpdp_company_id = 18171,
        'unverified' => $this->remote['session']['company_verification_status'] = 'pending',
    };
    if ($case === 'unverified') {
        $this->sessionProof = ($this->proof)('session');
    }
    $gateway = ($this->gateway)();
    $result = ($this->prepare)($gateway);
    expect($result['status'])->toBe('blocked')->and($result['ready_for_emission'])->toBeFalse()
        ->and($result['reason'])->toBe('local_data_or_qualification_insufficient')
        ->and($gateway->requests())->toBe([]);
})->with(['postal', 'amount', 'siret', 'missing_proof', 'expired_proofs', 'expired_token', 'wrong_client', 'wrong_provider', 'unverified']);

it('never promotes manual routing or enables live conversion even when local switches are changed', function () {
    config(['superpdp_preparation.live_enabled' => true, 'superpdp_emission.sandbox_enabled' => true]);
    request()->merge(['api_verified' => true, 'ready_for_emission' => true]);
    $result = ($this->diagnose)();
    expect($result['routing']['source'])->toBe('superpdp_interface')
        ->and($result['routing']['api_verified'])->toBeFalse()
        ->and($result['ready_for_emission'])->toBeFalse()->and($result['can_convert_live'])->toBeFalse();
});

it('fails closed without retry on conversion or report failures', function (string $case, string $reason, int $requests) {
    $report = PreparedCiiFixture::report($this->xml);
    $report['data'][0]['is_valid'] = false;
    $overrides = match ($case) {
        'conversion_http' => [ConvertInvoiceRequest::class => MockResponse::make('opaque error', 500)],
        'conversion_type' => [ConvertInvoiceRequest::class => MockResponse::make($this->xml, 200, ['Content-Type' => 'text/plain'])],
        'conversion_xml' => [ConvertInvoiceRequest::class => MockResponse::make('<broken>', 200, ['Content-Type' => 'application/xml'])],
        'report_http' => [CreateValidationReportRequest::class => MockResponse::make('opaque error', 503)],
        'report_invalid' => [CreateValidationReportRequest::class => MockResponse::make($report)],
    };
    $gateway = ($this->gateway)($overrides);
    $result = ($this->prepare)($gateway);
    expect($result['status'])->toBe('blocked')->and($result['reason'])->toBe($reason)
        ->and($result['ready_for_emission'])->toBeFalse()->and($result['deposit_performed'])->toBeFalse()
        ->and($gateway->requests())->toHaveCount($requests);
})->with([
    ['conversion_http', 'conversion_failed', 1], ['conversion_type', 'conversion_failed', 1],
    ['conversion_xml', 'conversion_failed', 1], ['report_http', 'validation_failed', 2], ['report_invalid', 'validation_failed', 2],
]);

it('rejects production or unauthorized users before any gateway operation', function (string $case) {
    match ($case) {
        'production' => $this->app->instance('env', 'production'),
        'provider' => config(['services.superpdp.environment' => 'production']),
        'company' => $this->company->id = 4,
        'owner' => $this->company->user_id = 4,
    };
    $gateway = ($this->gateway)();
    expect(fn () => ($this->prepare)($gateway))->toThrow(RuntimeException::class);
    expect($gateway->requests())->toBe([]);
})->with(['production', 'provider', 'company', 'owner']);

it('rechecks evidence freshness during conversion and validation without accepting an obsolete artifact', function (string $requestClass, int $expectedRequests) {
    Config::globalMiddleware()->onResponse(static function (Response $response) use ($requestClass) {
        if ($response->getPendingRequest()->getRequest()::class === $requestClass) {
            Carbon::setTestNow(now()->addMinutes(16));
        }
    });
    $gateway = ($this->gateway)();
    $result = ($this->prepare)($gateway);
    expect($result['status'])->toBe('blocked')->and($result['reason'])->toBe('qualification_changed_during_preparation')
        ->and($result['artifact'])->toBeNull()->and($result['ready_for_emission'])->toBeFalse()
        ->and($gateway->requests())->toHaveCount($expectedRequests);
})->with([[ConvertInvoiceRequest::class, 1], [CreateValidationReportRequest::class, 2]]);
