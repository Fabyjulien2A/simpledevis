<?php

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->data = SuperPdpPayloadFixture::data();
    $this->invoice = (new Invoice)->fill([
        'document_status' => 'issued', 'invoice_number' => 'FAC-2026-0001', 'date' => '2026-11-05',
    ]);
    $this->address = ['scheme' => '0225', 'value' => '732829320_ATELIER'];
    $this->source = $this->createMock(InvoiceExportDataBuilder::class);
    $this->source->expects($this->never())->method('build');
    $this->source->method('buildElectronic')->willReturnCallback(fn () => $this->data);
    $this->builder = new SuperPdpInvoicePayloadBuilder($this->source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class));
});

it('builds the exact generic EN16931 payload with decimal strings', function () {
    expect($this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toBe([
        'number' => 'FAC-2026-0001', 'issue_date' => '2026-11-05', 'type_code' => 380, 'currency_code' => 'EUR',
        'process_control' => ['specification_identifier' => 'urn:cen.eu:en16931:2017', 'business_process_type' => 'S1'],
        'notes' => SuperPdpPayloadFixture::frenchContext()['notes'],
        'seller' => [
            'name' => 'Atelier Martin',
            'legal_registration_identifier' => ['value' => '732829320', 'scheme' => '0002'],
            'identifiers' => [['value' => '73282932000074', 'scheme' => '0009']],
            'electronic_address' => $this->address,
            'postal_address' => ['address_line1' => '12 rue des Artisans', 'post_code' => '75012', 'city' => 'Paris', 'country_code' => 'FR'],
            'vat_identifier' => 'FR44732829320',
        ],
        'buyer' => [
            'name' => 'Marie Dupont',
            'postal_address' => ['address_line1' => '3 rue du Moulin', 'post_code' => '69002', 'city' => 'Lyon', 'country_code' => 'FR'],
        ],
        'lines' => [[
            'identifier' => '1', 'invoiced_quantity' => '1.50', 'invoiced_quantity_code' => 'HUR', 'net_amount' => '60.00',
            'price_details' => ['item_net_price' => '40.00', 'item_price_base_quantity' => '1.00', 'quantity_unit_code' => 'HUR'],
            'item_information' => ['name' => 'Réparation'],
            'vat_information' => ['invoiced_item_vat_category_code' => 'S', 'invoiced_item_vat_rate' => '20.00'],
        ]],
        'vat_break_down' => [[
            'vat_category_code' => 'S', 'vat_category_rate' => '20.00',
            'vat_category_taxable_amount' => '60.00', 'vat_category_tax_amount' => '12.00',
        ]],
        'totals' => [
            'sum_invoice_lines_amount' => '60.00', 'total_without_vat' => '60.00',
            'total_vat_amount' => ['value' => '12.00', 'currency_code' => 'EUR'],
            'total_with_vat' => '72.00', 'amount_due_for_payment' => '72.00',
        ],
        'payment_due_date' => '2026-12-05', 'payment_terms' => 'Paiement à trente jours.',
    ]);
});

it('delegates verification to the fiscal preparer with the exact stored data', function () {
    $fiscal = $this->createMock(InvoiceFiscalDataPreparer::class);
    $fiscal->expects($this->once())->method('prepare')
        ->with($this->data['items'], $this->data['totals'], 'decimal-v1')
        ->willReturn(app(InvoiceFiscalDataPreparer::class)->prepare($this->data['items'], $this->data['totals'], 'decimal-v1'));

    $payload = (new SuperPdpInvoicePayloadBuilder($this->source, $fiscal, app(SuperPdpInvoicePayloadValidator::class)))->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect($payload['totals']['total_with_vat'])->toBe('72.00');
});

it('maps every supported rate with fractional quantities and the selected unit', function (string $rate, string $vat, string $ttc, string $unit) {
    $this->data['items'][0]['tva_rate'] = $rate;
    $this->data['items'][0]['unit_code'] = $unit;
    $this->data['totals'] = ['subtotal_ht' => '60.00', 'total_tva' => $vat, 'total_ttc' => $ttc];
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect($payload['lines'][0]['invoiced_quantity'])->toBe('1.50')
        ->and($payload['lines'][0]['invoiced_quantity_code'])->toBe($unit)
        ->and($payload['lines'][0]['price_details']['quantity_unit_code'])->toBe($unit)
        ->and($payload['lines'][0]['vat_information']['invoiced_item_vat_rate'])->toBe($rate)
        ->and($payload['vat_break_down'][0]['vat_category_tax_amount'])->toBe($vat)
        ->and($payload['totals']['amount_due_for_payment'])->toBe($ttc);
})->with([
    ['20.00', '12.00', '72.00', 'HUR'], ['10.00', '6.00', '66.00', 'C62'],
    ['5.50', '3.30', '63.30', 'MTR'], ['2.10', '1.26', '61.26', 'HUR'],
]);

it('preserves explicit franchise and other exemption justifications on lines and breakdowns', function (?string $code, ?string $reason) {
    $this->data['items'][0] = array_replace($this->data['items'][0], [
        'vat_category' => 'E', 'tva_rate' => '0.00', 'exemption_reason_code' => $code, 'exemption_reason' => $reason,
    ]);
    $this->data['totals'] = ['subtotal_ht' => '60.00', 'total_tva' => '0.00', 'total_ttc' => '60.00'];
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    $lineVat = $payload['lines'][0]['vat_information'];
    $group = $payload['vat_break_down'][0];
    expect($lineVat['invoiced_item_vat_category_code'])->toBe('E')
        ->and($group['vat_category_code'])->toBe('E')
        ->and($group['vat_category_tax_amount'])->toBe('0.00');
    foreach (['exemption_reason_code' => $code, 'exemption_reason' => $reason] as $key => $value) {
        if ($value === null) {
            expect($lineVat)->not->toHaveKey($key)->and($group)->not->toHaveKey('vat_'.$key);
        } else {
            expect($lineVat[$key])->toBe($value)->and($group['vat_'.$key])->toBe($value);
        }
    }
})->with([
    ['VATEX-FR-FRANCHISE', null], ['VATEX-EU-132', 'Exonération explicitement enregistrée'],
    [null, 'Motif explicite conservé'],
]);

it('keeps zero rating distinct from exemption without adding reasons', function () {
    $this->data['items'][0]['vat_category'] = 'Z';
    $this->data['items'][0]['tva_rate'] = '0.00';
    $this->data['totals'] = ['subtotal_ht' => '60.00', 'total_tva' => '0.00', 'total_ttc' => '60.00'];
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect($payload['lines'][0]['vat_information'])->toBe([
        'invoiced_item_vat_category_code' => 'Z', 'invoiced_item_vat_rate' => '0.00',
    ])->and($payload['vat_break_down'][0])->toBe([
        'vat_category_code' => 'Z', 'vat_category_rate' => '0.00',
        'vat_category_taxable_amount' => '60.00', 'vat_category_tax_amount' => '0.00',
    ]);
});

it('rounds VAT on each breakdown and preserves mixed categories and rates', function () {
    $base = array_replace($this->data['items'][0], ['quantity' => '1.00', 'unit_price_ht' => '0.03', 'line_total_ht' => '0.03']);
    $this->data['items'] = [$base, $base, $base,
        array_replace($base, ['unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'tva_rate' => '10.00']),
        array_replace($base, ['quantity' => '1.50', 'line_total_ht' => '0.05', 'tva_rate' => '5.50']),
        array_replace($base, ['unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'tva_rate' => '2.10']),
        array_replace($base, ['vat_category' => 'E', 'tva_rate' => '0.00', 'exemption_reason' => 'Justification']),
        array_replace($base, ['vat_category' => 'Z', 'tva_rate' => '0.00']),
    ];
    $this->data['totals'] = ['subtotal_ht' => '200.20', 'total_tva' => '12.12', 'total_ttc' => '212.32'];
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect(array_column($payload['lines'], 'identifier'))->toBe(['1', '2', '3', '4', '5', '6', '7', '8'])
        ->and(array_column($payload['vat_break_down'], 'vat_category_taxable_amount'))->toBe(['0.09', '100.00', '0.05', '100.00', '0.03', '0.03'])
        ->and(array_column($payload['vat_break_down'], 'vat_category_tax_amount'))->toBe(['0.02', '10.00', '0.00', '2.10', '0.00', '0.00'])
        ->and($payload['totals']['total_vat_amount']['value'])->toBe('12.12')
        ->and($payload['totals']['amount_due_for_payment'])->toBe('212.32');
});

it('uses HALF_UP for a fractional line and its VAT breakdown', function () {
    $this->data['items'][0] = array_replace($this->data['items'][0], [
        'unit_price_ht' => '0.03', 'line_total_ht' => '0.05', 'tva_rate' => '10.00',
    ]);
    $this->data['totals'] = ['subtotal_ht' => '0.05', 'total_tva' => '0.01', 'total_ttc' => '0.06'];
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect($payload['lines'][0]['net_amount'])->toBe('0.05')
        ->and($payload['vat_break_down'][0]['vat_category_tax_amount'])->toBe('0.01');
});

it('rejects historical and unknown calculation versions', function (?string $version, string $exception) {
    $this->data['calculation_version'] = $version;
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow($exception);
})->with([[null, ValidationException::class], ['decimal-v2', InvalidArgumentException::class]]);

it('rejects absent fiscal data', function (string $key) {
    unset($this->data[$key]);
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(ValidationException::class);
})->with(['items', 'totals', 'calculation_version']);

it('rejects invalid or missing line data without guessing fiscal metadata', function (string $key, mixed $value) {
    if ($value === null) {
        unset($this->data['items'][0][$key]);
    } else {
        $this->data['items'][0][$key] = $value;
    }
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(ValidationException::class);
})->with([
    ['description', null], ['quantity', null], ['quantity', '0.00'], ['quantity', 1.5],
    ['quantity', '1e2'], ['unit_price_ht', null], ['unit_price_ht', '40,00'], ['unit_price_ht', '-1.00'],
    ['unit_code', null], ['unit_code', 'XXX'], ['vat_category', null], ['vat_category', 'AE'],
    ['vat_category', 'E'], ['vat_category', 'Z'], ['tva_rate', null], ['tva_rate', '0.00'], ['tva_rate', '19.00'],
    ['exemption_reason', 'Incompatible avec S'], ['line_total_ht', null], ['line_total_ht', '60.01'],
]);

it('rejects each inconsistent stored total', function (string $field) {
    $this->data['totals'][$field] = '0.01';
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(ValidationException::class);
})->with(['subtotal_ht', 'total_tva', 'total_ttc']);

it('rejects an exemption without a justification or with contradictory group reasons', function (bool $conflict) {
    $line = array_replace($this->data['items'][0], ['vat_category' => 'E', 'tva_rate' => '0.00']);
    $this->data['items'] = $conflict
        ? [array_replace($line, ['exemption_reason' => 'A']), array_replace($line, ['exemption_reason' => 'B'])]
        : [$line];
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(ValidationException::class);
})->with([false, true]);

it('requires positive proof that settlement scenarios are absent', function (string $key, mixed $value) {
    if ($value === null) {
        unset($this->data[$key]);
    } else {
        $this->data[$key] = $value;
    }
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(InvalidArgumentException::class);
})->with([
    ['payments', [1]], ['payments', null], ['issued_credit_notes', [1]], ['issued_credit_notes', null],
    ['amount_paid', '10.00'], ['amount_paid', '-1.00'], ['amount_paid', 0.0], ['amount_paid', null],
    ['status', 'partiellement_payee'], ['status', 'payee'], ['status', null],
]);

it('rechecks the stored header rather than trusting a stale caller model', function (string $key, mixed $value) {
    $this->data[$key] = $value;
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(InvalidArgumentException::class);
})->with([
    ['document_status', 'draft'], ['invoice_number', ' '], ['date', null], ['date', '2026-02-30'],
    ['due_date', '2026-13-05'],
]);

it('omits absent optional dates and terms while requiring one for positive dues', function () {
    $this->data['due_date'] = null;
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect($payload)->not->toHaveKey('payment_due_date')->toHaveKey('payment_terms');
    $this->data['seller']['payment_terms'] = ' ';
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(InvalidArgumentException::class);
    $this->data['items'][0]['unit_price_ht'] = '0.00';
    $this->data['items'][0]['line_total_ht'] = '0.00';
    $this->data['totals'] = ['subtotal_ht' => '0.00', 'total_tva' => '0.00', 'total_ttc' => '0.00'];
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect($payload)->not->toHaveKey('payment_due_date')->not->toHaveKey('payment_terms');
});

it('performs no database operation or network request during fiscal mapping and never mutates its inputs', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $before = $this->data;
    $attributes = $this->invoice->getAttributes();
    $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect($queries)->toBe([])->and($this->data)->toBe($before)->and($this->invoice->getAttributes())->toBe($attributes);
    Http::assertNothingSent();
});

it('serializes only documented properties with no float or null at any depth', function (string $category) {
    // Property names/types recorded from SUPER PDP OpenAPI 1.39.0.beta, en_invoice and referenced schemas.
    // This is a local contract check for emitted fields, not a replacement for semantic validation.
    $properties = [
        '' => ['number', 'issue_date', 'type_code', 'currency_code', 'process_control', 'notes', 'seller', 'buyer', 'lines', 'vat_break_down', 'totals', 'payment_due_date', 'payment_terms'],
        'notes.*' => ['subject_code', 'note'],
        'process_control' => ['specification_identifier', 'business_process_type'],
        'seller' => ['name', 'legal_registration_identifier', 'identifiers', 'electronic_address', 'postal_address', 'vat_identifier', 'contact'],
        'buyer' => ['name', 'legal_registration_identifier', 'identifiers', 'electronic_address', 'postal_address', 'vat_identifier', 'contact'],
        'seller.legal_registration_identifier' => ['value', 'scheme'],
        'seller.identifiers.*' => ['value', 'scheme'],
        'buyer.legal_registration_identifier' => ['value', 'scheme'],
        'buyer.identifiers.*' => ['value', 'scheme'],
        'seller.electronic_address' => ['value', 'scheme'],
        'buyer.electronic_address' => ['value', 'scheme'],
        'seller.postal_address' => ['address_line1', 'post_code', 'city', 'country_code'],
        'buyer.postal_address' => ['address_line1', 'post_code', 'city', 'country_code'],
        'seller.contact' => ['email_address', 'phone_number'],
        'buyer.contact' => ['email_address', 'phone_number'],
        'lines.*' => ['identifier', 'invoiced_quantity', 'invoiced_quantity_code', 'net_amount', 'price_details', 'item_information', 'vat_information'],
        'lines.*.price_details' => ['item_net_price', 'item_price_base_quantity', 'quantity_unit_code'],
        'lines.*.item_information' => ['name'],
        'lines.*.vat_information' => ['invoiced_item_vat_category_code', 'invoiced_item_vat_rate', 'exemption_reason_code', 'exemption_reason'],
        'vat_break_down.*' => ['vat_category_code', 'vat_category_rate', 'vat_category_taxable_amount', 'vat_category_tax_amount', 'vat_exemption_reason_code', 'vat_exemption_reason'],
        'totals' => ['sum_invoice_lines_amount', 'total_without_vat', 'total_vat_amount', 'total_with_vat', 'amount_due_for_payment'],
        'totals.total_vat_amount' => ['value', 'currency_code'],
    ];
    $this->data['buyer'] = array_replace($this->data['buyer'], [
        'client_type' => 'professional', 'siret' => '55210055400013', 'vat_number' => 'FR96552100554',
        'email' => 'buyer@example.com', 'phone' => '+33 4 12 34 56 78',
    ]);
    $this->data['seller']['email'] = 'seller@example.com';
    $this->data['seller']['phone'] = '+33 1 23 45 67 89';
    // Internal or legacy export fields must never leak into the electronic contract.
    $this->data['remaining_amount'] = '0.00';
    $this->data['totals']['issued_credit_total'] = '72.00';
    $this->data['items'][0]['internal_note'] = 'Interne';
    $this->data['items'][0]['vat_category'] = $category;
    if ($category !== 'S') {
        $this->data['items'][0]['tva_rate'] = '0.00';
        $this->data['totals']['total_tva'] = '0.00';
        $this->data['totals']['total_ttc'] = '60.00';
    }
    if ($category === 'E') {
        $this->data['items'][0]['exemption_reason_code'] = 'VATEX-FR-FRANCHISE';
        $this->data['items'][0]['exemption_reason'] = 'Justification conservée';
    }
    $payload = $this->builder->build($this->invoice, $this->address, ['scheme' => '0225', 'value' => '552100554_ACHATS'], frenchContext: SuperPdpPayloadFixture::frenchContext());
    $check = function (array $object, string $path = '') use (&$check, $properties) {
        foreach ($object as $key => $value) {
            if (! array_is_list($object)) {
                expect($properties)->toHaveKey($path);
                expect($key)->toBeIn($properties[$path]);
            }
            $child = ltrim($path.'.'.(is_int($key) ? '*' : $key), '.');
            if (is_array($value)) {
                $check($value, $child);
            } elseif ($child === 'type_code') {
                expect($value)->toBe(380);
            } else {
                expect($value)->toBeString();
            }
        }
    };
    $check($payload);
    expect(json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR))->toBe($payload);
})->with(['S', 'E', 'Z']);
