<?php

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationRegistry;
use App\Services\SuperPdp\Emission\DisabledEmissionTransport;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SandboxCiiPreparationGateway;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use App\Services\SuperPdp\Verification\VerificationObservation;
use Bluerock\SuperPdp\Requests\Invoices\ConvertInvoiceRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Bluerock\SuperPdp\Requests\ValidationReports\CreateValidationReportRequest;
use Bluerock\SuperPdp\SuperPdpConnector;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Http\Senders\GuzzleSender;
use Tests\Support\PreparedCiiFixture;

beforeEach(function () {
    Config::setSenderResolver(fn () => new class extends GuzzleSender
    {
        protected function defaultHandlerStack(): HandlerStack
        {
            return HandlerStack::create(static fn () => throw new RuntimeException('External network forbidden'));
        }
    });
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Storage::fake('invoice_cii');
    config(['services.superpdp.environment' => 'sandbox', 'services.superpdp.client_id' => 'preparation-client',
        'services.superpdp.api_url' => SandboxEmissionAuthorization::API_URL,
        'superpdp_preparation.sandbox_client_id' => 'preparation-client']);
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create([
        'company_name' => 'Historical seller', 'siret' => '73282932000074', 'country_code' => 'FR',
        'address' => '1 rue Test', 'postal_code' => '75001', 'city' => 'Paris',
        'vat_number' => 'FR44732829320', 'vat_regime' => 'subject_to_vat',
        'invoice_recovery_indemnity_text' => 'PMT', 'invoice_late_payment_penalties_text' => 'PMD',
        'invoice_early_payment_discount_text' => 'AAB', 'billing_electronic_address' => ['scheme' => '0225', 'value' => '732829320'],
    ]);
    $this->client = $this->owner->clients()->create([
        'company_name' => 'Historical buyer', 'client_type' => 'professional', 'siret' => '55210055400013',
        'country_code' => 'FR', 'address' => '2 rue Test', 'postal_code' => '69001', 'city' => 'Lyon',
        'vat_number' => 'FR00552100554', 'billing_electronic_address' => ['scheme' => '0225', 'value' => '552100554'],
    ]);
    $this->actingAs($this->owner)->post(route('invoices.store'), [
        'action' => 'issue', 'emission_profile' => 'fr_b2b_380_eur', 'french_business_process_type' => 'S1',
        'client_id' => $this->client->id, 'date' => '2026-11-11', 'due_date' => '2026-12-11',
        'items' => [['description' => 'CII preparation', 'quantity' => '1', 'unit_price_ht' => '100', 'tva_rate' => '20', 'unit_code' => 'C62', 'tax_treatment' => 'taxable']],
    ])->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
    $this->connection = $this->company->superPdpConnection()->create([
        'environment' => 'sandbox', 'oauth_client_id' => 'preparation-client', 'api_url' => SandboxEmissionAuthorization::API_URL,
        'superpdp_company_id' => 123, 'access_token' => 'NO-TOKEN-TRANSMISSION', 'refresh_token' => 'NO-REFRESH',
        'access_token_expires_at' => now()->addHour(), 'status' => 'connected', 'reception_enabled' => true,
        'last_invoice_id' => 55, 'last_event_id' => 66,
    ]);
    $this->data = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $this->fingerprint = CiiPreparationEvidence::hash($this->data);
    $this->payload = app(SuperPdpInvoicePayloadBuilder::class)->build($this->invoice);
    $this->xml = PreparedCiiFixture::xml($this->payload);
    $check = ['status' => 'passed', 'source' => 'simulated', 'verified' => false,
        'response_sha256' => str_repeat('a', 64), 'observed_at' => now()->toIso8601String()];
    $this->report = ['schema_version' => 1, 'user_id' => $this->owner->id, 'invoice_id' => $this->invoice->id,
        'emission_ready' => false, 'status' => 'passed', 'expected_environment' => 'sandbox',
        'expected_provider_company_id' => 123, 'expected_oauth_client_id' => 'preparation-client', 'expected_api_url' => SandboxEmissionAuthorization::API_URL,
        'checked_at' => now()->toIso8601String(), 'source_sha256' => $this->fingerprint,
        'party_snapshot_sha256' => hash('sha256', $this->data['party_snapshot']),
        'emission_context_sha256' => hash('sha256', $this->data['emission_context']),
        'checks' => array_fill_keys(['buyer_destination', 'seller_identity', 'seller_session'], $check)];
    $this->saveProof = function (?array $report = null) {
        $this->verification = app(VerificationAuditStore::class)->store($this->owner, $this->invoice, $report ?? $this->report);
    };
    ($this->saveProof)();
    $this->responses = fn (array $overrides = []) => $overrides + [
        ConvertInvoiceRequest::class => MockResponse::make($this->xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']),
        CreateValidationReportRequest::class => MockResponse::make(PreparedCiiFixture::report($this->xml)),
    ];
    $this->gateway = fn (array $overrides = []) => SandboxCiiPreparationGateway::forInvoice($this->owner, $this->invoice,
        $this->fingerprint, $this->verification, ($this->responses)($overrides));
    $this->produce = fn ($gateway) => app(InvoiceCiiPreparation::class)->produce($this->owner, $this->invoice, $this->verification, $gateway);
    $this->archive = fn ($result) => app(PreparedCiiArchive::class)->read($this->owner, $this->invoice, $result['artifact']['reference']);
    $this->failureDiagnostic = fn ($result) => app(VerificationAuditStore::class)
        ->read($this->owner, $this->invoice, $result['diagnostic_reference'])['diagnostic'];
    $this->liveProofFixture = function () {
        // Only identity evidence is synthetically labelled live to exercise API guards.
        // All conversion/validation responses remain mocked or cached: no validated artifact.
        $report = $this->report;
        foreach ($report['checks'] as &$check) {
            $check['source'] = 'live';
            $check['verified'] = true;
        } unset($check);
        ($this->saveProof)($report);
    };
    $this->grant = function () {
        ($this->liveProofFixture)();
        config(['superpdp_preparation.live_enabled' => true, 'superpdp_preparation.authorization' => [
            'approved_by' => 'TEST-ONLY', 'approved_at' => now()->timestamp, 'expires_at' => now()->addMinutes(10)->timestamp,
            'user_id' => $this->owner->id, 'invoice_id' => $this->invoice->id, 'source_sha256' => $this->fingerprint,
            'verification_reference' => $this->verification, 'environment' => 'sandbox', 'oauth_client_id' => 'preparation-client',
            'api_url' => SandboxEmissionAuthorization::API_URL, 'provider_company_id' => 123,
        ]]);
    };
});

afterEach(function () {
    Config::setSenderResolver(null);
    Config::clearGlobalMiddleware();
    Http::assertNothingSent();
    expect(Storage::disk('invoice_cii')->allFiles('v1/validated'))->toBe([])->and(ElectronicInvoice::count())->toBe(0)
        ->and(config('superpdp_emission.sandbox_enabled'))->toBeFalse()->and(config('superpdp_emission.authorization'))->toBeNull();
    MockClient::destroyGlobal();
    Config::allowStrayRequests();
});

it('keeps real API preparation closed independently of the closed emission transport', function () {
    $mock = MockClient::global([]);
    $result = ($this->produce)(null);
    expect($result['status'])->toBe('blocked')->and($result['reason'])->toBe('preparation_context_failed')
        ->and($result['local_checks_passed'])->toBeTrue()->and($result['requires_api'])->toBeTrue()
        ->and($result['artifact'])->toBeNull()->and(app(EmissionTransport::class))->toBeInstanceOf(DisabledEmissionTransport::class);
    $mock->assertSentCount(0);
});

it('converts validates and privately archives exact bytes with honest mock provenance and no SQL writes', function () {
    $gateway = ($this->gateway)();
    $before = $this->invoice->fresh()->getRawOriginal();
    $connectionBefore = $this->connection->fresh()->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('No business writes allowed');
        }
    });
    $result = ($this->produce)($gateway);
    expect($result['status'])->toBe('prepared_simulation')->and($result['ready_for_emission'])->toBeFalse()
        ->and($result['deposit_performed'])->toBeFalse();
    $archive = ($this->archive)($result);
    expect($archive['xml'])->toBe($this->xml)->and($archive['manifest']['cii_sha256'])->toBe(hash('sha256', $this->xml))
        ->and($archive['manifest']['conversion']['source'])->toBe('simulated')
        ->and($archive['manifest']['validation']['status'])->toBe('passed_simulation')
        ->and($archive['manifest']['validation']['source'])->toBe('simulated')
        ->and($archive['manifest']['local_validation']['status'])->toBe('passed')
        ->and($archive['manifest']['source_sha256'])->toBe($this->fingerprint)
        ->and($this->invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($this->connection->fresh()->getRawOriginal())->toBe($connectionBefore);
    $requests = $gateway->requests();
    expect(array_column($requests, 'class'))->toBe([ConvertInvoiceRequest::class, CreateValidationReportRequest::class])
        ->and($requests[0]['query'])->toBe(['from' => 'en16931', 'to' => 'cii'])
        ->and($requests[0]['body'])->toBe(json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->and($requests[1]['content_type'])->toStartWith('multipart/form-data; boundary=');
});

it('never promotes SDK mocks even when a separately authorized real preparation path is exercised', function () {
    ($this->grant)();
    $mock = MockClient::global(($this->responses)());
    $result = ($this->produce)(null);
    expect($result['status'])->toBe('prepared_simulation')->and($result['ready_for_emission'])->toBeFalse()
        ->and(($this->archive)($result)['manifest']['provenance'])->toBe('simulated');
    $mock->assertSentCount(2);
});

it('connects the sandbox HTTP POST to the real producer using simulated SDK replies and existing private results', function () {
    ($this->grant)();
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'superpdp_preparation.web_enabled' => true]);
    $mock = MockClient::global(($this->responses)());
    $csrf = str_repeat('c', 40);
    $this->withSession(['_token' => $csrf]);
    $invoiceBefore = $this->invoice->fresh()->getRawOriginal();
    $connectionBefore = $this->connection->fresh()->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('No SQL writes allowed during preparation');
        }
    });
    $this->post(route('invoices.request-cii-preparation', $this->invoice), ['_token' => $csrf,
        'authorization' => ['approved_by' => 'untrusted browser'], 'verification_reference' => 'documentary-reference/first-reference',
        'source_sha256' => str_repeat('0', 64), 'document_path' => 'document.cii.xml'])
        ->assertStatus(303)->assertSessionHas('cii_request_status', 'review');
    $mock->assertSentCount(2);
    $reservation = app(CiiPreparationRegistry::class)->consult($this->owner, $this->invoice);
    expect($reservation['state'])->toBe('review_required')
        ->and($reservation['result']['status'])->toBe('prepared_simulation');
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()->assertSee('Document de simulation uniquement');
    $this->get(route('invoices.cii-download', ['invoice' => $this->invoice, 'reservation' => $reservation['reservation_id']]))
        ->assertOk()->assertContent($this->xml)->assertHeader('Cache-Control', 'no-store, private');
    $this->post(route('invoices.request-cii-preparation', $this->invoice), ['_token' => $csrf])->assertStatus(303)->assertSessionHas('cii_request_status', 'review');
    $mock->assertSentCount(2);
    expect($this->invoice->fresh()->getRawOriginal())->toBe($invoiceBefore)
        ->and($this->connection->fresh()->getRawOriginal())->toBe($connectionBefore);
});

it('retains the HTTP reservation after simulated conversion or validation failures and never retries', function (string $failure) {
    ($this->grant)();
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'superpdp_preparation.web_enabled' => true]);
    $override = $failure === 'conversion'
        ? [ConvertInvoiceRequest::class => MockResponse::make('UPSTREAM-SECRET', 503)]
        : [CreateValidationReportRequest::class => MockResponse::make('UPSTREAM-SECRET', 503)];
    $mock = MockClient::global(($this->responses)($override));
    $csrf = str_repeat('d', 40);
    $this->withSession(['_token' => $csrf]);
    $before = $this->invoice->fresh()->getRawOriginal();
    for ($i = 0; $i < 2; $i++) {
        $this->post(route('invoices.request-cii-preparation', $this->invoice), ['_token' => $csrf])
            ->assertStatus(303)->assertSessionHas('cii_request_status', 'review')
            ->assertSessionHas('cii_request_messages', fn ($messages) => ! str_contains(implode(' ', $messages), 'UPSTREAM-SECRET'));
    }
    $mock->assertSentCount($failure === 'conversion' ? 1 : 2);
    $reservation = app(CiiPreparationRegistry::class)->consult($this->owner, $this->invoice);
    expect($reservation['state'])->toBe('review_required')->and($this->invoice->fresh()->getRawOriginal())->toBe($before);
    if ($failure === 'validation') {
        $artifact = $reservation['result']['artifact'];
        $stored = app(PreparedCiiArchive::class)->read($this->owner, $this->invoice, $artifact['reference']);
        expect($stored['xml'])->toBe($this->xml)->and($stored['manifest']['validation']['status'])->toBe('unavailable');
    }
})->with(['conversion', 'validation']);

it('ignores provider flags attempting to claim live validation in a mocked body', function () {
    $report = PreparedCiiFixture::report($this->xml) + ['source' => 'live', 'ready_for_emission' => true];
    $result = ($this->produce)(($this->gateway)([CreateValidationReportRequest::class => MockResponse::make($report)]));
    expect($result['ready_for_emission'])->toBeFalse()->and(($this->archive)($result)['manifest']['validation']['source'])->toBe('simulated');
});

it('rejects unqualified production or incorrectly configured contexts before any SDK request', function (string $case) {
    match ($case) {
        'production_app' => $this->app->instance('env', 'production'),
        'production_oauth' => config(['services.superpdp.environment' => 'production']),
        'production_token' => $this->connection->update(['environment' => 'production']),
        'legacy' => $this->connection->update(['environment' => null]),
        'api' => config(['services.superpdp.api_url' => 'https://wrong.test']),
        'client' => config(['services.superpdp.client_id' => 'other']),
        'unpinned' => config(['superpdp_preparation.sandbox_client_id' => null]),
        'expired' => $this->connection->update(['access_token_expires_at' => now()->addMinutes(4)]),
        'disconnected' => $this->connection->update(['status' => 'disconnected']),
    };
    expect(fn () => ($this->gateway)())->toThrow(Exception::class);
})->with(['production_app', 'production_oauth', 'production_token', 'legacy', 'api', 'client', 'unpinned', 'expired', 'disconnected']);

it('refuses real API access without a matching fresh preparation approval', function (string $case) {
    ($this->grant)();
    match ($case) {
        'closed' => config(['superpdp_preparation.live_enabled' => false]),
        'missing' => config(['superpdp_preparation.authorization' => null]),
        'expired' => config(['superpdp_preparation.authorization.expires_at' => now()->timestamp]),
        'future' => config(['superpdp_preparation.authorization.approved_at' => now()->addMinute()->timestamp]),
        'long' => config(['superpdp_preparation.authorization.expires_at' => now()->addHour()->timestamp]),
        'owner' => config(['superpdp_preparation.authorization.user_id' => 999]),
        'invoice' => config(['superpdp_preparation.authorization.invoice_id' => 999]),
        'source' => config(['superpdp_preparation.authorization.source_sha256' => str_repeat('0', 64)]),
        'proof' => config(['superpdp_preparation.authorization.verification_reference' => 'other']),
        'company' => config(['superpdp_preparation.authorization.provider_company_id' => 999]),
    };
    $mock = MockClient::global([]);
    expect(($this->produce)(null)['reason'])->toBe('preparation_context_failed');
    $mock->assertSentCount(0);
})->with(['closed', 'missing', 'expired', 'future', 'long', 'owner', 'invoice', 'source', 'proof', 'company']);

it('rejects missing incomplete or invalid historical invoice data locally', function (string $case) {
    match ($case) {
        'draft' => DB::table('invoices')->where('id', $this->invoice->id)->update(['document_status' => 'draft']),
        'snapshot' => DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => null]),
        'context' => DB::table('invoices')->where('id', $this->invoice->id)->update(['emission_context' => null]),
        'paid' => DB::table('invoices')->where('id', $this->invoice->id)->update(['amount_paid' => '1.00']),
        'calculation' => DB::table('invoices')->where('id', $this->invoice->id)->update(['calculation_version' => null]),
    };
    $mock = MockClient::global([]);
    expect(($this->produce)(null)['reason'])->toBe('local_data_incomplete');
    $mock->assertSentCount(0);
})->with(['draft', 'snapshot', 'context', 'paid', 'calculation']);

it('requires matching fresh party and destination evidence', function (string $case) {
    $report = $this->report;
    match ($case) {
        'stale' => $report['checked_at'] = now()->subMinutes(16)->toIso8601String(),
        'source' => $report['source_sha256'] = str_repeat('0', 64),
        'company' => $report['expected_provider_company_id'] = 999,
        'client' => $report['expected_oauth_client_id'] = 'other',
        'routing' => $report['checks']['buyer_destination']['status'] = 'refused',
        'identity' => $report['checks']['seller_identity'] = [],
        'fake_verified' => $report['checks']['seller_session']['verified'] = true,
    };
    ($this->saveProof)($report);
    expect(fn () => ($this->gateway)())->toThrow(RuntimeException::class);
})->with(['stale', 'source', 'company', 'client', 'routing', 'identity', 'fake_verified']);

it('does not archive malformed foreign or inconsistent converted documents', function (string $xml) {
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make($xml, 200, ['Content-Type' => 'application/xml'])]);
    $result = ($this->produce)($gateway);
    expect($result['reason'])->toBe('conversion_failed')->and($result['artifact'])->toBeNull()
        ->and(array_column($gateway->requests(), 'class'))->toBe([ConvertInvoiceRequest::class]);
    $diagnostic = ($this->failureDiagnostic)($result);
    expect($diagnostic['http_status'])->toBe(200)->and($diagnostic['response_sha256'])->toBe(hash('sha256', $xml))
        ->and($diagnostic['failure_stage'])->toBe($xml === '' ? 'conversion.response'
            : ($xml === '<Invoice>not CII</Invoice>' ? 'conversion.local_cii_checks' : 'conversion.xml'));
})->with(['<bad>', '<Invoice>not CII</Invoice>', '<!DOCTYPE x SYSTEM "https://forbidden.test"><x/>', '']);

it('accepts contract XML responses with charset BOM and equivalent namespace prefixes', function ($variant) {
    $xml = match ($variant) {
        'bom' => "\xEF\xBB\xBF".$this->xml,
        'prefix' => str_replace(['rsm:', 'xmlns:rsm='], ['cii:', 'xmlns:cii='], $this->xml),
        default => $this->xml,
    };
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make($xml, 200,
        ['Content-Type' => 'Application/XML; charset=UTF-8']),
        CreateValidationReportRequest::class => MockResponse::make(PreparedCiiFixture::report($xml))]);
    $result = ($this->produce)($gateway);
    expect($result['status'])->toBe('prepared_simulation')->and($result['artifact']['validation_status'])->toBe('passed_simulation')
        ->and($result)->not->toHaveKey('diagnostic_reference')->and(count($gateway->requests()))->toBe(2)
        ->and(($this->archive)($result)['xml'])->toBe($xml);
})->with(['charset', 'bom', 'prefix']);

it('rejects a converted CII for another invoice or party before API validation', function (string $case) {
    $xml = $case === 'invoice' ? str_replace($this->payload['number'], 'OTHER-INVOICE', $this->xml)
        : str_replace('552100554', '999999999', $this->xml);
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make($xml, 200, ['Content-Type' => 'application/xml'])]);
    $result = ($this->produce)($gateway);
    expect($result['reason'])->toBe('conversion_failed')->and($result['failure_kind'])->toBe('local_cii_rejected')
        ->and(($this->failureDiagnostic)($result)['http_status'])->toBe(200);
    expect(count($gateway->requests()))->toBe(1);
})->with(['invoice', 'party']);

it('handles conversion failures without retry fallback or leaking remote messages', function (int $status, string $type) {
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make('REMOTE-SECRET', $status, ['Content-Type' => $type])]);
    $result = ($this->produce)($gateway);
    expect($result['reason'])->toBe('conversion_failed')->and($result['artifact'])->toBeNull()
        ->and(json_encode($result))->not->toContain('REMOTE-SECRET')->and(count($gateway->requests()))->toBe(1);
    $diagnostic = ($this->failureDiagnostic)($result);
    expect($diagnostic['http_status'])->toBe($status)->and($diagnostic['content_type'])->toBe($type)
        ->and($diagnostic['response_size'])->toBe(strlen('REMOTE-SECRET'))
        ->and($diagnostic['response_sha256'])->toBe(hash('sha256', 'REMOTE-SECRET'))
        ->and($diagnostic['failure_kind'])->toBe($status === 200 ? 'unexpected_content' : 'http_non_200')
        ->and(json_encode($diagnostic))->not->toContain('REMOTE-SECRET');
})->with([[400, 'application/json'], [401, 'text/plain'], [302, 'text/plain'], [429, 'text/plain'], [500, 'text/plain'], [200, 'text/plain']]);

it('retains known converted bytes without authorizing emission when validation fails or is unavailable', function (string $case) {
    $report = PreparedCiiFixture::report($this->xml);
    match ($case) {
        'invalid' => $report['data'][0]['is_valid'] = false,
        'wrong_size' => $report['data'][0]['file_size']++,
        'empty_checks' => $report['data'][0]['subreports'] = [],
        default => null,
    };
    $response = match ($case) {
        'http' => MockResponse::make('REMOTE-SECRET', 503),
        'json' => MockResponse::make('INVALID-JSON'),
        'duplicate' => MockResponse::make('{"data":[],"data":[]}'),
        default => MockResponse::make($report),
    };
    $gateway = ($this->gateway)([CreateValidationReportRequest::class => $response]);
    $result = ($this->produce)($gateway);
    expect($result['status'])->toBe('prepared_simulation')->and($result['ready_for_emission'])->toBeFalse()
        ->and(($this->archive)($result)['xml'])->toBe($this->xml)->and(count($gateway->requests()))->toBe(2)
        ->and($result['artifact']['validation_status'])->toBe(in_array($case, ['http', 'json', 'duplicate'], true) ? 'unavailable' : 'failed')
        ->and(json_encode($result))->not->toContain('REMOTE-SECRET');
    $diagnostic = ($this->failureDiagnostic)($result);
    expect($diagnostic['operation'])->toBe('validation')
        ->and($diagnostic['failure_kind'])->toBe(match ($case) {
            'http' => 'http_non_200', 'json', 'duplicate' => 'unexpected_content', default => 'validation_report_rejected',
        })->and(json_encode($diagnostic))->not->toContain('REMOTE-SECRET');
})->with(['invalid', 'wrong_size', 'empty_checks', 'http', 'json', 'duplicate']);

it('keeps transport and timeout diagnostics without retry or leaking exception contents', function ($operation, $errno) {
    $attempts = 0;
    $response = MockResponse::make()->throw(function (PendingRequest $pending) use (&$attempts, $errno) {
        $attempts++;
        return new \Saloon\Exceptions\Request\FatalRequestException(
            new \GuzzleHttp\Exception\ConnectException('TOKEN-SECRET private address@example.test',
                new PsrRequest('POST', 'https://api.superpdp.tech'), null, ['errno' => $errno]), $pending);
    });
    $request = $operation === 'conversion' ? ConvertInvoiceRequest::class : CreateValidationReportRequest::class;
    $gateway = ($this->gateway)([$request => $response]);
    $result = ($this->produce)($gateway);
    $diagnostic = ($this->failureDiagnostic)($result);
    expect($attempts)->toBe(1)->and($diagnostic['failure_stage'])->toBe($operation.'.transport')
        ->and($diagnostic['failure_kind'])->toBe($errno === 28 ? 'timeout' : 'transport_error')
        ->and($diagnostic['http_status'])->toBeNull()->and($diagnostic['response_sha256'])->toBeNull()
        ->and(json_encode($diagnostic))->not->toContain('TOKEN-SECRET', 'address@example.test')
        ->and(app(CiiPreparationRegistry::class)->existing($this->owner, $this->invoice, 'simulation')['state'])->toBe('review_required');
    ($this->produce)($gateway);
    expect($attempts)->toBe(1);
})->with([['conversion', 28], ['conversion', 7], ['validation', 28], ['validation', 7]]);

it('filters remote errors and arbitrary headers before storing private diagnostics', function ($message, $expected) {
    $body = ['message' => $message, 'access_token' => 'TOKEN-SECRET', 'email' => 'person@example.test'];
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make($body, 400,
        ['Content-Type' => 'application/json; secret=HEADER-SECRET', 'Authorization' => 'Bearer HEADER-SECRET'])]);
    $result = ($this->produce)($gateway);
    $diagnostic = ($this->failureDiagnostic)($result);
    expect($diagnostic['remote_error_message'])->toBe($expected)->and($diagnostic['content_type'])->toBe('application/json')
        ->and(json_encode($diagnostic))->not->toContain('TOKEN-SECRET', 'HEADER-SECRET', 'person@example.test');
})->with([['Invalid invoice', 'Invalid invoice'], ['TOKEN-SECRET person@example.test', '[redacted] [redacted]']]);

it('stores useful structured HTTP 400 validation errors without secrets or full payloads', function () {
    $body = ['http_status_code' => 400, 'code' => 1234,
        'message' => 'Validation failed for seller.vat_identifier and lines[0].invoiced_quantity',
        'errors' => [
            ['field' => 'seller.vat_identifier', 'type' => 'format', 'message' => 'Invalid "FR12345678901" for '.$this->payload['seller']['name'], 'value' => 'FR12345678901'],
            ['field' => 'lines.0.invoiced_quantity', 'type' => 'decimal_parsing', 'message' => 'Cannot parse "PRIVATE-INPUT" as decimal', 'input' => 'PRIVATE-INPUT'],
            ['field' => 'access_token', 'type' => 'not_allowed', 'message' => 'Authorization: Bearer TOKEN-SECRET; session_id=SESSION-SECRET', 'value' => 'TOKEN-SECRET'],
        ],
        'session_id' => 'SESSION-SECRET', 'headers' => ['Authorization' => 'Bearer TOKEN-SECRET'], 'payload' => $this->payload];
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make($body, 400, ['Content-Type' => 'application/json'])]);
    $result = ($this->produce)($gateway);
    $diagnostic = ($this->failureDiagnostic)($result);
    expect($diagnostic['http_status'])->toBe(400)->and($diagnostic['remote_error_format'])->toBe('http_ko')
        ->and($diagnostic['remote_error_code'])->toBe(1234)
        ->and($diagnostic['remote_error_message'])->toContain('Validation failed', 'seller.vat_identifier', 'lines[0].invoiced_quantity')
        ->and(array_column($diagnostic['remote_validation_errors'], 'field'))->toBe(['seller.vat_identifier', 'lines.0.invoiced_quantity', 'access_token'])
        ->and(array_column($diagnostic['remote_validation_errors'], 'type'))->toBe(['format', 'decimal_parsing', 'not_allowed'])
        ->and($diagnostic['remote_validation_errors'][0]['value'])->toBe('[redacted]')
        ->and(json_encode($diagnostic))->not->toContain('FR12345678901', 'PRIVATE-INPUT', 'TOKEN-SECRET', 'SESSION-SECRET',
            $this->payload['seller']['name'], $this->payload['number'], 'Authorization', '"payload"')
        ->and(count($gateway->requests()))->toBe(1);
});

it('validates archived bytes without conversion and filters echoed payload values on failure', function () {
    $gateway = ($this->gateway)([CreateValidationReportRequest::class => MockResponse::make([
        'http_status_code' => 400, 'message' => 'Invalid invoice for '.$this->payload['seller']['name'],
    ], 400)]);
    try {
        $gateway->validate($this->xml);
        $this->fail('Expected validation refusal');
    } catch (\App\Services\SuperPdp\CiiPreparationFailure $exception) {
        expect($exception->diagnostic['operation'])->toBe('validation')
            ->and($exception->diagnostic['http_status'])->toBe(400)
            ->and(json_encode($exception->diagnostic))->not->toContain($this->payload['seller']['name']);
    }
    expect(array_column($gateway->requests(), 'class'))->toBe([CreateValidationReportRequest::class]);
});

it('keeps the uncertain registry blocking even if diagnostic storage fails', function () {
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make('SECRET', 500)]);
    $this->mock(VerificationAuditStore::class, fn ($mock) => $mock->makePartial()
        ->shouldReceive('store')->andThrow(new RuntimeException('storage unavailable')));
    $result = ($this->produce)($gateway);
    expect($result['diagnostic_status'])->toBe('unavailable')->and($result['reason'])->toBe('conversion_failed')
        ->and(app(CiiPreparationRegistry::class)->existing($this->owner, $this->invoice, 'simulation')['state'])->toBe('review_required')
        ->and($result)->not->toHaveKey('diagnostic_reference');
});

it('does not fall back to real requests when a conversion mock is missing', function () {
    $gateway = SandboxCiiPreparationGateway::forInvoice($this->owner, $this->invoice, $this->fingerprint, $this->verification, []);
    expect(($this->produce)($gateway)['reason'])->toBe('conversion_failed');
});

it('rejects unexpected SDK operations and non-memory mock configurations', function () {
    expect(fn () => SandboxCiiPreparationGateway::forInvoice($this->owner, $this->invoice, $this->fingerprint, $this->verification,
        [CreateInvoiceRequest::class => MockResponse::make([])]))->toThrow(RuntimeException::class);
    expect(fn () => SandboxCiiPreparationGateway::forInvoice($this->owner, $this->invoice, $this->fingerprint, $this->verification,
        [ConvertInvoiceRequest::class => fn () => MockResponse::make([])]))->toThrow(RuntimeException::class);
});

it('never uses current profiles to replace frozen seller buyer or electronic addresses', function () {
    $this->company->update(['siret' => '55210055400013', 'company_name' => 'Changed seller', 'billing_electronic_address' => null]);
    $this->client->update(['siret' => '73282932000074', 'billing_electronic_address' => null]);
    $result = ($this->produce)(($this->gateway)());
    expect(($this->archive)($result)['xml'])->toBe($this->xml)->and($result['artifact']['ready_for_emission'])->toBeFalse();
});

it('rejects invoice changes during preparation without repairing the external modification', function () {
    $response = MockResponse::make(PreparedCiiFixture::report($this->xml))->throw(function () {
        DB::table('invoices')->where('id', $this->invoice->id)->update(['amount_paid' => '10.00']);

        return null;
    });
    $result = ($this->produce)(($this->gateway)([CreateValidationReportRequest::class => $response]));
    expect($result['reason'])->toBe('invoice_changed_during_preparation')->and($result['artifact'])->toBeNull()
        ->and($this->invoice->fresh()->amount_paid)->toBe('10.00');
});

it('publishes identical receipts idempotently and refuses tampered archives without repair', function () {
    $this->freezeTime();
    $gateway = ($this->gateway)();
    $result = ($this->produce)($gateway);
    $secondGateway = ($this->gateway)();
    $again = ($this->produce)($secondGateway);
    expect($again['artifact']['reference'])->toBe($result['artifact']['reference'])
        ->and($again['reason'])->toBe('reservation_reused')->and($secondGateway->requests())->toBe([])
        ->and(count(Storage::disk('invoice_cii')->allFiles('v1/simulations')))->toBe(2);
    $path = substr($result['artifact']['reference'], strlen('invoice-cii/')).'/manifest.json';
    Storage::disk('invoice_cii')->put($path, '{}');
    $tamperedGateway = ($this->gateway)();
    expect(($this->produce)($tamperedGateway)['reason'])->toBe('preparation_context_failed')
        ->and($tamperedGateway->requests())->toBe([])
        ->and(Storage::disk('invoice_cii')->get($path))->toBe('{}');
});

it('refuses arbitrary payloads XML and cross-owner gateways before conversion', function () {
    $gateway = ($this->gateway)();
    $payload = $this->payload;
    $payload['number'] = 'OTHER';
    expect(fn () => $gateway->convert($payload))->toThrow(RuntimeException::class)
        ->and(fn () => $gateway->validate('<Other/>'))->toThrow(RuntimeException::class);
    $other = User::factory()->create();
    expect(fn () => app(InvoiceCiiPreparation::class)->produce($other, $this->invoice, $this->verification, $gateway))->toThrow(Exception::class);
    expect($gateway->requests())->toBe([]);
});

it('binds validation receipts to the exact converted bytes rather than accepting caller flags', function () {
    $gateway = ($this->gateway)();
    $conversion = $gateway->convert($this->payload);
    expect(fn () => app(PreparedCiiArchive::class)->storeProduced($this->owner, $this->invoice, $conversion, $conversion, $this->verification))
        ->toThrow(RuntimeException::class);
});

it('keeps cached conversions in a pending non-authorized namespace even with a structurally valid report', function () {
    ($this->liveProofFixture)();
    // Construct a cache receipt in memory. It is deliberately never marked live.
    $pending = new PendingRequest((new SuperPdpConnector)->withMockClient(new MockClient([ConvertInvoiceRequest::class => MockResponse::make($this->xml)])), new ConvertInvoiceRequest('{}', 'en16931', 'cii', 'application/json'));
    $psrRequest = new PsrRequest('POST', SandboxEmissionAuthorization::API_URL.'/invoices/convert');
    $response = Response::fromPsrResponse(new PsrResponse(200, ['Content-Type' => 'application/xml'], $this->xml), $pending, $psrRequest)->setCached(true);
    $conversion = VerificationObservation::fromResponse(['operation' => 'conversion', 'xml' => $this->xml,
        'input_sha256' => CiiPreparationEvidence::hash($this->payload)], $response);
    $artifact = app(PreparedCiiArchive::class)->storeProduced($this->owner, $this->invoice, $conversion, null, $this->verification);
    expect($artifact['reference'])->toContain('/pending/')->and($artifact['ready_for_emission'])->toBeFalse();
    $archive = app(PreparedCiiArchive::class)->readPending($this->owner, $this->invoice, $artifact['reference']);
    expect($archive['manifest']['provenance'])->toBe('cached')->and($archive['xml'])->toBe($this->xml)
        ->and($archive['manifest']['validation']['status'])->toBe('unavailable');
    expect(fn () => app(PreparedCiiArchive::class)->readValidated($this->owner, $this->invoice, $artifact['reference']))->toThrow(RuntimeException::class);
});
