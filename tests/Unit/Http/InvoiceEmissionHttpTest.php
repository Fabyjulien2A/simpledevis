<?php

use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceAttempt;
use App\Models\Invoice;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationRegistry;
use App\Services\SuperPdp\Emission\DisabledEmissionTransport;
use App\Services\SuperPdp\Emission\EmissionPrerequisites;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\Emission\HttpEmission;
use App\Services\SuperPdp\Emission\HttpEmissionConfirmation;
use App\Services\SuperPdp\Emission\OutgoingEmission;
use App\Services\SuperPdp\Emission\SandboxEmissionAuthorization;
use App\Services\SuperPdp\Emission\SandboxEmissionFactory;
use App\Services\SuperPdp\Emission\SandboxEmissionPrerequisites;
use App\Services\SuperPdp\Emission\SandboxWebEmissionActivation;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SandboxDatabaseIsolation;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Bluerock\SuperPdp\Requests\Invoices\CreateInvoiceRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Support\EmissionTestTransport;
use Tests\Support\IsolatedEmissionDatabase;
use Tests\Support\PreparedCiiFixture;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class, IsolatedEmissionDatabase::class);

beforeEach(function () {
    $this->setUpIsolatedEmissionDatabase();
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Config::setSenderResolver(fn () => throw new LogicException('SDK forbidden'));
    $this->transport = new EmissionTestTransport;
    $this->app->instance(EmissionTransport::class, $this->transport);
    $this->app->bind(EmissionPrerequisites::class, SandboxEmissionPrerequisites::class);
    $this->app->bind(SandboxEmissionFactory::class, fn () => throw new LogicException('Real transport factory forbidden'));
    $this->withoutVite();
    Carbon::setTestNow('2026-11-14T19:00:00Z');
    Storage::fake('invoice_cii');
    config(['session.driver' => 'array', 'cache.default' => 'array', 'app.debug' => false,
        'services.superpdp.environment' => 'sandbox', 'services.superpdp.client_id' => 'offline-client',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'superpdp_preparation.sandbox_client_id' => 'offline-client',
        'superpdp_emission.sandbox_enabled' => false, 'superpdp_emission.sandbox_client_id' => 'offline-client',
        'superpdp_emission.authorization' => null]);
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $data = SuperPdpPayloadFixture::data();
    unset($data['seller']['payment_terms']);
    $this->company = $this->owner->company()->create(array_diff_key($data['seller'], ['name' => true]) + [
        'company_name' => 'Vendeur de test', 'vat_regime' => 'subject_to_vat',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '732829320'],
        'invoice_recovery_indemnity_text' => 'PMT', 'invoice_late_payment_penalties_text' => 'PMD', 'invoice_early_payment_discount_text' => 'AAB',
    ]);
    $this->client = $this->owner->clients()->create(['client_type' => 'professional', 'company_name' => 'Client de test',
        'siret' => '55210055400013', 'vat_number' => 'FR00552100554', 'country_code' => 'FR', 'address' => '1 rue Test', 'postal_code' => '75001', 'city' => 'Paris',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '552100554']]);
    $this->actingAs($this->owner)->post(route('invoices.store'), ['action' => 'issue', 'client_id' => $this->client->id,
        'emission_profile' => 'fr_b2b_380_eur', 'french_business_process_type' => 'S1', 'date' => '2026-11-14', 'due_date' => '2026-12-14',
        'items' => [['description' => 'Prestation', 'quantity' => '1', 'unit_price_ht' => '100', 'tva_rate' => '20', 'unit_code' => 'C62', 'tax_treatment' => 'taxable']],
    ])->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
    $this->connection = $this->company->superPdpConnection()->create(['environment' => 'sandbox', 'oauth_client_id' => 'offline-client',
        'api_url' => 'https://api.superpdp.tech/v1.beta', 'superpdp_company_id' => 123, 'status' => 'connected',
        'directory_identifier' => '732829320', 'access_token' => 'OFFLINE-SECRET', 'refresh_token' => 'OFFLINE-REFRESH',
        'access_token_expires_at' => now()->addHour()]);
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox']);
    $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $this->csrf = str_repeat('e', 40);
    $this->withSession(['_token' => $this->csrf]);
    $this->requestEmission = fn ($body = []) => $this->post(route('invoices.request-electronic-emission', $this->invoice), $body + ['_token' => $this->csrf]);
    $this->invoiceBefore = $this->invoice->getRawOriginal();
    $this->snapshot = function () {
        $files = [];
        foreach (Storage::disk('invoice_cii')->allFiles() as $file) {
            $files[$file] = hash_file('sha256', Storage::disk('invoice_cii')->path($file));
        }

        return $files;
    };
    $this->validated = function () {
        // Synthetic private package and proof metadata, never a real provider validation.
        $registry = app(CiiPreparationRegistry::class);
        $slot = $registry->reserve($this->owner, $this->invoice, 'api');
        $binding = $slot['claim']->binding();
        $xml = PreparedCiiFixture::xml(app(SuperPdpInvoicePayloadBuilder::class)->build($this->invoice));
        $reference = 'invoice-party-checks/v1/'.$this->owner->id.'/'.$this->invoice->id.'/'.str_repeat('a', 64).'.json';
        $m = array_intersect_key($binding, array_flip(['user_id', 'invoice_id', 'source_sha256', 'payload_sha256', 'party_snapshot_sha256',
            'emission_context_sha256', 'environment', 'oauth_client_id', 'api_url', 'provider_company_id'])) + [
                'schema_version' => 1, 'mode' => 'validated_private_document', 'ready_for_emission' => true, 'provenance' => 'live',
                'prepared_at' => now()->toIso8601String(), 'cii_sha256' => hash('sha256', $xml), 'verification_reference' => $reference,
                'validation' => ['status' => 'passed', 'source' => 'live', 'cii_sha256' => hash('sha256', $xml),
                    'observed_at' => now()->toIso8601String(), 'report' => PreparedCiiFixture::report($xml)],
            ];
        $bytes = json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $bytes);
        $path = Storage::disk('invoice_cii')->path('v1/validated/'.$this->owner->id.'/'.$this->invoice->id.'/'.$hash);
        mkdir($path, 0700, true);
        file_put_contents($path.'/manifest.json', $bytes);
        file_put_contents($path.'/document.xml', $xml);
        $artifact = ['reference' => 'invoice-cii/v1/validated/'.$this->owner->id.'/'.$this->invoice->id.'/'.$hash,
            'manifest_sha256' => $hash, 'cii_sha256' => hash('sha256', $xml)];
        app(PreparedCiiArchive::class)->readValidated($this->owner, $this->invoice, $artifact['reference']);
        app(CiiDocumentChecks::class)->assertMatches($xml, app(SuperPdpInvoicePayloadBuilder::class)->build($this->invoice));
        $registry->complete($this->owner, $this->invoice, $slot['claim'], $artifact);
        $data = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
        $check = ['status' => 'passed', 'source' => 'live', 'verified' => true, 'response_sha256' => str_repeat('b', 64), 'observed_at' => now()->toIso8601String()];
        $this->proof = ['status' => 'passed', 'expected_environment' => 'sandbox', 'expected_provider_company_id' => 123,
            'expected_oauth_client_id' => 'offline-client', 'expected_api_url' => 'https://api.superpdp.tech/v1.beta',
            'source_sha256' => CiiPreparationEvidence::hash($data), 'party_snapshot_sha256' => hash('sha256', $data['party_snapshot']),
            'emission_context_sha256' => hash('sha256', $data['emission_context']), 'checked_at' => now()->toIso8601String(),
            'checks' => array_fill_keys(['buyer_destination', 'seller_identity', 'seller_session'], $check)];
        $audit = $this->createMock(VerificationAuditStore::class);
        $audit->method('read')->willReturnCallback(fn () => $this->proof);
        $audit->expects($this->never())->method('store');
        $this->app->instance(VerificationAuditStore::class, $audit);
        config(['superpdp_emission.sandbox_enabled' => true, 'superpdp_emission.authorization' => [
            'approved_by' => 'SIMULATED-TEST-ONLY', 'approved_at' => now()->timestamp, 'expires_at' => now()->addMinutes(10)->timestamp,
            'user_id' => $this->owner->id, 'invoice_id' => $this->invoice->id, 'document_reference' => $artifact['reference'],
            'cii_sha256' => $artifact['cii_sha256'], 'provider_company_id' => 123, 'environment' => 'sandbox',
            'oauth_client_id' => 'offline-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
        ]]);

        return [$artifact, $path];
    };
    $this->record = function ($state = 'pending', $mode = 'sandbox') {
        $record = (new ElectronicInvoice)->forceFill(['invoice_id' => $this->invoice->id, 'dispatch_key' => 'superpdp:'.$this->invoice->id,
            'local_reference' => (string) Str::uuid(), 'execution_mode' => $mode, 'environment' => 'sandbox', 'provider_company_id' => 123,
            'document_reference' => 'invoice-cii/v1/validated/'.$this->owner->id.'/'.$this->invoice->id.'/'.str_repeat('a', 64),
            'cii_sha256' => str_repeat('b', 64), 'status' => $state, 'last_error' => 'PRIVATE-UPSTREAM-SECRET',
            'provider_invoice_id' => $state === 'accepted' ? 987 : null]);
        $record->save();
        $record->attempts()->create(['number' => 1, 'reference' => (string) Str::uuid(), 'status' => $state,
            'cii_sha256' => str_repeat('b', 64), 'error_code' => $state === 'unknown' ? 'transport_uncertain' : null,
            'provider_invoice_id' => $state === 'accepted' ? 987 : null,
            'history' => [], 'finished_at' => now()]);

        return $record;
    };
});

afterEach(function () {
    Http::assertNothingSent();
    Config::setSenderResolver(null);
    Config::clearGlobalMiddleware();
    Carbon::setTestNow();
    expect($this->invoice->fresh()->getRawOriginal())->toBe($this->invoiceBefore);
});

beforeEach(function () {
    ($this->validated)();
    $this->app->instance('env', 'testing');
    config(['app.env' => 'testing', 'superpdp_emission.http_simulation_enabled' => true]);
    $this->reserveHttp = function () {
        ($this->requestEmission)(['step' => 'reserve'])->assertStatus(303)->assertSessionHasNoErrors();
        $grant = session('emission_confirmation.'.$this->invoice->id);
        expect($grant)->toBeArray()->and($this->transport->calls)->toBe(0);

        return ['step' => 'confirm', 'confirm_emission' => '1', 'attempt_reference' => $grant['uuid'], 'confirmation_token' => $grant['token']];
    };
});

it('reserves commits confirms dispatches and displays the durable simulated outcome once', function ($outcome) {
    $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Envoyer électroniquement');
    $body = ($this->reserveHttp)();
    $attempt = ElectronicInvoiceAttempt::sole();
    expect($attempt->reference)->toBe($body['attempt_reference'])->and($attempt->transport_started_at)->toBeNull();
    $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Confirmer l’envoi électronique');
    $this->transport->onSend = function ($document, $uuid) use ($outcome, $attempt) {
        expect(DB::transactionLevel())->toBe(0)->and($attempt->fresh()->transport_started_at)->not->toBeNull()
            ->and($uuid)->toBe($attempt->reference)->and($document->sha256)->toBe($attempt->cii_sha256);

        return match ($outcome) {
            'accepted' => ['status' => 200, 'body' => json_encode(['id' => 987, 'company_id' => 123, 'direction' => 'out', 'external_id' => $uuid, 'created_at' => now()->toIso8601String()])],
            'rejected' => ['status' => 400, 'body' => json_encode(['http_status_code' => 400, 'message' => 'Refused PRIVATE'])],
            'unknown' => throw new RuntimeException('Timeout PRIVATE'),
        };
    };
    ($this->requestEmission)($body)->assertStatus(303);
    ($this->requestEmission)($body)->assertStatus(303);
    expect($attempt->fresh()->status)->toBe($outcome)->and($this->transport->calls)->toBe(1)
        ->and(ElectronicInvoiceAttempt::count())->toBe(1);
    $response = $this->get(route('invoices.show', $this->invoice))->assertOk()->assertDontSee('PRIVATE');
    $response->assertSee(match ($outcome) {
        'accepted' => 'Simulation de dépôt acceptée', 'rejected' => 'Dépôt refusé', 'unknown' => 'Résultat du dépôt incertain',
    });
})->with(['accepted', 'rejected', 'unknown']);

it('persists a local refusal if a confirmation expires or any bound prerequisite changes', function ($case) {
    $body = ($this->reserveHttp)();
    match ($case) {
        'expiration' => Carbon::setTestNow(now()->addMinutes(5)),
        'approval' => config(['superpdp_emission.authorization.approved_by' => 'CHANGED']),
        'proof' => $this->proof['checks']['buyer_destination']['response_sha256'] = str_repeat('c', 64),
        'oauth' => $this->connection->update(['access_token_expires_at' => now()]),
        'cii' => Storage::disk('invoice_cii')->put(substr(ElectronicInvoice::sole()->document_reference, strlen('invoice-cii/')).'/document.xml', '<corrupt/>'),
    };
    ($this->requestEmission)($body)->assertStatus(303);
    expect(ElectronicInvoiceAttempt::sole()->status)->toBe('rejected')
        ->and(ElectronicInvoiceAttempt::sole()->error_code)->toBe('local_precondition_failed')
        ->and($this->transport->calls)->toBe(0);
})->with(['expiration', 'approval', 'proof', 'oauth', 'cii']);

it('requires checkbox CSRF owner exact UUID and secret before dispatch', function ($case) {
    $body = ($this->reserveHttp)();
    $status = match ($case) {
        'checkbox' => 302, 'csrf' => 419, 'owner', 'token' => 403, 'uuid' => 404,
    };
    match ($case) {
        'checkbox' => $body['confirm_emission'] = '0',
        'csrf' => $body['_token'] = 'wrong',
        'owner' => $this->actingAs($this->other),
        'token' => $body['confirmation_token'] = 'wrong',
        'uuid' => $body['attempt_reference'] = (string) Str::uuid(),
    };
    ($this->requestEmission)($body)->assertStatus($status);
    expect(ElectronicInvoiceAttempt::sole()->status)->toBe('pending')->and($this->transport->calls)->toBe(0);
})->with(['checkbox', 'csrf', 'owner', 'token', 'uuid']);

it('persists interrupted dispatch as uncertain and never reclaims it', function () {
    $body = ($this->reserveHttp)();
    $attempt = ElectronicInvoiceAttempt::sole();
    $attempt->update(['transport_started_at' => now()->subMinutes(6), 'claim_token' => (string) Str::uuid()]);
    ($this->requestEmission)($body)->assertStatus(303);
    expect($attempt->fresh()->status)->toBe('unknown')->and($attempt->fresh()->error_code)->toBe('interrupted_dispatch')
        ->and($this->transport->calls)->toBe(0);
});

it('cannot renew an authorization or manufacture an attempt with browser fields', function () {
    $body = ($this->reserveHttp)();
    $attempt = ElectronicInvoiceAttempt::sole();
    $history = $attempt->history;
    ($this->requestEmission)(['step' => 'reserve', 'authorization' => ['expires_at' => PHP_INT_MAX], 'attempt_reference' => (string) Str::uuid()])->assertStatus(422);
    expect($attempt->fresh()->history)->toBe($history)->and(ElectronicInvoiceAttempt::count())->toBe(1)
        ->and($this->transport->calls)->toBe(0);
});

it('recovers a stale claim locally after the confirmation session has been lost', function () {
    ($this->reserveHttp)();
    $attempt = ElectronicInvoiceAttempt::sole();
    $attempt->update(['transport_started_at' => now()->subMinutes(6), 'claim_token' => (string) Str::uuid()]);
    session()->forget('emission_confirmation.'.$this->invoice->id);
    Carbon::setTestNow(now()->addMinutes(6));
    $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Examiner une interruption localement');
    expect($attempt->fresh()->status)->toBe('pending');
    ($this->requestEmission)(['step' => 'review'])->assertStatus(303);
    expect($attempt->fresh()->status)->toBe('unknown')->and($this->transport->calls)->toBe(0);
});

it('keeps the confirmation session when another worker already holds a recent claim', function () {
    $body = ($this->reserveHttp)();
    $attempt = ElectronicInvoiceAttempt::sole();
    $attempt->update(['transport_started_at' => now(), 'claim_token' => (string) Str::uuid()]);
    ($this->requestEmission)($body)->assertStatus(303)->assertSessionHas('emission_confirmation.'.$this->invoice->id);
    expect($attempt->fresh()->status)->toBe('pending')->and($this->transport->calls)->toBe(0);
});

it('refuses dispatching an HTTP reservation through the engine without its confirmation check', function () {
    ($this->reserveHttp)();
    $attempt = ElectronicInvoiceAttempt::sole();
    $result = app(OutgoingEmission::class)->dispatch($this->owner, ElectronicInvoice::sole(), $attempt->id);
    expect($result->status)->toBe('rejected')->and($this->transport->calls)->toBe(0);
});

it('keeps the HTTP adapter closed outside the explicit isolated simulation boundary', function ($case) {
    match ($case) {
        'disabled' => config(['superpdp_emission.http_simulation_enabled' => false]),
        'production' => $this->app->instance('env', 'production'),
        'sandbox' => $this->app->instance('env', 'sandbox'),
        'file database' => config(['database.connections.sqlite.database' => 'forbidden-real.sqlite']),
        'closed transport' => $this->app->instance(EmissionTransport::class, new DisabledEmissionTransport),
    };
    expect(app(HttpEmission::class)->available())->toBeFalse()
        ->and(ElectronicInvoice::count())->toBe(0)->and($this->transport->calls)->toBe(0);
})->with(['disabled', 'production', 'sandbox', 'file database', 'closed transport']);

describe('sandbox factory HTTP', function () {
    beforeEach(function () {
        // Preserve the already migrated SQLite :memory: PDO, then exercise the
        // actual sandbox manager using a test-only connector. No MySQL socket.
        $this->memoryPdo = DB::connection()->getPdo();
        $this->app->instance('env', 'sandbox');
        config(['app.env' => 'sandbox', 'superpdp_emission.http_simulation_enabled' => false,
            'superpdp_emission.http_sandbox_enabled' => true,
            'database.default' => SandboxDatabaseIsolation::CONNECTION,
            'database.connections' => [SandboxDatabaseIsolation::CONNECTION => [
                'driver' => 'mysql', 'host' => 'forbidden.invalid',
                'database' => SandboxDatabaseIsolation::DATABASE,
                'username' => SandboxDatabaseIsolation::DATABASE,
            ]]]);
        DB::clearResolvedInstance('db');
        $this->app->forgetInstance('db');
        SandboxDatabaseIsolation::install($this->app);
        $manager = app('db');
        $manager->extend('mysql', fn ($configuration) => new SQLiteConnection(
            $this->memoryPdo, $configuration['database'], '', $configuration + ['name' => SandboxDatabaseIsolation::CONNECTION]));
        Model::setConnectionResolver($manager);
        foreach ([$this->owner, $this->other, $this->company, $this->client, $this->connection, $this->invoice] as $model) {
            $model->setConnection(SandboxDatabaseIsolation::CONNECTION);
        }
        expect(DB::connection()->getPdo())->toBe($this->memoryPdo)
            ->and($this->memoryPdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite');
        // The preparation journal is scoped to the database identity. Complete
        // a separate test slot for this scope using the same validated bytes.
        $registry = app(CiiPreparationRegistry::class);
        $slot = $registry->reserve($this->owner, $this->invoice, 'api');
        $reference = config('superpdp_emission.authorization.document_reference');
        $registry->complete($this->owner, $this->invoice, $slot['claim'], [
            'reference' => $reference, 'manifest_sha256' => basename($reference),
            'cii_sha256' => config('superpdp_emission.authorization.cii_sha256'),
        ]);
        $this->app->bind(SandboxEmissionFactory::class);
        Config::setSenderResolver(null);
        $this->sdk = MockClient::global([]);
        $this->beforeApplicationDestroyed(fn () => MockClient::destroyGlobal());
    });

    it('uses the factory and the SDK exactly once after an explicit HTTP confirmation', function ($outcome) {
        $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Envoyer électroniquement');
        $body = ($this->reserveHttp)();
        $attempt = ElectronicInvoiceAttempt::sole();
        expect(ElectronicInvoice::sole()->execution_mode)->toBe('sandbox')
            ->and($attempt->reference)->toBe($body['attempt_reference']);
        $this->sdk->assertNothingSent();
        $this->sdk->addResponses([CreateInvoiceRequest::class => function ($pending) use ($attempt, $outcome) {
            expect(DB::transactionLevel())->toBe(0)
                ->and($attempt->fresh()->transport_started_at)->not->toBeNull()
                ->and(array_column($attempt->fresh()->history, 'reason'))->toContain('http_confirmed')
                ->and($pending->getUrl())->toBe('https://api.superpdp.tech/v1.beta/invoices')
                ->and($pending->query()->get('external_id'))->toBe($attempt->reference)
                ->and(hash('sha256', $pending->body()->all()))->toBe($attempt->cii_sha256)
                ->and($pending->config()->get('allow_redirects'))->toBeFalse()
                ->and($pending->getConnector()->tries)->toBe(1);

            return match ($outcome) {
                'accepted' => MockResponse::make(['id' => 987, 'company_id' => 123, 'direction' => 'out',
                    'external_id' => $attempt->reference, 'created_at' => now()->toIso8601String()]),
                'rejected' => MockResponse::make(['http_status_code' => 400, 'message' => 'Refused'], 400),
                'unknown' => MockResponse::make([], 503),
            };
        }]);
        ($this->requestEmission)($body)->assertStatus(303);
        ($this->requestEmission)($body)->assertStatus(303);
        expect($attempt->fresh()->status)->toBe($outcome)->and(ElectronicInvoiceAttempt::count())->toBe(1);
        $this->sdk->assertSentCount(1);
        $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee(match ($outcome) {
            'accepted' => 'Dépôt accepté par SUPER PDP.', 'rejected' => 'Dépôt refusé.', 'unknown' => 'Résultat du dépôt incertain',
        });
    })->with(['accepted', 'rejected', 'unknown']);

    it('durably refuses changed prerequisites before the SDK and never renews an expired grant', function ($case) {
        $body = ($this->reserveHttp)();
        match ($case) {
            'confirmation expired' => Carbon::setTestNow(now()->addMinutes(5)),
            'approval expired' => config(['superpdp_emission.authorization.expires_at' => now()->timestamp]),
            'approval revoked' => config(['superpdp_emission.sandbox_enabled' => false]),
            'another attempt approved' => config(['superpdp_emission.authorization.attempt_reference' => (string) Str::uuid()]),
            'proof changed' => $this->proof['checks']['seller_session']['response_sha256'] = str_repeat('c', 64),
            'oauth expired' => $this->connection->update(['access_token_expires_at' => now()]),
            'cii changed' => Storage::disk('invoice_cii')->put(substr(ElectronicInvoice::sole()->document_reference, strlen('invoice-cii/')).'/document.xml', '<corrupt/>'),
        };
        ($this->requestEmission)($body)->assertStatus(303);
        expect(ElectronicInvoiceAttempt::sole()->status)->toBe('rejected')
            ->and(ElectronicInvoiceAttempt::sole()->error_code)->toBe('local_precondition_failed');
        $this->sdk->assertNothingSent();
    })->with(['confirmation expired', 'approval expired', 'approval revoked', 'another attempt approved', 'proof changed', 'oauth expired', 'cii changed']);

    it('rejects foreign UUIDs secrets owners and missing confirmations without an SDK call', function ($case) {
        $body = ($this->reserveHttp)();
        $status = match ($case) {
            'checkbox' => 302, 'csrf' => 419, 'owner', 'token' => 403, 'uuid' => 404,
        };
        match ($case) {
            'checkbox' => $body['confirm_emission'] = '0',
            'csrf' => $body['_token'] = 'wrong',
            'owner' => $this->actingAs($this->other),
            'token' => $body['confirmation_token'] = 'wrong',
            'uuid' => $body['attempt_reference'] = (string) Str::uuid(),
        };
        ($this->requestEmission)($body)->assertStatus($status);
        expect(ElectronicInvoiceAttempt::sole()->status)->toBe('pending');
        $this->sdk->assertNothingSent();
    })->with(['checkbox', 'csrf', 'owner', 'token', 'uuid']);

    it('refuses production mixed contexts disabled HTTP and any SQL scope mutation', function ($case) {
        $saved = config()->all();
        match ($case) {
            'disabled' => config(['superpdp_emission.http_sandbox_enabled' => false]),
            'production runtime' => $this->app->instance('env', 'production'),
            'production config' => config(['app.env' => 'production']),
            'production provider' => config(['services.superpdp.environment' => 'production']),
            'other endpoint' => config(['services.superpdp.api_url' => 'https://forbidden.invalid']),
            'other default database' => config(['database.default' => 'mysql']),
            'extra database' => config(['database.connections.mysql' => ['driver' => 'mysql', 'database' => 'forbidden']]),
            'changed database' => config(['database.connections.superpdp_sandbox.database' => 'forbidden']),
        };
        try {
            expect(app(HttpEmission::class)->available())->toBeFalse();
            expect(fn () => app(SandboxEmissionFactory::class)->forHttpInvoice($this->owner, $this->invoice, 'ignored'))
                ->toThrow(ValidationException::class);
            $this->sdk->assertNothingSent();
        } finally {
            config($saved);
            $this->app->instance('env', 'sandbox');
        }
    })->with(['disabled', 'production runtime', 'production config', 'production provider', 'other endpoint', 'other default database', 'extra database', 'changed database']);

    it('recovers an interrupted claim after approval expiry without sending anything', function () {
        ($this->reserveHttp)();
        $attempt = ElectronicInvoiceAttempt::sole();
        $attempt->update(['transport_started_at' => now()->subMinutes(6), 'claim_token' => (string) Str::uuid()]);
        session()->forget('emission_confirmation.'.$this->invoice->id);
        config(['superpdp_emission.authorization' => null]);
        ($this->requestEmission)(['step' => 'review'])->assertStatus(303);
        expect($attempt->fresh()->status)->toBe('unknown')->and($attempt->fresh()->error_code)->toBe('interrupted_dispatch');
        $this->sdk->assertNothingSent();
    });

    it('cannot bypass the HTTP confirmation using a direct factory dispatch', function ($withObject) {
        $body = ($this->reserveHttp)();
        $attempt = ElectronicInvoiceAttempt::sole();
        $record = ElectronicInvoice::sole();
        $confirmation = $withObject ? new HttpEmissionConfirmation(
            $this->owner, $body['attempt_reference'], $body['confirmation_token'],
            app(SandboxEmissionPrerequisites::class), app(SandboxEmissionAuthorization::class)) : null;
        $engine = app(SandboxEmissionFactory::class)->forHttpInvoice($this->owner, $this->invoice, $record->document_reference, $confirmation);
        // Even an empty callback cannot replace the persisted explicit confirmation.
        $result = $engine->dispatch($this->owner, $record, $attempt->id, fn () => null);
        expect($result->status)->toBe('rejected')->and($result->transport_started_at)->toBeNull();
        $this->sdk->assertNothingSent();
    })->with([false, true]);

    it('rechecks the authorization after the committed claim and blocks expiration before the SDK', function () {
        $body = ($this->reserveHttp)();
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'transport_started_at')) {
                Carbon::setTestNow(now()->addMinutes(6));
            }
        });
        ($this->requestEmission)($body)->assertStatus(303);
        expect(ElectronicInvoiceAttempt::sole()->status)->toBe('unknown');
        $this->sdk->assertNothingSent();
    });

    it('persists an accepted SDK result even if the authorization expires during the request', function () {
        $body = ($this->reserveHttp)();
        Config::globalMiddleware()->onResponse(static function () {
            Carbon::setTestNow(now()->addMinutes(6));
        });
        $this->sdk->addResponses([CreateInvoiceRequest::class => function () use ($body) {
            return MockResponse::make(['id' => 987, 'company_id' => 123, 'direction' => 'out',
                'external_id' => $body['attempt_reference'], 'created_at' => now()->toIso8601String()]);
        }]);
        ($this->requestEmission)($body)->assertStatus(303);
        expect(ElectronicInvoiceAttempt::sole()->status)->toBe('accepted');
        $this->sdk->assertSentCount(1);
    });

    describe('operator managed web sandbox activation', function () {
        beforeEach(function () {
            $this->webGrant = config('superpdp_emission.authorization') + [
                'company_id' => $this->company->id, 'connection_id' => $this->connection->id,
            ];
            $this->writeWebGrant = function () {
                Storage::disk('invoice_cii')->put(SandboxWebEmissionActivation::GRANT_PATH,
                    json_encode($this->webGrant, JSON_THROW_ON_ERROR));
            };
            ($this->writeWebGrant)();
            $this->reloadWebGrant = function () {
                // Each real browser request boots this provider in a fresh application.
                config(['superpdp_emission.sandbox_enabled' => false,
                    'superpdp_emission.sandbox_client_id' => null, 'superpdp_emission.authorization' => null]);
                app()->getProvider(AppServiceProvider::class)->boot();
            };
            ($this->reloadWebGrant)();
        });

        it('loads the same private grant across reserve and confirm and never resends', function ($outcome) {
            $bytes = Storage::disk('invoice_cii')->get(SandboxWebEmissionActivation::GRANT_PATH);
            expect(config('superpdp_emission.authorization'))->toBe($this->webGrant);
            $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Envoyer électroniquement');
            $body = ($this->reserveHttp)();
            $this->sdk->assertNothingSent();
            ($this->reloadWebGrant)();
            expect(config('superpdp_emission.authorization'))->toBe($this->webGrant);
            $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Confirmer l’envoi électronique');
            $this->sdk->addResponses([CreateInvoiceRequest::class => function ($pending) use ($body, $outcome) {
                expect(DB::transactionLevel())->toBe(0)
                    ->and($pending->getConnector()->tries)->toBe(1);

                return match ($outcome) {
                    'accepted' => MockResponse::make(['id' => 987, 'company_id' => 123, 'direction' => 'out',
                        'external_id' => $body['attempt_reference'], 'created_at' => now()->toIso8601String()]),
                    'rejected' => MockResponse::make(['http_status_code' => 400, 'message' => 'Refused'], 400),
                    'unknown' => MockResponse::make([], 503),
                };
            }]);
            ($this->requestEmission)($body)->assertStatus(303);
            ($this->reloadWebGrant)();
            ($this->requestEmission)($body)->assertStatus(303);
            expect(ElectronicInvoiceAttempt::sole()->status)->toBe($outcome)
                ->and(ElectronicInvoiceAttempt::count())->toBe(1)
                ->and(Storage::disk('invoice_cii')->get(SandboxWebEmissionActivation::GRANT_PATH))->toBe($bytes);
            $this->sdk->assertSentCount(1);
            $this->get(route('invoices.show', $this->invoice))->assertOk()->assertDontSee('Envoyer électroniquement');
        })->with(['accepted', 'rejected', 'unknown']);

        it('does not renew the grant or create another attempt on duplicate reservation', function () {
            ($this->reserveHttp)();
            $history = ElectronicInvoiceAttempt::sole()->history;
            ($this->reloadWebGrant)();
            ($this->requestEmission)(['step' => 'reserve'])->assertStatus(422);
            expect(ElectronicInvoiceAttempt::count())->toBe(1)
                ->and(ElectronicInvoiceAttempt::sole()->history)->toBe($history)
                ->and(config('superpdp_emission.authorization'))->toBe($this->webGrant);
            $this->sdk->assertNothingSent();
        });

        it('refuses expired changed or revoked grants between browser requests', function ($case) {
            $body = ($this->reserveHttp)();
            match ($case) {
                'grant expired' => Carbon::setTestNow(now()->addMinutes(10)),
                'confirmation expired' => Carbon::setTestNow(now()->addMinutes(5)),
                'grant changed' => $this->webGrant['approved_by'] = 'CHANGED',
                'grant revoked' => Storage::disk('invoice_cii')->delete(SandboxWebEmissionActivation::GRANT_PATH),
                'proof stale' => $this->proof['checks']['buyer_destination']['observed_at'] = now()->subMinutes(16)->toIso8601String(),
                'connection changed' => $this->webGrant['connection_id']++,
            };
            if (! in_array($case, ['grant expired', 'confirmation expired', 'grant revoked', 'proof stale'], true)) {
                ($this->writeWebGrant)();
            }
            ($this->reloadWebGrant)();
            ($this->requestEmission)($body)->assertStatus(303);
            expect(ElectronicInvoiceAttempt::sole()->status)->toBe('rejected')
                ->and(ElectronicInvoiceAttempt::sole()->transport_started_at)->toBeNull();
            $this->sdk->assertNothingSent();
        })->with(['grant expired', 'confirmation expired', 'grant changed', 'grant revoked', 'proof stale', 'connection changed']);

        it('rejects missing invalid broad or excessive lifetime grants', function ($case) {
            match ($case) {
                'expired' => $this->webGrant['expires_at'] = now()->timestamp,
                'future' => $this->webGrant['approved_at'] = now()->addMinute()->timestamp,
                'over fifteen minutes' => $this->webGrant['expires_at'] = now()->addMinutes(16)->timestamp,
                'no company' => $this->webGrant['company_id'] = null,
                'no connection' => $this->webGrant['connection_id'] = null,
                'no invoice' => $this->webGrant['invoice_id'] = null,
                'invoice 81' => $this->webGrant['invoice_id'] = 81,
                'foreign owner' => $this->webGrant['user_id'] = $this->other->id,
                'invalid hash' => $this->webGrant['cii_sha256'] = 'invalid',
                'attempt in grant' => $this->webGrant['attempt_reference'] = (string) Str::uuid(),
                default => null,
            };
            ($this->writeWebGrant)();
            if ($case === 'missing') {
                Storage::disk('invoice_cii')->delete(SandboxWebEmissionActivation::GRANT_PATH);
            } elseif ($case === 'malformed') {
                Storage::disk('invoice_cii')->put(SandboxWebEmissionActivation::GRANT_PATH, '{');
            }
            ($this->reloadWebGrant)();
            expect(config('superpdp_emission.authorization'))->toBeNull()
                ->and(config('superpdp_emission.sandbox_enabled'))->toBeFalse();
            ($this->requestEmission)(['step' => 'reserve'])->assertStatus(422);
            expect(ElectronicInvoiceAttempt::count())->toBe(0);
            $this->sdk->assertNothingSent();
        })->with(['missing', 'malformed', 'expired', 'future', 'over fifteen minutes', 'no company', 'no connection',
            'no invoice', 'invoice 81', 'foreign owner', 'invalid hash', 'attempt in grant']);

        it('rejects an otherwise valid grant for another company or OAuth connection', function ($field) {
            $this->webGrant[$field]++;
            ($this->writeWebGrant)();
            ($this->reloadWebGrant)();
            ($this->requestEmission)(['step' => 'reserve'])->assertStatus(422);
            expect(ElectronicInvoiceAttempt::count())->toBe(0);
            $this->sdk->assertNothingSent();
        })->with(['company_id', 'connection_id']);

        it('cannot activate or dispatch in production even with an opted in valid private grant', function ($case) {
            $body = ($this->reserveHttp)();
            $saved = config()->all();
            match ($case) {
                'runtime' => $this->app->instance('env', 'production'),
                'config' => config(['app.env' => 'production']),
                'provider' => config(['services.superpdp.environment' => 'production']),
            };
            try {
                ($this->reloadWebGrant)();
                expect(config('superpdp_emission.authorization'))->toBeNull()
                    ->and(app(HttpEmission::class)->available())->toBeFalse();
                // A mixed app.env also trips the existing SQL isolation guard during binding.
                ($this->requestEmission)($body)->assertStatus($case === 'config' ? 500 : 303);
                $this->sdk->assertNothingSent();
            } finally {
                config($saved);
                $this->app->instance('env', 'sandbox');
            }
            expect(ElectronicInvoiceAttempt::sole()->transport_started_at)->toBeNull();
        })->with(['runtime', 'config', 'provider']);

        it('permanently refuses invoice 81 even with a manually injected approval', function () {
            expect(fn () => app(SandboxEmissionAuthorization::class)->connection($this->owner->id, 81,
                'invoice-cii/v1/validated/'.$this->owner->id.'/81/'.str_repeat('a', 64)))
                ->toThrow(RuntimeException::class, 'Invoice 81 was already sent');
            expect(ElectronicInvoiceAttempt::count())->toBe(0);
            $this->sdk->assertNothingSent();
        });
    });
});
