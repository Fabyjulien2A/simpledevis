<?php

use App\Http\Controllers\InvoiceElectronicPreparationController;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceLogoArchive;
use App\Services\SuperPdp\CiiPreparationEvidence;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\PreparedCiiArchive;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpOAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SuperPdpPayloadFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    $this->owner = User::factory()->create();
    $data = SuperPdpPayloadFixture::data();
    $data['buyer'] = array_replace($data['buyer'], [
        'client_type' => 'professional', 'company_name' => 'Client figé', 'siret' => '55210055400013',
    ]);
    $data['company_vat_regime'] = 'subject_to_vat';
    $data['company_invoice_mentions'] = collect(SuperPdpPayloadFixture::frenchContext()['notes'])
        ->pluck('note', 'subject_code')->all();
    $client = $this->owner->clients()->create(['client_type' => 'professional', 'company_name' => 'Client actuel']);
    $this->invoice = Invoice::create([
        'user_id' => $this->owner->id, 'client_id' => $client->id,
        ...array_intersect_key($data, array_flip(['invoice_number', 'date', 'due_date', 'document_status', 'calculation_version', 'status', 'amount_paid'])),
        ...$data['totals'], 'french_business_process_type' => 'S1',
        'emission_profile' => InvoiceEmissionContext::PROFILE,
    ]);
    // Fixtures uniquement : simuler les données immuables enregistrées à la finalisation.
    DB::table('invoices')->where('id', $this->invoice->id)->update([
        'party_snapshot' => SuperPdpPayloadFixture::partySnapshot($data),
        'emission_context' => json_encode(InvoiceEmissionContext::capture(InvoiceEmissionContext::PROFILE,
            ['scheme' => '0225', 'value' => '732829320_ATELIER'],
            ['scheme' => '0225', 'value' => '552100554_ACHATS'], $data), JSON_THROW_ON_ERROR),
    ]);
    $this->invoice->refresh();
    $this->invoice->items()->create(array_diff_key($data['items'][0], ['id' => true]));
});

afterEach(function () {
    Http::assertNothingSent();
    MockClient::destroyGlobal();
});

it('links the local preparation only from issued invoices', function () {
    $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))->assertOk()
        ->assertSee('Préparer l’envoi électronique')->assertSee(route('invoices.electronic-preparation', $this->invoice), false);
    $this->invoice->update(['document_status' => 'draft', 'invoice_number' => null]);
    $this->get(route('invoices.show', $this->invoice))->assertOk()->assertDontSee('Préparer l’envoi électronique');
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertNotFound();
});

it('shows frozen parties and amounts with local eligibility but never readiness', function () {
    $response = $this->actingAs($this->owner)->get(route('invoices.electronic-preparation', [
        'invoice' => $this->invoice, 'routing_verified' => true,
        'buyer_electronic_address' => ['scheme' => 'EM', 'value' => 'injected@example.invalid'],
    ]))->assertOk()->assertViewHas('emissionDiagnostic', function ($result) {
        expect(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable']);

        return $result['local_checks_passed'] && ! $result['eligible'];
    })
        ->assertSee('Éligible au profil pris en charge')->assertSee('Oui — contrôles locaux réussis')
        ->assertSee('Prête à être envoyée')->assertSee('Non — ce contrôle de destination')
        ->assertSee('Atelier Martin')->assertSee('Client figé')->assertDontSee('Client actuel')
        ->assertSee('732829320_ATELIER')->assertSee('552100554_ACHATS')->assertDontSee('injected@example.invalid')
        ->assertSee('60.00')->assertSee('12.00')->assertSee('72.00')->assertSee('EUR')
        ->assertSee('Le XML interne SimpleDevis n’est pas le CII électronique');
    $content = substr($response->getContent(), strpos($response->getContent(), '<main'));
    expect($content)->toContain('Vérifier la destination électronique');
    expect(substr_count($content, '<form'))->toBe(1);
});

it('blocks missing or corrupt frozen data without replacing it with current profiles', function ($snapshot) {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => $snapshot === null ? null : json_encode($snapshot)]);
    $this->actingAs($this->owner)->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee('Données figées des parties indisponibles')->assertDontSee('Client actuel')
        ->assertSee('Non — contrôles locaux à compléter');
})->with([[null], [['schema_version' => 999]]]);

it('shows fiscal and settlement blockers and escapes frozen names', function () {
    $snapshot = $this->invoice->party_snapshot;
    $snapshot['buyer']['company_name'] = '<script>alert(1)</script>';
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => json_encode($snapshot), 'amount_paid' => '1.00', 'status' => 'partiellement_payee']);
    $this->invoice->items()->update(['line_total_ht' => '999.00']);
    $this->actingAs($this->owner)->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee('Non — contrôles locaux à compléter')->assertSee('Informations à corriger')
        ->assertSee('Cette facture est payée, partiellement payée')
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
});

it('keeps valid frozen parties visible while blocking a corrupt emission context', function () {
    DB::table('invoices')->where('id', $this->invoice->id)->update(['emission_context' => '{"routing_status":"verified"}']);
    $this->actingAs($this->owner)->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee('Client figé')->assertSee('Atelier Martin')
        ->assertSee('Contexte d’émission invalide')->assertSee('Non — contrôles locaux à compléter')
        ->assertSee('Non — ce contrôle de destination')->assertDontSee('Client actuel');
});

it('executes only SQL reads and makes no HTTP request', function () {
    $this->actingAs($this->owner);
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Écriture SQL interdite pendant la préparation.');
        }
    });
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk();
});

it('protects access before running the diagnostic', function () {
    $this->mock(SuperPdpEmissionEligibilityDiagnostic::class)->shouldNotReceive('diagnose');
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('invoices.electronic-preparation', $this->invoice))->assertForbidden();
    $this->get(route('invoices.electronic-preparation', 999999))->assertNotFound();
});

it('rechecks stored ownership for a stale invoice before reading preparation data', function () {
    $this->actingAs($this->owner);
    DB::table('invoices')->where('id', $this->invoice->id)->update(['user_id' => User::factory()->create()->id]);
    $diagnostic = $this->mock(SuperPdpEmissionEligibilityDiagnostic::class);
    $diagnostic->shouldNotReceive('diagnose');
    try {
        app(InvoiceElectronicPreparationController::class)($this->invoice, $diagnostic, app(InvoiceExportDataBuilder::class));
        $this->fail('Le propriétaire enregistré doit être contrôlé.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

it('reuses the successful payload construction once for local documentary preparation', function () {
    $builder = app(SuperPdpInvoicePayloadBuilder::class);
    $this->mock(SuperPdpInvoicePayloadBuilder::class, function ($mock) use ($builder) {
        $mock->shouldReceive('buildFromData')->once()->andReturnUsing(fn (...$arguments) => $builder->buildFromData(...$arguments));
        $mock->shouldNotReceive('buildSandboxFromData');
    });
    $response = $this->actingAs($this->owner)->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee('Préparation documentaire locale')->assertSee('Contrôles documentaires locaux réussis')
        ->assertSee('CII non produit')->assertSee('Validation non effectuée')->assertSee('Envoi bloqué')
        ->assertSee('Lignes préparées');
    $preparation = $response->viewData('documentaryPreparation');
    $data = $preparation['prepared_data'];
    expect($data['owner_user_id'])->toBe($this->owner->id)->and($data['invoice_id'])->toBe($this->invoice->id)
        ->and($data['document']['number'])->toBe($this->invoice->invoice_number)
        ->and($data['document']['seller']['name'])->toBe('Atelier Martin')
        ->and($data['document']['buyer']['name'])->toBe('Client figé')
        ->and($data['document']['type_code'])->toBe(380)->and($data['document']['currency_code'])->toBe('EUR')
        ->and($data['document']['totals']['total_with_vat'])->toBe('72.00')
        ->and($preparation['sha256'])->toBe(CiiPreparationEvidence::hash($data))->toMatch('/^[a-f0-9]{64}$/')
        ->and($preparation['blockers'])->toBe([])->and($preparation['diagnostic']['eligible'])->toBeFalse()
        ->and($preparation['cii_produced'])->toBeFalse()->and($preparation['validation_performed'])->toBeFalse()
        ->and($preparation['emission_allowed'])->toBeFalse();
    $response->assertSee($preparation['sha256']);
});

it('keeps the documentary fingerprint stable across time current profiles and HTTP claims', function () {
    $this->actingAs($this->owner);
    $first = $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()->viewData('documentaryPreparation');
    $this->travel(10)->minutes();
    $this->owner->company()->create(['company_name' => 'Burger Queen', 'siret' => null]);
    $this->invoice->client()->update(['company_name' => 'Tricatel actuel', 'siret' => '31514329600000']);
    config(['services.superpdp.sandbox_identity' => ['company_id' => 3, 'number_scheme' => 'sandbox', 'number' => '000000002']]);
    $second = $this->get(route('invoices.electronic-preparation', ['invoice' => $this->invoice,
        'owner_user_id' => 999, 'invoice_id' => 999, 'currency_code' => 'USD', 'routing_verified' => true,
        'seller' => ['name' => 'Burger Queen'], 'buyer' => ['name' => 'Tricatel'], 'sha256' => 'injected',
    ]))->assertOk()->viewData('documentaryPreparation');
    expect($second['sha256'])->toBe($first['sha256'])->and($second['prepared_data'])->toBe($first['prepared_data']);
});

it('normalizes snapshot object key order while preserving the documentary fingerprint', function () {
    $this->actingAs($this->owner);
    $first = $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()->viewData('documentaryPreparation');
    $snapshot = $this->invoice->party_snapshot;
    $snapshot['seller'] = array_reverse($snapshot['seller'], true);
    $snapshot['buyer'] = array_reverse($snapshot['buyer'], true);
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => json_encode(array_reverse($snapshot, true))]);
    $second = $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()->viewData('documentaryPreparation');
    expect($second['sha256'])->toBe($first['sha256'])->and($second['prepared_data'])->toBe($first['prepared_data']);
});

it('changes the fingerprint when a prepared line changes and escapes its contents', function () {
    $this->actingAs($this->owner);
    $first = $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()->viewData('documentaryPreparation');
    $description = '<script>alert("line")</script>';
    $this->invoice->items()->update(['description' => $description]);
    $response = $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee($description)->assertDontSee($description, false);
    expect($response->viewData('documentaryPreparation')['sha256'])->not->toBe($first['sha256']);
});

it('reports incomplete historical or inconsistent data without a document or fingerprint', function (string $case) {
    $snapshot = $this->invoice->party_snapshot;
    match ($case) {
        'absent' => $snapshot = null,
        'version' => $snapshot = ['schema_version' => 999],
        'country' => $snapshot['seller']['country_code'] = null,
        'vat' => $snapshot['seller']['vat_regime'] = null,
        'mention' => $snapshot['seller']['invoice_recovery_indemnity_text'] = null,
        default => null,
    };
    $changes = ['party_snapshot' => $snapshot === null ? null : json_encode($snapshot)];
    if ($case === 'totals') {
        $changes['total_tva'] = '99.00';
    }
    if ($case === 'legacy_calculation') {
        $changes['calculation_version'] = null;
    }
    if ($case === 'context') {
        $changes['emission_context'] = null;
    }
    DB::table('invoices')->where('id', $this->invoice->id)->update($changes);
    $response = $this->actingAs($this->owner)->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee('Contrôles documentaires locaux à compléter')->assertSee('Empreinte SHA-256 indisponible')
        ->assertSee('CII non produit')->assertSee('Validation non effectuée')->assertSee('Envoi bloqué');
    $preparation = $response->viewData('documentaryPreparation');
    expect($preparation['prepared_data'])->toBeNull()->and($preparation['sha256'])->toBeNull()
        ->and($preparation['blockers'])->not->toBeEmpty();
})->with(['absent', 'version', 'country', 'vat', 'mention', 'totals', 'legacy_calculation', 'context']);

it('binds identical documentary payloads to their distinct invoice owners', function () {
    $first = $this->actingAs($this->owner)->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()->viewData('documentaryPreparation');
    $other = User::factory()->create();
    $client = $other->clients()->create(['client_type' => 'professional', 'company_name' => 'Autre fiche actuelle']);
    $copy = Invoice::create(array_replace($this->invoice->only($this->invoice->getFillable()), [
        'user_id' => $other->id, 'client_id' => $client->id,
    ]));
    DB::table('invoices')->where('id', $copy->id)->update([
        'party_snapshot' => $this->invoice->getRawOriginal('party_snapshot'),
        'emission_context' => $this->invoice->getRawOriginal('emission_context'),
    ]);
    foreach ($this->invoice->items as $item) {
        $copy->items()->create($item->only(['description', 'quantity', 'unit_price_ht', 'tva_rate', 'line_total_ht', 'vat_category', 'unit_code', 'exemption_reason_code', 'exemption_reason']));
    }
    $this->actingAs($other)->get(route('invoices.electronic-preparation', $this->invoice))->assertForbidden();
    $second = $this->get(route('invoices.electronic-preparation', $copy))->assertOk()->viewData('documentaryPreparation');
    expect($second['prepared_data']['document'])->toBe($first['prepared_data']['document'])
        ->and($second['sha256'])->not->toBe($first['sha256'])
        ->and($second['prepared_data']['owner_user_id'])->toBe($other->id)
        ->and($second['prepared_data']['invoice_id'])->toBe($copy->id);
});

it('protects direct local preparation from crossed identities and stale models', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $diagnostic = app(SuperPdpEmissionEligibilityDiagnostic::class);
    try {
        $diagnostic->prepareLocal($this->owner, $this->invoice);
        $this->fail('L’identité authentifiée doit correspondre au propriétaire.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    $this->actingAs($this->owner);
    DB::table('invoices')->where('id', $this->invoice->id)->update(['user_id' => $other->id]);
    try {
        $diagnostic->prepareLocal($this->owner, $this->invoice);
        $this->fail('Le propriétaire stocké doit être contrôlé.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

it('prepares locally using only SQL reads without archive OAuth or network side effects', function () {
    Storage::fake('invoice_cii');
    $mock = MockClient::global([]);
    $this->mock(PreparedCiiArchive::class, function ($archive) {
        $archive->shouldNotReceive('store');
        $archive->shouldNotReceive('storeProduced');
    });
    $this->mock(InvoiceLogoArchive::class, function ($logos) {
        $logos->shouldNotReceive('archive');
        $logos->shouldNotReceive('resolve');
    });
    $this->mock(SuperPdpOAuthService::class, function ($oauth) {
        $oauth->shouldNotReceive('ensureValidAccessToken');
        $oauth->shouldNotReceive('refreshAccessToken');
    });
    $tables = ['invoices', 'invoice_items', 'clients', 'companies', 'payments', 'credit_notes',
        'electronic_invoices', 'electronic_invoice_attempts', 'electronic_invoice_statuses', 'superpdp_connections'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $this->actingAs($this->owner);
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('La préparation documentaire ne doit faire aucune écriture SQL.');
        }
    });
    $this->get(route('invoices.electronic-preparation', $this->invoice))->assertOk()
        ->assertSee('Contrôles documentaires locaux réussis');
    expect($snapshot())->toBe($before)->and(Storage::disk('invoice_cii')->allFiles())->toBe([]);
    $mock->assertNothingSent();
});
