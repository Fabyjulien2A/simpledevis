<?php

use App\Services\InvoiceElectronicPartyData;
use App\Services\InvoicePartySnapshot;
use App\Services\InvoicePartySnapshotException;

it('keeps explicit synthetic data unchanged when no storage metadata is supplied', function () {
    $data = ['seller' => ['name' => 'Identité synthétique'], 'company_vat_regime' => 'franchise'];
    expect(InvoiceElectronicPartyData::resolve($data))->toBe($data);
});

it('requires snapshot evidence for a persisted preparation', function (array $data) {
    expect(fn () => InvoiceElectronicPartyData::resolve($data, true))->toThrow(InvoicePartySnapshotException::class);
})->with([[[]], [['party_snapshot' => null]], [['party_snapshot' => 'null']]]);

it('reconstructs only the historical buyer name without current country VAT bank mentions or addresses', function () {
    $snapshot = InvoicePartySnapshot::capture(['company_name' => null, 'name' => 'Nom sans priorité', 'vat_number' => null], ['first_name' => 'Zoë', 'last_name' => 'Exemple']);
    $data = [
        'party_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        'seller' => ['country_code' => 'FR', 'iban' => 'IBAN actuel', 'tax_registration_identifier' => '123456789'],
        'buyer' => ['full_name' => 'Nom actuel'], 'company_vat_regime' => 'franchise', 'company_invoice_mentions' => ['PMT' => 'Actuelle'],
    ];
    $result = InvoiceElectronicPartyData::resolve($data);
    expect($result['seller'])->toBe(['name' => null, 'vat_number' => null])
        ->and($result['buyer'])->toBe(['full_name' => 'Zoë Exemple'])
        ->and($result['company_invoice_mentions'])->toBe([])
        ->and($result)->not->toHaveKey('company_vat_regime');
});

it('does not interpret an uncaptured VAT alias as proof of absence for BT32', function () {
    $seller = ['siret' => '00123456700012', 'vat_number' => null, 'vat_regime' => 'franchise'];
    $result = InvoiceElectronicPartyData::resolve(['party_snapshot' => json_encode(InvoicePartySnapshot::capture($seller, []), JSON_THROW_ON_ERROR)]);
    expect($result['seller'])->not->toHaveKey('tax_registration_identifier');
    $seller['tva_number'] = null;
    $result = InvoiceElectronicPartyData::resolve(['party_snapshot' => json_encode(InvoicePartySnapshot::capture($seller, []), JSON_THROW_ON_ERROR)]);
    expect($result['seller']['tax_registration_identifier'])->toBe('001234567');
});

it('preserves captured bank and contact values without creating BT34 or BT49', function () {
    $seller = ['company_name' => 'Entreprise fictive', 'iban' => '  IBAN fourni  ', 'bic' => null, 'email' => 'contact@example.invalid'];
    $buyer = ['company_name' => 'Acheteur fictif', 'email' => 'achats@example.invalid'];
    $result = InvoiceElectronicPartyData::resolve(['party_snapshot' => json_encode(InvoicePartySnapshot::capture($seller, $buyer), JSON_THROW_ON_ERROR)]);
    expect($result['seller']['iban'])->toBe($seller['iban'])->and($result['seller']['bic'])->toBeNull()
        ->and($result['seller'])->not->toHaveKey('electronic_address')
        ->and($result['buyer'])->not->toHaveKey('electronic_address');
});
