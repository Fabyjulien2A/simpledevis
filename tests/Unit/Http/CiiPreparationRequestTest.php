<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationRegistry;
use App\Services\SuperPdp\InvoiceCiiPreparation;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Tests\Support\PreparedCiiFixture;
use Tests\Support\ReadOnlyDraftPreviewDatabase;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::swap(new class extends Factory
    {
        protected function newPendingRequest()
        {
            return parent::newPendingRequest()->setHandler(static fn () => throw new LogicException('Network forbidden'));
        }
    });
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Config::setSenderResolver(static fn () => throw new LogicException('SDK sender forbidden'));
    $this->app->bind(InvoiceCiiPreparation::class, static fn () => throw new LogicException('Producer must not be resolved by this closed POST'));
    $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $this->auditReads = 0;
    $this->onAuditRead = null;
    $audits = $this->createMock(VerificationAuditStore::class);
    $audits->method('read')->willReturnCallback(function ($actor, $invoice, $reference) {
        $this->auditReads++;
        if ($this->onAuditRead !== null) {
            ($this->onAuditRead)();
        }

        return $this->report;
    });
    $audits->expects($this->never())->method('store');
    $this->app->instance(VerificationAuditStore::class, $audits);
    $this->withoutVite();
    Carbon::setTestNow('2026-11-14 12:00:00');
    Storage::fake('invoice_cii');
    config(['app.debug' => false, 'session.driver' => 'array', 'cache.default' => 'array', 'database.default' => 'offline',
        'database.connections' => ['offline' => ['driver' => 'sqlite', 'database' => ':memory:']],
        'services.superpdp.environment' => 'sandbox', 'services.superpdp.client_id' => 'offline-client',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'superpdp_preparation.sandbox_client_id' => 'offline-client',
        'superpdp_preparation.live_enabled' => false, 'superpdp_preparation.web_enabled' => false,
        'superpdp_preparation.authorization' => null]);
    $this->openWeb = function () {
        $this->app->instance('env', 'sandbox');
        config(['app.env' => 'sandbox', 'superpdp_preparation.web_enabled' => true]);
    };
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new ReadOnlyDraftPreviewDatabase;
    DB::extend('offline', fn () => $this->memory);
    Model::setConnectionResolver(app('db'));
    $data = SuperPdpPayloadFixture::data();
    unset($data['seller']['payment_terms']);
    $data['buyer'] += ['company_name' => 'Acheteur figé', 'siret' => '55210055400013', 'vat_number' => 'FR00552100554'];
    $data['buyer']['client_type'] = 'professional';
    $data['company_vat_regime'] = 'subject_to_vat';
    $data['items'][0] = array_replace($data['items'][0], ['quantity' => '1.00', 'unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'unit_code' => 'C62']);
    $data['totals'] = ['subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00'];
    $data['company_invoice_mentions'] = collect(SuperPdpPayloadFixture::frenchContext()['notes'])->pluck('note', 'subject_code')->all();
    $this->memory->rows = [
        'users' => [1 => ['id' => 1, 'name' => 'Owner'], 2 => ['id' => 2, 'name' => 'Other']],
        'companies' => [1 => ['id' => 1, 'user_id' => 1, 'company_name' => 'Current profile']],
        'invoices' => [99 => ['id' => 99, 'user_id' => 1, 'client_id' => 45,
            ...array_intersect_key($data, array_flip(['invoice_number', 'date', 'due_date', 'document_status', 'calculation_version', 'status', 'amount_paid'])),
            ...$data['totals'], 'french_business_process_type' => 'S1', 'emission_profile' => InvoiceEmissionContext::PROFILE,
            'party_snapshot' => SuperPdpPayloadFixture::partySnapshot($data),
            'emission_context' => json_encode(InvoiceEmissionContext::capture(InvoiceEmissionContext::PROFILE,
                ['scheme' => '0225', 'value' => '732829320'], ['scheme' => '0225', 'value' => '552100554'], $data))]],
        'invoice_items' => [42 => $data['items'][0] + ['invoice_id' => 99]], 'payments' => [], 'credit_notes' => [],
        'superpdp_connections' => [1 => ['id' => 1, 'company_id' => 1, 'superpdp_company_id' => 123,
            'environment' => 'sandbox', 'oauth_client_id' => 'offline-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
            'status' => 'connected', 'directory_identifier' => '732829320',
            'access_token' => Crypt::encryptString('ACCESS-SECRET'), 'refresh_token' => Crypt::encryptString('REFRESH-SECRET'),
            'access_token_expires_at' => now()->addHour()->toDateTimeString()]],
    ];
    $this->actor = User::findOrFail(1);
    $this->actingAs($this->actor);
    $this->invoice = Invoice::findOrFail(99);
    $this->csrf = str_repeat('a', 40);
    $this->withSession(['_token' => $this->csrf]);
    $this->postRequest = fn (array $body = []) => $this->post(route('invoices.request-cii-preparation', 99), $body + ['_token' => $this->csrf]);
    $source = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $this->sourceHash = CiiPreparationEvidence::hash($source);
    $this->reference = 'invoice-party-checks/v1/1/99/'.str_repeat('a', 64).'.json';
    $check = ['status' => 'passed', 'source' => 'live', 'verified' => true,
        'response_sha256' => str_repeat('a', 64), 'observed_at' => now()->toIso8601String()];
    // Synthetic report metadata for exercising the guards, never an actual live verification.
    $this->report = ['status' => 'passed', 'expected_environment' => 'sandbox', 'expected_provider_company_id' => 123,
        'expected_oauth_client_id' => 'offline-client', 'expected_api_url' => 'https://api.superpdp.tech/v1.beta',
        'source_sha256' => $this->sourceHash, 'party_snapshot_sha256' => hash('sha256', $source['party_snapshot']),
        'emission_context_sha256' => hash('sha256', $source['emission_context']), 'checked_at' => now()->toIso8601String(),
        'checks' => array_fill_keys(['buyer_destination', 'seller_identity', 'seller_session'], $check)];
    $this->grant = fn () => config(['superpdp_preparation.live_enabled' => true, 'superpdp_preparation.authorization' => [
        'approved_by' => 'Offline fixture', 'approved_at' => now()->timestamp, 'expires_at' => now()->addMinutes(10)->timestamp,
        'user_id' => 1, 'invoice_id' => 99, 'source_sha256' => $this->sourceHash, 'verification_reference' => $this->reference,
        'environment' => 'sandbox', 'oauth_client_id' => 'offline-client', 'provider_company_id' => 123,
        'api_url' => 'https://api.superpdp.tech/v1.beta',
    ]]);
    $this->beforeRows = $this->memory->rows;
    $this->fileSnapshot = function () {
        $files = [];
        foreach (Storage::disk('invoice_cii')->allFiles() as $file) {
            $path = Storage::disk('invoice_cii')->path($file);
            clearstatcache(true, $path);
            $files[$file] = [hash_file('sha256', $path), filemtime($path), fileperms($path)];
        }

        return $files;
    };
});

afterEach(function () {
    Config::setSenderResolver(null);
    Config::clearGlobalMiddleware();
    expect($this->memory->rows)->toBe($this->beforeRows);
    Http::assertNothingSent();
    Model::setConnectionResolver($this->originalResolver);
    Carbon::setTestNow();
});

it('refuses forged HTTP approvals and redirects to the CII section without touching a reservation', function () {
    ($this->postRequest)(['authorization' => ['user_id' => 1], 'live_enabled' => true, 'source_sha256' => $this->sourceHash,
        'verification_reference' => $this->reference, 'company_id' => 1, 'mode' => 'live'])
        ->assertStatus(303)->assertRedirect(route('invoices.electronic-preparation', 99).'#cii-document-title')
        ->assertSessionHas('cii_request_status', 'refused')
        ->assertSessionHas('cii_request_messages', fn ($messages) => str_contains(implode(' ', $messages), 'désactivées dans la configuration'));
    expect(app(PreparedCiiArchive::class)->reservationDirectory(false))->toBeNull()
        ->and(config('superpdp_preparation.authorization'))->toBeNull();
});

it('keeps a valid fully authorized request closed without resolving the producer or reserving', function () {
    ($this->grant)();
    ($this->postRequest)()->assertStatus(303)->assertSessionHas('cii_request_status', 'closed')
        ->assertSessionHas('cii_request_messages', fn ($messages) => str_contains(implode(' ', $messages), 'parcours HTTP de préparation réelle reste fermé'));
    expect($this->auditReads)->toBe(2)->and(app(PreparedCiiArchive::class)->reservationDirectory(false))->toBeNull();
    $response = $this->get(route('invoices.electronic-preparation', 99))->assertOk()
        ->assertSee('Les prérequis sont réunis, mais le parcours HTTP de préparation réelle reste fermé.')
        ->assertSee('Préparation documentaire locale');
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $button = (new DOMXPath($dom))->query('//section[@aria-labelledby="cii-document-title"]//button')->item(0);
    expect($button->hasAttribute('disabled'))->toBeTrue()->and($button->getAttribute('type'))->toBe('button');
});

it('executes the real CSRF check', function (string $token) {
    ($this->openWeb)();
    ($this->grant)();
    ($this->postRequest)(['_token' => $token])->assertStatus(419);
    expect($this->auditReads)->toBe(0);
})->with(['', 'wrong-token']);

it('requires authenticated ownership a definitive invoice and the right local company', function (string $case) {
    ($this->openWeb)();
    if ($case === 'guest') {
        auth()->forgetGuards();
        ($this->postRequest)()->assertRedirect(route('login'));

        return;
    }
    match ($case) {
        'foreign owner' => $this->actingAs(User::findOrFail(2)),
        'draft' => $this->memory->rows['invoices'][99]['document_status'] = 'draft',
        'company owner' => $this->memory->rows['companies'][1]['user_id'] = 2,
        'invoice company' => $this->memory->rows['invoices'][99]['company_id'] = 2,
    };
    $this->beforeRows = $this->memory->rows;
    ($this->postRequest)()->assertStatus($case === 'draft' ? 404 : 403);
    expect($this->auditReads)->toBe(0);
})->with(['guest', 'foreign owner', 'draft', 'company owner', 'invoice company']);

it('rejects invalid proofs scoped authorizations and production without any preparation', function (string $case) {
    ($this->openWeb)();
    ($this->grant)();
    match ($case) {
        'expired approval' => config(['superpdp_preparation.authorization.expires_at' => now()->timestamp]),
        'future approval' => config(['superpdp_preparation.authorization.approved_at' => now()->addMinute()->timestamp]),
        'long approval' => config(['superpdp_preparation.authorization.expires_at' => now()->addHour()->timestamp]),
        'foreign owner' => config(['superpdp_preparation.authorization.user_id' => 2]),
        'foreign invoice' => config(['superpdp_preparation.authorization.invoice_id' => 100]),
        'foreign provider' => config(['superpdp_preparation.authorization.provider_company_id' => 456]),
        'foreign client' => config(['superpdp_preparation.authorization.oauth_client_id' => 'other']),
        'foreign api' => config(['superpdp_preparation.authorization.api_url' => 'https://other.invalid']),
        'foreign source' => config(['superpdp_preparation.authorization.source_sha256' => str_repeat('b', 64)]),
        'stale proof' => $this->report['checks']['buyer_destination']['observed_at'] = now()->subMinutes(16)->toIso8601String(),
        'missing proof' => $this->report['checks']['seller_identity'] = [],
        'simulated proof' => $this->report['checks']['seller_session'] = array_replace($this->report['checks']['seller_session'], ['source' => 'simulated', 'verified' => false]),
        'foreign connection' => $this->memory->rows['superpdp_connections'][1]['company_id'] = 2,
        'production' => $this->app->instance('env', 'production'),
        'snapshot' => $this->memory->rows['invoices'][99]['party_snapshot'] = null,
    };
    $this->beforeRows = $this->memory->rows;
    ($this->postRequest)()->assertStatus(303)->assertSessionHas('cii_request_status', 'refused');
    expect(app(PreparedCiiArchive::class)->reservationDirectory(false))->toBeNull();
})->with(['expired approval', 'future approval', 'long approval', 'foreign owner', 'foreign invoice', 'foreign provider',
    'foreign client', 'foreign api', 'foreign source', 'stale proof', 'missing proof', 'simulated proof', 'foreign connection', 'production', 'snapshot']);

it('retains SIRET VAT and 0225 identity requirements', function (string $case) {
    ($this->openWeb)();
    ($this->grant)();
    if ($case === 'address') {
        $context = json_decode($this->memory->rows['invoices'][99]['emission_context'], true);
        $context['seller_electronic_address']['value'] = '315143296_18171';
        $this->memory->rows['invoices'][99]['emission_context'] = json_encode($context);
    } else {
        $snapshot = json_decode($this->memory->rows['invoices'][99]['party_snapshot'], true);
        $snapshot['seller'][$case === 'siret' ? 'siret' : 'vat_number'] = null;
        $this->memory->rows['invoices'][99]['party_snapshot'] = json_encode($snapshot);
    }
    $this->beforeRows = $this->memory->rows;
    ($this->postRequest)()->assertStatus(303)->assertSessionHas('cii_request_status', 'refused');
    expect(app(PreparedCiiArchive::class)->reservationDirectory(false))->toBeNull();
})->with(['siret', 'vat', 'address']);

it('recalculates the source hash instead of accepting a stale preflight or browser hash', function () {
    ($this->openWeb)();
    ($this->grant)();
    $this->onAuditRead = function () {
        $this->memory->rows['invoices'][99]['invoice_number'] = 'CHANGED-BY-CONCURRENT-FIXTURE';
        $this->beforeRows = $this->memory->rows;
    };
    ($this->postRequest)(['source_sha256' => $this->sourceHash])->assertSessionHas('cii_request_status', 'refused')
        ->assertSessionHas('cii_request_messages', fn ($messages) => str_contains(implode(' ', $messages), 'ont changé pendant le contrôle'));
});

it('limits requests per authenticated user', function () {
    for ($i = 0; $i < 3; $i++) {
        ($this->postRequest)()->assertStatus(303);
    }
    ($this->postRequest)()->assertStatus(429)->assertHeader('Retry-After')->assertSee('Trop de demandes de préparation CII');
    Carbon::setTestNow(now()->addSeconds(61));
    ($this->postRequest)()->assertStatus(303);
});

it('recognizes duplicate running and interrupted reservations without changing them', function (bool $expired) {
    ($this->openWeb)();
    ($this->grant)();
    app(CiiPreparationRegistry::class)->reserve($this->actor, $this->invoice, 'api');
    if ($expired) {
        Carbon::setTestNow(now()->addMinutes(16));
        ($this->grant)();
        $this->report['checked_at'] = now()->toIso8601String();
        foreach ($this->report['checks'] as &$check) {
            $check['observed_at'] = now()->toIso8601String();
        }
        unset($check);
    }
    $files = ($this->fileSnapshot)();
    ($this->postRequest)()->assertStatus(303)->assertSessionHas('cii_request_status', $expired ? 'review' : 'in_progress');
    expect(($this->fileSnapshot)())->toBe($files);
})->with([false, true]);

it('reuses an already completed verified result and preserves the archive and journal bytes', function () {
    ($this->openWeb)();
    ($this->grant)();
    $registry = app(CiiPreparationRegistry::class);
    $reservation = $registry->reserve($this->actor, $this->invoice, 'api');
    $binding = $reservation['claim']->binding();
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($this->invoice);
    $xml = PreparedCiiFixture::xml($payload);
    $manifest = array_intersect_key($binding, array_flip(['user_id', 'invoice_id', 'source_sha256', 'payload_sha256',
        'party_snapshot_sha256', 'emission_context_sha256', 'environment', 'oauth_client_id', 'api_url', 'provider_company_id']))
        + ['schema_version' => 1, 'mode' => 'validated_private_document', 'ready_for_emission' => true, 'provenance' => 'live',
            'cii_sha256' => hash('sha256', $xml), 'validation' => ['status' => 'passed', 'report' => PreparedCiiFixture::report($xml)]];
    $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hash = hash('sha256', $json);
    $path = Storage::disk('invoice_cii')->path('v1/validated/1/99/'.$hash);
    mkdir($path, 0700, true);
    file_put_contents($path.'/manifest.json', $json);
    file_put_contents($path.'/document.xml', $xml);
    $registry->complete($this->actor, $this->invoice, $reservation['claim'], [
        'reference' => 'invoice-cii/v1/validated/1/99/'.$hash, 'manifest_sha256' => $hash, 'cii_sha256' => hash('sha256', $xml),
    ]);
    $files = ($this->fileSnapshot)();
    ($this->postRequest)()->assertStatus(303)->assertSessionHas('cii_request_status', 'existing');
    ($this->postRequest)()->assertStatus(303)->assertSessionHas('cii_request_status', 'existing');
    expect(($this->fileSnapshot)())->toBe($files);
});

it('resolves the existing producer only after sandbox web activation and handles unexpected failure safely', function () {
    ($this->openWeb)();
    ($this->grant)();
    $calls = 0;
    $this->app->bind(InvoiceCiiPreparation::class, function () use (&$calls) {
        $calls++;
        throw new RuntimeException('PRIVATE-PRODUCER-SECRET');
    });
    ($this->postRequest)(['verification_reference' => 'HTTP-FAKE', 'authorization' => ['approved_by' => 'HTTP']])
        ->assertStatus(303)->assertSessionHas('cii_request_status', 'review')
        ->assertSessionHas('cii_request_messages', fn ($messages) => ! str_contains(implode(' ', $messages), 'PRIVATE-PRODUCER-SECRET'));
    expect($calls)->toBe(1);
});

it('keeps the HTTP producer closed outside the sandbox even with its switch enabled', function (string $environment) {
    ($this->grant)();
    $this->app->instance('env', $environment);
    config(['app.env' => $environment, 'superpdp_preparation.web_enabled' => true]);
    ($this->postRequest)()->assertStatus(303)->assertSessionHas('cii_request_status', 'closed');
    expect(app(PreparedCiiArchive::class)->reservationDirectory(false))->toBeNull();
})->with(['local', 'testing', 'staging']);
