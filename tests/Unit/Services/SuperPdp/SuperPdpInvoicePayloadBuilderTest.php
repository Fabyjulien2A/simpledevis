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
    $this->sellerData = [
        'name' => 'Atelier Martin',
        'siret' => '73282932000074',
        'vat_number' => 'FR44732829320',
        'address' => '12 rue des Artisans',
        'postal_code' => '75012',
        'city' => 'Paris',
        'country_code' => 'FR',
        'email' => 'contact@example.com',
        'phone' => '+33 1 23 45 67 89',
    ];
    $this->electronicAddress = ['scheme' => '0225', 'value' => '732829320_ATELIER'];
    $this->issuedInvoice = (new Invoice)->setDateFormat('Y-m-d H:i:s')->fill([
        'document_status' => 'issued',
        'invoice_number' => 'FAC-2026-0001',
        'date' => '2026-11-05',
    ]);
    $this->dataBuilder = $this->createMock(InvoiceExportDataBuilder::class);
    $this->payloadBuilder = new SuperPdpInvoicePayloadBuilder($this->dataBuilder, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class));
    $this->exportData = array_replace(SuperPdpPayloadFixture::data(), [
        'invoice_number' => 'FAC-2026-0001',
        'date' => '2026-11-05',
        'due_date' => '2026-12-05',
        'seller' => $this->sellerData,
        'buyer' => [
            'client_type' => 'individual',
            'full_name' => 'Marie Dupont',
            'address' => '3 rue du Moulin',
            'postal_code' => '69002',
            'city' => 'Lyon',
            'country_code' => 'FR',
        ],
    ]);
});

it('builds the existing header and the exact seller structure for an issued invoice', function () {
    $invoice = (new Invoice)->setDateFormat('Y-m-d H:i:s')->fill([
        'document_status' => 'issued',
        'invoice_number' => 'FAC-2026-0001',
        'date' => '2026-11-05',
    ]);

    $dataBuilder = $this->createMock(InvoiceExportDataBuilder::class);
    $dataBuilder->expects($this->once())
        ->method('buildElectronic')
        ->with($this->identicalTo($invoice))
        ->willReturn($this->exportData);

    $payload = (new SuperPdpInvoicePayloadBuilder($dataBuilder, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class)))->build($invoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext());

    expect(array_intersect_key($payload, array_flip(['number', 'issue_date', 'payment_due_date', 'currency_code', 'seller', 'buyer'])))->toEqual([
        'number' => 'FAC-2026-0001',
        'issue_date' => '2026-11-05',
        'payment_due_date' => '2026-12-05',
        'currency_code' => 'EUR',
        'seller' => [
            'name' => 'Atelier Martin',
            'legal_registration_identifier' => [
                'value' => '732829320',
                'scheme' => '0002',
            ],
            'identifiers' => [['value' => '73282932000074', 'scheme' => '0009']],
            'electronic_address' => ['scheme' => '0225', 'value' => '732829320_ATELIER'],
            'postal_address' => [
                'address_line1' => '12 rue des Artisans',
                'post_code' => '75012',
                'city' => 'Paris',
                'country_code' => 'FR',
            ],
            'vat_identifier' => 'FR44732829320',
            'contact' => [
                'email_address' => 'contact@example.com',
                'phone_number' => '+33 1 23 45 67 89',
            ],
        ],
        'buyer' => [
            'name' => 'Marie Dupont',
            'postal_address' => [
                'address_line1' => '3 rue du Moulin',
                'post_code' => '69002',
                'city' => 'Lyon',
                'country_code' => 'FR',
            ],
        ],
    ]);
});

it('rejects a draft before exporting any data', function () {
    $invoice = (new Invoice)->setDateFormat('Y-m-d H:i:s')->fill([
        'document_status' => 'draft',
        'invoice_number' => 'FAC-2026-0001',
        'date' => '2026-11-05',
    ]);

    $dataBuilder = $this->createMock(InvoiceExportDataBuilder::class);
    $dataBuilder->expects($this->never())->method('buildElectronic');

    expect(fn () => (new SuperPdpInvoicePayloadBuilder($dataBuilder, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class)))->build($invoice, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'Seule une facture définitive peut être préparée pour SUPER PDP.');
});

it('rejects an issued invoice without a number before exporting any data', function (?string $number) {
    $invoice = (new Invoice)->setDateFormat('Y-m-d H:i:s')->fill([
        'document_status' => 'issued',
        'invoice_number' => $number,
        'date' => '2026-11-05',
    ]);

    $dataBuilder = $this->createMock(InvoiceExportDataBuilder::class);
    $dataBuilder->expects($this->never())->method('buildElectronic');

    expect(fn () => (new SuperPdpInvoicePayloadBuilder($dataBuilder, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class)))->build($invoice, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'La facture doit posséder un numéro avant sa préparation pour SUPER PDP.');
})->with([
    'null number' => [null],
    'empty number' => [''],
    'whitespace number' => ['   '],
]);

it('rejects an issued invoice without an issue date before exporting any data', function () {
    $invoice = (new Invoice)->setDateFormat('Y-m-d H:i:s')->fill([
        'document_status' => 'issued',
        'invoice_number' => 'FAC-2026-0001',
        'date' => null,
    ]);

    $dataBuilder = $this->createMock(InvoiceExportDataBuilder::class);
    $dataBuilder->expects($this->never())->method('buildElectronic');

    expect(fn () => (new SuperPdpInvoicePayloadBuilder($dataBuilder, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class)))->build($invoice, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'La facture doit posséder une date d’émission avant sa préparation pour SUPER PDP.');
});

it('rejects an omitted electronic address even when a contact email and SIRET exist', function () {
    $this->dataBuilder->expects($this->never())->method('buildElectronic');

    expect(fn () => $this->payloadBuilder->build($this->issuedInvoice, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'L’adresse électronique de facturation du vendeur doit être fournie explicitement.');
});

it('rejects an explicitly null electronic address', function () {
    $this->dataBuilder->expects($this->never())->method('buildElectronic');

    expect(fn () => $this->payloadBuilder->build($this->issuedInvoice, null, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'L’adresse électronique de facturation du vendeur doit être fournie explicitement.');
});

it('rejects an incomplete electronic address before exporting', function (array $address, string $message) {
    $this->dataBuilder->expects($this->never())->method('buildElectronic');

    expect(fn () => $this->payloadBuilder->build($this->issuedInvoice, $address, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing scheme' => [['value' => '732829320'], 'Le schéma de l’adresse électronique du vendeur est obligatoire.'],
    'blank scheme' => [['scheme' => '  ', 'value' => '732829320'], 'Le schéma de l’adresse électronique du vendeur est obligatoire.'],
    'numeric scheme' => [['scheme' => 225, 'value' => '732829320'], 'Le schéma de l’adresse électronique du vendeur doit être une chaîne de caractères.'],
    'missing value' => [['scheme' => '0225'], 'La valeur de l’adresse électronique du vendeur est obligatoire.'],
    'null value' => [['scheme' => '0225', 'value' => null], 'La valeur de l’adresse électronique du vendeur est obligatoire.'],
    'blank value' => [['scheme' => '0225', 'value' => ' '], 'La valeur de l’adresse électronique du vendeur est obligatoire.'],
    'numeric value' => [['scheme' => '0225', 'value' => 732829320], 'La valeur de l’adresse électronique du vendeur doit être une chaîne de caractères.'],
]);

it('rejects missing seller details', function (string $field, string $label) {
    unset($this->exportData['seller'][$field]);
    $this->dataBuilder->method('buildElectronic')->willReturn($this->exportData);

    expect(fn () => $this->payloadBuilder->build($this->issuedInvoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, $label.' est obligatoire.');
})->with([
    'name' => ['name', 'Le nom du vendeur'],
    'siret' => ['siret', 'Le SIRET du vendeur'],
    'address' => ['address', 'L’adresse postale du vendeur'],
    'postal code' => ['postal_code', 'Le code postal du vendeur'],
    'city' => ['city', 'La ville du vendeur'],
    'country' => ['country_code', 'Le code pays du vendeur'],
]);

it('rejects blank or null required seller details', function (string $field, mixed $value) {
    $this->exportData['seller'][$field] = $value;
    $this->dataBuilder->method('buildElectronic')->willReturn($this->exportData);

    expect(fn () => $this->payloadBuilder->build($this->issuedInvoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class);
})->with(['name', 'siret', 'address', 'postal_code', 'city', 'country_code'])
    ->with(['null' => [null], 'empty' => [''], 'whitespace' => ['   ']]);

it('rejects a missing seller block', function () {
    unset($this->exportData['seller']);
    $this->dataBuilder->method('buildElectronic')->willReturn($this->exportData);

    expect(fn () => $this->payloadBuilder->build($this->issuedInvoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'Le nom du vendeur est obligatoire.');
});

it('rejects a malformed SIRET without replacing it with a SIREN', function (string $siret) {
    $this->exportData['seller']['siret'] = $siret;
    $this->dataBuilder->method('buildElectronic')->willReturn($this->exportData);

    expect(fn () => $this->payloadBuilder->build($this->issuedInvoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'Le SIRET du vendeur doit contenir exactement 14 chiffres.');
})->with(['732829320', '7328293200007A', '732829320000740']);

it('rejects a malformed country code', function () {
    $this->exportData['seller']['country_code'] = 'France';
    $this->dataBuilder->method('buildElectronic')->willReturn($this->exportData);

    expect(fn () => $this->payloadBuilder->build($this->issuedInvoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'Le code pays du vendeur doit contenir deux lettres majuscules.');
});

it('omits absent optional VAT and contact details without inventing values', function () {
    unset($this->exportData['seller']['vat_number']);
    $this->exportData['seller']['email'] = null;
    $this->exportData['seller']['phone'] = '  ';
    $this->dataBuilder->method('buildElectronic')->willReturn($this->exportData);

    $seller = $this->payloadBuilder->build($this->issuedInvoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext())['seller'];

    expect($seller)->not->toHaveKey('vat_identifier')
        ->not->toHaveKey('contact')
        ->and($seller['electronic_address'])->toBe($this->electronicAddress);
});

it('includes only the available contact detail', function (string $missingField, string $expectedKey, string $expectedValue) {
    unset($this->exportData['seller'][$missingField]);
    $this->dataBuilder->method('buildElectronic')->willReturn($this->exportData);

    expect($this->payloadBuilder->build($this->issuedInvoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext())['seller']['contact'])
        ->toBe([$expectedKey => $expectedValue]);
})->with([
    'email only' => ['phone', 'email_address', 'contact@example.com'],
    'phone only' => ['email', 'phone_number', '+33 1 23 45 67 89'],
]);

it('rejects an invalid supplied contact email', function () {
    $this->exportData['seller']['email'] = 'not-an-email';
    $this->dataBuilder->method('buildElectronic')->willReturn($this->exportData);

    expect(fn () => $this->payloadBuilder->build($this->issuedInvoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'L’e-mail de contact du vendeur est invalide.');
});

it('keeps the electronic address specific to each build', function () {
    $this->dataBuilder->method('buildElectronic')->willReturn($this->exportData);
    $otherAddress = ['scheme' => '0009', 'value' => '73282932000074'];

    $first = $this->payloadBuilder->build($this->issuedInvoice, $this->electronicAddress, frenchContext: SuperPdpPayloadFixture::frenchContext());
    $second = $this->payloadBuilder->build($this->issuedInvoice, $otherAddress, frenchContext: SuperPdpPayloadFixture::frenchContext());

    expect($first['seller']['electronic_address'])->toBe($this->electronicAddress)
        ->and($second['seller']['electronic_address'])->toBe($otherAddress);
});
