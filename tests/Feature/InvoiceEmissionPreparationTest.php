<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceElectronicPartyData;
use App\Services\InvoiceExportDataBuilder;
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

it('enforces the same 35 character finalization limit for the French electronic profile', function ($length) {
    $this->post(route('invoices.store'), ['action' => 'issue'] + $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->getRawOriginal();
    try {
        DB::transaction(function () use ($invoice, $length) {
            DB::table('invoices')->where('id', $invoice->id)->update(['invoice_number' => str_repeat('N', $length)]);
            app(\App\Services\SuperPdp\InvoiceEmissionPreparation::class)->assertCompleteForFinalization($invoice);
            // Roll back the synthetic number after checking the accepted boundary too.
            throw new LogicException('boundary_checked');
        });
    } catch (ValidationException $exception) {
        expect($length)->toBe(36)->and($exception->errors())->toHaveKey('invoice_number');
    } catch (LogicException $exception) {
        expect($length)->toBe(35)->and($exception->getMessage())->toBe('boundary_checked');
    }
    expect($invoice->fresh()->getRawOriginal())->toBe($before);
})->with([35, 36]);

it('rejects an incompatible electronic profile before saving a new draft but preserves explicit PDF creation', function (string $party, string $field, mixed $value) {
    ($party === 'seller' ? $this->company : $this->client)->update([$field => $value]);
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasErrors('emission_profile');
    expect(Invoice::count())->toBe(0)->and(DB::table('invoice_items')->count())->toBe(0)
        ->and(DB::table('invoice_counters')->count())->toBe(0);
    $this->post(route('invoices.store'), ['action' => 'issue', 'emission_profile' => null] + $this->payload)->assertSessionHasNoErrors();
    expect(Invoice::sole()->emission_profile)->toBeNull()->and(Invoice::sole()->emission_context)->toBeNull();
})->with([
    ['buyer', 'client_type', 'individual'], ['buyer', 'country_code', 'BE'],
    ['seller', 'country_code', 'BE'], ['seller', 'country_code', null],
]);

it('rejects an incompatible profile on draft update without changing lines and permits explicit PDF fallback', function () {
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $draft = Invoice::sole();
    $before = $draft->getRawOriginal();
    $lines = $draft->items()->get()->toJson();
    $this->client->update(['client_type' => 'individual']);
    $this->put(route('invoices.update', $draft), $this->payload)->assertSessionHasErrors('emission_profile');
    expect($draft->fresh()->getRawOriginal())->toBe($before)->and($draft->items()->get()->toJson())->toBe($lines);
    $this->put(route('invoices.update', $draft), ['emission_profile' => null] + $this->payload)->assertSessionHasNoErrors();
    expect($draft->fresh()->emission_profile)->toBeNull();
});

it('persists company and client form fields and freezes all electronic sources for a new invoice', function (string $path) {
    // Existing isolated fixtures only: no identification values are written to real companies.
    $sellerVat = $this->company->vat_number;
    $this->post(route('company.update'), [
        'tva_number' => strtolower($sellerVat), 'vat_regime' => 'subject_to_vat',
        'country_code' => 'FR', 'siret' => $this->company->siret,
        'billing_electronic_address' => $this->sellerAddress,
        'invoice_recovery_indemnity_text' => 'PMT saisie', 'invoice_late_payment_penalties_text' => 'PMD saisie',
        'invoice_early_payment_discount_text' => 'AAB saisie',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $clientInput = $this->client->only(['client_type', 'company_name', 'first_name', 'last_name', 'country_code', 'siret',
        'address', 'postal_code', 'city', 'email', 'billing_electronic_address']);
    $clientInput['vat_number'] = 'FR00552100554';
    $this->post(route('clients.store'), $clientInput)->assertRedirect(route('clients.index'))->assertSessionHasNoErrors();
    $client = $this->owner->clients()->orderByDesc('id')->firstOrFail();
    expect($this->company->fresh()->vat_number)->toBe($sellerVat)
        ->and($this->company->fresh()->tva_number)->toBe($sellerVat)->and($client->vat_number)->toBe($clientInput['vat_number']);
    $this->post(route('invoices.store'), ['action' => $path === 'direct' ? 'issue' : 'draft', 'client_id' => $client->id] + $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    if ($path === 'draft') {
        $this->post(route('invoices.finalize', $invoice))->assertSessionHasNoErrors();
    }
    $invoice->refresh();
    $snapshot = $invoice->party_snapshot;
    expect($snapshot['seller']['siret'])->toBe($this->company->siret)
        ->and($snapshot['seller']['vat_number'])->toBe($sellerVat)->and($snapshot['seller']['tva_number'])->toBe($sellerVat)
        ->and($snapshot['seller']['vat_regime'])->toBe('subject_to_vat')
        ->and($snapshot['seller']['invoice_recovery_indemnity_text'])->toBe('PMT saisie')
        ->and($snapshot['buyer']['siret'])->toBe($client->siret)->and($snapshot['buyer']['vat_number'])->toBe($clientInput['vat_number'])
        ->and($snapshot['buyer']['client_type'])->toBe('professional')
        ->and($invoice->emission_context['seller_electronic_address'])->toBe($this->sellerAddress)
        ->and($invoice->emission_context['buyer_electronic_address'])->toBe($this->buyerAddress)
        ->and($invoice->subtotal_ht)->toBe('100.00')->and($invoice->total_tva)->toBe('20.00')->and($invoice->total_ttc)->toBe('120.00');
    $source = app(InvoiceExportDataBuilder::class);
    $dataBefore = $source->buildElectronic($invoice);
    $rawBefore = $invoice->getRawOriginal();
    $this->company->update(['vat_number' => null, 'tva_number' => null, 'siret' => null, 'vat_regime' => null,
        'country_code' => 'BE', 'billing_electronic_address' => null]);
    $client->update(['siret' => null, 'vat_number' => null, 'billing_electronic_address' => null]);
    expect($invoice->fresh()->getRawOriginal())->toBe($rawBefore)
        ->and($source->buildElectronic($invoice))->toBe($dataBefore)
        ->and(app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice)['local_checks_passed'])->toBeTrue();
})->with(['direct', 'draft']);

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
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    // Profile data may become incomplete or incompatible after the draft was saved.
    if ($target === 'company') {
        $this->company->update([$field => $value]);
    } else {
        $this->client->update([$field => $value]);
    }
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
