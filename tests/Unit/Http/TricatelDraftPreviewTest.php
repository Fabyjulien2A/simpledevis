<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\TricatelDraftDocumentPreview;
use App\Services\SuperPdp\TricatelDraftPreviewScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReadOnlyDraftPreviewDatabase;
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
    Storage::shouldReceive('disk')->never();
    $this->withoutVite();
    $this->originalResolver = Model::getConnectionResolver();
    $this->memory = new ReadOnlyDraftPreviewDatabase;
    $this->app->instance('env', 'sandbox');
    config(['app.env' => 'sandbox', 'session.driver' => 'array',
        'services.superpdp.environment' => 'sandbox', 'services.superpdp.client_id' => 'offline-client',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'database.default' => 'superpdp_sandbox', 'database.connections' => ['superpdp_sandbox' => [
            'driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test',
        ]]]);
    DB::extend('superpdp_sandbox', fn () => $this->memory);
    Model::setConnectionResolver(app('db'));
    $this->memory->rows = [
        'users' => [4 => ['id' => 4, 'name' => 'Owner Tricatel'], 5 => ['id' => 5, 'name' => 'Other owner']],
        'companies' => [4 => ['id' => 4, 'user_id' => 4, 'company_name' => 'Tricatel', 'siret' => null,
            'vat_number' => null, 'tva_number' => null, 'vat_regime' => 'subject_to_vat',
            'address' => 'Adresse vendeur de test', 'postal_code' => '75001', 'city' => 'Paris', 'country_code' => 'FR',
            'billing_electronic_address' => json_encode(['scheme' => '0225', 'value' => '315143296_18171'])]],
        'superpdp_connections' => [2 => ['id' => 2, 'company_id' => 4, 'environment' => 'sandbox',
            'directory_identifier' => '000000001', 'status' => 'connected', 'superpdp_company_id' => 18171,
            'oauth_client_id' => 'offline-client', 'api_url' => 'https://api.superpdp.tech/v1.beta',
            'access_token' => 'DO-NOT-DECRYPT-OR-EXPOSE', 'refresh_token' => 'DO-NOT-DECRYPT-OR-EXPOSE']],
        'clients' => [45 => ['id' => 45, 'user_id' => 4, 'company_name' => 'test45', 'client_type' => 'professional',
            'siret' => '55210055400013', 'address' => 'Adresse client de test', 'postal_code' => '69001',
            'city' => 'Lyon', 'country_code' => 'FR',
            'billing_electronic_address' => json_encode(['scheme' => '0225', 'value' => '552100554'])]],
        'invoices' => [99 => ['id' => 99, 'user_id' => 4, 'client_id' => 45, 'quote_id' => null,
            'document_status' => 'draft', 'invoice_number' => null, 'date' => '2026-11-14', 'due_date' => '2026-12-14',
            'emission_profile' => InvoiceEmissionContext::PROFILE, 'party_snapshot' => null, 'emission_context' => null,
            'french_business_process_type' => 'S1', 'calculation_version' => 'decimal-v1',
            'status' => 'non_payee', 'amount_paid' => '0.00',
            'subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00']],
        'invoice_items' => [1 => ['id' => 1, 'invoice_id' => 99, 'description' => 'Prestation sandbox',
            'quantity' => '1.00', 'unit_price_ht' => '100.00', 'tva_rate' => '20.00', 'vat_category' => 'S',
            'unit_code' => 'C62', 'line_total_ht' => '100.00', 'exemption_reason_code' => null, 'exemption_reason' => null]],
        'payments' => [], 'credit_notes' => [], 'quotes' => [],
    ];
    $this->actor = User::findOrFail(4);
    $this->actingAs($this->actor);
    $this->openPreview = fn (array $query = []) => $this->get(route('invoices.sandbox-electronic-preview', ['invoice' => 99] + $query));
    $this->beforeRows = $this->memory->rows;
});

afterEach(function () {
    Model::setConnectionResolver($this->originalResolver);
    Http::assertNothingSent();
    // Includes drafts, numbering, frozen data, parties, OAuth tokens, payments and credit notes.
    expect($this->memory->rows)->toBe($this->beforeRows);
});

it('shows saved draft data calculations and honest blockers without producing a document', function () {
    ($this->openPreview)()->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Tricatel')->assertSee('test45')->assertSee('Prestation sandbox')
        ->assertSee('100.00 EUR')->assertSee('20.00 EUR')->assertSee('120.00 EUR')
        ->assertSee('sandbox / 000000001')->assertSee('315143296_18171')
        ->assertSee('Document CII non produit.')->assertSee('Conformité électronique non établie.')
        ->assertSee('Envoi électronique interdit.')->assertDontSee('DO-NOT-DECRYPT-OR-EXPOSE')
        ->assertViewHas('preview', function ($preview) {
            $codes = array_column($preview['blockers'], 'code');
            expect($codes)->toContain('seller.siret.missing', 'seller.vat_number.missing', 'seller.electronic_address.invalid', 'routing.verification_unavailable')
                ->and($preview['data']['seller']['siret'])->toBeNull()
                ->and($preview['data']['seller']['vat_number'])->toBeNull()
                ->and($preview['data']['seller'])->not->toHaveKeys(['superpdp_identity', 'legal_registration_identifier'])
                ->and($preview['cii_produced'])->toBeFalse()->and($preview['conformity_established'])->toBeFalse()
                ->and($preview['emission_allowed'])->toBeFalse()->and($preview['routing_verified'])->toBeFalse();

            return true;
        });
});

it('links the preview from the authorized draft page', function () {
    $this->get(route('invoices.show', 99))->assertOk()->assertSee('Aperçu électronique (test)')
        ->assertSee(route('invoices.sandbox-electronic-preview', 99), false);
});

it('hides the action outside the authorized sandbox binding', function (string $case) {
    if ($case === 'production') {
        $this->app->instance('env', 'production');
        config(['app.env' => 'production', 'services.superpdp.environment' => 'production']);
    } else {
        $this->memory->rows['superpdp_connections'][2]['directory_identifier'] = '000000002';
    }
    $this->beforeRows = $this->memory->rows;
    $this->get(route('invoices.show', 99))->assertOk()->assertDontSee('Aperçu électronique (test)');
})->with(['production', 'wrong identity']);

it('ignores all caller supplied identity party amount and qualification fields', function () {
    ($this->openPreview)(['company_id' => 5, 'user_id' => 5, 'client_id' => 999, 'total_ttc' => '9999.00',
        'number' => 'INJECTED', 'routing_verified' => 1, 'emission_allowed' => 1,
        'seller_electronic_address' => ['scheme' => 'EM', 'value' => 'injected@example.invalid']])
        ->assertOk()->assertSee('120.00 EUR')->assertSee('test45')->assertDontSee('INJECTED')->assertDontSee('injected@example.invalid');
});

it('refuses every altered scope or binding', function (string $case) {
    match ($case) {
        'app environment' => $this->app->instance('env', 'production'),
        'config environment' => config(['app.env' => 'production']),
        'provider environment' => config(['services.superpdp.environment' => 'production']),
        'database' => config(['database.connections.superpdp_sandbox.database' => 'other']),
        'other connection configured' => config(['database.connections.other' => ['database' => 'other']]),
        'api url' => config(['services.superpdp.api_url' => 'https://other.invalid']),
        'client id' => config(['services.superpdp.client_id' => 'other']),
        'empty client id' => config(['services.superpdp.client_id' => '']),
        'oauth environment' => $this->memory->rows['superpdp_connections'][2]['environment'] = 'production',
        'oauth api' => $this->memory->rows['superpdp_connections'][2]['api_url'] = 'https://other.invalid',
        'oauth identity' => $this->memory->rows['superpdp_connections'][2]['directory_identifier'] = '000000002',
        'oauth owner' => $this->memory->rows['superpdp_connections'][2]['company_id'] = 5,
        'oauth status' => $this->memory->rows['superpdp_connections'][2]['status'] = 'disconnected',
        'oauth remote id' => $this->memory->rows['superpdp_connections'][2]['superpdp_company_id'] = null,
        'missing oauth' => $this->memory->rows['superpdp_connections'] = [],
        'other company' => $this->memory->rows['companies'][4]['id'] = 5,
        'company owner' => $this->memory->rows['companies'][4]['user_id'] = 5,
        'company name' => $this->memory->rows['companies'][4]['company_name'] = 'Other',
        'missing company' => $this->memory->rows['companies'] = [],
        'invoice owner' => $this->memory->rows['invoices'][99]['user_id'] = 5,
        'invoice company' => $this->memory->rows['invoices'][99]['company_id'] = 5,
        'client owner' => $this->memory->rows['clients'][45]['user_id'] = 5,
        'other actor' => $this->actingAs(User::findOrFail(5)),
    };
    $this->beforeRows = $this->memory->rows;
    ($this->openPreview)()->assertForbidden()->assertDontSee('Prestation sandbox')->assertDontSee('DO-NOT-DECRYPT-OR-EXPOSE');
})->with(['app environment', 'config environment', 'provider environment', 'database', 'other connection configured',
    'api url', 'client id', 'empty client id', 'oauth environment', 'oauth api', 'oauth identity', 'oauth owner',
    'oauth status', 'oauth remote id', 'missing oauth', 'other company', 'company owner', 'company name',
    'missing company', 'invoice owner', 'invoice company', 'client owner', 'other actor']);

it('requires authentication', function () {
    auth()->forgetGuards();
    ($this->openPreview)()->assertRedirect(route('login'));
});

it('refuses an issued invoice or existing frozen data', function (string $case) {
    if ($case === 'issued') {
        $this->memory->rows['invoices'][99]['document_status'] = 'issued';
    } else {
        $this->memory->rows['invoices'][99][$case] = '{"preserve":"exactly"}';
    }
    $this->beforeRows = $this->memory->rows;
    ($this->openPreview)()->assertStatus($case === 'issued' ? 404 : 409);
    expect(app(TricatelDraftPreviewScope::class)->allows($this->actor, Invoice::findOrFail(99)))->toBeFalse();
})->with(['issued', 'party_snapshot', 'emission_context']);

it('shows incomplete data and invalid lines without inventing totals or identifiers', function (string $case) {
    match ($case) {
        'missing client' => $this->memory->rows['invoices'][99]['client_id'] = null,
        'missing address' => $this->memory->rows['companies'][4]['billing_electronic_address'] = null,
        'no lines' => $this->memory->rows['invoice_items'] = [],
        'bad quantity' => $this->memory->rows['invoice_items'][1]['quantity'] = '0.00',
        'missing seller details' => $this->memory->rows['companies'][4]['address'] = null,
    };
    $this->beforeRows = $this->memory->rows;
    ($this->openPreview)()->assertOk()->assertSee('Non renseigné')->assertSee('Envoi électronique interdit.')
        ->assertViewHas('preview', function ($preview) use ($case) {
            expect($preview['blockers'])->not->toBeEmpty();
            if (in_array($case, ['no lines', 'bad quantity'])) {
                expect($preview['data']['totals'])->toBe([]);
            }

            return true;
        });
})->with(['missing client', 'missing address', 'no lines', 'bad quantity', 'missing seller details']);

it('calculates multiple VAT rates and reports stale stored totals without changing them', function () {
    $this->memory->rows['invoice_items'][2] = array_replace($this->memory->rows['invoice_items'][1], [
        'id' => 2, 'description' => 'Second line', 'quantity' => '3.00', 'unit_price_ht' => '0.33',
        'tva_rate' => '10.00', 'line_total_ht' => '999.00',
    ]);
    $this->beforeRows = $this->memory->rows;
    ($this->openPreview)()->assertOk()->assertSee('100.99 EUR')->assertSee('20.10 EUR')->assertSee('121.09 EUR')
        ->assertViewHas('preview', fn ($p) => in_array('draft.total_ttc.mismatch', array_column($p['blockers'], 'code'), true)
            && in_array('draft.line_total_ht.mismatch', array_column($p['blockers'], 'code'), true));
});

it('escapes client and line content', function () {
    $this->memory->rows['clients'][45]['company_name'] = '<script>client()</script>';
    $this->memory->rows['invoice_items'][1]['description'] = '<script>line()</script>';
    $this->beforeRows = $this->memory->rows;
    ($this->openPreview)()->assertOk()->assertSee('&lt;script&gt;client()&lt;/script&gt;', false)
        ->assertSee('&lt;script&gt;line()&lt;/script&gt;', false)->assertDontSee('<script>client()</script>', false)
        ->assertDontSee('<script>line()</script>', false);
});

it('rechecks stale models against the persisted owner and status', function (string $case) {
    $draft = Invoice::findOrFail(99);
    $this->memory->rows['invoices'][99][$case === 'owner' ? 'user_id' : 'document_status'] = $case === 'owner' ? 5 : 'issued';
    $this->beforeRows = $this->memory->rows;
    try {
        app(TricatelDraftDocumentPreview::class)->build($this->actor, $draft);
        $this->fail('A stale model must not bypass ownership');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe($case === 'owner' ? 403 : 404);
    }
})->with(['owner', 'status']);

it('rejects model instances explicitly bound to another database', function () {
    $draft = Invoice::findOrFail(99)->setConnection('other');
    expect(app(TricatelDraftPreviewScope::class)->allows($this->actor, $draft))->toBeFalse();
});
