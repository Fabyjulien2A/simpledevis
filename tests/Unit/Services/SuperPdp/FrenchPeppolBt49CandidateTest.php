<?php

use App\Services\SuperPdp\FrenchPeppolBt49Candidate;

// Tests PHP purs : aucun démarrage de Laravel, base, transport ou ressource SDK.
it('returns a candidate BT49 pair and preserves the original identifier and leading zeroes', function (string $siren) {
    $identifier = '0225:'.$siren;
    $original = $identifier;
    $candidate = FrenchPeppolBt49Candidate::fromIdentifier($identifier, $siren);

    expect($candidate->originalIdentifier)->toBe($original)
        ->and($candidate->scheme)->toBe('0225')
        ->and($candidate->value)->toBe($siren)
        ->and($identifier)->toBe($original)
        ->and(get_object_vars($candidate))->toBe([
            'originalIdentifier' => $original, 'scheme' => '0225', 'value' => $siren,
        ]);
})->with(['853322915', '001234567', '000000000', '123456789']);

it('refuses a different recipient SIREN without comparing them numerically', function (string $siren, string $expected) {
    expect(fn () => FrenchPeppolBt49Candidate::fromIdentifier('0225:'.$siren, $expected))
        ->toThrow(InvalidArgumentException::class, 'ne correspond pas');
})->with([['853322915', '732829320'], ['001234567', '000123456'], ['000000000', '000000001']]);

it('rejects malformed identifiers without coercion trimming or adding a prefix', function (mixed $identifier) {
    expect(fn () => FrenchPeppolBt49Candidate::fromIdentifier($identifier, '001234567'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    [null], [false], [true], [123456789], [123456789.0], [[]], [['0225:001234567']], [new stdClass],
    [''], [' '], ['001234567'], ['853322915'],
    ['0002:001234567'], ['0009:001234567'], ['0088:001234567'], ['225:001234567'], ['02250:001234567'],
    ['0225:'], ['0225:00123456'], ['0225:0012345678'], ['0225:00123456A'],
    ['0225:１２３４５６７８９'], ['0225:٠٠١٢٣٤٥٦٧'],
    [' 0225:001234567'], ['0225:001234567 '], ['0225: 001234567'], ['0225 :001234567'],
    ["0225:001234567\n"], ["0225:001234567\r\n"], ["0225:001234567\0"], ["0225:001\t234567"],
    ["0225:001234567\u{00A0}"], ['0225::001234567'], ['0225:001234567:'], ['0225:0225:001234567'],
    ['0225/001234567'], ['0225：001234567'], ['0225:001234567*'],
]);

it('rejects every composed French format including prefixed and unprefixed forms', function (string $identifier) {
    expect(fn () => FrenchPeppolBt49Candidate::fromIdentifier($identifier, '001234567'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '001234567_00123456700012', '0225:001234567_00123456700012',
    '001234567_ACHATS', '0225:001234567_ACHATS', '0225:001234567_achats',
    '001234567_00123456700012_SERVICE', '0225:001234567_00123456700012_SERVICE',
    '0225:001234567_', '0225:001234567_SOUS_SERVICE',
]);

it('explicitly refuses technical reply addresses regardless of case', function (string $identifier) {
    expect(fn () => FrenchPeppolBt49Candidate::fromIdentifier($identifier, '001234567'))
        ->toThrow(InvalidArgumentException::class, 'Une adresse technique _replyto');
})->with([
    '0225:001234567_replyto', '0225:001234567_REPLYTO', '0225:001234567_RePlYtO',
    '001234567_replyto', '0225:001234567_00123456700012_REPLYTO',
    '0225:001234567_replyto_SERVICE',
]);

it('requires an explicitly supplied expected SIREN of exactly nine ASCII digits', function (mixed $expected) {
    expect(fn () => FrenchPeppolBt49Candidate::fromIdentifier('0225:001234567', $expected))
        ->toThrow(InvalidArgumentException::class, 'Le SIREN destinataire attendu');
})->with([
    [null], [false], [1234567], [123456789], [123456789.0], [[]], [['001234567']], [new stdClass],
    [''], ['00123456'], ['0012345670'], ['00123456700012'], ['0225:001234567'], ['00123456A'],
    [' 001234567'], ['001234567 '], ["001234567\n"], ['００１２３４５６７'],
]);

it('keeps the original identifier and candidate pair immutable', function () {
    $candidate = FrenchPeppolBt49Candidate::fromIdentifier('0225:001234567', '001234567');
    foreach (['originalIdentifier', 'scheme', 'value'] as $property) {
        expect(function () use ($candidate, $property) {
            $candidate->{$property} = 'modified';
        })->toThrow(Error::class);
    }
});
