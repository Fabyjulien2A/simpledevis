<?php

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\Sandbox\SandboxConversionProbe;
use App\Services\SuperPdp\Sandbox\SandboxFranchiseInvoiceFixture as Franchise;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\CapturedSandboxCii;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('Aucun accès SQL autorisé pour la fiction franchise.');
    });
    Http::preventStrayRequests();
    Http::fake();
    config(['services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta']);
    $this->fixture = new Franchise;
    $this->data = $this->fixture->buildElectronic($this->fixture->invoice());
    $this->source = $this->createMock(InvoiceExportDataBuilder::class);
    $this->source->expects($this->never())->method('build');
    $this->source->method('buildElectronic')->willReturnCallback(fn () => $this->data);
    $this->builder = new SuperPdpInvoicePayloadBuilder(
        $this->source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class),
    );
    $this->buildFranchise = fn () => $this->builder->build(
        $this->fixture->invoice(), ['scheme' => 'EM', 'value' => 'vendeur-fictif@example.invalid'],
        ['scheme' => 'EM', 'value' => 'acheteur-fictif@example.invalid'], $this->fixture->frenchContext(),
    );
});

afterEach(function () {
    Http::assertNothingSent();
});

it('prepares the distinct exempt S1 fiction with exact amounts code and legal text', function () {
    $payload = ($this->buildFranchise)();
    expect($payload['number'])->toBe('SANDBOX-FICTIF-FRANCHISE-0002')
        ->and($payload['process_control']['business_process_type'])->toBe('S1')
        ->and($payload['notes'])->toBe($this->fixture->frenchContext()['notes'])
        ->and($payload['seller'])->not->toHaveKey('vat_identifier')
        ->and($payload['seller']['legal_registration_identifier'])->toBe(['value' => '000000000', 'scheme' => '0002'])
        ->and($payload['seller']['tax_registration_identifier'])->toBe('000000000')
        ->and($payload['lines'][0]['vat_information'])->toBe([
            'invoiced_item_vat_category_code' => 'E', 'invoiced_item_vat_rate' => '0.00',
            'exemption_reason_code' => Franchise::REASON_CODE, 'exemption_reason' => Franchise::REASON,
        ])
        ->and($payload['vat_break_down'])->toBe([[
            'vat_category_code' => 'E', 'vat_category_rate' => '0.00',
            'vat_category_taxable_amount' => '100.00', 'vat_category_tax_amount' => '0.00',
            'vat_exemption_reason_code' => Franchise::REASON_CODE, 'vat_exemption_reason' => Franchise::REASON,
        ]])
        ->and($payload['totals'])->toBe([
            'sum_invoice_lines_amount' => '100.00', 'total_without_vat' => '100.00',
            'total_vat_amount' => ['value' => '0.00', 'currency_code' => 'EUR'],
            'total_with_vat' => '100.00', 'amount_due_for_payment' => '100.00',
        ]);
    app(SuperPdpInvoicePayloadValidator::class)->validate($payload);
    array_walk_recursive($payload, fn ($value) => expect(is_float($value) || $value === null)->toBeFalse());
    expect($this->fixture->invoice()->exists)->toBeFalse()->and($this->fixture->invoice()->getKey())->toBeNull();
});

it('keeps the standard scenario and the captured real CII unchanged', function () {
    $probe = app(SandboxConversionProbe::class);
    $standard = $probe->run()['payload'];
    $franchise = $probe->run(scenario: 'franchise')['payload'];
    expect($standard['number'])->toBe('SANDBOX-FICTIF-0001')
        ->and($standard['lines'][0]['vat_information']['invoiced_item_vat_category_code'])->toBe('S')
        ->and($standard['totals']['amount_due_for_payment'])->toBe('120.00')
        ->and($standard['seller'])->not->toHaveKey('tax_registration_identifier')
        ->and($franchise['totals']['amount_due_for_payment'])->toBe('100.00')
        ->and($probe->run()['payload'])->toBe($standard)
        ->and(hash('sha256', CapturedSandboxCii::xml()))->toBe(CapturedSandboxCii::SHA256);
});

it('satisfies BR-E-02 and the supplied BR-FR-CO-16 for the actual franchise probe payload', function () {
    $payload = app(SandboxConversionProbe::class)->run(scenario: 'franchise')['payload'];
    // Regression for the reported remote failure: BT-30 alone is not a tax registration.
    expect($payload['lines'][0]['vat_information']['invoiced_item_vat_category_code'])->toBe('E')
        ->and($payload['vat_break_down'][0]['vat_category_code'])->toBe('E')
        ->and($payload['vat_break_down'][0]['vat_exemption_reason_code'])->toBe('VATEX-FR-FRANCHISE')
        ->and($payload['seller'])->not->toHaveKey('vat_identifier')
        ->and($payload)->not->toHaveKey('seller_tax_representative_party')
        ->and($payload['seller']['tax_registration_identifier'])->toBe('000000000')
        ->toBe($payload['seller']['legal_registration_identifier']['value'])
        ->not->toStartWith('FR');
    app(SuperPdpInvoicePayloadValidator::class)->validate($payload);
});

it('does not infer BT32 from a SIREN for other explicitly selected tax scenarios', function (string $category) {
    unset($this->data['seller']['tax_registration_identifier']);
    $this->data['items'][0]['vat_category'] = $category;
    if ($category === 'E') {
        $this->data['items'][0]['exemption_reason_code'] = 'VATEX-EU-132';
        $this->data['items'][0]['exemption_reason'] = 'Autre exonération fictive explicite';
    } else {
        unset($this->data['items'][0]['exemption_reason_code'], $this->data['items'][0]['exemption_reason']);
        if ($category === 'S') {
            $this->data['items'][0]['tva_rate'] = '20.00';
            $this->data['totals']['total_tva'] = '20.00';
            $this->data['totals']['total_ttc'] = '120.00';
        }
    }
    expect(($this->buildFranchise)()['seller'])->not->toHaveKey('tax_registration_identifier');
})->with(['S', 'E', 'Z']);

it('omits an absent optional tax registration rather than constructing one', function (mixed $value) {
    $this->data['seller']['tax_registration_identifier'] = $value;
    expect(($this->buildFranchise)()['seller'])->not->toHaveKey('tax_registration_identifier');
})->with([null, '', ' ']);

it('rejects non-string tax registration inputs without numeric coercion', function (mixed $value) {
    $this->data['seller']['tax_registration_identifier'] = $value;
    expect($this->buildFranchise)->toThrow(InvalidArgumentException::class);
})->with([[123456789], [false], [['value' => '000000000', 'scheme' => 'FC']]]);

it('maps an explicit tax registration as a string without inventing a VAT identifier', function () {
    $this->data['seller']['tax_registration_identifier'] = ' 000000000 ';
    $payload = ($this->buildFranchise)();
    expect($payload['seller']['tax_registration_identifier'])->toBe('000000000')
        ->and($payload['seller'])->not->toHaveKey('vat_identifier');
    expect($this->data['seller']['tax_registration_identifier'])->toBe(' 000000000 ');
});

it('simulates the selected franchise even when remote conversion is enabled', function (bool $enabled) {
    config(['superpdp_sandbox.conversion_enabled' => $enabled]);
    $this->artisan('superpdp:sandbox-convert --scenario=franchise')
        ->expectsOutputToContain('100.00 EUR HT + 0.00 EUR TVA = 100.00 EUR TTC.')
        ->expectsOutputToContain('SIMULATION')
        ->expectsOutputToContain('VATEX-FR-FRANCHISE')->assertExitCode(0);
})->with([false, true]);

it('retains the explicit execution configuration gate for franchise', function () {
    config(['superpdp_sandbox.conversion_enabled' => false]);
    $this->artisan('superpdp:sandbox-convert --scenario=franchise --execute')->assertExitCode(1);
});

it('rejects unknown scenarios instead of falling back to a different invoice', function () {
    $this->artisan('superpdp:sandbox-convert --scenario=unknown')->assertExitCode(1);
});

it('rejects another model or a persisted fixture', function (string $mutation) {
    $invoice = $this->fixture->invoice();
    if ($mutation === 'other') {
        $invoice = new Invoice;
    } elseif ($mutation === 'exists') {
        $invoice->exists = true;
    } else {
        $invoice->id = 123;
    }
    expect(fn () => $this->fixture->buildElectronic($invoice))->toThrow(InvalidArgumentException::class);
})->with(['other', 'exists', 'id']);

it('rejects nonzero rates and a mistaken Z or S category with the franchise justification', function (array $changes) {
    $this->data['items'][0] = array_replace($this->data['items'][0], $changes);
    expect($this->buildFranchise)->toThrow(ValidationException::class);
})->with([
    [['tva_rate' => '20.00']], [['tva_rate' => '10.00']],
    [['vat_category' => 'Z']], [['vat_category' => 'S']], [['vat_category' => null]],
]);

it('rejects an absent or blank exemption justification without inferring it from the seller', function (mixed $value) {
    $this->data['items'][0]['exemption_reason_code'] = $value;
    $this->data['items'][0]['exemption_reason'] = $value;
    expect($this->buildFranchise)->toThrow(ValidationException::class);
})->with([null, '', ' ']);

it('allows code only or text only under the existing generic exemption policy', function (string $field) {
    unset($this->data['items'][0][$field]);
    $payload = ($this->buildFranchise)();
    expect($payload['lines'][0]['vat_information'])->not->toHaveKey($field)
        ->and($payload['vat_break_down'][0])->not->toHaveKey('vat_'.$field);
})->with(['exemption_reason_code', 'exemption_reason']);

it('rejects inconsistent stored line amounts or invoice totals', function (string $field) {
    if ($field === 'line_total_ht') {
        $this->data['items'][0][$field] = '100.01';
    } else {
        $this->data['totals'][$field] = '100.01';
    }
    expect($this->buildFranchise)->toThrow(ValidationException::class);
})->with(['line_total_ht', 'subtotal_ht', 'total_tva', 'total_ttc']);

it('rejects incorrectly typed or misspelled exemption fields in the local schema', function (string $mutation) {
    $payload = ($this->buildFranchise)();
    if ($mutation === 'type') {
        $payload['vat_break_down'][0]['vat_exemption_reason_code'] = 293;
    } else {
        unset($payload['vat_break_down'][0]['vat_exemption_reason']);
        $payload['vat_break_down'][0]['exemption_reason'] = Franchise::REASON;
    }
    expect(fn () => app(SuperPdpInvoicePayloadValidator::class)->validate($payload))->toThrow(ValidationException::class);
})->with(['type', 'spelling']);

it('finds the proposed code in the installed Factur-X code list without treating OpenAPI as a VATEX validator', function () {
    $xml = new DOMDocument;
    $xml->load(base_path('vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.08_EN16931_codedb.xml'), LIBXML_NONET);
    $xpath = new DOMXPath($xml);
    expect($xpath->query('//*[local-name()="enumeration"][@value="VATEX-FR-FRANCHISE"]')->length)->toBe(1);
    $schema = json_decode(file_get_contents(resource_path('schemas/superpdp/1.39.0.beta/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($schema['components']['schemas']['vat_exemption_reason_code']['type'])->toBe('string')
        ->and($schema['components']['schemas']['vat_exemption_reason_code'])->not->toHaveKey('enum');
});
