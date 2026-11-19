<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceLogoArchive;
use App\Services\SuperPdp\InvoiceEmissionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SuperPdpPayloadFixture;

function compactFinalizationStates(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);
    $sections = $xpath->query('//details[@data-summary-group]');
    expect($sections->length)->toBe(4);
    $states = [];
    foreach ($sections as $section) {
        expect($section->hasAttribute('open'))->toBeFalse();
        $heading = $xpath->query('./summary', $section)->item(0);
        expect($heading)->not->toBeNull();
        $states[$section->getAttribute('data-summary-group')] = preg_replace('/\s+/u', ' ', trim($heading->textContent));
        expect($xpath->query('.//dl | .//p', $heading)->length)->toBe(0);
    }

    return $states;
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $data = SuperPdpPayloadFixture::data();
    $this->company = $this->owner->company()->create(array_diff_key($data['seller'], ['name' => true]) + [
        'company_name' => 'Entreprise de test', 'vat_regime' => 'subject_to_vat',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '732829320_ATELIER'],
        'invoice_recovery_indemnity_text' => 'PMT explicite',
        'invoice_late_payment_penalties_text' => 'PMD explicite',
        'invoice_early_payment_discount_text' => 'AAB explicite',
    ]);
    $this->client = $this->owner->clients()->create([
        'client_type' => 'professional', 'company_name' => 'Client de test',
        'country_code' => 'FR', 'siret' => '55210055400013', 'address' => '3 rue du Moulin',
        'postal_code' => '69002', 'city' => 'Lyon',
        'billing_electronic_address' => ['scheme' => '0225', 'value' => '552100554_ACHATS'],
    ]);
    $this->actingAs($this->owner);
    $this->payload = [
        'emission_profile' => InvoiceEmissionContext::PROFILE, 'french_business_process_type' => 'S1',
        'client_id' => $this->client->id, 'date' => '2026-11-14', 'due_date' => '2026-12-14',
        'items' => [[
            'description' => 'Prestation', 'quantity' => '1', 'unit_price_ht' => '100',
            'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
        ]],
    ];
});

afterEach(function () {
    Http::assertNothingSent();
});

it('previews current company client and calculated invoice data without technical input', function () {
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk()
        ->assertSee('Entreprise de test')->assertSee('Client de test')->assertSee('PMT explicite')
        ->assertSee('Facture ordinaire en euros')->assertSee('Attribué automatiquement à la finalisation')
        ->assertSee('100.00')->assertSee('20.00')->assertSee('120.00')
        ->assertSee('Routage')->assertSee('Vérification indisponible')->assertSee('Aucun envoi n’est activé')
        ->assertViewHas('summary', fn ($summary) => $summary['groups'] === ['Entreprise' => [], 'Client' => [], 'Facture' => []])
        ->assertDontSee('Compléter les paramètres entreprise');
    expect(Invoice::count())->toBe(0);
});

it('groups missing fields separately from unavailable routing and links to their source', function () {
    $this->company->update(['country_code' => null, 'vat_regime' => null, 'invoice_recovery_indemnity_text' => null]);
    $this->client->update(['billing_electronic_address' => null]);
    unset($this->payload['french_business_process_type']);
    $response = $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk()
        ->assertSee('Informations manquantes')->assertSee('Vérification indisponible')
        ->assertSee(route('company.edit'), false)->assertSee(route('clients.edit', $this->client), false)
        ->assertViewHas('summary', function ($summary) {
            expect(array_column($summary['groups']['Entreprise'], 'code'))->toContain('seller.country_code.missing', 'context.vat_regime.missing', 'company.mentions.PMT.missing');
            expect(array_column($summary['groups']['Client'], 'code'))->toContain('buyer.electronic_address.scheme.missing');
            expect(array_column($summary['groups']['Facture'], 'code'))->toContain('invoice.business_process_type.missing');
            expect(array_column($summary['unavailable'], 'code'))->toBe(['routing.verification_unavailable']);

            return true;
        });
    expect($response->getContent())->not->toContain('context.document_kind.missing', 'context.currency_code.missing', 'invoice.not_issued');
});

it('reports invalid lines and unsupported parties with shared rules', function () {
    $this->client->update(['client_type' => 'individual']);
    $this->payload['items'][0]['tva_rate'] = '17';
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk()
        ->assertSee('Cas non pris en charge')->assertSee('Informations à corriger')
        ->assertSee('Seuls les clients professionnels sont pris en charge.')
        ->assertSee('Pour une ligne soumise à la TVA, choisissez un taux');
});

it('does not trust supplied parties totals context or routing claims', function () {
    $this->company->update(['company_name' => '<script>alert(1)</script>']);
    $this->post(route('invoices.electronic-summary'), $this->payload + [
        'seller' => ['name' => 'Injected seller'], 'totals' => ['total_ttc' => '0.01'],
        'routing_verified' => true, 'emission_context' => ['routing_status' => 'verified'],
    ])->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('Injected seller')->assertSee('120.00')->assertSee('Vérification indisponible');
});

it('reads only SQL and never archives a logo while previewing', function () {
    $this->mock(InvoiceLogoArchive::class, function ($mock) {
        $mock->shouldNotReceive('archive');
        $mock->shouldNotReceive('resolve');
    });
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Aucune écriture dans le récapitulatif.');
        }
    });
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk();
});

it('shows the summary before finalizing a saved electronic draft', function () {
    $this->post(route('invoices.store'), ['action' => 'draft'] + $this->payload)->assertSessionHasNoErrors();
    $draft = Invoice::sole();
    $before = $draft->getRawOriginal();
    $this->get(route('invoices.show', $draft))->assertOk()->assertSee('Récapitulatif avant finalisation électronique')
        ->assertSee('Entreprise de test')->assertSee('120.00')->assertSee('Valider définitivement');
    $this->get(route('invoices.edit', $draft))->assertOk()->assertSee('data-electronic-summary', false)
        ->assertSee('invoice-finalization-summary.js');
    expect($draft->fresh()->getRawOriginal())->toBe($before)->and($draft->party_snapshot)->toBeNull();
    $this->post(route('invoices.finalize', $draft))->assertSessionHasNoErrors();
    expect($draft->fresh()->isIssued())->toBeTrue();
});

it('preserves direct finalization and never previews an issued invoice', function () {
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk();
    $this->post(route('invoices.store'), ['action' => 'issue'] + $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->getRawOriginal();
    $this->post(route('invoices.electronic-summary'), ['invoice_id' => $invoice->id] + $this->payload)->assertForbidden();
    $this->get(route('invoices.show', $invoice))->assertOk()->assertDontSee('Récapitulatif avant finalisation électronique');
    expect($invoice->fresh()->getRawOriginal())->toBe($before);
});

it('only reads SQL when displaying the summary on a draft invoice', function () {
    $this->post(route('invoices.store'), ['action' => 'draft'] + $this->payload)->assertSessionHasNoErrors();
    $draft = Invoice::sole();
    $this->mock(InvoiceLogoArchive::class)->shouldNotReceive('archive');
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('La fiche brouillon doit rester en lecture seule.');
        }
    });
    $this->get(route('invoices.show', $draft))->assertOk()->assertSee('Récapitulatif avant finalisation électronique');
});

it('preserves classical drafts without selecting the profile', function () {
    unset($this->payload['emission_profile']);
    $this->company->update(['country_code' => null, 'vat_regime' => null]);
    $this->post(route('invoices.store'), ['action' => 'draft'] + $this->payload)->assertSessionHasNoErrors();
    $draft = Invoice::sole();
    $this->get(route('invoices.show', $draft))->assertOk()->assertDontSee('Récapitulatif avant finalisation électronique');
    $this->post(route('invoices.finalize', $draft))->assertSessionHasNoErrors();
    expect($draft->fresh()->isIssued())->toBeTrue()->and($draft->fresh()->emission_context)->toBeNull();
});

it('protects preview authentication and ownership', function () {
    $this->post(route('invoices.store'), ['action' => 'draft'] + $this->payload)->assertSessionHasNoErrors();
    $draft = Invoice::sole();
    $this->actingAs(User::factory()->create());
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertNotFound();
    $this->post(route('invoices.electronic-summary'), ['invoice_id' => $draft->id] + $this->payload)->assertForbidden();
    auth()->logout();
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertRedirect(route('login'));
});

it('handles an incomplete new form without saving anything', function () {
    $this->get(route('invoices.create'))->assertOk()->assertSee('data-electronic-summary', false);
    $this->post(route('invoices.electronic-summary'), ['emission_profile' => InvoiceEmissionContext::PROFILE])->assertOk()
        ->assertSee('Informations manquantes')->assertSee('Informations à corriger')
        ->assertSee('Non renseigné')->assertSee('Vérification indisponible');
    expect(Invoice::count())->toBe(0);
});

it('reports compatibility before any electronic profile has been selected', function () {
    unset($this->payload['emission_profile']);
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk()
        ->assertViewHas('summary', fn ($summary) => $summary['profileCompatible'])
        ->assertSee('data-profile-compatible="true"', false)->assertSee('facture PDF classique');
    $this->client->update(['client_type' => 'individual']);
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk()
        ->assertViewHas('summary', fn ($summary) => ! $summary['profileCompatible'])
        ->assertSee('data-profile-compatible="false"', false)->assertSee('Seuls les clients professionnels');
    expect(Invoice::count())->toBe(0);
});

it('reuses payment requirements and party format checks from the payload builder', function () {
    $this->company->update(['payment_terms' => null, 'siret' => 'invalid']);
    // EM évite qu'une adresse 0225 incohérente masque le contrôle du SIRET lui-même.
    $this->company->update(['billing_electronic_address' => ['scheme' => 'EM', 'value' => 'billing@example.invalid']]);
    unset($this->payload['due_date']);
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk()
        ->assertViewHas('summary', function ($summary) {
            expect($summary['groups']['Entreprise'])->not->toBeEmpty();
            expect(array_column($summary['groups']['Facture'], 'message'))->toContain('Une échéance ou des conditions de paiement sont obligatoires lorsque le montant dû est positif.');

            return true;
        });
});

it('renders four collapsed accessible sections with compact states', function () {
    $response = $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk();
    expect(compactFinalizationStates($response->getContent()))->toBe([
        'Entreprise' => 'Entreprise Complet', 'Client' => 'Client Complet',
        'Facture' => 'Facture Complet', 'Routage' => 'Routage Vérification indisponible',
    ]);
    $response->assertSee('ne signifie pas que la facture est prête à être envoyée');
});

it('shows unsupported before missing while preserving both reasons and correction links', function () {
    $this->company->update(['country_code' => null]);
    $this->client->update(['client_type' => 'individual', 'postal_code' => null]);
    unset($this->payload['french_business_process_type']);
    $response = $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk()
        ->assertSee('Renseignez le pays de votre entreprise.')
        ->assertSee('Renseignez le code postal du client.')
        ->assertSee('Seuls les clients professionnels sont pris en charge.')
        ->assertSee(route('company.edit'), false)->assertSee(route('clients.edit', $this->client), false);
    expect(compactFinalizationStates($response->getContent()))->toBe([
        'Entreprise' => 'Entreprise À compléter', 'Client' => 'Client Non pris en charge',
        'Facture' => 'Facture À compléter', 'Routage' => 'Routage Vérification indisponible',
    ]);
    expect(array_column($response->viewData('summary')['groups']['Client'], 'code'))->toContain(
        'buyer.postal_code.missing', 'buyer.client_type.unsupported',
    );
});

it('translates field names in messages without changing the underlying diagnostic', function () {
    $this->company->update(['address' => null, 'postal_code' => null, 'city' => null, 'country_code' => null]);
    $this->client->update(['billing_electronic_address' => null]);
    $response = $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk()
        ->assertSee('Renseignez l’adresse de votre entreprise.')
        ->assertSee('Renseignez le code postal de votre entreprise.')
        ->assertSee('Renseignez la ville de votre entreprise.')
        ->assertSee('Complétez l’adresse de facturation électronique du client')
        ->assertDontSee('postal_code')->assertDontSee('country_code')->assertDontSee('scheme')->assertDontSee('value');
    $summary = $response->viewData('summary');
    expect(array_column($summary['groups']['Entreprise'], 'code'))->toContain('seller.address.missing', 'seller.postal_code.missing')
        ->and($summary['groups']['Entreprise'][0]['message'])->toContain('address');
});

it('uses verification unavailable for a group containing only unavailable checks', function () {
    $response = $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk();
    $summary = $response->viewData('summary');
    $summary['groups']['Entreprise'] = [[
        'code' => 'verification.example', 'category' => 'verification_unavailable',
        'field' => 'seller.details', 'message' => 'Contrôle temporairement indisponible.',
    ]];
    $html = view('invoices.partials.finalization-summary', ['summary' => $summary, 'client' => $this->client])->render();
    expect(compactFinalizationStates($html)['Entreprise'])->toBe('Entreprise Vérification indisponible');
});

it('identifies incomplete line fields using plain language', function () {
    unset($this->payload['items'][0]['tax_treatment']);
    $this->post(route('invoices.electronic-summary'), $this->payload)->assertOk()
        ->assertSee('Ligne 1 — traitement de TVA : choisissez un traitement de TVA parmi les choix proposés.')
        ->assertDontSee('items.0.tax_treatment')->assertDontSee('field is required');
});
