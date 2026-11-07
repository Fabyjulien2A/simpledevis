<?php

use App\Services\InvoiceAmountCalculator;

beforeEach(function () {
    $this->calculator = new InvoiceAmountCalculator;
    $this->line = [
        'quantity' => '1',
        'unit_price_ht' => '100.00',
        'vat_rate' => '20',
        'vat_category' => 'S',
    ];
});

it('calculates each supported rate with decimal string totals', function (string $rate, string $vat, string $ttc) {
    $line = array_replace($this->line, ['vat_rate' => $rate]);
    $result = $this->calculator->calculate([$line]);

    expect($result['totals'])->toBe(['total_ht' => '100.00', 'total_vat' => $vat, 'total_ttc' => $ttc])
        ->and($result['lines'][0]['line_total_ht'])->toBe('100.00')
        ->and($result['vat_breakdowns'][0]['vat_amount'])->toBe($vat);
})->with([
    ['20', '20.00', '120.00'],
    ['10', '10.00', '110.00'],
    ['5.5', '5.50', '105.50'],
    ['2.1', '2.10', '102.10'],
]);

it('calculates a mixed invoice with four separate VAT breakdowns', function () {
    $lines = array_map(fn ($rate) => array_replace($this->line, ['vat_rate' => $rate]), ['20', '10', '5.5', '2.1']);
    $result = $this->calculator->calculate($lines);

    expect($result['totals'])->toBe(['total_ht' => '400.00', 'total_vat' => '37.60', 'total_ttc' => '437.60'])
        ->and(array_column($result['vat_breakdowns'], 'vat_rate'))->toBe(['20.00', '10.00', '5.50', '2.10'])
        ->and(array_column($result['vat_breakdowns'], 'taxable_amount'))->toBe(array_fill(0, 4, '100.00'))
        ->and(array_column($result['vat_breakdowns'], 'vat_amount'))->toBe(['20.00', '10.00', '5.50', '2.10']);
});

it('groups numerically identical rates without rounding them to a supported rate', function () {
    $result = $this->calculator->calculate([
        array_replace($this->line, ['vat_rate' => '5.5']),
        array_replace($this->line, ['vat_rate' => '5.500']),
    ]);

    expect($result['vat_breakdowns'])->toHaveCount(1)
        ->and($result['vat_breakdowns'][0]['vat_rate'])->toBe('5.50')
        ->and($result['totals'])->toBe(['total_ht' => '200.00', 'total_vat' => '11.00', 'total_ttc' => '211.00']);
});

it('rounds VAT once per breakdown for three lines of 0.03 at 20 percent', function () {
    $line = array_replace($this->line, ['unit_price_ht' => '0.03']);
    $result = $this->calculator->calculate([$line, $line, $line]);

    expect(array_column($result['lines'], 'line_total_ht'))->toBe(['0.03', '0.03', '0.03'])
        ->and($result['vat_breakdowns'][0]['taxable_amount'])->toBe('0.09')
        ->and($result['vat_breakdowns'][0]['vat_amount'])->toBe('0.02')
        ->and($result['totals'])->toBe(['total_ht' => '0.09', 'total_vat' => '0.02', 'total_ttc' => '0.11']);
});

it('sums rounded line amounts instead of rounding their unrounded sum', function () {
    $line = array_replace($this->line, ['quantity' => '1.5', 'unit_price_ht' => '0.01']);
    $result = $this->calculator->calculate([$line, $line]);

    expect(array_column($result['lines'], 'line_total_ht'))->toBe(['0.02', '0.02'])
        ->and($result['totals'])->toBe(['total_ht' => '0.04', 'total_vat' => '0.01', 'total_ttc' => '0.05']);
});

it('keeps fractional quantities and prices exact until line rounding', function () {
    $result = $this->calculator->calculate([
        array_replace($this->line, ['quantity' => '0.125', 'unit_price_ht' => '19.99', 'vat_rate' => '5.5']),
    ]);

    expect($result['lines'][0]['quantity'])->toBe('0.125')
        ->and($result['lines'][0]['unit_price_ht'])->toBe('19.99')
        ->and($result['totals'])->toBe(['total_ht' => '2.50', 'total_vat' => '0.14', 'total_ttc' => '2.64']);
});

it('uses HALF_UP for both line and VAT ties', function () {
    $result = $this->calculator->calculate([
        array_replace($this->line, ['quantity' => '0.5', 'unit_price_ht' => '0.09', 'vat_rate' => '10']),
    ]);

    expect($result['totals'])->toBe(['total_ht' => '0.05', 'total_vat' => '0.01', 'total_ttc' => '0.06']);
});

it('preserves precision beyond floating point integer limits', function () {
    $result = $this->calculator->calculate([
        array_replace($this->line, ['unit_price_ht' => '9007199254740993.01']),
    ]);

    expect($result['totals'])->toBe([
        'total_ht' => '9007199254740993.01',
        'total_vat' => '1801439850948198.60',
        'total_ttc' => '10808639105689191.61',
    ]);
});

it('preserves an explicitly declared franchise and the supplied legal text', function () {
    $reason = 'Mention fournie par l’appelant pour cette facture';
    $line = array_replace($this->line, [
        'vat_category' => 'E',
        'vat_rate' => '0',
        'exemption_reason_code' => 'VATEX-FR-FRANCHISE',
        'exemption_reason' => $reason,
    ]);
    $result = $this->calculator->calculate([$line, $line]);

    expect($result['vat_breakdowns'])->toBe([[
        'vat_category' => 'E',
        'vat_rate' => '0.00',
        'taxable_amount' => '200.00',
        'vat_amount' => '0.00',
        'exemption_reason_code' => 'VATEX-FR-FRANCHISE',
        'exemption_reason' => $reason,
    ]])->and($result['lines'][0]['exemption_reason'])->toBe($reason)
        ->and($result['totals'])->toBe(['total_ht' => '200.00', 'total_vat' => '0.00', 'total_ttc' => '200.00']);
});

it('does not generate a legal text from a franchise code', function () {
    $line = array_replace($this->line, [
        'vat_category' => 'E', 'vat_rate' => '0', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE',
    ]);

    expect($this->calculator->calculate([$line])['vat_breakdowns'][0]['exemption_reason'])->toBeNull();
});

it('accepts another explicitly justified exemption without calling it franchise', function () {
    $line = array_replace($this->line, [
        'vat_category' => 'E', 'vat_rate' => '0', 'exemption_reason' => 'Autre motif explicite fourni',
    ]);
    $group = $this->calculator->calculate([$line])['vat_breakdowns'][0];

    expect($group['exemption_reason_code'])->toBeNull()
        ->and($group['exemption_reason'])->toBe('Autre motif explicite fourni');
});

it('separates taxable exempt and zero rated amounts even when the rates are zero', function () {
    $result = $this->calculator->calculate([
        $this->line,
        array_replace($this->line, ['vat_category' => 'E', 'vat_rate' => '0', 'exemption_reason' => 'Exonération explicite']),
        array_replace($this->line, ['vat_category' => 'Z', 'vat_rate' => '0.00']),
    ]);

    expect(array_column($result['vat_breakdowns'], 'vat_category'))->toBe(['S', 'E', 'Z'])
        ->and($result['vat_breakdowns'][2]['exemption_reason_code'])->toBeNull()
        ->and($result['totals'])->toBe(['total_ht' => '300.00', 'total_vat' => '20.00', 'total_ttc' => '320.00']);
});

it('refuses different exemption justifications in a single category and rate group', function (array $secondReason) {
    $first = array_replace($this->line, [
        'vat_category' => 'E', 'vat_rate' => '0',
        'exemption_reason_code' => 'VATEX-FR-FRANCHISE', 'exemption_reason' => 'Motif A',
    ]);
    $second = array_replace($first, $secondReason);

    expect(fn () => $this->calculator->calculate([$first, $second]))
        ->toThrow(InvalidArgumentException::class, 'Ligne 2 : justifications différentes dans une même ventilation TVA.');
})->with([
    'different codes' => [['exemption_reason_code' => 'VATEX-EU-132']],
    'different texts' => [['exemption_reason' => 'Motif B']],
    'missing text in one line' => [['exemption_reason' => null]],
    'missing code in one line' => [['exemption_reason_code' => null]],
]);

it('rejects ambiguous or inconsistent fiscal data', function (array $changes) {
    $line = array_replace($this->line, $changes);

    expect(fn () => $this->calculator->calculate([$line]))->toThrow(InvalidArgumentException::class);
})->with([
    'zero without category' => [['vat_rate' => '0', 'vat_category' => null]],
    'zero as taxable' => [['vat_rate' => '0']],
    'unsupported positive rate' => [['vat_rate' => '7']],
    'almost supported rate' => [['vat_rate' => '5.499']],
    'exempt with positive rate' => [['vat_category' => 'E', 'exemption_reason' => 'Motif']],
    'zero rated with positive rate' => [['vat_category' => 'Z']],
    'unjustified exemption' => [['vat_category' => 'E', 'vat_rate' => '0']],
    'blank exemption' => [['vat_category' => 'E', 'vat_rate' => '0', 'exemption_reason' => ' ']],
    'franchise on taxable line' => [['exemption_reason_code' => 'VATEX-FR-FRANCHISE']],
    'franchise on zero rated line' => [['vat_category' => 'Z', 'vat_rate' => '0', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE']],
    'unsupported category' => [['vat_category' => 'AE', 'vat_rate' => '0']],
    'non string reason' => [['vat_category' => 'E', 'vat_rate' => '0', 'exemption_reason' => []]],
]);

it('rejects invalid numeric inputs without coercing them', function (string $field, mixed $value) {
    expect(fn () => $this->calculator->calculate([array_replace($this->line, [$field => $value])]))
        ->toThrow(InvalidArgumentException::class);
})->with(['quantity', 'unit_price_ht', 'vat_rate'])
    ->with([
        'float' => [0.1],
        'integer' => [1],
        'null' => [null],
        'negative' => ['-1'],
        'comma' => ['5,5'],
        'exponent' => ['1e2'],
        'non finite' => ['NaN'],
        'empty' => [''],
    ]);

it('rejects zero quantity but accepts zero priced lines', function () {
    expect(fn () => $this->calculator->calculate([array_replace($this->line, ['quantity' => '0'])]))
        ->toThrow(InvalidArgumentException::class, 'Ligne 1 : la quantité doit être strictement positive.');

    expect($this->calculator->calculate([array_replace($this->line, ['unit_price_ht' => '0'])])['totals'])
        ->toBe(['total_ht' => '0.00', 'total_vat' => '0.00', 'total_ttc' => '0.00']);
});

it('rejects empty or malformed line lists', function (array $lines) {
    expect(fn () => $this->calculator->calculate($lines))->toThrow(InvalidArgumentException::class);
})->with([
    'empty list' => [[]],
    'associative input' => [['a' => []]],
    'non array line' => [['invalid']],
    'missing fields' => [[[]]],
]);

it('does not mutate input or retain previous calculation state', function () {
    $lines = [$this->line];
    $original = $lines;
    $first = $this->calculator->calculate($lines);
    $second = $this->calculator->calculate([array_replace($this->line, ['unit_price_ht' => '1', 'vat_rate' => '10'])]);

    expect($lines)->toBe($original)
        ->and($first['totals']['total_ttc'])->toBe('120.00')
        ->and($second['totals'])->toBe(['total_ht' => '1.00', 'total_vat' => '0.10', 'total_ttc' => '1.10'])
        ->and($second['vat_breakdowns'])->toHaveCount(1);
});
