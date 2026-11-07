<?php

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\Sandbox\SandboxConversionProbe;
use App\Services\SuperPdp\Sandbox\SandboxMultiRateInvoiceFixture;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\CapturedFranchiseCii;
use Tests\Support\CapturedSandboxCii;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('Aucun accès SQL autorisé pour cette fiction.');
    });
    Http::preventStrayRequests();
    Http::fake();
    config(['services.superpdp.api_url' => 'https://api.superpdp.tech/v1.beta']);
    $this->fixture = new SandboxMultiRateInvoiceFixture;
    $this->data = $this->fixture->buildElectronic($this->fixture->invoice());
    $source = $this->createMock(InvoiceExportDataBuilder::class);
    $source->expects($this->never())->method('build');
    $source->method('buildElectronic')->willReturnCallback(fn () => $this->data);
    $builder = new SuperPdpInvoicePayloadBuilder($source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class));
    $this->buildMultiRate = fn () => $builder->build(
        $this->fixture->invoice(), ['scheme' => 'EM', 'value' => 'vendeur-fictif@example.invalid'],
        ['scheme' => 'EM', 'value' => 'acheteur-fictif@example.invalid'], $this->fixture->frenchContext(),
    );
});

afterEach(function () {
    Http::assertNothingSent();
});

it('builds two exact service lines and two separate VAT breakdowns using the real fiscal pipeline', function () {
    $before = $this->data;
    $payload = ($this->buildMultiRate)();
    expect($payload['number'])->toBe('SANDBOX-FICTIF-MULTITAUX-0003')
        ->and($payload['process_control'])->toBe([
            'specification_identifier' => 'urn:cen.eu:en16931:2017', 'business_process_type' => 'S1',
        ])
        ->and($payload['notes'])->toBe($this->fixture->frenchContext()['notes'])
        ->and($payload['lines'])->toHaveCount(2);
    foreach (['20.00', '10.00'] as $index => $rate) {
        $line = $payload['lines'][$index];
        expect($line['identifier'])->toBe((string) ($index + 1))
            ->and($line['invoiced_quantity'])->toBe('1.00')
            ->and($line['invoiced_quantity_code'])->toBe('C62')
            ->and($line['price_details']['item_net_price'])->toBe('100.00')
            ->and($line['net_amount'])->toBe('100.00')
            ->and($line['vat_information'])->toBe([
                'invoiced_item_vat_category_code' => 'S', 'invoiced_item_vat_rate' => $rate,
            ])
            ->and($line['item_information']['name'])->toBe($before['items'][$index]['description']);
    }
    expect($payload['vat_break_down'])->toBe([
        ['vat_category_code' => 'S', 'vat_category_rate' => '20.00', 'vat_category_taxable_amount' => '100.00', 'vat_category_tax_amount' => '20.00'],
        ['vat_category_code' => 'S', 'vat_category_rate' => '10.00', 'vat_category_taxable_amount' => '100.00', 'vat_category_tax_amount' => '10.00'],
    ])->and($payload['totals'])->toBe([
        'sum_invoice_lines_amount' => '200.00', 'total_without_vat' => '200.00',
        'total_vat_amount' => ['value' => '30.00', 'currency_code' => 'EUR'],
        'total_with_vat' => '230.00', 'amount_due_for_payment' => '230.00',
    ])->and($payload['seller'])->not->toHaveKey('tax_registration_identifier')
        ->and($this->data)->toBe($before);
    app(SuperPdpInvoicePayloadValidator::class)->validate($payload);
    array_walk_recursive($payload, fn ($value) => expect(is_float($value) || $value === null)->toBeFalse());
    expect($this->fixture->invoice()->exists)->toBeFalse()->and($this->fixture->invoice()->getKey())->toBeNull();
});

it('prepares the selected third scenario without changing either previous scenario or captured CII', function () {
    $probe = app(SandboxConversionProbe::class);
    $standard = $probe->run()['payload'];
    $franchise = $probe->run(scenario: 'franchise')['payload'];
    $result = $probe->run(scenario: 'multi-rate');
    expect($result['mode'])->toBe('simulation')->and($result['xml'])->toBeNull()->and($result['status'])->toBeNull()
        ->and($result['payload'])->toBe(($this->buildMultiRate)())
        ->and($standard['totals']['amount_due_for_payment'])->toBe('120.00')
        ->and($franchise['totals']['amount_due_for_payment'])->toBe('100.00')
        ->and($probe->run()['payload'])->toBe($standard)
        ->and($probe->run(scenario: 'franchise')['payload'])->toBe($franchise)
        ->and(hash('sha256', CapturedSandboxCii::xml()))->toBe(CapturedSandboxCii::SHA256)
        ->and(hash('sha256', CapturedFranchiseCii::xml()))->toBe(CapturedFranchiseCii::SHA256);
});

it('simulates the command even when conversion configuration is enabled', function (bool $enabled) {
    config(['superpdp_sandbox.conversion_enabled' => $enabled]);
    $this->artisan('superpdp:sandbox-convert --scenario=multi-rate')
        ->expectsOutputToContain('200.00 EUR HT + 30.00 EUR TVA = 230.00 EUR TTC.')
        ->expectsOutputToContain('SANDBOX-FICTIF-MULTITAUX-0003')
        ->expectsOutputToContain('SIMULATION')->assertExitCode(0);
})->with([false, true]);

it('retains the configuration gate before any future manual conversion', function () {
    config(['superpdp_sandbox.conversion_enabled' => false]);
    $this->artisan('superpdp:sandbox-convert --scenario=multi-rate --execute')->assertExitCode(1);
});

it('refuses another or persisted model', function (string $mutation) {
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

it('rejects missing lines incorrect rates categories and exemption reasons', function (string $mutation) {
    match ($mutation) {
        'missing' => array_pop($this->data['items']),
        'same-rate' => $this->data['items'][1]['tva_rate'] = '20.00',
        'zero-rate' => $this->data['items'][1]['tva_rate'] = '0.00',
        'unsupported' => $this->data['items'][1]['tva_rate'] = '7.00',
        'category-E' => $this->data['items'][1]['vat_category'] = 'E',
        'category-Z' => $this->data['items'][1]['vat_category'] = 'Z',
        'reason' => $this->data['items'][1]['exemption_reason_code'] = 'VATEX-FR-FRANCHISE',
        'line-total' => $this->data['items'][1]['line_total_ht'] = '99.99',
    };
    expect($this->buildMultiRate)->toThrow(ValidationException::class);
})->with(['missing', 'same-rate', 'zero-rate', 'unsupported', 'category-E', 'category-Z', 'reason', 'line-total']);

it('rejects inconsistent stored invoice totals', function (string $key) {
    $this->data['totals'][$key] = '0.01';
    expect($this->buildMultiRate)->toThrow(ValidationException::class);
})->with(['subtotal_ht', 'total_tva', 'total_ttc']);

it('rejects malformed second line or breakdown properties in the local OpenAPI contract', function (string $mutation) {
    $payload = ($this->buildMultiRate)();
    if ($mutation === 'decimal') {
        $payload['lines'][1]['vat_information']['invoiced_item_vat_rate'] = '10,00';
    } elseif ($mutation === 'type') {
        $payload['vat_break_down'][1]['vat_category_tax_amount'] = 10;
    } else {
        unset($payload['vat_break_down'][1]['vat_category_code']);
    }
    expect(fn () => app(SuperPdpInvoicePayloadValidator::class)->validate($payload))->toThrow(ValidationException::class);
})->with(['decimal', 'type', 'missing']);
