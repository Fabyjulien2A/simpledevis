<?php

use App\Services\InvoiceLogoReference;
use App\Services\InvoicePartySnapshot;
use App\Services\InvoicePartySnapshotException;

it('roundtrips both snapshot versions without reinterpreting or rewriting version one', function (int $version) {
    $seller = ['company_name' => 'Artisan', 'logo' => $version === 1
        ? 'logos/legacy.png' : 'invoice-logos/v1/12/'.str_repeat('a', 64).'.png'];
    $json = json_encode(InvoicePartySnapshot::capture($seller, [], $version), JSON_THROW_ON_ERROR);
    $parsed = InvoicePartySnapshot::fromJson($json);
    expect($parsed->toArray()['schema_version'])->toBe($version)
        ->and($parsed->toArray()['seller'])->toBe($seller)
        ->and(json_encode($parsed, JSON_THROW_ON_ERROR))->toBe($json);
})->with([1, 2]);

it('allows absent or explicitly null logos in version two without inventing a reference', function (array $seller) {
    $data = InvoicePartySnapshot::capture($seller, [], 2)->toArray();
    expect($data['seller'])->toBe($seller);
})->with([[[]], [['logo' => null]]]);

it('rejects malformed private references in version two without exposing their contents', function (string $reference) {
    expect(fn () => InvoiceLogoReference::parse($reference))->toThrow(InvalidArgumentException::class);
    $json = json_encode(['schema_version' => 2, 'seller' => ['logo' => $reference],
        'buyer' => new stdClass, 'electronic_qualification' => ['status' => 'not_assessed']]);
    expect(fn () => InvoicePartySnapshot::fromJson($json))->toThrow(InvoicePartySnapshotException::class);
})->with([
    '', 'logos/current.png', '../private/secret.png', 'https://example.invalid/logo.png',
    'invoice-logos/v1/0/'.str_repeat('a', 64).'.png',
    'invoice-logos/v1/01/'.str_repeat('a', 64).'.png',
    'invoice-logos/v1/12/'.str_repeat('A', 64).'.png',
    'invoice-logos/v1/12/'.str_repeat('a', 63).'.png',
    'invoice-logos/v2/12/'.str_repeat('a', 64).'.png',
    'invoice-logos/v1/12/'.str_repeat('a', 64).'.svg',
    'invoice-logos/v1/12/'.str_repeat('a', 64).".png\0",
    'invoice-logos/v1/12/'.str_repeat('a', 64).".png\n",
]);
