<?php

use App\Http\Controllers\InvoiceController;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SuperPdpPayloadFixture;

function invoiceDiagnosticSection(string $html): string
{
    $start = strpos($html, '<section aria-labelledby="emission-diagnostic-title"');
    expect($start)->not->toBeFalse();
    $end = strpos($html, '</section>', $start);

    return substr($html, $start, $end + strlen('</section>') - $start);
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $data = SuperPdpPayloadFixture::data();
    $this->owner = User::factory()->create();
    $this->owner->company()->create(array_diff_key($data['seller'], array_flip(['name', 'country_code'])) + ['company_name' => 'Atelier Martin']);
    $client = $this->owner->clients()->create(array_diff_key($data['buyer'], array_flip(['full_name', 'client_type'])) + [
        'client_type' => 'professional', 'company_name' => 'Client professionnel', 'siret' => '55210055400013',
    ]);
    $this->invoice = Invoice::create([
        'user_id' => $this->owner->id, 'client_id' => $client->id,
        'invoice_number' => $data['invoice_number'], 'date' => $data['date'], 'due_date' => $data['due_date'],
        'document_status' => 'issued', 'calculation_version' => 'decimal-v1', 'status' => 'non_payee', 'amount_paid' => '0.00',
        ...$data['totals'],
    ]);
    $this->invoice->items()->create(array_diff_key($data['items'][0], ['id' => true]));
});

afterEach(function () {
    Http::assertNothingSent();
});

it('shows the real diagnostic for the owner with missing context and readable messages', function () {
    $response = $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))
        ->assertOk()->assertViewIs('invoices.show')
        ->assertViewHas('emissionDiagnostic', fn ($result) => ! $result['eligible'] && ! $result['local_checks_passed'])
        ->assertSee('Diagnostic de facturation électronique')
        ->assertSee('Contrôles locaux à compléter')
        ->assertSee('Informations manquantes')->assertSee('Vérifications indisponibles')
        ->assertSee('Régime de TVA non renseigné dans les paramètres de votre entreprise.')
        ->assertSee('Cadre de facturation non renseigné')
        ->assertSee('La destination électronique du client ne peut pas encore être vérifiée')
        ->assertSee('Ce diagnostic ne vaut ni autorisation d’émettre, ni validation réglementaire complète.');

    $section = invoiceDiagnosticSection($response->getContent());
    expect($section)->not->toContain('document_kind', 'currency_code', 'vat_regime', 'french_context', '<button', '<form', '<input', '<a ');
    expect(substr_count($section, 'L’adresse de facturation électronique du client'))->toBe(1);
    $response->assertSee(route('invoices.xml', $this->invoice), false)
        ->assertSee(route('invoices.action', $this->invoice), false)
        ->assertSee(route('invoices.duplicate', $this->invoice), false)
        ->assertSee('Envoyer par e-mail')->assertSee('Détail des prestations')->assertSee('Paiements')
        ->assertSee('Créer un avoir total');
});

it('passes only the authorized invoice to the diagnostic and ignores request context claims', function () {
    $realDiagnostic = app(SuperPdpEmissionEligibilityDiagnostic::class);
    $this->mock(SuperPdpEmissionEligibilityDiagnostic::class, function ($mock) use ($realDiagnostic) {
        $mock->shouldReceive('diagnose')->once()
            ->withArgs(fn (...$arguments) => count($arguments) === 1 && $arguments[0]->id === $this->invoice->id)
            ->andReturnUsing(fn ($invoice) => $realDiagnostic->diagnose($invoice));
    });

    $this->actingAs($this->owner)->get(route('invoices.show', [
        'invoice' => $this->invoice, 'document_kind' => 'ordinary', 'type_code' => 380,
        'currency_code' => 'EUR', 'vat_regime' => 'subject_to_vat', 'routing_verified' => true,
        'french_context' => SuperPdpPayloadFixture::frenchContext(),
    ]))->assertOk()->assertViewHas('emissionDiagnostic', function ($result) {
        expect(array_column($result['blockers'], 'code'))->toContain('context.document_kind.missing', 'context.type_code.missing',
            'context.currency_code.missing', 'context.vat_regime.missing', 'invoice.business_process_type.missing', 'routing.verification_unavailable');

        return true;
    });
});

it('keeps successful local checks distinct from permission to issue', function () {
    $this->mock(SuperPdpEmissionEligibilityDiagnostic::class, function ($mock) {
        $mock->shouldReceive('diagnose')->once()->andReturn([
            'eligible' => false, 'local_checks_passed' => true,
            'blockers' => [[
                'code' => 'routing.verification_unavailable', 'category' => 'verification_unavailable',
                'field' => 'buyer.electronic_address', 'message' => 'Destination non vérifiée.',
            ]],
        ]);
    });
    $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))->assertOk()
        ->assertSee('Contrôles locaux réussis')->assertDontSee('Contrôles locaux à compléter')
        ->assertSee('Des blocages empêchent actuellement la préparation électronique.')
        ->assertSee('Ce diagnostic ne vaut ni autorisation d’émettre')
        ->assertSee('Vérifications indisponibles');
});

it('shows tax checks for the stored regime without reporting it as missing', function (string $regime, string $expectedCode) {
    $this->owner->company()->update(['vat_regime' => $regime, 'vat_number' => null, 'tva_number' => null]);
    $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))->assertOk()
        ->assertDontSee('Régime de TVA non renseigné dans les paramètres de votre entreprise.')
        ->assertSee('Ce diagnostic ne vaut ni autorisation d’émettre')
        ->assertViewHas('emissionDiagnostic', function ($result) use ($expectedCode) {
            expect(array_column($result['blockers'], 'code'))->toContain($expectedCode)
                ->not->toContain('context.vat_regime.missing');

            return true;
        });
})->with([
    ['subject_to_vat', 'seller.vat_number.missing'],
    ['franchise', 'tax.franchise.line_invalid'],
]);

it('escapes diagnostic messages and displays invalid data separately', function () {
    $message = '<script>alert("diagnostic")</script>';
    $this->mock(SuperPdpEmissionEligibilityDiagnostic::class, function ($mock) use ($message) {
        $mock->shouldReceive('diagnose')->once()->andReturn([
            'eligible' => false, 'local_checks_passed' => false,
            'blockers' => [['code' => 'payload.invalid', 'category' => 'invalid_data', 'field' => 'payload', 'message' => $message]],
        ]);
    });
    $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))->assertOk()
        ->assertSee('Informations à corriger')->assertSee($message)->assertDontSee($message, false);
});

it('shows the diagnostic on drafts and preserves draft actions', function () {
    $this->invoice->update(['document_status' => 'draft', 'invoice_number' => null]);
    $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))->assertOk()
        ->assertSee('Diagnostic de facturation électronique')->assertSee('La facture doit être définitive.')
        ->assertSee('Cas non pris en charge')->assertSee('Modifier le brouillon')->assertSee('Valider définitivement')
        ->assertSee(route('invoices.edit', $this->invoice), false)->assertSee(route('invoices.finalize', $this->invoice), false);
});

it('preserves the payment history and explains its emission blocker', function () {
    $this->invoice->payments()->create(['amount' => '12.00', 'paid_at' => '2026-11-01']);
    $this->invoice->update(['status' => 'partiellement_payee', 'amount_paid' => '12.00']);
    $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))->assertOk()
        ->assertSee('Un règlement ou un acompte est enregistré.')
        ->assertSee('Historique des paiements')->assertSee('01/11/2026')->assertSee('12,00');
});

it('preserves credit note details and explains their emission blocker', function () {
    $creditNote = $this->invoice->creditNotes()->create([
        'user_id' => $this->owner->id, 'credit_note_number' => 'AV-2026-0001', 'date' => '2026-11-06',
        'status' => 'issued', 'reason' => 'Annulation de la prestation', 'subtotal_ht' => '60.00', 'total_tva' => '12.00', 'total_ttc' => '72.00',
    ]);
    $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))->assertOk()
        ->assertSee('Un avoir définitif est associé à cette facture.')
        ->assertSee('AV-2026-0001')->assertSee('Annulation de la prestation')
        ->assertSee(route('credit-notes.pdf', $creditNote), false);
});

it('keeps the detail request read only including electronic storage', function () {
    $this->actingAs($this->owner);
    $tables = ['invoices', 'invoice_items', 'payments', 'credit_notes', 'clients', 'companies', 'electronic_invoices', 'electronic_invoice_statuses', 'superpdp_connections', 'electronic_invoice_events'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('La consultation ne doit effectuer aucune écriture SQL.');
        }
    });
    $this->get(route('invoices.show', $this->invoice))->assertOk();
    expect($snapshot())->toBe($before);
});

it('redirects guests before running the diagnostic', function () {
    $this->mock(SuperPdpEmissionEligibilityDiagnostic::class)->shouldNotReceive('diagnose');
    $this->get(route('invoices.show', $this->invoice))->assertRedirect(route('login'));
});

it('forbids another owner before running the diagnostic', function () {
    $other = User::factory()->create();
    $this->mock(SuperPdpEmissionEligibilityDiagnostic::class)->shouldNotReceive('diagnose');
    $this->actingAs($other)->get(route('invoices.show', $this->invoice))->assertForbidden()
        ->assertDontSee('Client professionnel')->assertDontSee('Diagnostic de facturation électronique');
});

it('returns not found for missing invoices without running the diagnostic', function () {
    $this->mock(SuperPdpEmissionEligibilityDiagnostic::class)->shouldNotReceive('diagnose');
    $this->actingAs($this->owner)->get(route('invoices.show', 999999))->assertNotFound();
});

it('rechecks stored ownership before diagnosing a stale model', function () {
    $stale = $this->invoice;
    $other = User::factory()->create();
    DB::table('invoices')->where('id', $stale->id)->update(['user_id' => $other->id]);
    $this->actingAs($this->owner);
    $diagnostic = $this->mock(SuperPdpEmissionEligibilityDiagnostic::class);
    $diagnostic->shouldNotReceive('diagnose');

    try {
        app(InvoiceController::class)->show($stale, $diagnostic);
        $this->fail('Le propriétaire doit être contrôlé avant le diagnostic.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});
