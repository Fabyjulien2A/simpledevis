<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SuperPdpPayloadFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $data = SuperPdpPayloadFixture::data();
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create(array_replace(
        array_diff_key($data['seller'], array_flip(['name', 'country_code'])),
        ['company_name' => 'Artisan en franchise', 'vat_regime' => 'franchise', 'vat_number' => null, 'tva_number' => null],
    ));
    $client = $this->owner->clients()->create(array_replace($data['buyer'], [
        'client_type' => 'professional', 'company_name' => 'Client de test', 'siret' => '55210055400013',
    ]));
    $this->invoice = Invoice::create([
        'user_id' => $this->owner->id, 'client_id' => $client->id,
        'french_business_process_type' => 'S1',
        'invoice_number' => $data['invoice_number'], 'date' => $data['date'], 'due_date' => $data['due_date'],
        'document_status' => 'issued', 'calculation_version' => 'decimal-v1', 'status' => 'non_payee', 'amount_paid' => '0.00',
        'subtotal_ht' => '60.00', 'total_tva' => '0.00', 'total_ttc' => '60.00',
    ]);
    $this->invoice->items()->create(array_replace(array_diff_key($data['items'][0], ['id' => true]), [
        'vat_category' => 'E', 'tva_rate' => '0.00', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE',
    ]));
    $this->context = [
        'document_kind' => 'ordinary', 'type_code' => 380, 'currency_code' => 'EUR',
        'seller_electronic_address' => ['scheme' => '0225', 'value' => '732829320_ATELIER'],
        'buyer_electronic_address' => ['scheme' => '0225', 'value' => '552100554_ACHATS'],
        'french_context' => SuperPdpPayloadFixture::frenchContext(),
    ];
    $notes = array_column($this->context['french_context']['notes'], 'note', 'subject_code');
    $this->company->update([
        'invoice_recovery_indemnity_text' => $notes['PMT'],
        'invoice_late_payment_penalties_text' => $notes['PMD'],
        'invoice_early_payment_discount_text' => $notes['AAB'],
    ]);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('maps a stored franchise seller to BT29 BT30 and BT32 without inventing BT31', function (string $siret, string $siren) {
    $this->company->update(['siret' => $siret]);
    $source = app(InvoiceExportDataBuilder::class);
    $data = $source->buildElectronic($this->invoice);
    $captured = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $captured['seller']['tva_number'] = $this->company->fresh()->tva_number;
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot($captured)]);
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build(
        $this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address'], $this->context['french_context'],
    );
    expect($data['seller']['tax_registration_identifier'])->toBe($siren)
        ->and($payload['seller']['tax_registration_identifier'])->toBe($siren)
        ->and($payload['seller']['legal_registration_identifier'])->toBe(['value' => $siren, 'scheme' => '0002'])
        ->and($payload['seller']['identifiers'])->toBe([['value' => $siret, 'scheme' => '0009']])
        ->and($payload['seller'])->not->toHaveKey('vat_identifier')
        ->and($source->build($this->invoice)['seller'])->not->toHaveKey('tax_registration_identifier');
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeTrue()->and($result['eligible'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable']);
})->with([['73282932000074', '732829320'], ['00123456700012', '001234567'], ['00000000000000', '000000000']]);

it('does not prepare BT32 from an absent or malformed SIRET and blocks preparation', function (?string $siret) {
    $this->company->update(['siret' => $siret]);
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    expect($data['seller'])->not->toHaveKey('tax_registration_identifier');
    expect(fn () => app(SuperPdpInvoicePayloadBuilder::class)->build(
        $this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address'], $this->context['french_context'],
    ))->toThrow(InvalidArgumentException::class);
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toContain('seller.tax_registration_identifier.missing')
        ->toContain($siret === null || trim($siret) === '' ? 'seller.siret.missing' : 'seller.siret.invalid');
})->with([[null], [''], [' '], ['732829320'], ['7328293200007'], ['732829320000740'], ['7328293200007A'], ['732829320 0074']]);

it('limits automatic BT32 to a stored franchise without either VAT alias', function (?string $regime, ?string $vat, ?string $legacyVat) {
    $this->company->update(['vat_regime' => $regime, 'vat_number' => $vat, 'tva_number' => $legacyVat]);
    $data = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    expect($data['seller'])->not->toHaveKey('tax_registration_identifier');
})->with([
    ['subject_to_vat', null, null], ['subject_to_vat', 'FR44732829320', 'FR44732829320'],
    [null, null, null], [null, 'FR44732829320', 'FR44732829320'],
    ['franchise', 'FR44732829320', 'FR44732829320'],
    ['franchise', 'FR44732829320', null], ['franchise', null, 'FR44732829320'],
]);

it('does not let manual BT32 bypass a malformed stored SIRET', function () {
    $this->company->update(['siret' => '7328293200007']);
    $this->context['tax_registration_identifier'] = '732829320';
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toContain('seller.siret.invalid', 'seller.tax_registration_identifier.invalid');
});

it('keeps a supplied VAT number and does not add BT32 in the franchise with VAT case', function () {
    $this->company->update(['vat_number' => 'FR44732829320', 'tva_number' => 'FR44732829320']);
    $captured = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $captured['seller']['tva_number'] = $this->company->fresh()->tva_number;
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot($captured)]);
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build(
        $this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address'], $this->context['french_context'],
    );
    expect($payload['seller']['vat_identifier'])->toBe('FR44732829320')
        ->and($payload['seller'])->not->toHaveKey('tax_registration_identifier');
});

it('treats blank VAT aliases as absent without turning the SIREN into a VAT number', function () {
    $this->company->update(['vat_number' => '', 'tva_number' => '   ']);
    expect(app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice)['seller']['tax_registration_identifier'])->toBe('732829320');
    $captured = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $captured['seller']['tva_number'] = $this->company->fresh()->tva_number;
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot($captured)]);
    $payload = app(SuperPdpInvoicePayloadBuilder::class)->build(
        $this->invoice, $this->context['seller_electronic_address'], $this->context['buyer_electronic_address'], $this->context['french_context'],
    );
    expect($payload['seller'])->not->toHaveKey('vat_identifier');
});

it('retains regime conflicts even when BT32 can be prepared', function () {
    $this->context['vat_regime'] = 'subject_to_vat';
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('tax.regime.context_conflict')
        ->not->toContain('seller.tax_registration_identifier.missing');
    expect($result['local_checks_passed'])->toBeFalse();
});

it('does not allow explicit BT32 context to overwrite the prepared identifier', function (mixed $identifier) {
    $this->context['tax_registration_identifier'] = $identifier;
    $result = app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('seller.tax_registration_identifier.invalid')
        ->not->toContain('seller.tax_registration_identifier.missing');
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice)['seller']['tax_registration_identifier'])->toBe('732829320');
})->with([['999999999'], ['FR732829320'], [null], [''], [732829320]]);

it('prepares the identifier with fresh data and only SELECTs without modifying caller or storage', function () {
    $captured = app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice);
    $captured['seller']['tva_number'] = $this->company->fresh()->tva_number;
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => SuperPdpPayloadFixture::partySnapshot($captured)]);
    $this->invoice->load('user.company', 'items', 'client');
    $this->invoice->user->company->siret = '99999999999999';
    $this->invoice->user->company->vat_number = 'FR99999999999';
    $caller = $this->invoice->toArray();
    $tables = ['companies', 'invoices', 'invoice_items'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    DB::connection()->beforeExecuting(function ($sql) {
        if (! preg_match('/^\s*select\b/i', $sql)) {
            throw new RuntimeException('Aucune écriture pendant la préparation de BT-32.');
        }
    });
    expect(app(InvoiceExportDataBuilder::class)->buildElectronic($this->invoice)['seller']['tax_registration_identifier'])->toBe('732829320')
        ->and(app(SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($this->invoice, $this->context)['local_checks_passed'])->toBeTrue()
        ->and($this->invoice->toArray())->toBe($caller)->and($snapshot())->toBe($before);
});
