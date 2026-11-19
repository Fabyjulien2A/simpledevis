<?php

use App\Models\Company;
use App\Models\User;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\Emission\SandboxCiiProfile;
use App\Services\SuperPdp\Emission\SandboxEmissionContext;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\SuperPdpPayloadFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->app->instance('env', 'sandbox');
    config([
        'app.env' => 'sandbox', 'services.superpdp.environment' => 'sandbox',
        'services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta',
        'services.superpdp.sandbox_identity' => ['company_id' => '3', 'number_scheme' => 'sandbox', 'number' => '000000002'],
        'database.default' => 'superpdp_sandbox',
        'database.connections' => ['superpdp_sandbox' => ['driver' => 'mysql', 'database' => 'fabyjulien_simpledevis_test']],
    ]);
    // Represent previously loaded records in memory only; never save or query them.
    $this->company = (new Company)->forceFill(['id' => 3, 'user_id' => 3, 'company_name' => 'Burger Queen', 'siret' => null]);
    $this->company->exists = true;
    $this->actingAs((new User)->forceFill(['id' => 3]));
    $this->context = SandboxEmissionContext::forCompany($this->company);
    $exporter = $this->createMock(InvoiceExportDataBuilder::class);
    $exporter->expects($this->never())->method('buildElectronic');
    $this->builder = new SuperPdpInvoicePayloadBuilder($exporter, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class));
    $this->data = SuperPdpPayloadFixture::data();
    // Offline fixture addresses are not claims about either company's actual address.
    $this->data['seller'] = [
        'name' => 'Burger Queen', 'siret' => null, 'country_code' => 'FR',
        'address' => 'Adresse vendeur de test', 'postal_code' => '75001', 'city' => 'Paris',
    ];
    $this->data['buyer'] = [
        'client_type' => 'professional', 'company_name' => 'Tricatel', 'siret' => null, 'country_code' => 'FR',
        'address' => 'Adresse acheteur de test', 'postal_code' => '69001', 'city' => 'Lyon',
    ];
    $this->data['company_vat_regime'] = 'subject_to_vat';
    $this->french = SuperPdpPayloadFixture::frenchContext();
    $this->build = fn () => $this->builder->buildSandboxFromData($this->data, $this->context, $this->company, $this->french);
    Http::preventStrayRequests();
});

afterEach(function () {
    Http::assertNothingSent();
});

it('maps the approved inverse scenario exactly without GlobalID SIRET or a deposit permission', function () {
    $before = [$this->data, $this->context->toArray(), $this->company->getAttributes()];
    $result = ($this->build)();
    expect($result['profile'])->toBe(SandboxCiiProfile::PROFILE)
        ->and($result['ready_for_emission'])->toBeFalse();
    foreach ([
        'seller' => ['Burger Queen', '000000002', '315143296_18172', 'FR18000000002', 'Adresse vendeur de test', '75001', 'Paris'],
        'buyer' => ['Tricatel', '000000001', '315143296_18171', 'FR15000000001', 'Adresse acheteur de test', '69001', 'Lyon'],
    ] as $role => [$name, $number, $address, $vat, $street, $postcode, $city]) {
        expect($result['payload'][$role])->toBe([
            'name' => $name,
            'postal_address' => ['address_line1' => $street, 'post_code' => $postcode, 'city' => $city, 'country_code' => 'FR'],
            'vat_identifier' => $vat,
            'electronic_address' => ['scheme' => '0225', 'value' => $address],
            'legal_registration_identifier' => ['scheme' => '0002', 'value' => $number],
        ]);
    }
    expect($result['payload']['totals']['amount_due_for_payment'])->toBe('72.00')
        ->and($result['payload']['lines'][0]['invoiced_quantity'])->toBe('1.50')
        ->and($result['payload']['notes'])->toBe($this->french['notes'])
        ->and($result['payload'])->not->toHaveKeys(['ready_for_emission', 'profile'])
        ->and($this->context->toArray()['buyer']['routing_confirmation']['api_verified'])->toBeFalse()
        ->and([$this->data, $this->context->toArray(), $this->company->getAttributes()])->toBe($before)
        ->and(config('superpdp_emission.sandbox_enabled'))->toBeFalse();
});

it('keeps the approved mapping immutable and separate from the original context', function () {
    $profile = SandboxCiiProfile::forContext($this->context, $this->company);
    $copy = $profile->toArray();
    $copy['seller']['vat_identifier'] = 'FR00000000000';
    expect($profile->toArray()['seller']['vat_identifier'])->toBe('FR18000000002')
        ->and((new ReflectionClass($profile))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($profile))->getConstructor()->isPrivate())->toBeTrue()
        ->and($this->context->toArray()['seller']['legal_registration_identity'])->toBeNull();
    expect(fn () => $profile->parties = [])->toThrow(Error::class);
});

it('can prepare new custom sandbox invoice lines and a new reference without reusing the fixed first trial', function () {
    $this->data['invoice_number'] = 'SANDBOX-INTERFACE-NEW-CASE-001';
    $line = $this->data['items'][0];
    $this->data['items'] = [
        array_replace($line, ['description' => 'Nouvelle prestation A', 'quantity' => '2.00', 'unit_price_ht' => '15.00', 'line_total_ht' => '30.00']),
        array_replace($line, ['description' => 'Nouvelle prestation B', 'quantity' => '3.00', 'unit_price_ht' => '10.00', 'line_total_ht' => '30.00']),
    ];
    $this->data['totals'] = ['subtotal_ht' => '60.00', 'total_tva' => '12.00', 'total_ttc' => '72.00'];
    $before = $this->company->getAttributes();
    $result = ($this->build)();
    expect($result['payload']['number'])->toBe('SANDBOX-INTERFACE-NEW-CASE-001')
        ->and(array_column($result['payload']['lines'], 'invoiced_quantity'))->toBe(['2.00', '3.00'])
        ->and($result['payload']['totals']['amount_due_for_payment'])->toBe('72.00')
        ->and($result['ready_for_emission'])->toBeFalse()
        ->and($this->company->getAttributes())->toBe($before)->and($this->company->siret)->toBeNull();
});

it('rejects swapping the two parties rather than silently correcting their roles', function () {
    $this->data['seller']['name'] = 'Tricatel';
    $this->data['buyer']['company_name'] = 'Burger Queen';
    expect(fn () => ($this->build)())->toThrow(InvalidArgumentException::class);
});

it('refuses competing identities addresses taxes and ownership claims', function (string $path, mixed $value) {
    data_set($this->data, $path, $value);
    expect(fn () => ($this->build)())->toThrow(InvalidArgumentException::class);
})->with([
    ['seller.name', 'Tricatel'], ['buyer.company_name', 'Burger Queen'],
    ['seller.company_name', 'Tricatel'], ['buyer.name', 'Burger Queen'],
    ['seller.siret', '00000000200000'], ['buyer.siret', '00000000100000'],
    ['seller.siren', '000000002'], ['buyer.siren', '000000001'],
    ['seller.identifiers', [['scheme' => '0009', 'value' => '00000000200000']]],
    ['buyer.identifiers', [['scheme' => '0225', 'value' => '000000001']]],
    ['buyer.global_id', ['scheme' => '0225', 'value' => '000000001']],
    ['seller.legal_registration_identifier', ['scheme' => '0002', 'value' => '000000002']],
    ['buyer.legal_registration_identity', ['scheme' => 'sandbox', 'value' => '000000001']],
    ['seller.superpdp_identity', ['number_scheme' => 'sandbox', 'number' => '000000001']],
    ['seller.electronic_address', ['scheme' => '0225', 'value' => '315143296_18172_replyto']],
    ['buyer.electronic_address', ['scheme' => '0225', 'value' => '315143296_18171_REPLYTO']],
    ['seller.vat_number', 'FR15000000001'], ['buyer.vat_number', 'FR18000000002'],
    ['seller.tax_registration_identifier', '000000002'], ['buyer.vat_identifier', 'FR15000000001'],
    ['buyer.client_type', 'individual'], ['seller.country_code', 'BE'], ['buyer.country_code', 'BE'],
    ['company_id', 4], ['user_id', 4], ['ready_for_emission', true], ['currency_code', 'USD'], ['type_code', 381],
    ['party_snapshot', null], ['emission_context', null], ['emission_profile', 'fr_b2b_380_eur'],
]);

it('rechecks context ownership and sandbox isolation on each build', function (string $change) {
    match ($change) {
        'production' => $this->app->instance('env', 'production'),
        'config' => config(['app.env' => 'production']),
        'provider' => config(['services.superpdp.environment' => 'production']),
        'api' => config(['services.superpdp.api_url' => 'https://example.invalid']),
        'database' => config(['database.default' => 'mysql']),
        'database_name' => config(['database.connections.superpdp_sandbox.database' => 'main']),
        'binding' => config(['services.superpdp.sandbox_identity.company_id' => 4]),
        'number' => config(['services.superpdp.sandbox_identity.number' => '000000001']),
        'company' => $this->company->id = 4,
        'owner' => $this->company->user_id = 4,
        'user' => $this->actingAs((new User)->forceFill(['id' => 4])),
        'guest' => auth()->logout(),
        'unsaved' => $this->company->exists = false,
        'siret' => $this->company->siret = '73282932000074',
    };
    expect(fn () => ($this->build)())->toThrow(RuntimeException::class);
})->with(['production', 'config', 'provider', 'api', 'database', 'database_name', 'binding', 'number', 'company', 'owner', 'user', 'guest', 'unsaved', 'siret']);

it('retains the existing validation failures instead of manufacturing missing data', function (string $path, mixed $value, string $exception) {
    data_set($this->data, $path, $value);
    expect(fn () => ($this->build)())->toThrow($exception);
})->with([
    ['seller.address', null, ValidationException::class],
    ['buyer.postal_code', '', ValidationException::class],
    ['seller.city', '', ValidationException::class],
    ['buyer.address', '', ValidationException::class],
    ['seller.email', 'invalid', InvalidArgumentException::class],
    ['buyer.email', 'invalid', InvalidArgumentException::class],
    ['invoice_number', '', InvalidArgumentException::class],
    ['date', '2026-02-30', InvalidArgumentException::class],
    ['due_date', '2026-13-01', InvalidArgumentException::class],
    ['document_status', 'draft', InvalidArgumentException::class],
    ['payments', [['amount' => '1.00']], InvalidArgumentException::class],
    ['issued_credit_notes', [['id' => 1]], InvalidArgumentException::class],
    ['amount_paid', '1.00', InvalidArgumentException::class],
    ['totals.total_ttc', '72.01', ValidationException::class],
    ['items.0.line_total_ht', '61.00', ValidationException::class],
    ['items.0.unit_price_ht', 40.0, ValidationException::class],
    ['items.0.vat_category', 'Z', ValidationException::class],
    ['calculation_version', null, ValidationException::class],
    ['company_vat_regime', null, ValidationException::class],
    ['company_vat_regime', 'franchise', ValidationException::class],
]);

it('requires payment terms or a valid due date and the explicit business context', function () {
    $this->data['due_date'] = null;
    expect(fn () => ($this->build)())->toThrow(InvalidArgumentException::class);
    $this->data['seller']['payment_terms'] = 'Conditions explicites de test';
    expect(($this->build)()['payload']['payment_terms'])->toBe('Conditions explicites de test');
    $this->french = null;
    expect(fn () => ($this->build)())->toThrow(ValidationException::class);
});

it('does not let a manual business context replace stored context or notes', function () {
    $this->data['french_business_process_type'] = 'M1';
    expect(fn () => ($this->build)())->toThrow(ValidationException::class);
    $this->french['business_process_type'] = 'M1';
    expect(fn () => ($this->build)())->toThrow(ValidationException::class);
    $this->data['company_invoice_mentions'] = array_column($this->french['notes'], 'note', 'subject_code');
    expect(($this->build)()['payload']['process_control']['business_process_type'])->toBe('M1');
});

it('keeps production construction unchanged and rejects the sandbox data through that entry', function () {
    $this->app->instance('env', 'production');
    config(['app.env' => 'production', 'services.superpdp.environment' => 'production']);
    $production = $this->builder->buildFromData(SuperPdpPayloadFixture::data(),
        ['scheme' => '0225', 'value' => '732829320'], frenchContext: SuperPdpPayloadFixture::frenchContext());
    expect($production['seller']['legal_registration_identifier'])->toBe(['value' => '732829320', 'scheme' => '0002'])
        ->and($production['seller']['identifiers'])->toBe([['value' => '73282932000074', 'scheme' => '0009']])
        ->and($production['seller']['vat_identifier'])->toBe('FR44732829320');
    expect(fn () => $this->builder->buildFromData($this->data,
        ['scheme' => '0225', 'value' => '315143296_18172'], frenchContext: $this->french))
        ->toThrow(InvalidArgumentException::class);
});
