<?php

use App\Services\SuperPdp\FrenchDirectoryEntriesAnalysis;
use App\Services\SuperPdp\FrenchPeppolBt49Candidate;

function analysisDirectoryLine(mixed $identifier = '0225:001234567', mixed $active = true, mixed $siren = '001234567'): array
{
    return [
        'company' => ['number' => $siren, 'formal_name' => 'Entreprise fictive',
            'address' => '1 rue Fictive', 'postcode' => '75001', 'city' => 'Paris', 'country' => 'FR'],
        'identifier' => $identifier, 'is_active' => $active,
    ];
}

it('analyzes an active simple identifier as a candidate only and preserves all source data', function (string $siren) {
    $lines = [analysisDirectoryLine('0225:'.$siren, true, $siren)];
    $before = $lines;
    $result = (new FrenchDirectoryEntriesAnalysis)->analyze($siren, $lines);
    $row = $result['lines'][0];
    expect($row['source_index'])->toBe(0)->and($row['line'])->toBe($before[0])
        ->and($row['classification'])->toBe('active_candidate')
        ->and($row['identifier_status'])->toBe('compatible')
        ->and($row['bt49_candidate'])->toBeInstanceOf(FrenchPeppolBt49Candidate::class)
        ->and($row['bt49_candidate']->originalIdentifier)->toBe('0225:'.$siren)
        ->and($row['bt49_candidate']->scheme)->toBe('0225')
        ->and($row['bt49_candidate']->value)->toBe($siren)
        ->and($result['pagination'])->toBe([])->and($lines)->toBe($before)
        ->and($result)->toHaveKeys(['lines', 'pagination'])
        ->and(array_keys($result))->toBe(['lines', 'pagination'])
        ->and(array_keys($row))->toBe(['source_index', 'line', 'classification', 'identifier_status', 'reason', 'bt49_candidate']);
})->with(['001234567', '000000000', '853322915']);

it('keeps mixed lines and duplicate candidates in their original global order without selecting one', function () {
    $lines = [
        analysisDirectoryLine(), analysisDirectoryLine('0225:001234567_ACHATS'),
        analysisDirectoryLine('0225:001234567', false), analysisDirectoryLine('0225:999999999'),
        analysisDirectoryLine(), analysisDirectoryLine('0225:001234567_REPLYTO'),
    ];
    $before = $lines;
    $result = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', $lines, ['has_more' => true]);
    expect(array_column($result['lines'], 'source_index'))->toBe([0, 1, 2, 3, 4, 5])
        ->and(array_column($result['lines'], 'line'))->toBe($before)
        ->and(array_column($result['lines'], 'classification'))->toBe([
            'active_candidate', 'unsupported_format', 'inactive', 'invalid_identifier', 'active_candidate', 'invalid_identifier',
        ])->and($result['pagination'])->toBe(['has_more' => true])->and($lines)->toBe($before);
    expect($result['lines'][0]['bt49_candidate']->value)->toBe($result['lines'][4]['bt49_candidate']->value);
});

it('classifies inactive rows separately while retaining the identifier assessment and no candidate', function (mixed $identifier, string $status, string $reason) {
    $line = analysisDirectoryLine($identifier, false);
    $row = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', [$line])['lines'][0];
    expect($row['classification'])->toBe('inactive')->and($row['line'])->toBe($line)
        ->and($row['identifier_status'])->toBe($status)->and($row['reason'])->toBe($reason)
        ->and($row['bt49_candidate'])->toBeNull();
})->with([
    ['0225:001234567', 'compatible', 'simple_peppol_identifier'],
    ['0225:001234567_ACHATS', 'unsupported', 'composed_format'],
    ['0225:999999999', 'invalid', 'identifier_siren_mismatch'],
    ['0225:001234567_replyto', 'invalid', 'technical_reply_address'],
    [null, 'invalid', 'malformed_identifier'],
]);

it('recognizes unsupported format families without treating them as missing destinations', function (string $identifier, string $reason) {
    $row = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', [analysisDirectoryLine($identifier)])['lines'][0];
    expect($row['classification'])->toBe('unsupported_format')
        ->and($row['identifier_status'])->toBe('unsupported')->and($row['reason'])->toBe($reason)
        ->and($row['line']['identifier'])->toBe($identifier)->and($row['line']['is_active'])->toBeTrue()
        ->and($row['bt49_candidate'])->toBeNull();
})->with([
    ['001234567', 'missing_peppol_prefix'],
    ['001234567_ACHATS', 'missing_peppol_prefix'],
    ['001234567_00123456700012', 'missing_peppol_prefix'],
    ['001234567_00123456700012_SERVICE', 'missing_peppol_prefix'],
    ['0225:001234567_ACHATS', 'composed_format'],
    ['0225:001234567_achats', 'composed_format'],
    ['0225:001234567_00123456700012', 'composed_format'],
    ['0225:001234567_00123456700012_SERVICE', 'composed_format'],
    ['0225:001234567_SOUS_SERVICE', 'composed_format'],
    ['0088:1234567890123', 'other_peppol_scheme'],
]);

it('rejects technical return identifiers before unsupported-format recognition regardless of case', function (string $identifier) {
    $row = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', [analysisDirectoryLine($identifier)])['lines'][0];
    expect($row['classification'])->toBe('invalid_identifier')
        ->and($row['reason'])->toBe('technical_reply_address')->and($row['bt49_candidate'])->toBeNull();
})->with(['0225:001234567_replyto', '0225:001234567_REPLYTO', '001234567_RePlYtO',
    '0225:001234567_replyto_SERVICE', '0225:001234567_00123456700012_REPLYTO']);

it('reports malformed identifiers independently without losing the surrounding valid rows', function (mixed $identifier) {
    $lines = [analysisDirectoryLine(), analysisDirectoryLine($identifier), analysisDirectoryLine()];
    $result = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', $lines);
    expect(array_column($result['lines'], 'classification'))->toBe(['active_candidate', 'invalid_identifier', 'active_candidate'])
        ->and($result['lines'][1]['line']['identifier'])->toBe($identifier)
        ->and($result['lines'][1]['reason'])->toBe('malformed_identifier')
        ->and($result['lines'][1]['bt49_candidate'])->toBeNull();
})->with([
    [null], [false], [123456789], [[]], [''], [' '], ['0225:'], ['0225:00123456'], ['0225:0012345678'],
    ['0225:00123456A'], ['0225::001234567'], ['0225:001234567:'], ['0225:001234567_'],
    [' 0225:001234567'], ['0225:001234567 '], ["0225:001234567\n"], ['0225:００１２３４５６７'],
]);

it('distinguishes mismatching identifier SIRENs even on unsupported families', function (string $identifier) {
    $row = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', [analysisDirectoryLine($identifier)])['lines'][0];
    expect($row['classification'])->toBe('invalid_identifier')
        ->and($row['reason'])->toBe('identifier_siren_mismatch')->and($row['bt49_candidate'])->toBeNull();
})->with(['0225:999999999', '999999999', '0225:999999999_ACHATS', '999999999_00123456700012_SERVICE']);

it('does not trust a missing or contradictory company identity even on inactive rows', function (mixed $number, bool $active) {
    $line = analysisDirectoryLine('0225:001234567', $active, $number);
    $row = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', [$line])['lines'][0];
    expect($row['classification'])->toBe('invalid_identifier')->and($row['line'])->toBe($line)
        ->and($row['reason'])->toBe($number === '999999999' ? 'company_siren_mismatch' : 'invalid_company_siren')
        ->and($row['bt49_candidate'])->toBeNull();
})->with([[null], [123456789], [''], ['00123456'], ['999999999']])->with([true, false]);

it('preserves malformed lines and invalid activity values as invalid data rather than silently dropping them', function (mixed $line) {
    $row = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', [$line])['lines'][0];
    expect($row['classification'])->toBe('invalid_identifier')->and($row['line'])->toBe($line)
        ->and($row['bt49_candidate'])->toBeNull();
})->with([
    [null], ['not an array'], [[]],
    [array_diff_key(analysisDirectoryLine(), ['company' => true])],
    [array_diff_key(analysisDirectoryLine(), ['is_active' => true])],
    [analysisDirectoryLine('0225:001234567', null)],
    [analysisDirectoryLine('0225:001234567', 'true')],
    [analysisDirectoryLine('0225:001234567', 1)],
]);

it('never fabricates a candidate from the expected SIREN on an empty response and preserves pagination exactly', function (array $pagination) {
    $before = $pagination;
    $result = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', [], $pagination);
    expect($result)->toBe(['lines' => [], 'pagination' => $before])->and($pagination)->toBe($before);
})->with([
    [[]], [['has_more' => false]], [['has_more' => true]],
    [['has_more' => true, 'next_cursor' => 'OPAQUE-CURSOR', 'page' => 3]],
    [['next_cursor' => null]],
]);

it('preserves available pagination on nonempty results without filtering or following it', function () {
    $pagination = ['has_more' => true, 'opaque' => ['token' => 'UNCHANGED']];
    $lines = [analysisDirectoryLine(), analysisDirectoryLine('0225:001234567_ACHATS')];
    $result = (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', $lines, $pagination);
    expect($result['pagination'])->toBe($pagination)->and(count($result['lines']))->toBe(2);
});

it('rejects an invalid expected SIREN even when no lines were returned', function (mixed $siren) {
    expect(fn () => (new FrenchDirectoryEntriesAnalysis)->analyze($siren, []))->toThrow(InvalidArgumentException::class);
})->with([[null], [false], [123456789], [[]], [''], ['00123456'], ['0012345670'], ['0225:001234567'],
    [' 001234567'], ["001234567\n"], ['００１２３４５６７']]);

it('rejects an unordered collection rather than renumbering or reordering it', function () {
    expect(fn () => (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', [2 => analysisDirectoryLine()]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects malformed has_more metadata without coercion', function (mixed $more) {
    expect(fn () => (new FrenchDirectoryEntriesAnalysis)->analyze('001234567', [], ['has_more' => $more]))
        ->toThrow(InvalidArgumentException::class);
})->with([[null], ['false'], [0], [1], [[]]]);
