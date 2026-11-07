<?php

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->invoice = (new Invoice)->setDateFormat('Y-m-d H:i:s')->fill([
        'document_status' => 'issued',
        'invoice_number' => 'FAC-2026-0001',
        'date' => '2026-11-05',
    ]);
    $this->sellerAddress = ['scheme' => '0225', 'value' => '732829320_ATELIER'];
    $this->buyerData = [
        'client_type' => 'professional',
        'company_name' => 'Entreprise Dupont',
        'full_name' => 'Marie Dupont',
        'siret' => '55210055400013',
        'vat_number' => 'FR96552100554',
        'address' => '3 rue du Moulin',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'country_code' => 'FR',
        'email' => 'achats@example.com',
        'phone' => '+33 4 12 34 56 78',
    ];
    $this->dataBuilder = $this->createMock(InvoiceExportDataBuilder::class);
    $this->builder = new SuperPdpInvoicePayloadBuilder($this->dataBuilder, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class));
    $this->dataBuilder->method('buildElectronic')->willReturnCallback(fn () => array_replace(SuperPdpPayloadFixture::data(), [
        'invoice_number' => 'FAC-2026-0001',
        'date' => '2026-11-05',
        'due_date' => '2026-12-05',
        'seller' => [
            'name' => 'Atelier Martin',
            'siret' => '73282932000074',
            'address' => '12 rue des Artisans',
            'postal_code' => '75012',
            'city' => 'Paris',
            'country_code' => 'FR',
        ],
        'buyer' => $this->buyerData,
    ]));
});

it('maps a French professional with distinct legal and establishment identifiers', function () {
    $address = ['scheme' => '0225', 'value' => '552100554_ACHATS'];
    $payload = $this->builder->build($this->invoice, $this->sellerAddress, $address, frenchContext: SuperPdpPayloadFixture::frenchContext());

    expect($payload['buyer'])->toBe([
        'name' => 'Entreprise Dupont',
        'postal_address' => [
            'address_line1' => '3 rue du Moulin',
            'post_code' => '69002',
            'city' => 'Lyon',
            'country_code' => 'FR',
        ],
        'legal_registration_identifier' => ['value' => '552100554', 'scheme' => '0002'],
        'identifiers' => [['value' => '55210055400013', 'scheme' => '0009']],
        'vat_identifier' => 'FR96552100554',
        'contact' => ['email_address' => 'achats@example.com', 'phone_number' => '+33 4 12 34 56 78'],
        'electronic_address' => $address,
    ])->and($payload['seller']['electronic_address'])->toBe($this->sellerAddress)
        ->and($payload)->not->toHaveKey('processing_rule');
});

it('uses the real personal name for a sole trader without a company name', function () {
    $this->buyerData['company_name'] = '  ';

    expect($this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext())['buyer']['name'])->toBe('Marie Dupont');
});

it('constructs a professional buyer without optional identifiers or routing data', function () {
    unset($this->buyerData['siret'], $this->buyerData['vat_number'], $this->buyerData['email'], $this->buyerData['phone']);

    $buyer = $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext())['buyer'];

    expect($buyer)->not->toHaveKey('legal_registration_identifier')
        ->not->toHaveKey('identifiers')
        ->not->toHaveKey('vat_identifier')
        ->not->toHaveKey('electronic_address')
        ->not->toHaveKey('contact');
});

it('constructs an individual without requiring or copying business identifiers', function () {
    $this->buyerData['client_type'] = 'individual';
    // Business fields must not turn an individual into a professional buyer.
    $buyer = $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext())['buyer'];

    expect($buyer['name'])->toBe('Marie Dupont')
        ->and($buyer)->not->toHaveKey('legal_registration_identifier')
        ->not->toHaveKey('identifiers')
        ->not->toHaveKey('vat_identifier')
        ->not->toHaveKey('electronic_address');
});

it('preserves an individual country without substituting France', function () {
    $this->buyerData['client_type'] = 'individual';
    $this->buyerData['country_code'] = 'BE';
    $this->buyerData['postal_code'] = '1000';
    $this->buyerData['city'] = 'Bruxelles';

    expect($this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext())['buyer']['postal_address']['country_code'])->toBe('BE');
});

it('accepts an explicitly supplied address for either client type', function (string $clientType) {
    $this->buyerData['client_type'] = $clientType;
    $address = ['scheme' => 'EM', 'value' => 'factures@example.com'];

    $buyer = $this->builder->build($this->invoice, $this->sellerAddress, $address, frenchContext: SuperPdpPayloadFixture::frenchContext())['buyer'];

    expect($buyer['electronic_address'])->toBe($address)
        ->and($buyer['contact']['email_address'])->toBe('achats@example.com');
})->with(['professional', 'individual']);

it('omits a null buyer address and never derives one from the contact email', function () {
    expect($this->builder->build($this->invoice, $this->sellerAddress, null, frenchContext: SuperPdpPayloadFixture::frenchContext())['buyer'])
        ->not->toHaveKey('electronic_address');
});

it('keeps buyer routing data separate between successive calls', function () {
    $withAddress = $this->builder->build($this->invoice, $this->sellerAddress, ['scheme' => '0225', 'value' => '552100554'], frenchContext: SuperPdpPayloadFixture::frenchContext());
    $withoutAddress = $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext());

    expect($withAddress['buyer'])->toHaveKey('electronic_address')
        ->and($withoutAddress['buyer'])->not->toHaveKey('electronic_address');
});

it('omits blank optional contact and VAT fields', function () {
    $this->buyerData['email'] = null;
    $this->buyerData['phone'] = '';
    $this->buyerData['vat_number'] = '  ';

    expect($this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext())['buyer'])
        ->not->toHaveKey('contact')->not->toHaveKey('vat_identifier');
});

it('includes only the available buyer contact detail', function (string $missing, string $key, string $value) {
    unset($this->buyerData[$missing]);

    expect($this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext())['buyer']['contact'])->toBe([$key => $value]);
})->with([
    ['phone', 'email_address', 'achats@example.com'],
    ['email', 'phone_number', '+33 4 12 34 56 78'],
]);

it('rejects fictitious or absent identities', function (string $clientType, ?string $name) {
    $this->buyerData['client_type'] = $clientType;
    $this->buyerData['company_name'] = $name;
    $this->buyerData['full_name'] = $name;

    expect(fn () => $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(InvalidArgumentException::class);
})->with(['professional', 'individual'])
    ->with(['placeholder' => ['Client sans nom'], 'case and whitespace' => [' CLIENT   SANS NOM '], 'missing' => [null], 'empty' => [' ']]);

it('rejects incomplete addresses instead of inventing them', function (string $field) {
    unset($this->buyerData[$field]);

    expect(fn () => $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(InvalidArgumentException::class);
})->with(['address', 'postal_code', 'city', 'country_code']);

it('rejects malformed buyer data with explicit errors', function (string $field, mixed $value, string $message) {
    $this->buyerData[$field] = $value;

    expect(fn () => $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['client_type', 'unknown', 'Le type de client doit être professional ou individual.'],
    ['country_code', 'France', 'Le code pays de l’acheteur doit contenir deux lettres majuscules.'],
    ['country_code', 'BE', 'Le mapping des acheteurs professionnels étrangers n’est pas encore pris en charge.'],
    ['siret', '552100554', 'Le SIRET de l’acheteur doit contenir exactement 14 chiffres.'],
    ['siret', '5521005540001A', 'Le SIRET de l’acheteur doit contenir exactement 14 chiffres.'],
    ['siret', 55210055400013, 'Le SIRET de l’acheteur doit être une chaîne de caractères.'],
    ['vat_number', '96552100554', 'Le numéro de TVA de l’acheteur doit comporter un préfixe pays et une valeur alphanumérique.'],
    ['vat_number', [], 'Le numéro de TVA de l’acheteur doit être une chaîne de caractères.'],
    ['email', 'invalid', 'L’e-mail de contact de l’acheteur est invalide.'],
    ['phone', [], 'Le téléphone de l’acheteur doit être une chaîne de caractères.'],
]);

it('rejects an incomplete supplied buyer electronic address', function (array $address, string $message) {
    expect(fn () => $this->builder->build($this->invoice, $this->sellerAddress, $address, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    [[], 'Le schéma de l’adresse électronique de l’acheteur est obligatoire.'],
    [['scheme' => '0225'], 'La valeur de l’adresse électronique de l’acheteur est obligatoire.'],
    [['scheme' => ' ', 'value' => '552100554'], 'Le schéma de l’adresse électronique de l’acheteur est obligatoire.'],
    [['scheme' => '0225', 'value' => null], 'La valeur de l’adresse électronique de l’acheteur est obligatoire.'],
    [['scheme' => 225, 'value' => '552100554'], 'Le schéma de l’adresse électronique de l’acheteur doit être une chaîne de caractères.'],
    [['scheme' => '0225', 'value' => 552100554], 'La valeur de l’adresse électronique de l’acheteur doit être une chaîne de caractères.'],
]);
