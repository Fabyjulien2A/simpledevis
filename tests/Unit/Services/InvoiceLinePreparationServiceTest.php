<?php

use App\Services\InvoiceAmountCalculator;
use App\Services\InvoiceLinePreparationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->service = new InvoiceLinePreparationService(new InvoiceAmountCalculator);
    $this->item = [
        'description' => 'Réparation', 'quantity' => '1.50', 'unit_price_ht' => '40.00',
        'tva_rate' => '10', 'vat_category' => 'S', 'unit_code' => 'HUR',
    ];
});

it('maps and calculates form lines while preserving their order and metadata', function () {
    $second = array_replace($this->item, [
        'description' => 'Fourniture', 'quantity' => '2', 'unit_price_ht' => '10',
        'tva_rate' => '20', 'unit_code' => 'C62',
    ]);
    $result = $this->service->prepare([$this->item, $second]);

    expect($result['items'])->toBe([
        [
            'description' => 'Réparation', 'quantity' => '1.50', 'unit_price_ht' => '40.00',
            'tva_rate' => '10.00', 'vat_category' => 'S', 'unit_code' => 'HUR',
            'exemption_reason_code' => null, 'exemption_reason' => null, 'line_total_ht' => '60.00',
        ],
        [
            'description' => 'Fourniture', 'quantity' => '2', 'unit_price_ht' => '10',
            'tva_rate' => '20.00', 'vat_category' => 'S', 'unit_code' => 'C62',
            'exemption_reason_code' => null, 'exemption_reason' => null, 'line_total_ht' => '20.00',
        ],
    ])->and($result['totals'])->toBe(['total_ht' => '80.00', 'total_vat' => '10.00', 'total_ttc' => '90.00'])
        ->and(array_column($result['vat_breakdowns'], 'vat_rate'))->toBe(['10.00', '20.00']);
});

it('passes only decimal strings to the injected calculator exactly once', function () {
    $calculator = $this->createMock(InvoiceAmountCalculator::class);
    $expected = [
        'quantity' => '1', 'unit_price_ht' => '40', 'vat_rate' => '10', 'vat_category' => 'S',
        'exemption_reason_code' => null, 'exemption_reason' => null,
    ];
    $calculated = (new InvoiceAmountCalculator)->calculate([$expected]);
    $calculator->expects($this->once())->method('calculate')->with([$expected])->willReturn($calculated);
    $item = array_replace($this->item, ['quantity' => 1, 'unit_price_ht' => 40, 'tva_rate' => 10]);

    expect((new InvoiceLinePreparationService($calculator))->prepare([$item])['totals']['total_ttc'])->toBe('44.00');
});

it('ignores all browser calculated amounts and unrecognised fields', function () {
    $item = array_merge($this->item, [
        'line_total_ht' => '0.01', 'subtotal_ht' => '0.01', 'total_tva' => '0.01',
        'total_ttc' => '0.01', 'vat_rate' => '0', 'calculation_version' => 'forged', 'invoice_id' => 123,
    ]);
    $result = $this->service->prepare([$item]);

    expect($result['items'][0]['line_total_ht'])->toBe('60.00')
        ->and($result['totals']['total_ttc'])->toBe('66.00')
        ->and($result['items'][0])->not->toHaveKey('invoice_id')->not->toHaveKey('calculation_version')
        ->and($result)->not->toHaveKey('calculation_version');
});

it('preserves the three small line rounding result', function () {
    $item = array_replace($this->item, ['quantity' => '1', 'unit_price_ht' => '0.03', 'tva_rate' => '20']);

    expect($this->service->prepare([$item, $item, $item])['totals'])
        ->toBe(['total_ht' => '0.09', 'total_vat' => '0.02', 'total_ttc' => '0.11']);
});

it('accepts the supported rates and units', function (string $rate, string $unit) {
    $result = $this->service->prepare([array_replace($this->item, ['tva_rate' => $rate, 'unit_code' => $unit])]);

    expect($result['items'][0]['unit_code'])->toBe($unit)
        ->and($result['items'][0]['line_total_ht'])->toBe('60.00');
})->with(['20', '10', '5.5', '2.1'])->with(['C62', 'HUR', 'MTR']);

it('keeps an explicit franchise without generating legal text', function () {
    $item = array_replace($this->item, [
        'vat_category' => 'E', 'tva_rate' => '0', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE',
    ]);
    $result = $this->service->prepare([$item]);

    expect($result['items'][0]['exemption_reason_code'])->toBe('VATEX-FR-FRANCHISE')
        ->and($result['items'][0]['exemption_reason'])->toBeNull()
        ->and($result['totals']['total_vat'])->toBe('0.00');
});

it('accepts an explicit ordinary exemption or zero rated line', function (array $changes) {
    $result = $this->service->prepare([array_replace($this->item, $changes)]);

    expect($result['totals']['total_vat'])->toBe('0.00')
        ->and($result['items'][0]['vat_category'])->toBe($changes['vat_category']);
})->with([
    [['vat_category' => 'E', 'tva_rate' => '0', 'exemption_reason' => 'Autre exonération explicitement justifiée']],
    [['vat_category' => 'Z', 'tva_rate' => '0']],
]);

it('reports field validation errors using form keys', function (string $field, mixed $value) {
    try {
        $this->service->prepare([array_replace($this->item, [$field => $value])]);
        $this->fail('Une erreur de validation était attendue.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items.0.' . $field);
    }
})->with([
    ['description', ' '], ['description', str_repeat('a', 256)], ['description', []],
    ['quantity', null], ['quantity', '0'], ['quantity', '-1'], ['quantity', '1.234'],
    ['quantity', '100000000'], ['quantity', 1.5], ['quantity', true],
    ['unit_price_ht', '-0.01'], ['unit_price_ht', '0.001'], ['unit_price_ht', '100000000'],
    ['unit_price_ht', 0.1], ['unit_price_ht', '1e2'], ['unit_price_ht', '5,5'],
    ['tva_rate', '5.501'], ['tva_rate', '7'], ['tva_rate', '0'], ['tva_rate', false],
    ['vat_category', null], ['vat_category', 'AE'], ['vat_category', []],
    ['unit_code', null], ['unit_code', 'UNKNOWN'], ['unit_code', []],
    ['exemption_reason_code', str_repeat('a', 256)], ['exemption_reason_code', []],
    ['exemption_reason', []], ['exemption_reason', str_repeat('é', 32768)],
]);

it('requires every mandatory form field', function (string $field) {
    $item = $this->item;
    unset($item[$field]);

    try {
        $this->service->prepare([$item]);
        $this->fail('Une erreur de validation était attendue.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items.0.' . $field);
    }
})->with(['description', 'quantity', 'unit_price_ht', 'tva_rate', 'vat_category', 'unit_code']);

it('attaches fiscal consistency errors to the appropriate field', function (array $changes, string $field) {
    try {
        $this->service->prepare([array_replace($this->item, $changes)]);
        $this->fail('Une erreur de validation était attendue.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items.0.' . $field);
    }
})->with([
    [['vat_category' => 'E', 'tva_rate' => '0'], 'exemption_reason'],
    [['vat_category' => 'E', 'exemption_reason' => 'Motif'], 'tva_rate'],
    [['vat_category' => 'Z'], 'tva_rate'],
    [['exemption_reason_code' => 'VATEX-FR-FRANCHISE'], 'exemption_reason_code'],
    [['vat_category' => 'Z', 'tva_rate' => '0', 'exemption_reason' => 'Motif'], 'exemption_reason'],
]);

it('translates incompatible breakdown justifications into a list validation error', function () {
    $first = array_replace($this->item, ['vat_category' => 'E', 'tva_rate' => '0', 'exemption_reason' => 'Motif A']);
    $second = array_replace($first, ['exemption_reason' => 'Motif B']);

    try {
        $this->service->prepare([$first, $second]);
        $this->fail('Une erreur de validation était attendue.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items')
            ->and($exception->errors()['items'][0])->toContain('justifications différentes');
    }
});

it('rejects empty or malformed line lists', function (array $items, string $key) {
    try {
        $this->service->prepare($items);
        $this->fail('Une erreur de validation était attendue.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($key);
    }
})->with([
    [[], 'items'], [['line' => []], 'items'], [['not-an-array'], 'items.0'],
]);

it('accepts the exact decimal storage maximum when no VAT increases it', function () {
    $item = array_replace($this->item, [
        'quantity' => '1', 'unit_price_ht' => '99999999.99', 'vat_category' => 'Z', 'tva_rate' => '0',
    ]);

    expect($this->service->prepare([$item])['totals'])->toBe([
        'total_ht' => '99999999.99', 'total_vat' => '0.00', 'total_ttc' => '99999999.99',
    ]);
});

it('rejects a line amount exceeding decimal storage capacity', function () {
    $item = array_replace($this->item, ['quantity' => '2', 'unit_price_ht' => '50000000.00']);

    try {
        $this->service->prepare([$item]);
        $this->fail('Une erreur de validation était attendue.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items.0.line_total_ht');
    }
});

it('rejects overflowing totals even when individual line amounts fit', function (array $items) {
    $items = array_map(fn ($changes) => array_replace($this->item, $changes), $items);

    try {
        $this->service->prepare($items);
        $this->fail('Une erreur de validation était attendue.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items')
            ->not->toHaveKey('items.0.line_total_ht');
    }
})->with([
    'HT sum overflow' => [[
        ['quantity' => '1', 'unit_price_ht' => '50000000', 'tva_rate' => '0', 'vat_category' => 'Z'],
        ['quantity' => '1', 'unit_price_ht' => '50000000', 'tva_rate' => '0', 'vat_category' => 'Z'],
    ]],
    'TTC only overflow' => [[['quantity' => '1', 'unit_price_ht' => '90000000', 'tva_rate' => '20']]],
]);

it('does not mutate input or perform database or network operations', function () {
    Http::preventStrayRequests();
    $queries = [];
    DB::listen(function ($query) use (&$queries) { $queries[] = $query; });
    $items = [$this->item];
    $original = $items;

    $this->service->prepare($items);

    expect($items)->toBe($original)->and($queries)->toBe([]);
    Http::assertNothingSent();
});
