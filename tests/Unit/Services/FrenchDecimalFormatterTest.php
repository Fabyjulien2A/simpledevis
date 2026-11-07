<?php

use App\Support\FrenchDecimalFormatter;

it('formats exact decimal strings in French without changing their precision through floats', function (string $input, string $expected) {
    expect((new FrenchDecimalFormatter)->format($input))->toBe($expected);
})->with([
    ['0', '0,00'], ['20.00', '20,00'], ['5.5', '5,50'],
    ['1234.50', "1\u{00A0}234,50"], ['99999999.99', "99\u{00A0}999\u{00A0}999,99"],
    ['9007199254740993.01', "9\u{00A0}007\u{00A0}199\u{00A0}254\u{00A0}740\u{00A0}993,01"],
    ['1.005', '1,01'], ['-1.005', '-1,01'], ['-0.001', '0,00'],
    ['00012.30', '12,30'], ['999.995', "1\u{00A0}000,00"],
]);

it('rejects non decimal strings and numeric PHP values', function (mixed $input) {
    expect(fn () => (new FrenchDecimalFormatter)->format($input))->toThrow(InvalidArgumentException::class);
})->with([1.25, 20, null, true, '', '1,25', '1e2', ' 1.00', '1 000.00', 'NaN', 'INF']);
