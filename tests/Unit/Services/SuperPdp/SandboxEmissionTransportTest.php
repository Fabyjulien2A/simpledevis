<?php

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\Emission\ClosedEmissionPrerequisites;
use App\Services\SuperPdp\Emission\DisabledEmissionTransport;
use App\Services\SuperPdp\Emission\EmissionPrerequisites;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Emission\SandboxEmissionFactory;
use App\Services\SuperPdp\Emission\SandboxEmissionPrerequisites;
use App\Services\SuperPdp\Emission\SandboxSdkEmissionTransport;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Bluerock\SuperPdp\Requests\InvoiceEvents\ListInvoiceEventsRequest;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Bluerock\SuperPdp\Requests\Invoices\GetInvoiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Support\IsolatedEmissionDatabase;
use Tests\Support\PreparedCiiFixture;
use Tests\TestCase;

uses(TestCase::class, IsolatedEmissionDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Storage::fake('invoice_cii');
    config(['services.superpdp.environment' => 'sandbox', 'services.superpdp.client_id' => 'sandbox-client',
        'services.superpdp.api_url' => SandboxEmissionAuthorization::API_URL]);
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create([
        'company_name' => 'Test historical seller', 'siret' => '73282932000074', 'country_code' => 'FR',
        'address' => '1 rue Test', 'postal_code' => '75001', 'city' => 'Paris',
        'vat_number' => 'FR44732829320', 'vat_regime' => 'subject_to_vat',
        'invoice_recovery_indemnity_text' => 'PMT', 'invoice_late_payment_penalties_text' => 'PMD',
        'invoice_early_payment_discount_text' => 'AAB', 'billing_electronic_address' => ['scheme' => '0225', 'value' => '732829320'],
    ]);
    $client = $this->owner->clients()->create([
        'company_name' => 'Test historical buyer', 'client_type' => 'professional', 'siret' => '55210055400013',
        'country_code' => 'FR', 'address' => '2 rue Test', 'postal_code' => '69001', 'city' => 'Lyon',
        'vat_number' => 'FR00552100554', 'billing_electronic_address' => ['scheme' => '0225', 'value' => '552100554'],
    ]);
    $this->actingAs($this->owner)->post(route('invoices.store'), [
        'action' => 'issue', 'emission_profile' => 'fr_b2b_380_eur', 'french_business_process_type' => 'S1',
        'client_id' => $client->id, 'date' => '2026-11-11', 'due_date' => '2026-12-11',
        'items' => [['description' => 'Offline sandbox test', 'quantity' => '1', 'unit_price_ht' => '100', 'tva_rate' => '20', 'unit_code' => 'C62', 'tax_treatment' => 'taxable']],
    ])->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
    $this->connection = $this->company->superPdpConnection()->create([
        'environment' => 'sandbox', 'oauth_client_id' => 'sandbox-client', 'api_url' => SandboxEmissionAuthorization::API_URL,
        'superpdp_company_id' => 123, 'access_token' => 'SANDBOX-TOKEN', 'refresh_token' => 'DO-NOT-REFRESH',
        'access_token_expires_at' => now()->addHour(), 'status' => 'connected', 'reception_enabled' => true,
        'last_invoice_id' => 55, 'last_event_id' => 66,
    ]);
    $this->payload = app(SuperPdpInvoicePayloadBuilder::class)->build($this->invoice);
    $this->xml = PreparedCiiFixture::xml($this->payload);
    $this->sourceData = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $this->hash = fn (array $value) => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    // Synthetic private fixtures exercise the real reader contract. They are never
    // promoted from stored simulations, and exist only on Storage::fake in testing.
    $check = ['status' => 'passed', 'source' => 'live', 'verified' => true, 'response_sha256' => str_repeat('a', 64), 'observed_at' => now()->toIso8601String()];
    $this->report = ['schema_version' => 1, 'user_id' => $this->owner->id, 'invoice_id' => $this->invoice->id,
        'emission_ready' => false, 'status' => 'passed', 'expected_environment' => 'sandbox',
        'expected_provider_company_id' => 123, 'expected_oauth_client_id' => 'sandbox-client', 'expected_api_url' => SandboxEmissionAuthorization::API_URL,
        'checked_at' => now()->toIso8601String(), 'source_sha256' => ($this->hash)($this->sourceData),
        'party_snapshot_sha256' => hash('sha256', $this->sourceData['party_snapshot']),
        'emission_context_sha256' => hash('sha256', $this->sourceData['emission_context']),
        'checks' => array_fill_keys(['buyer_destination', 'seller_identity', 'seller_session'], $check)];
    $audit = app(VerificationAuditStore::class)->store($this->owner, $this->invoice, $this->report);
    $this->manifest = ['schema_version' => 1, 'mode' => 'validated_private_document', 'provenance' => 'live', 'ready_for_emission' => true,
        'user_id' => $this->owner->id, 'invoice_id' => $this->invoice->id, 'environment' => 'sandbox',
        'api_url' => SandboxEmissionAuthorization::API_URL, 'oauth_client_id' => 'sandbox-client', 'provider_company_id' => 123,
        'prepared_at' => now()->toIso8601String(), 'source_sha256' => ($this->hash)($this->sourceData), 'payload_sha256' => ($this->hash)($this->payload),
        'party_snapshot_sha256' => $this->report['party_snapshot_sha256'], 'emission_context_sha256' => $this->report['emission_context_sha256'],
        'cii_sha256' => hash('sha256', $this->xml), 'verification_reference' => $audit,
        'validation' => ['status' => 'passed', 'source' => 'live', 'observed_at' => now()->toIso8601String(),
            'cii_sha256' => hash('sha256', $this->xml), 'report' => PreparedCiiFixture::report($this->xml)]];
    $this->publish = function (?array $manifest = null, ?string $xml = null) {
        $manifest ??= $this->manifest;
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $relative = 'v1/validated/'.$this->owner->id.'/'.$this->invoice->id.'/'.hash('sha256', $json);
        Storage::disk('invoice_cii')->put($relative.'/document.xml', $xml ?? $this->xml);
        Storage::disk('invoice_cii')->put($relative.'/manifest.json', $json);
        $this->reference = 'invoice-cii/'.$relative;
        $this->archivePath = Storage::disk('invoice_cii')->path($relative);
        config(['superpdp_emission.authorization.document_reference' => $this->reference]);

        return $this->reference;
    };
    $this->approval = ['approved_by' => 'TEST-OPERATOR', 'approved_at' => now()->timestamp, 'expires_at' => now()->addMinutes(10)->timestamp,
        'environment' => 'sandbox', 'api_url' => SandboxEmissionAuthorization::API_URL, 'oauth_client_id' => 'sandbox-client',
        'user_id' => $this->owner->id, 'invoice_id' => $this->invoice->id, 'provider_company_id' => 123,
        'cii_sha256' => hash('sha256', $this->xml)];
    ($this->publish)();
    $this->approval['document_reference'] = $this->reference;
    $this->unlock = fn () => config(['superpdp_emission.sandbox_enabled' => true, 'superpdp_emission.sandbox_client_id' => 'sandbox-client',
        'superpdp_emission.authorization' => $this->approval]);
    $this->factory = fn () => app(SandboxEmissionFactory::class)->forInvoice($this->owner, $this->invoice, $this->reference);
    $this->prepare = fn () => app(SandboxEmissionPrerequisites::class)->authorize($this->owner, $this->invoice, $this->reference);
    $this->reserve = function () {
        $this->service = ($this->factory)();
        $attempt = $this->service->reserve($this->owner, $this->invoice, $this->reference);
        $this->record = ElectronicInvoice::findOrFail($attempt->electronic_invoice_id);
        config(['superpdp_emission.authorization.attempt_reference' => $attempt->reference]);

        return $attempt;
    };
    $this->remote = fn (string $reference) => ['id' => 900, 'company_id' => 123, 'direction' => 'out', 'external_id' => $reference, 'created_at' => now()->toIso8601String()];
});

afterEach(function () {
    Http::assertNothingSent();
    MockClient::destroyGlobal();
    Config::allowStrayRequests();
});

it('keeps default bindings and every direct SDK operation locked without explicit approval', function () {
    expect(app(EmissionPrerequisites::class))->toBeInstanceOf(ClosedEmissionPrerequisites::class)
        ->and(app(EmissionTransport::class))->toBeInstanceOf(DisabledEmissionTransport::class);
    $mock = MockClient::global([]);
    expect(fn () => ($this->factory)())->toThrow(RuntimeException::class)
        ->and(fn () => ($this->prepare)())->toThrow(RuntimeException::class);
    $transport = new SandboxSdkEmissionTransport($this->owner->id, $this->invoice->id, $this->reference,
        app(SandboxEmissionAuthorization::class), app(SandboxEmissionPrerequisites::class));
    foreach (['lookup', 'original', 'events'] as $method) {
        expect(fn () => $transport->$method(900, null))->toThrow(RuntimeException::class);
    }
    $mock->assertSentCount(0);
    expect(ElectronicInvoice::count())->toBe(0);
});

it('refuses production unqualified URLs clients credentials expired approvals and wrong scopes without requests', function (string $case) {
    ($this->unlock)();
    match ($case) {
        'production_app' => $this->app->instance('env', 'production'),
        'production_oauth' => config(['services.superpdp.environment' => 'production']),
        'production_connection' => $this->connection->update(['environment' => 'production']),
        'legacy' => $this->connection->update(['environment' => null]),
        'wrong_api' => config(['services.superpdp.api_url' => 'https://api.superpdp.tech']),
        'foreign_api' => config(['services.superpdp.api_url' => 'https://other.test/v1.beta']),
        'api_suffix' => config(['services.superpdp.api_url' => SandboxEmissionAuthorization::API_URL.'/']),
        'client' => config(['services.superpdp.client_id' => 'production-client']),
        'unpinned_client' => config(['superpdp_emission.sandbox_client_id' => null]),
        'stored_client' => $this->connection->update(['oauth_client_id' => 'production-client']),
        'stored_api' => $this->connection->update(['api_url' => 'https://other.test/v1.beta']),
        'expired_token' => $this->connection->update(['access_token_expires_at' => now()->addMinutes(4)]),
        'disconnected' => $this->connection->update(['status' => 'disconnected']),
        'expired_approval' => config(['superpdp_emission.authorization.expires_at' => now()->timestamp]),
        'long_approval' => config(['superpdp_emission.authorization.expires_at' => now()->addHour()->timestamp]),
        'future_approval' => config(['superpdp_emission.authorization.approved_at' => now()->addMinute()->timestamp]),
        'wrong_owner' => config(['superpdp_emission.authorization.user_id' => 999]),
        'wrong_invoice' => config(['superpdp_emission.authorization.invoice_id' => 999]),
        'wrong_company' => config(['superpdp_emission.authorization.provider_company_id' => 999]),
        'approval_production' => config(['superpdp_emission.authorization.environment' => 'production']),
        'missing_approval' => config(['superpdp_emission.authorization' => null]),
        'string_switch' => config(['superpdp_emission.sandbox_enabled' => 'true']),
    };
    $mock = MockClient::global([]);
    expect(fn () => ($this->factory)())->toThrow(Exception::class);
    $mock->assertSentCount(0);
    expect(ElectronicInvoice::count())->toBe(0);
})->with(['production_app', 'production_oauth', 'production_connection', 'legacy', 'wrong_api', 'foreign_api', 'api_suffix',
    'client', 'unpinned_client', 'stored_client', 'stored_api', 'expired_token', 'disconnected', 'expired_approval',
    'long_approval', 'future_approval', 'wrong_owner', 'wrong_invoice', 'wrong_company', 'approval_production', 'missing_approval', 'string_switch']);

it('reads exact validated private CII without writing SQL or changing reception state', function () {
    ($this->unlock)();
    $before = $this->connection->fresh()->getRawOriginal();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Read-only prerequisites required');
        }
    });
    $document = ($this->prepare)();
    expect($document->xml)->toBe($this->xml)->and($document->environment)->toBe('sandbox')
        ->and($document->companyId)->toBe(123)->and($this->connection->fresh()->getRawOriginal())->toBe($before);
});

it('rejects simulated stale foreign or mismatched validated archive evidence', function (string $case) {
    ($this->unlock)();
    $m = $this->manifest;
    match ($case) {
        'simulation' => $m['mode'] = 'offline_simulation',
        'not_ready' => $m['ready_for_emission'] = false,
        'simulated_source' => $m['provenance'] = 'simulated',
        'schema' => $m['schema_version'] = 2,
        'owner' => $m['user_id'] = 999,
        'invoice' => $m['invoice_id'] = 999,
        'production' => $m['environment'] = 'production',
        'client' => $m['oauth_client_id'] = 'other',
        'company' => $m['provider_company_id'] = 999,
        'source_hash' => $m['source_sha256'] = str_repeat('b', 64),
        'payload_hash' => $m['payload_sha256'] = str_repeat('b', 64),
        'simulation_validation' => $m['validation']['status'] = 'passed_simulation',
        'cached_validation' => $m['validation']['source'] = 'cached',
        'validation_hash' => $m['validation']['cii_sha256'] = str_repeat('b', 64),
        'invalid_report' => $m['validation']['report']['data'][0]['is_valid'] = false,
        'stale_preparation' => $m['prepared_at'] = now()->subMinutes(16)->toIso8601String(),
        'future_validation' => $m['validation']['observed_at'] = now()->addMinute()->toIso8601String(),
        'empty_validation' => $m['validation']['report'] = [],
    };
    ($this->publish)($m);
    expect(fn () => ($this->prepare)())->toThrow(Exception::class);
    expect(ElectronicInvoice::count())->toBe(0);
})->with(['simulation', 'not_ready', 'simulated_source', 'schema', 'owner', 'invoice', 'production', 'client', 'company',
    'source_hash', 'payload_hash', 'simulation_validation', 'cached_validation', 'validation_hash', 'invalid_report', 'stale_preparation', 'future_validation', 'empty_validation']);

it('never accepts a simulation path or an altered private archive', function (string $case) {
    ($this->unlock)();
    match ($case) {
        'simulation_path' => $this->reference = str_replace('/validated/', '/simulations/', $this->reference),
        'traversal' => $this->reference .= '/../document.xml',
        'xml_changed' => file_put_contents($this->archivePath.'/document.xml', $this->xml.' '),
        'manifest_changed' => file_put_contents($this->archivePath.'/manifest.json', '{}'),
        'missing' => unlink($this->archivePath.'/document.xml'),
        'public' => config(['filesystems.disks.invoice_cii.visibility' => 'public']),
        'served' => config(['filesystems.disks.invoice_cii.serve' => true]),
    };
    expect(fn () => ($this->prepare)())->toThrow(Exception::class);
})->with(['simulation_path', 'traversal', 'xml_changed', 'manifest_changed', 'missing', 'public', 'served']);

it('requires fresh real auditable checks rather than merely passing simulations', function (string $case) {
    ($this->unlock)();
    $report = $this->report;
    match ($case) {
        'simulated' => $report['checks']['buyer_destination']['source'] = 'simulated',
        'cached' => $report['checks']['seller_identity']['source'] = 'cached',
        'unverified' => $report['checks']['seller_session']['verified'] = false,
        'missing' => $report['checks']['buyer_destination'] = [],
        'stale' => $report['checked_at'] = now()->subMinutes(16)->toIso8601String(),
        'stale_check' => $report['checks']['seller_identity']['observed_at'] = now()->subMinutes(16)->toIso8601String(),
        'production' => $report['expected_environment'] = 'production',
        'client' => $report['expected_oauth_client_id'] = 'other',
        'source' => $report['source_sha256'] = str_repeat('b', 64),
    };
    $m = $this->manifest;
    $m['verification_reference'] = app(VerificationAuditStore::class)->store($this->owner, $this->invoice, $report);
    ($this->publish)($m);
    expect(fn () => ($this->prepare)())->toThrow(RuntimeException::class);
})->with(['simulated', 'cached', 'unverified', 'missing', 'stale', 'stale_check', 'production', 'client', 'source']);

it('uses the SDK once with the exact XML correlation URL and token after durable claim', function () {
    ($this->unlock)();
    $before = $this->connection->fresh()->getRawOriginal();
    $attempt = ($this->reserve)();
    $mock = MockClient::global([CreateInvoiceRequest::class => function ($pending) use ($attempt) {
        expect(DB::transactionLevel())->toBe(0)->and($attempt->fresh()->transport_started_at)->not->toBeNull()
            ->and($pending->getUrl())->toBe(SandboxEmissionAuthorization::API_URL.'/invoices')
            ->and($pending->getMethod()->value)->toBe('POST')->and($pending->body()->all())->toBe($this->xml)
            ->and($pending->query()->get('external_id'))->toBe($attempt->reference)
            ->and($pending->query()->get('disable_pre_check'))->toBeNull()
            ->and($pending->headers()->get('Authorization'))->toBe('Bearer SANDBOX-TOKEN')
            ->and($pending->config()->get('allow_redirects'))->toBeFalse();

        return MockResponse::make(($this->remote)($attempt->reference));
    }]);
    expect($this->service->dispatch($this->owner, $this->record, $attempt->id)->status)->toBe('accepted');
    $this->service->dispatch($this->owner, $this->record, $attempt->id);
    $same = $this->service->reserve($this->owner, $this->invoice, $this->reference, true);
    expect($same->id)->toBe($attempt->id)->and($this->record->fresh()->execution_mode)->toBe('sandbox')
        ->and($this->connection->fresh()->getRawOriginal())->toBe($before);
    $mock->assertSentCount(1);
});

it('keeps uncertain errors unknown without retry or leaking remote errors', function (int $status, string $body) {
    ($this->unlock)();
    $attempt = ($this->reserve)();
    $mock = MockClient::global([CreateInvoiceRequest::class => MockResponse::make($body, $status)]);
    $result = $this->service->dispatch($this->owner, $this->record, $attempt->id);
    $same = $this->service->reserve($this->owner, $this->invoice, $this->reference, true);
    $this->service->dispatch($this->owner, $this->record, $same->id);
    expect($result->status)->toBe('unknown')->and($same->id)->toBe($attempt->id)
        ->and(json_encode($result->toArray()))->not->toContain('REMOTE-SECRET');
    $mock->assertSentCount(1);
})->with([[200, '{}'], [200, '{"id":900,"id":901}'], [201, '{}'], [302, 'REMOTE-SECRET'], [401, 'REMOTE-SECRET'], [429, 'REMOTE-SECRET'], [500, 'REMOTE-SECRET']]);

it('treats a transport exception as unknown with no automatic retry', function () {
    ($this->unlock)();
    $attempt = ($this->reserve)();
    $calls = 0;
    MockClient::global([CreateInvoiceRequest::class => function () use (&$calls) {
        $calls++;
        throw new RuntimeException('REMOTE-SECRET');
    }]);
    expect($this->service->dispatch($this->owner, $this->record, $attempt->id)->status)->toBe('unknown');
    $this->service->dispatch($this->owner, $this->record, $attempt->id);
    expect($calls)->toBe(1);
});

it('requires a new explicit attempt authorization for a retry after a certain rejection', function () {
    ($this->unlock)();
    $attempt = ($this->reserve)();
    $mock = MockClient::global([CreateInvoiceRequest::class => MockResponse::make(['http_status_code' => 400, 'message' => 'Refused'], 400)]);
    expect($this->service->dispatch($this->owner, $this->record, $attempt->id)->status)->toBe('rejected');
    $again = $this->service->reserve($this->owner, $this->invoice, $this->reference, true);
    expect($again->id)->not->toBe($attempt->id);
    // The grant still names the first UUID. No second POST can occur.
    $this->service->dispatch($this->owner, $this->record, $again->id);
    $mock->assertSentCount(1);
});

it('refuses mutations between reservation and dispatch without a POST', function (string $case) {
    ($this->unlock)();
    $attempt = ($this->reserve)();
    match ($case) {
        'lock' => config(['superpdp_emission.sandbox_enabled' => false]),
        'token_environment' => $this->connection->update(['environment' => 'production']),
        'legacy' => $this->connection->update(['environment' => null]),
        'approval' => config(['superpdp_emission.authorization' => null]),
        'source' => DB::table('invoices')->where('id', $this->invoice->id)->update(['emission_context' => null]),
        'archive' => file_put_contents($this->archivePath.'/document.xml', $this->xml.' '),
        'owner' => DB::table('invoices')->where('id', $this->invoice->id)->update(['user_id' => User::factory()->create()->id]),
    };
    $mock = MockClient::global([]);
    try {
        $this->service->dispatch($this->owner, $this->record, $attempt->id);
    } catch (Throwable) {
    }
    $mock->assertSentCount(0);
    expect($attempt->fresh()->transport_started_at)->toBeNull();
})->with(['lock', 'token_environment', 'legacy', 'approval', 'source', 'archive', 'owner']);

it('supports positive reconciliation original GET and event polling through the existing orchestrator', function () {
    ($this->unlock)();
    $attempt = ($this->reserve)();
    MockClient::global([CreateInvoiceRequest::class => MockResponse::make('{}')]);
    $this->service->dispatch($this->owner, $this->record, $attempt->id);
    MockClient::destroyGlobal();
    $mock = MockClient::global([
        GetInvoiceRequest::class => function ($pending) use ($attempt) {
            return $pending->query()->get('format') === 'original' ? MockResponse::make($this->xml)
                : MockResponse::make(($this->remote)($attempt->reference));
        },
        ListInvoiceEventsRequest::class => MockResponse::make(['data' => [], 'has_after' => false]),
    ]);
    expect($this->service->reconcile($this->owner, $this->record, $attempt->id, 900)->status)->toBe('accepted');
    expect($this->service->syncEvents($this->owner, $this->record))->toBe(['processed' => 0, 'has_after' => false]);
    $mock->assertSentCount(3);
});

it('refuses SDK deposits without a committed claimed attempt even with a document approval', function () {
    ($this->unlock)();
    $document = ($this->prepare)();
    $uuid = (string) Str::uuid();
    config(['superpdp_emission.authorization.attempt_reference' => $uuid]);
    $transport = new SandboxSdkEmissionTransport($this->owner->id, $this->invoice->id, $this->reference,
        app(SandboxEmissionAuthorization::class), app(SandboxEmissionPrerequisites::class));
    $mock = MockClient::global([]);
    expect(fn () => $transport->send($document, $uuid))->toThrow(Exception::class);
    $mock->assertSentCount(0);
});

it('refuses a deposit without the specific attempt UUID approval before claiming it', function () {
    ($this->unlock)();
    $attempt = ($this->reserve)();
    config(['superpdp_emission.authorization.attempt_reference' => null]);
    $mock = MockClient::global([]);
    $result = $this->service->dispatch($this->owner, $this->record, $attempt->id);
    expect($result->status)->toBe('rejected')->and($result->transport_started_at)->toBeNull();
    $mock->assertSentCount(0);
});

it('does not let a sandbox service process a production or simulated registry', function (string $mode, string $environment) {
    ($this->unlock)();
    $attempt = ($this->reserve)();
    $this->record->forceFill(['execution_mode' => $mode, 'environment' => $environment])->save();
    expect(fn () => $this->service->recoverInterrupted($this->owner, $this->record, $attempt->id))->toThrow(Exception::class);
    expect($attempt->fresh()->status)->toBe('pending');
})->with([['test', 'sandbox'], ['sandbox', 'production']]);
