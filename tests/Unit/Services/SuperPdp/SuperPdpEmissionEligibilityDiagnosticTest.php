<?php

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('Aucun SQL autorisé dans les tests unitaires du diagnostic.');
    });
    $this->data = SuperPdpPayloadFixture::data();
    $this->data['french_business_process_type'] = 'S1';
    $this->data['company_vat_regime'] = 'subject_to_vat';
    $this->data['company_invoice_mentions'] = array_column(SuperPdpPayloadFixture::frenchContext()['notes'], 'note', 'subject_code');
    $this->data['buyer'] = array_replace($this->data['buyer'], [
        'client_type' => 'professional', 'company_name' => 'Entreprise de test', 'siret' => '55210055400013',
    ]);
    $this->context = [
        'document_kind' => 'ordinary', 'type_code' => 380, 'currency_code' => 'EUR', 'vat_regime' => 'subject_to_vat',
        'seller_electronic_address' => ['scheme' => '0225', 'value' => '732829320_ATELIER'],
        'buyer_electronic_address' => ['scheme' => '0225', 'value' => '552100554_ACHATS'],
        'french_context' => SuperPdpPayloadFixture::frenchContext(),
    ];
    // Instance simulée : seule la lecture est remplacée, jamais les validateurs.
    $this->invoice = new Invoice;
    $this->invoice->id = 42;
    $this->invoice->exists = true;
    $this->source = $this->createMock(InvoiceExportDataBuilder::class);
    $this->source->method('buildElectronic')->willReturnCallback(fn () => $this->data + ['party_snapshot' => SuperPdpPayloadFixture::partySnapshot($this->data)]);
    $fiscal = app(InvoiceFiscalDataPreparer::class);
    $builder = new SuperPdpInvoicePayloadBuilder($this->source, $fiscal, app(SuperPdpInvoicePayloadValidator::class));
    $this->diagnostic = new SuperPdpEmissionEligibilityDiagnostic($this->source, $fiscal, $builder);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('passes the local ordinary checks while always reporting unavailable routing', function (string $mode) {
    $this->data['french_business_process_type'] = $mode;
    $this->context['french_context']['business_process_type'] = $mode;
    $beforeData = $this->data;
    $beforeContext = $this->context;
    $beforeInvoice = $this->invoice->getAttributes();
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);

    expect($result['eligible'])->toBeFalse()
        ->and($result['local_checks_passed'])->toBeTrue()
        ->and(array_column($result['blockers'], 'code'))->toBe(['routing.verification_unavailable'])
        ->and($result['blockers'][0]['category'])->toBe('verification_unavailable')
        ->and($result['blockers'][0]['message'])->not->toBeEmpty()
        ->and($this->data)->toBe($beforeData)
        ->and($this->context)->toBe($beforeContext)
        ->and($this->invoice->getAttributes())->toBe($beforeInvoice)
        ->and($result)->not->toHaveKey('payload');
})->with(['B1', 'S1', 'M1']);

it('does not trust a caller routing assertion or an electronic address', function () {
    $this->context['routing_verified'] = true;
    $this->context['directory_identifier'] = '552100554';
    expect($this->diagnostic->diagnose($this->invoice, $this->context)['eligible'])->toBeFalse();
});

it('does not require manual regime context when the stored snapshot provides it', function () {
    unset($this->context['vat_regime']);
    expect($this->diagnostic->diagnose($this->invoice, $this->context)['local_checks_passed'])->toBeTrue();
});

it('reports explicit context mismatches without replacing the stored snapshot', function (mixed $value) {
    $this->context['vat_regime'] = $value;
    $before = $this->data;
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    $blocker = collect($result['blockers'])->firstWhere('code', 'tax.regime.context_conflict');
    expect($blocker['category'])->toBe('invalid_data')->and($blocker['field'])->toBe('vat_regime')
        ->and($result['local_checks_passed'])->toBeFalse()->and($this->data)->toBe($before);
})->with([['franchise'], [null], [''], ['unknown'], [false], [['subject_to_vat']]]);

it('does not fall back to context if the snapshot lacks its company regime', function () {
    unset($this->data['company_vat_regime']);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('context.vat_regime.missing', 'tax.regime.context_conflict');
});

it('collects independent missing data rather than stopping at the first field', function () {
    $this->data['company_vat_regime'] = null;
    $this->data['invoice_number'] = null;
    $this->data['date'] = null;
    $this->data['seller']['address'] = '';
    $this->data['buyer']['siret'] = null;
    $result = $this->diagnostic->diagnose($this->invoice);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toContain(
            'invoice.invoice_number.missing', 'invoice.date.missing', 'seller.address.missing', 'buyer.siret.missing',
            'context.document_kind.missing', 'context.type_code.missing', 'context.currency_code.missing', 'context.vat_regime.missing',
            'seller.electronic_address.scheme.missing', 'buyer.electronic_address.value.missing',
        );
});

it('reports excluded document scope without silently coercing it', function (string $field, mixed $value) {
    $this->context[$field] = $value;
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and($result['blockers'])->toContain([
            'code' => 'context.'.$field.'.unsupported', 'category' => 'unsupported', 'field' => $field,
            'message' => 'Ce diagnostic couvre uniquement les factures ordinaires de type 380 en EUR.',
        ]);
})->with([['document_kind', 'deposit'], ['type_code', 381], ['type_code', 386], ['type_code', '380'], ['currency_code', 'USD']]);

it('reports unsupported stored invoice cases', function (string $path, mixed $value, string $code) {
    data_set($this->data, $path, $value);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toContain($code);
})->with([
    ['document_status', 'draft', 'invoice.not_issued'],
    ['calculation_version', 'decimal-v2', 'invoice.calculation_version.unsupported'],
    ['calculation_version', null, 'invoice.calculation_version.missing'],
    ['payments', [1], 'invoice.payments.unsupported'],
    ['issued_credit_notes', [3], 'invoice.credit_notes.unsupported'],
    ['amount_paid', '0.01', 'invoice.settlement.unsupported'],
    ['amount_paid', 'invalid', 'invoice.amount_paid.invalid'],
    ['status', 'payee', 'invoice.settlement.unsupported'],
    ['status', 'partiellement_payee', 'invoice.settlement.unsupported'],
    ['buyer.client_type', 'individual', 'buyer.client_type.unsupported'],
    ['buyer.country_code', 'BE', 'buyer.country.unsupported'],
    ['seller.country_code', 'BE', 'seller.country.unsupported'],
]);

it('never treats missing settlement evidence as an empty history', function () {
    unset($this->data['payments'], $this->data['issued_credit_notes']);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('invoice.payments.unknown', 'invoice.credit_notes.unknown')
        ->and($result['local_checks_passed'])->toBeFalse();
});

it('reuses the fiscal preparer for inconsistent or unsupported line data', function (string $path, mixed $value) {
    data_set($this->data, $path, $value);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('fiscal.invalid')
        ->and($result['local_checks_passed'])->toBeFalse();
})->with([
    ['totals.total_ttc', '72.01'], ['items.0.line_total_ht', '60.01'],
    ['items.0.unit_code', 'UNKNOWN'], ['items.0.tva_rate', '19.00'],
    ['items.0.vat_category', null], ['items', []], ['items.0.quantity', 1.5],
]);

it('reuses the payload builder for remaining identity dates and structure errors', function (string $path, mixed $value) {
    data_set($this->data, $path, $value);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('payload.invalid')
        ->and($result['local_checks_passed'])->toBeFalse();
})->with([
    ['seller.siret', 'wrong'], ['buyer.siret', '123'], ['buyer.company_name', 'Client sans nom'],
    ['seller.email', 'invalid'], ['buyer.vat_number', 'invalid'], ['date', '2026-02-30'],
]);

it('requires a due date or payment terms through the existing builder', function () {
    $this->data['due_date'] = null;
    $this->data['seller']['payment_terms'] = null;
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain('payload.invalid');
});

it('reuses the French context validation for missing duplicate and unsupported mentions', function (string $path, mixed $value) {
    data_set($this->context, $path, $value);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('french_context.invalid')
        ->and($result['local_checks_passed'])->toBeFalse();
})->with([
    ['french_context', 'S1'],
    ['french_context.notes', []], ['french_context.notes.0.note', ' '],
    ['french_context.notes.0.subject_code', 'AAB'], ['french_context.notes.0.subject_code', 'XYZ'],
]);

it('requires a qualified regime independently of VAT rate and identifiers', function () {
    $this->data['company_vat_regime'] = null;
    unset($this->context['vat_regime']);
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain('context.vat_regime.missing');
    $this->context['vat_regime'] = 'reverse_charge';
    $this->data['company_vat_regime'] = 'reverse_charge';
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain('tax.regime.unsupported');
});

it('requires the taxable seller VAT identifier', function (mixed $vat, string $code) {
    $this->data['seller']['vat_number'] = $vat;
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain($code);
})->with([[null, 'seller.vat_number.missing'], ['invalid', 'seller.vat_number.invalid']]);

it('prepares BT32 only from captured VAT absence without changing the source', function () {
    $this->data['company_vat_regime'] = 'franchise';
    $this->context['vat_regime'] = 'franchise';
    $this->data['seller']['vat_number'] = null;
    $this->data['items'][0]['vat_category'] = 'E';
    $this->data['items'][0]['tva_rate'] = '0.00';
    $this->data['items'][0]['exemption_reason_code'] = 'VATEX-FR-FRANCHISE';
    $this->data['totals'] = ['subtotal_ht' => '60.00', 'total_tva' => '0.00', 'total_ttc' => '60.00'];
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain('seller.tax_registration_identifier.missing');

    $this->context['tax_registration_identifier'] = '732829320';
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain('seller.tax_registration_identifier.missing');
    $this->data['seller']['tva_number'] = null;
    $before = $this->data;
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeTrue()->and($result['eligible'])->toBeFalse()
        ->and($this->data)->toBe($before)->and($this->data['seller'])->not->toHaveKey('tax_registration_identifier');
    $this->context['tax_registration_identifier'] = '999999999';
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain('seller.tax_registration_identifier.invalid');
});

it('does not relabel ordinary VAT lines as franchise', function () {
    $this->data['company_vat_regime'] = 'franchise';
    $this->context['vat_regime'] = 'franchise';
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain('tax.franchise.line_invalid');
});

it('does not qualify a franchise line under a different declared regime', function () {
    $this->data['items'][0]['exemption_reason_code'] = 'VATEX-FR-FRANCHISE';
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect(array_column($result['blockers'], 'code'))->toContain('tax.regime.inconsistent');
});

it('keeps monetary discrepancies in the invalid data category', function () {
    $this->data['totals']['total_ttc'] = '72.01';
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    $error = collect($result['blockers'])->firstWhere('field', 'total_ttc');
    expect($error['code'])->toBe('fiscal.invalid')->and($error['category'])->toBe('invalid_data');
});

it('reports unavailable client identities without inferring one', function () {
    $this->data['buyer'] = [];
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))
        ->toContain('buyer.name.missing', 'buyer.client_type.missing', 'buyer.siret.missing');
});

it('reports unreadable persisted casts without leaking an exception as a business result', function () {
    $source = $this->createMock(InvoiceExportDataBuilder::class);
    $source->method('buildElectronic')->willThrowException(new InvalidArgumentException('Donnée interne'));
    $diagnostic = new SuperPdpEmissionEligibilityDiagnostic($source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadBuilder::class));
    expect(array_column($diagnostic->diagnose($this->invoice)['blockers'], 'code'))->toContain('invoice.data_unreadable');
});

it('handles malformed electronic addresses as blockers', function (mixed $address, string $code) {
    $this->context['buyer_electronic_address'] = $address;
    expect(array_column($this->diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))->toContain($code);
})->with([['invalid', 'buyer.electronic_address.invalid'], [[], 'buyer.electronic_address.scheme.missing'], [['scheme' => '0225', 'value' => 123], 'payload.invalid']]);

it('does not read unpersisted or missing invoices', function () {
    $source = $this->createMock(InvoiceExportDataBuilder::class);
    $source->expects($this->never())->method('buildElectronic');
    $diagnostic = new SuperPdpEmissionEligibilityDiagnostic($source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadBuilder::class));
    expect(array_column($diagnostic->diagnose(new Invoice)['blockers'], 'code'))->toContain('invoice.not_persisted');

    $source = $this->createMock(InvoiceExportDataBuilder::class);
    $source->method('buildElectronic')->willThrowException(new ModelNotFoundException);
    $diagnostic = new SuperPdpEmissionEligibilityDiagnostic($source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadBuilder::class));
    expect(array_column($diagnostic->diagnose($this->invoice)['blockers'], 'code'))->toContain('invoice.not_found');
});

it('does not use payment terms or context as a fallback when stored mentions are absent', function () {
    unset($this->data['company_invoice_mentions']);
    $this->data['seller']['payment_terms'] = '40 € dus en cas de retard de paiement. 10% du montant TTC. Néant.';
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toContain(
            'company.mentions.PMT.missing', 'company.mentions.PMD.missing', 'company.mentions.AAB.missing',
            'company.mentions.PMT.context_conflict', 'company.mentions.PMD.context_conflict', 'company.mentions.AAB.context_conflict',
        );
});

it('rejects invalid persisted mention types or lengths', function (mixed $value) {
    $this->data['company_invoice_mentions']['PMT'] = $value;
    unset($this->context['french_context']['notes']);
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    $blocker = collect($result['blockers'])->firstWhere('code', is_string($value) ? 'company.mentions.PMT.invalid' : 'invoice.party_snapshot.invalid');
    expect($blocker['category'])->toBe('invalid_data')
        ->and($blocker['field'])->toBe(is_string($value) ? 'party_snapshot.seller.invoice_recovery_indemnity_text' : 'party_snapshot')
        ->and($result['local_checks_passed'])->toBeFalse();
})->with([[42], [false], [[]], [str_repeat('é', 2001)]]);

it('does not mask malformed explicit notes with valid persisted notes', function (mixed $notes) {
    $this->context['french_context']['notes'] = $notes;
    $result = $this->diagnostic->diagnose($this->invoice, $this->context);
    expect($result['local_checks_passed'])->toBeFalse()
        ->and(array_column($result['blockers'], 'code'))->toContain('french_context.invalid');
})->with([[null], ['texte'], [[]], [['PMT' => 'texte']], [[null]], [[['subject_code' => []]]]]);

it('blocks a persisted source that omits the snapshot metadata entirely', function () {
    $source = $this->createMock(InvoiceExportDataBuilder::class);
    $source->method('buildElectronic')->willReturn($this->data);
    $diagnostic = new SuperPdpEmissionEligibilityDiagnostic($source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadBuilder::class));
    expect(array_column($diagnostic->diagnose($this->invoice, $this->context)['blockers'], 'code'))
        ->toContain('invoice.party_snapshot.missing');
});
