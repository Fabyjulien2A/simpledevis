<?php

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('Aucun accès SQL autorisé pour le mapping des identifiants.');
    });
    $this->data = SuperPdpPayloadFixture::data();
    $this->invoice = (new Invoice)->fill([
        'document_status' => 'issued', 'invoice_number' => 'FAC-2026-0001', 'date' => '2026-11-05',
    ]);
    $this->source = $this->createMock(InvoiceExportDataBuilder::class);
    $this->source->expects($this->never())->method('build');
    $this->source->method('buildElectronic')->willReturnCallback(fn () => $this->data);
    $this->builder = new SuperPdpInvoicePayloadBuilder($this->source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class));
    $this->sellerAddress = ['scheme' => '0225', 'value' => 'ADRESSE_EXPLICITE_INDEPENDANTE'];
});

afterEach(function () {
    Http::assertNothingSent();
});

it('maps SIREN to BT30 and the full SIRET to BT29 while preserving leading zeros', function (string $siret, string $siren) {
    $this->data['seller']['siret'] = $siret;
    $before = $this->data;
    $attributes = $this->invoice->getAttributes();
    $payload = $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext());

    expect($payload['seller']['legal_registration_identifier'])->toBe(['value' => $siren, 'scheme' => '0002'])
        ->and($payload['seller']['identifiers'])->toBe([['value' => $siret, 'scheme' => '0009']])
        ->and(strlen($payload['seller']['legal_registration_identifier']['value']))->toBe(9)
        ->and(substr($payload['seller']['identifiers'][0]['value'], 0, 9))->toBe($siren)
        ->and($payload['seller']['electronic_address'])->toBe($this->sellerAddress)
        ->and($this->data)->toBe($before)->and($this->invoice->getAttributes())->toBe($attributes);
    app(SuperPdpInvoicePayloadValidator::class)->validate($payload);
})->with([
    ['73282932000074', '732829320'], ['00123456700012', '001234567'], ['00000000000000', '000000000'],
]);

it('rejects missing or malformed SIRETs rather than generating an identifier', function (mixed $siret) {
    $this->data['seller']['siret'] = $siret;
    expect(fn () => $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(InvalidArgumentException::class);
})->with([
    [null], [''], [' '], [73282932000074], [false], [[]],
    ['732829320'], ['7328293200007'], ['732829320000740'], ['7328293200007A'],
    ['732829 32000074'], ["73282932\n000074"], ['７３２８２９３２００００７４'],
]);

it('rejects an absent SIRET even when a VAT number and electronic address exist', function () {
    unset($this->data['seller']['siret']);
    expect(fn () => $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))
        ->toThrow(InvalidArgumentException::class, 'Le SIRET du vendeur est obligatoire.');
});

it('preserves additional seller identifiers and appends the stored SIRET without overwriting them', function () {
    $identifiers = [
        ['value' => '0000000000000', 'scheme' => '0088'],
        ['value' => '73282932000082', 'scheme' => '0009'],
    ];
    $this->data['seller']['identifiers'] = $identifiers;
    $payload = $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect($payload['seller']['identifiers'])->toBe([...$identifiers, ['value' => '73282932000074', 'scheme' => '0009']])
        ->and($this->data['seller']['identifiers'])->toBe($identifiers)
        ->and($payload['seller']['vat_identifier'])->toBe('FR44732829320');
    app(SuperPdpInvoicePayloadValidator::class)->validate($payload);
});

it('keeps an existing matching SIRET without duplicating it or removing other identifiers', function () {
    $identifiers = [
        ['scheme' => '0088', 'value' => '0000000000000'],
        ['scheme' => '0009', 'value' => '73282932000074'],
    ];
    $this->data['seller']['identifiers'] = $identifiers;
    expect($this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext())['seller']['identifiers'])->toBe($identifiers);
});

it('refuses invalid or incoherent supplied identifiers instead of silently overwriting them', function (mixed $identifiers) {
    $this->data['seller']['identifiers'] = $identifiers;
    expect(fn () => $this->builder->build($this->invoice, $this->sellerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext()))->toThrow(InvalidArgumentException::class);
    expect($this->data['seller']['identifiers'])->toBe($identifiers);
})->with([
    ['invalid list'], [['scheme' => '0009', 'value' => '73282932000074']], [[false]],
    [[['scheme' => '0009']]], [[['value' => '73282932000074']]],
    [[['scheme' => '0009', 'value' => '55210055400013']]],
    [[['scheme' => '0009', 'value' => '732829320']]],
    [[['scheme' => '0009', 'value' => '7328293200007A']]],
    [[['scheme' => '0009', 'value' => ' 73282932000074 ']]],
]);

it('changes only seller legal and establishment identifiers with buyer fiscal data and routing untouched', function () {
    $this->data['buyer'] = array_replace($this->data['buyer'], [
        'client_type' => 'professional', 'company_name' => 'Entreprise cliente',
        'siret' => '55210055400013', 'vat_number' => 'FR96552100554',
    ]);
    $buyerAddress = ['scheme' => 'EM', 'value' => 'buyer@example.invalid'];
    $baseline = $this->builder->build($this->invoice, $this->sellerAddress, $buyerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext());
    $this->data['seller']['siret'] = '00123456700012';
    $updated = $this->builder->build($this->invoice, $this->sellerAddress, $buyerAddress, frenchContext: SuperPdpPayloadFixture::frenchContext());
    foreach (['legal_registration_identifier', 'identifiers'] as $key) {
        unset($baseline['seller'][$key], $updated['seller'][$key]);
    }
    expect($updated)->toBe($baseline)
        ->and($updated['buyer']['legal_registration_identifier'])->toBe(['value' => '552100554', 'scheme' => '0002'])
        ->and($updated['buyer']['identifiers'])->toBe([['value' => '55210055400013', 'scheme' => '0009']])
        ->and($updated['buyer']['electronic_address'])->toBe($buyerAddress)
        ->and($updated['seller']['electronic_address'])->toBe($this->sellerAddress)
        ->and($updated['notes'])->toBe(SuperPdpPayloadFixture::frenchContext()['notes'])
        ->and($updated['process_control'])->toBe(['specification_identifier' => 'urn:cen.eu:en16931:2017', 'business_process_type' => 'S1']);
});
