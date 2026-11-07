<?php

use App\Services\InvoiceAmountCalculator;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\InvoiceLinePreparationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->preparer = new InvoiceFiscalDataPreparer(new InvoiceLinePreparationService(new InvoiceAmountCalculator));
    $this->line = [
        'id' => 42, 'description' => 'Réparation', 'quantity' => '1.50', 'unit_price_ht' => '40.00',
        'tva_rate' => '20.00', 'line_total_ht' => '60.00', 'vat_category' => 'S', 'unit_code' => 'HUR',
        'exemption_reason_code' => null, 'exemption_reason' => null,
    ];
    $this->totals = ['subtotal_ht' => '60.00', 'total_tva' => '12.00', 'total_ttc' => '72.00'];
});

it('checks all supported rates and preserves stored line data', function (string $rate, string $vat, string $ttc, string $unit) {
    $line = array_replace($this->line, ['tva_rate' => $rate, 'unit_code' => $unit]);
    $totals = ['subtotal_ht' => '60.00', 'total_tva' => $vat, 'total_ttc' => $ttc];
    $result = $this->preparer->prepare([$line], $totals, 'decimal-v1');
    expect($result['items'])->toBe([$line])->and($result['totals'])->toBe($totals)
        ->and($result['vat_breakdowns'][0]['taxable_amount'])->toBe('60.00')
        ->and($result['vat_breakdowns'][0]['vat_amount'])->toBe($vat);
    array_walk_recursive($result, fn ($value) => expect(is_float($value))->toBeFalse());
})->with([
    ['20.00', '12.00', '72.00', 'C62'], ['10.00', '6.00', '66.00', 'HUR'],
    ['5.50', '3.30', '63.30', 'MTR'], ['2.10', '1.26', '61.26', 'HUR'],
]);

it('reconstructs mixed rates with VAT rounded by breakdown rather than per line', function () {
    $tiny = array_replace($this->line, ['quantity' => '1.00', 'unit_price_ht' => '0.03', 'line_total_ht' => '0.03']);
    $lines = [$tiny, $tiny, $tiny,
        array_replace($this->line, ['quantity' => '1.00', 'unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'tva_rate' => '10.00']),
        array_replace($this->line, ['unit_price_ht' => '0.03', 'line_total_ht' => '0.05', 'tva_rate' => '5.50']),
        array_replace($this->line, ['quantity' => '1.00', 'unit_price_ht' => '100.00', 'line_total_ht' => '100.00', 'tva_rate' => '2.10']),
    ];
    $totals = ['subtotal_ht' => '200.14', 'total_tva' => '12.12', 'total_ttc' => '212.26'];
    $data = $this->preparer->prepare($lines, $totals, 'decimal-v1');
    expect($data['items'])->toBe($lines)->and($data['totals'])->toBe($totals)
        ->and(array_column($data['vat_breakdowns'], 'vat_amount'))->toBe(['0.02', '10.00', '0.00', '2.10'])
        ->and(array_column($data['vat_breakdowns'], 'taxable_amount'))->toBe(['0.09', '100.00', '0.05', '100.00']);
});

it('retains HALF_UP rounding for fractional quantities and group VAT ties', function () {
    $line = array_replace($this->line, ['unit_price_ht' => '0.03', 'line_total_ht' => '0.05', 'tva_rate' => '10.00']);
    $data = $this->preparer->prepare([$line], ['subtotal_ht' => '0.05', 'total_tva' => '0.01', 'total_ttc' => '0.06'], 'decimal-v1');
    expect($data['items'][0]['quantity'])->toBe('1.50')->and($data['vat_breakdowns'][0]['vat_amount'])->toBe('0.01');
});

it('preserves explicit exemptions franchise and zero rating without inventing legal text', function (string $category, ?string $code, ?string $reason) {
    $line = array_replace($this->line, [
        'vat_category' => $category, 'tva_rate' => '0.00',
        'exemption_reason_code' => $code, 'exemption_reason' => $reason,
    ]);
    $data = $this->preparer->prepare([$line], ['subtotal_ht' => '60.00', 'total_tva' => '0.00', 'total_ttc' => '60.00'], 'decimal-v1');
    expect($data['items'])->toBe([$line])->and($data['vat_breakdowns'][0]['vat_category'])->toBe($category)
        ->and($data['vat_breakdowns'][0]['exemption_reason_code'])->toBe($code)
        ->and($data['vat_breakdowns'][0]['exemption_reason'])->toBe($reason);
})->with([
    ['E', 'VATEX-FR-FRANCHISE', null],
    ['E', 'VATEX-EU-132', 'Motif explicite'],
    ['E', null, 'Justification fournie'], ['Z', null, null],
]);

it('does not rewrite stored descriptions or reasons when validating', function () {
    $line = array_replace($this->line, [
        'description' => '  Libellé conservé  ', 'vat_category' => 'E', 'tva_rate' => '0.00',
        'exemption_reason' => '  Motif conservé  ',
    ]);
    $data = $this->preparer->prepare([$line], ['subtotal_ht' => '60.00', 'total_tva' => '0.00', 'total_ttc' => '60.00'], 'decimal-v1');
    expect($data['items'][0])->toBe($line);
});

it('refuses each stored amount mismatch without mutating its inputs', function (string $field) {
    $lines = [$this->line];
    $totals = $this->totals;
    if ($field === 'line_total_ht') {
        $lines[0][$field] = '60.01';
    } else {
        $totals[$field] = '0.01';
    }
    $before = [$lines, $totals];
    try {
        $this->preparer->prepare($lines, $totals, 'decimal-v1');
        $this->fail('Inconsistent stored amounts must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field === 'line_total_ht' ? 'items.0.line_total_ht' : $field);
    }
    expect([$lines, $totals])->toBe($before);
})->with(['line_total_ht', 'subtotal_ht', 'total_tva', 'total_ttc']);

it('rejects invalid fiscal metadata and missing lines', function (array $changes, string $key) {
    $lines = $changes === [] ? [] : [array_replace($this->line, $changes)];
    try {
        $this->preparer->prepare($lines, $this->totals, 'decimal-v1');
        $this->fail('Invalid fiscal metadata must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($key);
    }
})->with([
    [[], 'items'], [['vat_category' => null], 'items.0.vat_category'],
    [['vat_category' => 'AE'], 'items.0.vat_category'], [['unit_code' => null], 'items.0.unit_code'],
    [['unit_code' => 'BAD'], 'items.0.unit_code'], [['tva_rate' => '0.00'], 'items.0.tva_rate'],
    [['vat_category' => 'E', 'tva_rate' => '0.00'], 'items.0.exemption_reason'],
    [['vat_category' => 'Z', 'tva_rate' => '20.00'], 'items.0.tva_rate'],
    [['exemption_reason_code' => 'VATEX-FR-FRANCHISE'], 'items.0.exemption_reason_code'],
]);

it('refuses different reasons in the same exemption breakdown', function () {
    $first = array_replace($this->line, ['vat_category' => 'E', 'tva_rate' => '0.00', 'exemption_reason' => 'Motif A']);
    $second = array_replace($first, ['exemption_reason' => 'Motif B']);
    expect(fn () => $this->preparer->prepare([$first, $second], $this->totals, 'decimal-v1'))->toThrow(ValidationException::class);
});

it('rejects missing or non string stored amounts instead of converting floats', function (string $field, mixed $value) {
    $lines = [$this->line];
    $totals = $this->totals;
    if (array_key_exists($field, $this->line)) {
        $lines[0][$field] = $value;
        $key = "items.0.$field";
    } else {
        $totals[$field] = $value;
        $key = $field;
    }
    try {
        $this->preparer->prepare($lines, $totals, 'decimal-v1');
        $this->fail('A stored decimal string is required.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($key);
    }
})->with([
    ['line_total_ht', null], ['line_total_ht', 60.0], ['subtotal_ht', 60.0],
    ['total_tva', null], ['total_ttc', '72,00'], ['total_ttc', '7.2e1'],
    ['quantity', 1], ['unit_price_ht', 40.0], ['tva_rate', 20.0],
]);

it('refuses historical fiscal export even if some new metadata was filled manually', function () {
    try {
        $this->preparer->prepare([$this->line], $this->totals, null);
        $this->fail('Legacy data must not be silently qualified.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('calculation_version');
    }
});

it('refuses unknown calculation versions', function () {
    expect(fn () => $this->preparer->prepare([$this->line], $this->totals, 'decimal-v2'))
        ->toThrow(InvalidArgumentException::class, 'Version de calcul inconnue');
});

it('excludes settlement amounts and has no database or network interaction', function () {
    Http::preventStrayRequests();
    $queries = [];
    DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
    $totals = array_merge($this->totals, ['amount_paid' => '10.00', 'issued_credit_total' => '20.00', 'remaining_amount' => '42.00', 'amount_due_for_payment' => '42.00']);
    $data = $this->preparer->prepare([$this->line], $totals, 'decimal-v1');
    expect($data['totals'])->toBe($this->totals)->and($queries)->toBe([])
        ->and(array_keys($data))->toBe(['items', 'vat_breakdowns', 'totals']);
    Http::assertNothingSent();
});
