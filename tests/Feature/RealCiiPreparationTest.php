<?php

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiPreparationEvidence;
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
use Tests\Support\PreparedCiiFixture;

beforeEach(function () {
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
})->with(['<bad>', '<Invoice>not CII</Invoice>', '<!DOCTYPE x SYSTEM "https://forbidden.test"><x/>', '']);

it('rejects a converted CII for another invoice or party before API validation', function (string $case) {
    $xml = $case === 'invoice' ? str_replace($this->payload['number'], 'OTHER-INVOICE', $this->xml)
        : str_replace('552100554', '999999999', $this->xml);
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make($xml, 200, ['Content-Type' => 'application/xml'])]);
    expect(($this->produce)($gateway)['reason'])->toBe('conversion_failed');
    expect(count($gateway->requests()))->toBe(1);
})->with(['invoice', 'party']);

it('handles conversion failures without retry fallback or leaking remote messages', function (int $status, string $type) {
    $gateway = ($this->gateway)([ConvertInvoiceRequest::class => MockResponse::make('REMOTE-SECRET', $status, ['Content-Type' => $type])]);
    $result = ($this->produce)($gateway);
    expect($result['reason'])->toBe('conversion_failed')->and($result['artifact'])->toBeNull()
        ->and(json_encode($result))->not->toContain('REMOTE-SECRET')->and(count($gateway->requests()))->toBe(1);
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
})->with(['invalid', 'wrong_size', 'empty_checks', 'http', 'json', 'duplicate']);

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
    $again = ($this->produce)(($this->gateway)());
    expect($again['artifact']['reference'])->toBe($result['artifact']['reference'])
        ->and(count(Storage::disk('invoice_cii')->allFiles('v1/simulations')))->toBe(2);
    $path = substr($result['artifact']['reference'], strlen('invoice-cii/')).'/manifest.json';
    Storage::disk('invoice_cii')->put($path, '{}');
    expect(($this->produce)(($this->gateway)())['reason'])->toBe('private_storage_failed')
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
