<?php

use App\Services\SuperPdp\InvoiceEmissionContext;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->parties = ['seller' => ['siret' => '73282932000074'], 'buyer' => ['siret' => '55210055400013']];
    $this->context = InvoiceEmissionContext::capture(InvoiceEmissionContext::PROFILE,
        ['scheme' => '0225', 'value' => '732829320'], ['scheme' => 'EM', 'value' => 'billing@example.invalid'], $this->parties);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('round trips an explicit context without assuming routing', function () {
    expect(InvoiceEmissionContext::fromJson(json_encode($this->context), $this->parties))->toBe($this->context)
        ->and(InvoiceEmissionContext::capture(null, null, null, $this->parties))->toBeNull();
});

it('rejects invalid raw JSON structures', function (mixed $raw) {
    expect(fn () => InvoiceEmissionContext::fromJson($raw, $this->parties))->toThrow(InvalidArgumentException::class);
})->with(['{', '[]', '{}', 'null', 'false', '"text"', 1, [[1]], null]);

it('rejects duplicated keys including escaped duplicates at any object level', function (string $mode) {
    $raw = json_encode($this->context);
    $raw = match ($mode) {
        'root' => str_replace('"schema_version":1', '"schema_version":1,"schema_version":1', $raw),
        'escaped' => str_replace('"schema_version":1', '"schema_version":1,"schema_versi\u006fn":1', $raw),
        'address' => str_replace('"scheme":"0225"', '"scheme":"0225","scheme":"0225"', $raw),
    };
    expect(fn () => InvoiceEmissionContext::fromJson($raw, $this->parties))->toThrow(InvalidArgumentException::class);
})->with(['root', 'escaped', 'address']);

it('rejects altered shapes and types without coercion', function (string $mode) {
    $data = $this->context;
    match ($mode) {
        'schema_string' => $data['schema_version'] = '1',
        'type_string' => $data['type_code'] = '380',
        'currency' => $data['currency_code'] = 'USD',
        'kind' => $data['document_kind'] = 'deposit',
        'profile' => $data['profile'] = 'other',
        'array_address' => $data['seller_electronic_address'] = [],
        'unknown_address_field' => $data['seller_electronic_address']['verified'] = true,
        'incomplete_address' => $data['seller_electronic_address'] = ['scheme' => '0225', 'value' => null],
        'wrong_scheme_type' => $data['seller_electronic_address']['scheme'] = 225,
    };
    expect(fn () => InvoiceEmissionContext::fromJson(json_encode($data), $this->parties))->toThrow(InvalidArgumentException::class);
})->with(['schema_string', 'type_string', 'currency', 'kind', 'profile', 'array_address', 'unknown_address_field', 'incomplete_address', 'wrong_scheme_type']);
