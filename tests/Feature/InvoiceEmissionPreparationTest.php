<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceElectronicPartyData;
use App\Services\InvoicePartySnapshot;
use App\Services\SuperPdp\InvoiceEmissionContext;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\SuperPdpPayloadFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $data = SuperPdpPayloadFixture::data();
    $this->sellerAddress = ['scheme' => '0225', 'value' => '732829320_ATELIER'];
    $this->buyerAddress = ['scheme' => '0225', 'value' => '552100554_ACHATS'];
    $this->company = $this->owner->company()->create(array_diff_key($data['seller'], ['name' => true]) + [
        'company_name' => 'Atelier historique', 'vat_regime' => 'subject_to_vat',
        'billing_electronic_address' => $this->sellerAddress,
        'invoice_recovery_indemnity_text' => 'PMT explicite',
        'invoice_late_payment_penalties_text' => 'PMD explicite',
        'invoice_early_payment_discount_text' => 'AAB explicite',
    ]);
    $this->client = $this->owner->clients()->create([
        'client_type' => 'professional', 'company_name' => null, 'first_name' => 'Marie', 'last_name' => 'Historique',
        'country_code' => 'FR', 'siret' => '55210055400013', 'address' => '3 rue du Moulin',
        'postal_code' => '69002', 'city' => 'Lyon', 'email' => 'contact@example.invalid',
        'billing_electronic_address' => $this->buyerAddress,
    ]);
    $this->actingAs($this->owner);
    $this->payload = [
        'action' => 'draft', 'emission_profile' => InvoiceEmissionContext::PROFILE,
        'french_business_process_type' => 'S1', 'client_id' => $this->client->id,
        'date' => '2026-11-09', 'due_date' => '2026-12-09',
        'items' => [[
            'description' => 'Prestation', 'quantity' => '1', 'unit_price_ht' => '100',
            'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
        ]],
    ];
});

afterEach(function () {
    Http::assertNothingSent();
});

it('freezes qualified data on both finalization paths while routing stays blocked', function (string $path) {
    $this->post(route('invoices.store'), ['action' => $path === 'direct' ? 'issue' : 'draft'] + $this->payload)
        ->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    if ($path === 'draft') {
        expect($invoice->emission_context)->toBeNull()->and($invoice->party_snapshot)->toBeNull();
        $this->post(route('invoices.finalize', $invoice))->assertSessionHasNoErrors();
        $invoice->refresh();
    }
    expect($invoice->document_status)->toBe('issued')
        ->and($invoice->party_snapshot['seller']['country_code'])->toBe('FR')
        ->and($invoice->party_snapshot['schema_version'])->toBe(2)
        ->and($invoice->emission_context['schema_version'])->toBe(1)
        ->and($invoice->emission_context['type_code'])->toBe(380)
        ->and($invoice->emission_context['document_kind'])->toBe('ordinary')
        ->and($invoice->emission_context['currency_code'])->toBe('EUR')
        ->and($invoice->emission_context['routing_status'])->toBe('not_verified');
    $before = $invoice->getRawOriginal();
    $this->company->update(['country_code' => 'BE', 'company_name' => 'Actuel', 'billing_electronic_address' => ['scheme' => 'EM', 'value' => 'autre@example.invalid']]);
    $this->client->update(['first_name' => 'Actuel', 'last_name' => 'Modifie', 'billing_electronic_address' => null]);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice);
    expect($result['local_checks_passed'])->toBeTrue()->and($result['eligible'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable']);
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build($invoice);
    expect($payload['buyer']['name'])->toBe('Marie Historique')
        ->and($payload['seller']['electronic_address'])->toBe($this->sellerAddress)
        ->and($payload['buyer']['electronic_address'])->toBe($this->buyerAddress)
        ->and($invoice->fresh()->getRawOriginal())->toBe($before);
    $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('emission-diagnostic-title', false);
})->with(['direct', 'draft']);

it('rolls back incomplete qualified invoices without consuming a number or persisting snapshots', function (string $target, string $field, mixed $value) {
    if ($target === 'company') {
        $this->company->update([$field => $value]);
    } else {
        $this->client->update([$field => $value]);
    }
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $beforeCounters = DB::table('invoice_counters')->get()->toJson();
    $this->post(route('invoices.finalize', $invoice))->assertSessionHasErrors();
    $invoice->refresh();
    expect($invoice->document_status)->toBe('draft')->and($invoice->invoice_number)->toBeNull()
        ->and($invoice->party_snapshot)->toBeNull()->and($invoice->emission_context)->toBeNull()
        ->and(DB::table('invoice_counters')->get()->toJson())->toBe($beforeCounters)
        ->and($invoice->items()->count())->toBe(1);
})->with([
    ['company', 'country_code', null], ['company', 'country_code', 'BE'],
    ['company', 'company_name', ''], ['company', 'address', null], ['company', 'postal_code', null],
    ['company', 'city', null], ['company', 'siret', null], ['company', 'vat_regime', null],
    ['company', 'vat_number', null], ['company', 'invoice_recovery_indemnity_text', null],
    ['company', 'invoice_late_payment_penalties_text', null], ['company', 'invoice_early_payment_discount_text', null],
    ['company', 'billing_electronic_address', null], ['client', 'billing_electronic_address', null],
    ['client', 'client_type', 'individual'], ['client', 'country_code', 'BE'],
    ['client', 'address', null], ['client', 'postal_code', null], ['client', 'city', null],
]);

it('rejects a direct issue atomically when the billing address is missing', function () {
    $this->client->update(['billing_electronic_address' => null]);
    $this->post(route('invoices.store'), ['action' => 'issue'] + $this->payload)->assertSessionHasErrors();
    expect(Invoice::count())->toBe(0)->and(DB::table('invoice_items')->count())->toBe(0)
        ->and(DB::table('invoice_counters')->count())->toBe(0);
});

it('preserves ordinary finalization without inferring an electronic qualification or contact address', function () {
    $this->company->update(['country_code' => null, 'billing_electronic_address' => null]);
    $this->client->update(['billing_electronic_address' => null]);
    unset($this->payload['emission_profile']);
    $this->post(route('invoices.store'), ['action' => 'issue'] + $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    expect($invoice->isIssued())->toBeTrue()->and($invoice->emission_context)->toBeNull()
        ->and($invoice->emission_profile)->toBeNull()->and($invoice->party_snapshot['seller'])->not->toHaveKey('country_code');
    $codes = array_column(app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice)['blockers'], 'code');
    expect($codes)->toContain('context.document_kind.missing', 'seller.electronic_address.scheme.missing', 'buyer.electronic_address.value.missing');
});

it('does not accept an arbitrary emission scope or injected frozen context', function () {
    $this->post(route('invoices.store'), ['emission_profile' => 'credit_note'] + $this->payload)->assertSessionHasErrors('emission_profile');
    $this->post(route('invoices.store'), ['emission_context' => ['routing_status' => 'verified']] + $this->payload)->assertSessionHasNoErrors();
    expect(Invoice::sole()->emission_context)->toBeNull();
});

it('keeps scope editable only on drafts and freezes context against stale models', function () {
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $stale = $invoice->fresh();
    $this->put(route('invoices.update', $invoice), ['emission_profile' => null] + $this->payload)->assertSessionHasNoErrors();
    expect($invoice->fresh()->emission_profile)->toBeNull();
    $this->put(route('invoices.update', $invoice), $this->payload)->assertSessionHasNoErrors();
    $this->post(route('invoices.finalize', $invoice))->assertSessionHasNoErrors();
    expect(fn () => $stale->update(['emission_profile' => null]))->toThrow(ValidationException::class);
    $stale->emission_context = ['schema_version' => 99];
    expect(fn () => $stale->save())->toThrow(ValidationException::class);
    $this->put(route('invoices.update', $invoice), ['emission_profile' => null] + $this->payload)->assertForbidden();
});

it('rejects caller overrides and corrupt contexts without falling back to current addresses', function (string $corruption) {
    $this->post(route('invoices.store'), ['action' => 'issue'] + $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    if ($corruption === 'caller') {
        $other = ['scheme' => 'EM', 'value' => 'other@example.invalid'];
        $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice, ['buyer_electronic_address' => $other]);
        expect(fn () => app(SuperPdpInvoicePayloadBuilder::class)->build($invoice, null, $other))->toThrow(InvalidArgumentException::class);
    } else {
        $context = $invoice->emission_context;
        match ($corruption) {
            'version' => $context['schema_version'] = 99,
            'foreign' => $context['buyer_electronic_address']['value'] = '732829320',
            'routing' => $context['routing_status'] = 'verified',
            'type' => $context['type_code'] = 381,
            'extra' => $context['extra'] = true,
            'missing' => $context = null,
        };
        DB::table('invoices')->where('id', $invoice->id)->update(['emission_context' => $context === null ? null : json_encode($context)]);
        $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice);
        expect(fn () => app(SuperPdpInvoicePayloadBuilder::class)->build($invoice))->toThrow(InvalidArgumentException::class);
    }
    expect($result['eligible'])->toBeFalse()->and(array_column($result['blockers'], 'code'))
        ->toContain('routing.verification_unavailable', 'invoice.emission_context.invalid');
})->with(['caller', 'version', 'foreign', 'routing', 'type', 'extra', 'missing']);

it('reconstructs names exclusively from either historical snapshot version', function (int $version, array $buyer, ?string $expected) {
    $snapshot = InvoicePartySnapshot::capture([], $buyer, $version);
    $data = InvoiceElectronicPartyData::resolve(['party_snapshot' => json_encode($snapshot), 'buyer' => ['full_name' => 'Profil actuel interdit']]);
    expect($data['buyer']['full_name'] ?? null)->toBe($expected);
})->with([1, 2])->with([
    [['first_name' => ' Marie ', 'last_name' => ' Historique '], 'Marie Historique'],
    [['first_name' => 'Marie'], 'Marie'],
    [['last_name' => 'Historique'], 'Historique'],
    [['full_name' => 'Nom complet historique', 'first_name' => 'Autre'], 'Nom complet historique'],
    [['full_name' => '', 'first_name' => 'Marie', 'last_name' => 'Historique'], 'Marie Historique'],
    [[], null],
]);
