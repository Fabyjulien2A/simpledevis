<?php

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\CiiDocumentChecks;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\CiiPreparationRegistry;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\Emission\InvoiceEmissionReadiness;
use App\Services\SuperPdp\Emission\SandboxEmissionFactory;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\Verification\VerificationAuditStore;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Saloon\Config;
use Tests\Support\PreparedCiiFixture;
use Tests\Support\SuperPdpPayloadFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    Config::setSenderResolver(fn () => throw new LogicException('SDK forbidden'));
    $transport = $this->createMock(EmissionTransport::class);
    foreach (['mode', 'send', 'lookup', 'original', 'events'] as $method) {
        $transport->expects($this->never())->method($method);
    }
    $this->app->instance(EmissionTransport::class, $transport);
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

it('shows eligible reservation blocked preparation and explicit confirmation without modifying SQL or archives', function () {
    $files = ($this->snapshot)();
    DB::connection()->beforeExecuting(fn ($sql) => preg_match('/^\s*select\b/i', $sql) ?: throw new LogicException('No SQL writes'));
    $response = $this->get(route('invoices.show', $this->invoice))->assertOk()->assertDontSee('Envoyer électroniquement')
        ->assertSee('Aucune émission demandée.')->assertSee('Le transport web reste fermé')->assertDontSee('OFFLINE-SECRET');
    $xpath = function (string $html): DOMXPath {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    };
    $section = '//section[@id="electronic-emission"]';
    $x = $xpath($response->getContent());
    $readiness = $response->viewData('emissionReadiness');
    expect($readiness['can_send'])->toBeFalse()
        ->and($x->query($section.'//form')->length)->toBe(0)
        ->and($x->query($section.'//p[@id="electronic-emission-closed"]')->length)->toBe(1)
        ->and($x->query($section.'//a[@href="'.route('invoices.electronic-preparation', $this->invoice).'"]')->length)->toBe(1);
    foreach ($readiness['groups'] as $key => $group) {
        $text = $x->query($section.'//*[@data-emission-prerequisite="'.$key.'"]')->item(0)->textContent;
        foreach ($group['messages'] as $message) {
            expect($text)->toContain($message);
        }
    }

    // Render presentation states only: never enable a transport or reserve a real attempt.
    $readiness['can_send'] = true;
    $render = fn () => view('invoices.partials.electronic-emission', [
        'invoice' => $this->invoice, 'emissionReadiness' => $readiness,
    ])->render();
    $x = $xpath($render());
    $form = $section.'//form[@method="POST" and @action="'.route('invoices.request-electronic-emission', $this->invoice).'"]';
    expect($x->query($form.'[input[@name="step" and @value="reserve"]]//button[normalize-space(.)="Envoyer électroniquement" and not(@disabled)]')->length)->toBe(1)
        ->and($x->query($form.'//input[@name="_token"]')->length)->toBe(1)
        ->and($x->query($section.'//input[@name="step" and @value="confirm"]')->length)->toBe(0);

    $readiness['can_send'] = false;
    $readiness['status']['state'] = 'pending';
    $uuid = (string) Str::uuid();
    session()->put('emission_confirmation.'.$this->invoice->id, [
        'uuid' => $uuid, 'token' => 'OFFLINE-CONFIRMATION', 'expires_at' => now()->addMinutes(5)->timestamp,
    ]);
    $x = $xpath(view('invoices.partials.electronic-emission', [
        'invoice' => $this->invoice, 'emissionReadiness' => $readiness,
    ])->render());
    expect($x->query($form.'[input[@name="step" and @value="confirm"]]')->length)->toBe(1)
        ->and($x->query($form.'//input[@name="_token"]')->length)->toBe(1)
        ->and($x->query($form.'//input[@name="attempt_reference" and @value="'.$uuid.'"]')->length)->toBe(1)
        ->and($x->query($form.'//input[@name="confirmation_token" and @value="OFFLINE-CONFIRMATION"]')->length)->toBe(1)
        ->and($x->query($form.'//input[@type="checkbox" and @name="confirm_emission" and @value="1" and @required]')->length)->toBe(1)
        ->and($x->query($form.'//button[normalize-space(.)="Confirmer l’envoi électronique" and not(@disabled)]')->length)->toBe(1)
        ->and($x->query($section.'//input[@name="step" and @value="reserve"]')->length)->toBe(0)
        ->and($x->query($section.'//button[normalize-space(.)="Envoyer électroniquement"]')->length)->toBe(0);
    session()->forget('emission_confirmation.'.$this->invoice->id);
    ($this->requestEmission)(['confirm_emission' => '1'])->assertStatus(303)->assertRedirect(route('invoices.show', $this->invoice).'#electronic-emission');
    expect(($this->snapshot)())->toBe($files)->and(ElectronicInvoice::count())->toBe(0);
});

it('requires explicit confirmation and real CSRF authentication and ownership', function () {
    ($this->requestEmission)()->assertSessionHas('emission_request_messages', fn ($m) => str_contains(implode(' ', $m), 'Confirmez explicitement'));
    ($this->requestEmission)(['_token' => 'wrong', 'confirm_emission' => '1'])->assertStatus(419);
    $this->actingAs($this->other);
    ($this->requestEmission)(['confirm_emission' => '1'])->assertForbidden();
    $this->get(route('invoices.show', $this->invoice))->assertForbidden();
    auth()->forgetGuards();
    ($this->requestEmission)()->assertRedirect(route('login'));
});

it('remains closed with every synthetic prerequisite passed and ignores browser approval references', function () {
    ($this->validated)();
    $ready = app(InvoiceEmissionReadiness::class)->inspect($this->owner, $this->invoice);
    expect(array_column($ready['groups'], 'passed'))->toBe([true, true, true, true, false])->and($ready['can_send'])->toBeFalse();
    $files = ($this->snapshot)();
    for ($i = 0; $i < 2; $i++) {
        ($this->requestEmission)(['confirm_emission' => '1', 'authorization' => ['approved_by' => 'HTTP'],
            'document_reference' => 'documentary-reference/first-reference', 'attempt_reference' => (string) Str::uuid()])
            ->assertStatus(303)->assertSessionHas('emission_request_messages', fn ($m) => str_contains(implode(' ', $m), 'Aucun dépôt effectué'));
    }
    expect(ElectronicInvoice::count())->toBe(0)->and(($this->snapshot)())->toBe($files);
});

it('displays blocked checks for invalid archive proof OAuth or authorization without invoking transport', function ($case) {
    [, $path] = ($this->validated)();
    match ($case) {
        'archive' => file_put_contents($path.'/document.xml', '<altered/>'),
        'missing archive' => unlink($path.'/document.xml'),
        'stale proof' => $this->proof['checks']['buyer_destination']['observed_at'] = now()->subMinutes(16)->toIso8601String(),
        'simulated proof' => $this->proof['checks']['seller_identity']['source'] = 'simulated',
        'expired token' => $this->connection->update(['access_token_expires_at' => now()]),
        'expired approval' => config(['superpdp_emission.authorization.expires_at' => now()->timestamp]),
        'foreign approval' => config(['superpdp_emission.authorization.user_id' => $this->other->id]),
        'production' => $this->app->instance('env', 'production'),
    };
    $ready = app(InvoiceEmissionReadiness::class)->inspect($this->owner, $this->invoice);
    expect(count(array_filter(array_slice(array_column($ready['groups'], 'passed'), 0, 4), fn ($v) => ! $v)))->toBeGreaterThan(0)
        ->and($ready['can_send'])->toBeFalse();
    ($this->requestEmission)(['confirm_emission' => '1'])->assertStatus(303);
    expect(ElectronicInvoice::count())->toBe(0);
})->with(['archive', 'missing archive', 'stale proof', 'simulated proof', 'expired token', 'expired approval', 'foreign approval', 'production']);

it('reads existing registry states without retry recovery synchronization or leaking remote errors', function ($state, $expected) {
    ($this->record)($state);
    $files = ($this->snapshot)();
    DB::connection()->beforeExecuting(fn ($sql) => preg_match('/^\s*select\b/i', $sql) ?: throw new LogicException('No SQL writes'));
    $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee($expected)->assertDontSee('PRIVATE-UPSTREAM-SECRET');
    ($this->requestEmission)(['confirm_emission' => '1'])->assertStatus(303);
    expect(ElectronicInvoice::count())->toBe(1)->and(($this->snapshot)())->toBe($files);
})->with([['pending', 'Tentative réservée'], ['accepted', 'Dépôt accepté par SUPER PDP'], ['rejected', 'Dépôt refusé'], ['unknown', 'Résultat du dépôt incertain']]);

it('labels test deposits as simulations and hides records from a different provider context', function () {
    ($this->record)('accepted', 'test');
    $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Simulation de dépôt acceptée')->assertSee('Simulation uniquement');
    $this->connection->update(['superpdp_company_id' => 456]);
    $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Historique d’émission à examiner')->assertDontSee('Simulation de dépôt acceptée');
});

it('throttles repeated confirmed preflights without reserving a dispatch', function () {
    for ($i = 0; $i < 3; $i++) {
        ($this->requestEmission)(['confirm_emission' => '1'])->assertStatus(303);
    }
    ($this->requestEmission)(['confirm_emission' => '1'])->assertStatus(429)->assertHeader('Retry-After');
    expect(ElectronicInvoice::count())->toBe(0);
});

it('does not offer emission for drafts and refuses their POST', function () {
    $draft = Invoice::create(['user_id' => $this->owner->id, 'client_id' => $this->client->id, 'date' => '2026-11-14',
        'document_status' => 'draft', 'status' => 'non_payee', 'subtotal_ht' => '100', 'total_tva' => '20', 'total_ttc' => '120', 'amount_paid' => '0']);
    $this->get(route('invoices.show', $draft))->assertOk()->assertDontSee('Envoyer électroniquement');
    $this->post(route('invoices.request-electronic-emission', $draft), ['_token' => $this->csrf, 'confirm_emission' => '1'])->assertNotFound();
    expect(ElectronicInvoice::count())->toBe(0);
});

it('projects interrupted dispatches without recovering or persisting any state', function () {
    $record = ($this->record)('pending');
    $record->attempts()->first()->update(['transport_started_at' => now()->subMinutes(20)]);
    $attemptBefore = $record->attempts()->first()->getRawOriginal();
    DB::connection()->beforeExecuting(fn ($sql) => preg_match('/^\s*select\b/i', $sql) ?: throw new LogicException('No SQL writes'));
    $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Dépôt commencé, résultat à vérifier');
    ($this->requestEmission)(['confirm_emission' => '1'])->assertStatus(303);
    expect($record->attempts()->first()->getRawOriginal())->toBe($attemptBefore);
});
