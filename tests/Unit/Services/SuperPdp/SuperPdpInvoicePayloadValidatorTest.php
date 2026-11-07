<?php

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
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
    $this->source->method('buildElectronic')->willReturnCallback(fn () => $this->data);
    $this->validator = app(SuperPdpInvoicePayloadValidator::class);
    $this->builder = new SuperPdpInvoicePayloadBuilder($this->source, app(InvoiceFiscalDataPreparer::class), $this->validator);
    $this->payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
});

it('validates simple and mixed VAT invoices produced by the real builder', function (bool $mixed) {
    if ($mixed) {
        $line = $this->data['items'][0];
        $this->data['items'][] = array_replace($line, ['tva_rate' => '10.00']);
        $this->data['totals'] = ['subtotal_ht' => '120.00', 'total_tva' => '18.00', 'total_ttc' => '138.00'];
    }
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    $before = $payload;
    $this->validator->validate($payload);
    expect($payload)->toBe($before)
        ->and($payload['type_code'])->toBe(380)
        ->and($payload['vat_break_down'])->toHaveCount($mixed ? 2 : 1);
})->with([false, true]);

it('validates franchise other exemptions and zero rating without changing fiscal decisions', function (string $category, ?string $code, ?string $reason) {
    $this->data['items'][0] = array_replace($this->data['items'][0], [
        'vat_category' => $category, 'tva_rate' => '0.00', 'exemption_reason_code' => $code, 'exemption_reason' => $reason,
    ]);
    $this->data['totals'] = ['subtotal_ht' => '60.00', 'total_tva' => '0.00', 'total_ttc' => '60.00'];
    $payload = $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
    $this->validator->validate($payload);
    expect($payload['vat_break_down'][0]['vat_category_code'])->toBe($category)
        ->and($payload['vat_break_down'][0]['vat_exemption_reason_code'] ?? null)->toBe($code)
        ->and($payload['vat_break_down'][0]['vat_exemption_reason'] ?? null)->toBe($reason);
})->with([
    ['E', 'VATEX-FR-FRANCHISE', null], ['E', 'VATEX-EU-132', 'Motif explicite'],
    ['E', null, 'Texte seul'], ['Z', null, null],
]);

it('preserves all official required properties including those in referenced objects', function (string $path) {
    Arr::forget($this->payload, $path);
    $parent = implode('.', array_slice(explode('.', $path), 0, -1));
    if ($parent !== '' && data_get($this->payload, $parent) === []) {
        // Preserve an empty JSON object after removing its last property; [] is a JSON array.
        data_set($this->payload, $parent, new stdClass);
    }
    try {
        $this->validator->validate($this->payload);
        $this->fail('A missing required property must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('payload.'.$path);
        expect($exception->errors()['payload.'.$path][0])->toContain('[required]');
    }
})->with([
    'number', 'issue_date', 'type_code', 'currency_code', 'process_control', 'seller', 'buyer', 'totals', 'vat_break_down', 'lines',
    'process_control.specification_identifier', 'seller.name', 'seller.postal_address', 'seller.postal_address.country_code',
    'seller.electronic_address', 'seller.electronic_address.scheme', 'seller.electronic_address.value',
    'seller.legal_registration_identifier.value', 'buyer.name', 'buyer.postal_address.country_code',
    'lines.0.identifier', 'lines.0.net_amount', 'lines.0.price_details', 'lines.0.item_information.name',
    'vat_break_down.0.vat_category_code', 'vat_break_down.0.vat_category_taxable_amount', 'vat_break_down.0.vat_category_tax_amount',
    'totals.sum_invoice_lines_amount', 'totals.total_without_vat', 'totals.total_with_vat', 'totals.amount_due_for_payment',
    'totals.total_vat_amount.value',
]);

it('rejects incorrect JSON types structures dates and decimal representations', function (string $path, mixed $value, string $keyword) {
    data_set($this->payload, $path, $value);
    try {
        $this->validator->validate($this->payload);
        $this->fail('Invalid contract data must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('payload.'.$path);
        expect($exception->errors()['payload.'.$path][0])->toContain('['.$keyword.']');
    }
})->with([
    ['type_code', '380', 'type'], ['currency_code', 123, 'type'], ['number', false, 'type'],
    ['seller', [], 'type'], ['buyer.postal_address', [], 'type'],
    ['lines', 'incorrect', 'type'], ['lines', (object) ['0' => new stdClass], 'type'],
    ['lines.0', 'incorrect', 'type'], ['lines.0.price_details', [], 'type'],
    ['vat_break_down', new stdClass, 'type'], ['totals.total_vat_amount', '12.00', 'type'],
    ['lines.0.invoiced_quantity', 1.5, 'type'], ['lines.0.net_amount', 60, 'type'],
    ['lines.0.invoiced_quantity', '1,50', 'pattern'], ['lines.0.invoiced_quantity', '1e2', 'pattern'],
    ['lines.0.price_details.item_net_price', '40,00', 'pattern'], ['lines.0.price_details.item_net_price', 'NaN', 'pattern'],
    ['lines.0.price_details.item_price_base_quantity', '1.', 'pattern'],
    ['lines.0.vat_information.invoiced_item_vat_rate', '20 %', 'pattern'],
    ['totals.total_vat_amount.value', '', 'pattern'], ['totals.total_vat_amount.value', ' 12.00', 'pattern'],
    ['totals.total_with_vat', "72.00\n", 'pattern'], ['totals.amount_due_for_payment', '+72.00', 'pattern'],
    ['vat_break_down.0.vat_category_taxable_amount', '60.0.0', 'pattern'],
    ['vat_break_down.0.vat_category_tax_amount', '12e0', 'pattern'],
    ['vat_break_down.0.vat_category_code', 'UNKNOWN', 'enum'],
    ['lines.0.vat_information.invoiced_item_vat_category_code', 'UNKNOWN', 'enum'],
    ['issue_date', '2026-02-30', 'format'], ['payment_due_date', '05/12/2026', 'format'],
]);

it('rejects forbidden nulls including optional fields at every nesting level', function (string $path) {
    data_set($this->payload, $path, null);
    try {
        $this->validator->validate($this->payload);
        $this->fail('Null must not be treated as an omitted property.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('payload.'.$path);
        expect($exception->errors()['payload.'.$path][0])->toContain('[type]');
    }
})->with([
    'number', 'payment_due_date', 'payment_terms', 'seller', 'seller.contact', 'seller.vat_identifier',
    'buyer.electronic_address', 'lines', 'lines.0', 'lines.0.invoiced_quantity', 'lines.0.price_details.item_net_price',
    'lines.0.vat_information.exemption_reason', 'vat_break_down.0.vat_exemption_reason_code',
    'totals', 'totals.total_vat_amount', 'totals.total_vat_amount.value',
]);

it('detects misspelled fiscal properties even when the correct property is optional upstream', function (string $correct, string $wrong) {
    $value = data_get($this->payload, $correct);
    Arr::forget($this->payload, $correct);
    data_set($this->payload, $wrong, $value);
    try {
        $this->validator->validate($this->payload);
        $this->fail('Unknown fiscal properties must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('payload.'.$wrong);
        expect($exception->errors()['payload.'.$wrong][0])->toContain('[additionalProperties]');
    }
})->with([
    ['vat_break_down', 'vat_breakdowns'],
    ['lines.0.vat_information.invoiced_item_vat_rate', 'lines.0.vat_information.vat_rate'],
    ['lines.0.vat_information.invoiced_item_vat_category_code', 'lines.0.vat_information.vat_category'],
    ['vat_break_down.0.vat_category_rate', 'vat_break_down.0.vat_rate'],
    ['lines.0.price_details.item_net_price', 'lines.0.price_details.net_item_price'],
]);

it('reports multiple errors with precise paths rather than silently stopping at the first one', function () {
    unset($this->payload['number'], $this->payload['currency_code']);
    $this->payload['lines'][0]['net_amount'] = 'incorrect';
    try {
        $this->validator->validate($this->payload);
        $this->fail('Invalid data must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys(['payload.number', 'payload.currency_code', 'payload.lines.0.net_amount']);
    }
});

it('rejects incomplete nested objects without converting arrays into objects', function (string $path, string $missing) {
    data_set($this->payload, $path, new stdClass);
    try {
        $this->validator->validate($this->payload);
        $this->fail('Incomplete objects must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('payload.'.$path.'.'.$missing);
    }
})->with([
    ['seller.electronic_address', 'value'], ['buyer.postal_address', 'country_code'],
    ['lines.0.item_information', 'name'], ['totals.total_vat_amount', 'value'],
]);

it('rejects incorrect roots and values that cannot be encoded as JSON', function (mixed $payload, string $keyword) {
    try {
        $this->validator->validate($payload);
        $this->fail('Invalid root must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('payload');
        expect($exception->errors()['payload'][0])->toContain('['.$keyword.']');
    }
})->with([[[], 'type'], [null, 'type'], ['{}', 'type'], [false, 'type'], [['number' => "\xB1"], 'json']]);

it('accepts documented optional properties outside the fields currently emitted by the builder', function () {
    $this->payload['buyer_reference'] = 'ACH-42';
    $this->payload['seller']['postal_address']['address_line2'] = 'Bâtiment B';
    $this->payload['lines'][0]['item_information']['description'] = 'Détail de la prestation';
    $this->payload['notes'] = [['note' => 'Note documentée']];
    $this->validator->validate($this->payload);
    expect($this->payload['buyer_reference'])->toBe('ACH-42');
});

it('does not confuse the structural contract with semantic monetary or fiscal checks', function () {
    // The official contract has no cross-field monetary identity and no S/E/Z scenario policy.
    // These rules remain in InvoiceFiscalDataPreparer and in the builder, not in JSON Schema.
    $this->payload['totals']['total_with_vat'] = '999.00';
    $this->validator->validate($this->payload);
    $this->data['totals']['total_ttc'] = '999.00';
    expect(fn () => $this->builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(ValidationException::class);
});

it('checks the generated payload automatically before the builder can return it', function () {
    $prepared = app(InvoiceFiscalDataPreparer::class)->prepare($this->data['items'], $this->data['totals'], 'decimal-v1');
    // Simulate a mapping/input regression after fiscal verification: the structural gate must catch it.
    $prepared['totals']['total_tva'] = '12,00';
    $fiscal = $this->createMock(InvoiceFiscalDataPreparer::class);
    $fiscal->method('prepare')->willReturn($prepared);
    $builder = new SuperPdpInvoicePayloadBuilder($this->source, $fiscal, $this->validator);
    try {
        $builder->build($this->invoice, $this->address, frenchContext: SuperPdpPayloadFixture::frenchContext());
        $this->fail('The builder must validate its output before returning.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('payload.totals.total_vat_amount.value');
    }
});

it('validates locally without database access network requests or mutation on success and failure', function () {
    $before = $this->payload;
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('No database query is permitted during schema validation.');
    });
    $this->validator->validate($this->payload);
    $invalid = $this->payload;
    $invalid['lines'][0]['net_amount'] = null;
    expect(fn () => $this->validator->validate($invalid))->toThrow(ValidationException::class);
    expect($this->payload)->toBe($before)->and($invalid['lines'][0]['net_amount'])->toBeNull();
    Http::assertNothingSent();
});

it('pins the official source provenance and reproduces the entire local schema without network access', function () {
    $directory = resource_path('schemas/superpdp/'.SuperPdpInvoicePayloadValidator::SCHEMA_VERSION);
    $source = json_decode(file_get_contents($directory.'/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    $derived = json_decode(file_get_contents($directory.'/en-invoice.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($source['info']['version'])->toBe('1.39.0.beta')
        ->and($source['openapi'])->toBe('3.0.4')
        ->and($derived['x-source']['sha256'])->toBe(hash_file('sha256', $directory.'/openapi.json'))
        ->and($derived['definitions'])->toHaveCount(50)
        ->and($derived['definitions']['en_invoice']['required'])->toBe($source['components']['schemas']['en_invoice']['required'])
        ->and($derived['definitions']['vat_category_code']['enum'])->toBe($source['components']['schemas']['vat_category_code']['enum']);
    $walk = function (array $node) use (&$walk) {
        if (isset($node['$ref'])) {
            expect($node['$ref'])->toStartWith('#/definitions/');
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($derived);
    $process = new Process([PHP_BINARY, '-d', 'allow_url_fopen=0', base_path('tools/build-superpdp-schema.php'), '--check']);
    $process->mustRun();
    expect($process->getOutput())->toContain('50 local definitions verified');
    Http::assertNothingSent();
});
