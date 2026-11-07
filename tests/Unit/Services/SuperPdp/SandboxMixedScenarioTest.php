<?php

use App\Models\Invoice;
use App\Services\InvoiceExportDataBuilder;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\SuperPdp\Sandbox\SandboxConversionProbe;
use App\Services\SuperPdp\Sandbox\SandboxMixedInvoiceFixture;
use App\Services\SuperPdp\SuperPdpInvoicePayloadBuilder;
use App\Services\SuperPdp\SuperPdpInvoicePayloadValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\CapturedFranchiseCii;
use Tests\Support\CapturedMultiRateCii;
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
    $this->fixture = new SandboxMixedInvoiceFixture;
    $this->data = $this->fixture->buildElectronic($this->fixture->invoice());
    $this->context = $this->fixture->frenchContext();
    $source = $this->createMock(InvoiceExportDataBuilder::class);
    $source->expects($this->never())->method('build');
    $source->method('buildElectronic')->willReturnCallback(fn () => $this->data);
    $builder = new SuperPdpInvoicePayloadBuilder($source, app(InvoiceFiscalDataPreparer::class), app(SuperPdpInvoicePayloadValidator::class));
    $this->buildMixed = fn () => $builder->build(
        $this->fixture->invoice(), ['scheme' => 'EM', 'value' => 'vendeur-fictif@example.invalid'],
        ['scheme' => 'EM', 'value' => 'acheteur-fictif@example.invalid'], $this->context,
    );
});

afterEach(function () {
    Http::assertNothingSent();
});

it('builds explicit M1 goods and services with a single exact VAT breakdown through the fiscal pipeline', function () {
    $before = $this->data;
    $payload = ($this->buildMixed)();
    expect($payload['number'])->toBe('SANDBOX-FICTIF-MIXTE-0004')
        ->and($payload['process_control'])->toBe([
            'specification_identifier' => 'urn:cen.eu:en16931:2017', 'business_process_type' => 'M1',
        ])
        ->and($payload['notes'])->toBe($this->context['notes'])
        ->and(array_column($payload['notes'], 'subject_code'))->toBe(['PMT', 'PMD', 'AAB'])
        ->and($payload['lines'])->toHaveCount(2);
    foreach (['100.00', '150.00'] as $index => $amount) {
        $line = $payload['lines'][$index];
        expect($line['identifier'])->toBe((string) ($index + 1))
            ->and($line['invoiced_quantity'])->toBe('1.00')
            ->and($line['invoiced_quantity_code'])->toBe('C62')
            ->and($line['price_details'])->toBe([
                'item_net_price' => $amount, 'item_price_base_quantity' => '1.00', 'quantity_unit_code' => 'C62',
            ])
            ->and($line['net_amount'])->toBe($amount)
            ->and($line['item_information']['name'])->toBe($before['items'][$index]['description'])
            ->and($line['vat_information'])->toBe([
                'invoiced_item_vat_category_code' => 'S', 'invoiced_item_vat_rate' => '20.00',
            ]);
    }
    expect($payload['lines'][0]['item_information']['name'])->toContain('robinet')
        ->and($payload['lines'][1]['item_information']['name'])->toContain('installation')
        ->and($payload['vat_break_down'])->toBe([
            ['vat_category_code' => 'S', 'vat_category_rate' => '20.00', 'vat_category_taxable_amount' => '250.00', 'vat_category_tax_amount' => '50.00'],
        ])->and($payload['totals'])->toBe([
            'sum_invoice_lines_amount' => '250.00', 'total_without_vat' => '250.00',
            'total_vat_amount' => ['value' => '50.00', 'currency_code' => 'EUR'],
            'total_with_vat' => '300.00', 'amount_due_for_payment' => '300.00',
        ])->and($payload['seller'])->not->toHaveKey('tax_registration_identifier')
        ->and($this->data)->toBe($before)
        ->and($this->fixture->invoice()->exists)->toBeFalse()
        ->and($this->fixture->invoice()->getKey())->toBeNull();
    app(SuperPdpInvoicePayloadValidator::class)->validate($payload);
    array_walk_recursive($payload, fn ($value) => expect(is_float($value) || $value === null)->toBeFalse());
});

it('isolates the fourth scenario from all three previous payloads and captured CII references', function () {
    $probe = app(SandboxConversionProbe::class);
    $previous = [];
    foreach (['standard', 'franchise', 'multi-rate'] as $scenario) {
        $previous[$scenario] = $probe->run(scenario: $scenario)['payload'];
    }
    $result = $probe->run(scenario: 'mixed');
    expect($result['mode'])->toBe('simulation')->and($result['xml'])->toBeNull()
        ->and($result['status'])->toBeNull()->and($result['payload'])->toBe(($this->buildMixed)());
    foreach ($previous as $scenario => $payload) {
        expect($probe->run(scenario: $scenario)['payload'])->toBe($payload)
            ->and($payload['process_control']['business_process_type'])->toBe('S1')
            ->and($payload['number'])->not->toBe($result['payload']['number']);
    }
    foreach ([CapturedSandboxCii::class, CapturedFranchiseCii::class, CapturedMultiRateCii::class] as $reference) {
        expect(hash('sha256', $reference::xml()))->toBe($reference::SHA256);
    }
});

it('simulates M1 from the command regardless of conversion configuration', function (bool $enabled) {
    config(['superpdp_sandbox.conversion_enabled' => $enabled]);
    $this->artisan('superpdp:sandbox-convert --scenario=mixed')
        ->expectsOutputToContain('250.00 EUR HT + 50.00 EUR TVA = 300.00 EUR TTC.')
        ->expectsOutputToContain('SANDBOX-FICTIF-MIXTE-0004')
        ->expectsOutputToContain('SIMULATION')->assertExitCode(0);
})->with([false, true]);

it('retains the execution gate for M1', function () {
    config(['superpdp_sandbox.conversion_enabled' => false]);
    $this->artisan('superpdp:sandbox-convert --scenario=mixed --execute')->assertExitCode(1);
});

it('rejects another or persisted invoice in the mixed fixture', function (string $mutation) {
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

it('rejects inconsistent mixed lines rates and exemptions', function (string $mutation) {
    match ($mutation) {
        'missing' => array_pop($this->data['items']),
        'rate' => $this->data['items'][1]['tva_rate'] = '10.00',
        'zero' => $this->data['items'][1]['tva_rate'] = '0.00',
        'unsupported' => $this->data['items'][1]['tva_rate'] = '7.00',
        'category-E' => $this->data['items'][1]['vat_category'] = 'E',
        'category-Z' => $this->data['items'][1]['vat_category'] = 'Z',
        'reason' => $this->data['items'][1]['exemption_reason_code'] = 'VATEX-FR-FRANCHISE',
        'line-total' => $this->data['items'][1]['line_total_ht'] = '149.99',
    };
    expect($this->buildMixed)->toThrow(ValidationException::class);
})->with(['missing', 'rate', 'zero', 'unsupported', 'category-E', 'category-Z', 'reason', 'line-total']);

it('rejects inconsistent mixed stored totals', function (string $key) {
    $this->data['totals'][$key] = '0.01';
    expect($this->buildMixed)->toThrow(ValidationException::class);
})->with(['subtotal_ht', 'total_tva', 'total_ttc']);

it('requires explicit valid French context and all three notes for M1', function (string $mutation) {
    match ($mutation) {
        'absent' => $this->context = null,
        'missing-mode' => $this->context = array_diff_key($this->context, ['business_process_type' => true]),
        'invalid-mode' => $this->context['business_process_type'] = 'M2',
        'missing-note' => array_pop($this->context['notes']),
        'blank-note' => $this->context['notes'][1]['note'] = ' ',
    };
    expect($this->buildMixed)->toThrow(ValidationException::class);
})->with(['absent', 'missing-mode', 'invalid-mode', 'missing-note', 'blank-note']);

it('rejects malformed mixed payload properties under the local OpenAPI contract', function (string $mutation) {
    $payload = ($this->buildMixed)();
    if ($mutation === 'decimal') {
        $payload['lines'][1]['net_amount'] = '150,00';
    } elseif ($mutation === 'type') {
        $payload['vat_break_down'][0]['vat_category_tax_amount'] = 50;
    } elseif ($mutation === 'context-type') {
        $payload['process_control']['business_process_type'] = ['M1'];
    } else {
        unset($payload['vat_break_down'][0]['vat_category_code']);
    }
    expect(fn () => app(SuperPdpInvoicePayloadValidator::class)->validate($payload))->toThrow(ValidationException::class);
})->with(['decimal', 'type', 'context-type', 'missing']);
