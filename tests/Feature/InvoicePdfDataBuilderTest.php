<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceLinePreparationService;
use App\Services\InvoiceFiscalDataPreparer;
use App\Services\InvoicePdfDataBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function createPdfDataInvoice(array $items): Invoice
{
    $user = User::factory()->create();
    $client = Client::create(['user_id' => $user->id, 'first_name' => 'Marie']);
    $prepared = app(InvoiceLinePreparationService::class)->prepare($items);
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'invoice_number' => 'FAC-2026-0001',
        'date' => '2026-11-05', 'due_date' => '2026-12-05', 'document_status' => 'issued',
        'calculation_version' => 'decimal-v1', 'status' => 'non_payee', 'amount_paid' => '0.00',
        'subtotal_ht' => $prepared['totals']['total_ht'], 'total_tva' => $prepared['totals']['total_vat'],
        'total_ttc' => $prepared['totals']['total_ttc'],
    ]);
    $invoice->items()->createMany($prepared['items']);

    return $invoice;
}

function addPdfDataCredit(Invoice $invoice, string $amount, string $status = 'issued'): void
{
    $invoice->creditNotes()->create([
        'user_id' => $invoice->user_id, 'credit_note_number' => 'AV-' . ($invoice->creditNotes()->count() + 1),
        'date' => '2026-11-05', 'status' => $status, 'reason' => 'Annulation',
        'subtotal_ht' => $amount, 'total_tva' => '0.00', 'total_ttc' => $amount,
    ]);
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->builder = app(InvoicePdfDataBuilder::class);
    $this->line = [
        'description' => 'Prestation', 'quantity' => '1.00', 'unit_price_ht' => '100.00',
        'tva_rate' => '20.00', 'vat_category' => 'S', 'unit_code' => 'HUR',
    ];
});

it('prepares structured decimals for all rates and preserves fiscal metadata and units', function (array $changes, string $vat) {
    $invoice = createPdfDataInvoice([array_replace($this->line, $changes)]);
    $stored = $invoice->items()->sole();
    $data = $this->builder->build($invoice);
    expect($data['items'][0])->toBe($stored->only([
        'id', 'description', 'quantity', 'unit_price_ht', 'tva_rate', 'line_total_ht',
        'vat_category', 'unit_code', 'exemption_reason_code', 'exemption_reason',
    ]))->and($data['totals']['total_tva'])->toBe($vat)
        ->and($data['vat_breakdowns'][0]['vat_category'])->toBe($stored->vat_category)
        ->and($data['vat_breakdowns'][0]['vat_rate'])->toBe($stored->tva_rate)
        ->and($data['vat_breakdowns'][0]['exemption_reason_code'])->toBe($stored->exemption_reason_code)
        ->and($data['vat_breakdowns'][0]['exemption_reason'])->toBe($stored->exemption_reason)
        ->and($data['date'])->toBe('2026-11-05')->and($data['due_date'])->toBe('2026-12-05');
    array_walk_recursive($data['totals'], fn ($value) => expect($value)->toBeString());
})->with([
    [['tva_rate' => '20', 'unit_code' => 'C62'], '20.00'],
    [['tva_rate' => '10', 'unit_code' => 'HUR'], '10.00'],
    [['tva_rate' => '5.5', 'unit_code' => 'MTR'], '5.50'],
    [['tva_rate' => '2.1'], '2.10'],
    [['vat_category' => 'E', 'tva_rate' => '0', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE'], '0.00'],
    [['vat_category' => 'E', 'tva_rate' => '0', 'exemption_reason_code' => 'VATEX-EU-132', 'exemption_reason' => 'Motif enregistré'], '0.00'],
    [['vat_category' => 'E', 'tva_rate' => '0', 'exemption_reason' => 'Motif seul'], '0.00'],
    [['vat_category' => 'Z', 'tva_rate' => '0'], '0.00'],
]);

it('reconstructs mixed breakdowns and rounds the three tiny lines at group level', function () {
    $tiny = array_replace($this->line, ['unit_price_ht' => '0.03']);
    $invoice = createPdfDataInvoice([
        $tiny, $tiny, $tiny,
        array_replace($this->line, ['tva_rate' => '10']),
        array_replace($this->line, ['tva_rate' => '5.5', 'quantity' => '1.50', 'unit_price_ht' => '0.03']),
        array_replace($this->line, ['tva_rate' => '2.1']),
        array_replace($this->line, ['vat_category' => 'E', 'tva_rate' => '0', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE']),
        array_replace($this->line, ['vat_category' => 'Z', 'tva_rate' => '0']),
    ]);
    $data = $this->builder->build($invoice);
    expect(array_column($data['vat_breakdowns'], 'vat_amount'))->toBe(['0.02', '10.00', '0.00', '2.10', '0.00', '0.00'])
        ->and($data['totals']['subtotal_ht'])->toBe('400.14')
        ->and($data['totals']['total_tva'])->toBe('12.12')->and($data['totals']['total_ttc'])->toBe('412.26')
        ->and($data['items'][3]['tva_rate'])->toBe('10.00')->and($data['items'][4]['line_total_ht'])->toBe('0.05');
});

it('rejects each stored amount mismatch without repairing the invoice', function (string $field) {
    $invoice = createPdfDataInvoice([$this->line]);
    if ($field === 'line_total_ht') {
        $invoice->items()->sole()->update([$field => '100.01']);
    } else {
        $invoice->update([$field => '0.01']);
    }
    $before = $invoice->fresh()->getRawOriginal();
    $beforeLines = $invoice->items()->get()->map->getRawOriginal()->all();
    try {
        $this->builder->build($invoice);
        $this->fail('Inconsistent amounts must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field === 'line_total_ht' ? 'items.0.line_total_ht' : $field);
    }
    expect($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($invoice->items()->get()->map->getRawOriginal()->all())->toBe($beforeLines);
})->with(['line_total_ht', 'subtotal_ht', 'total_tva', 'total_ttc']);

it('refuses invalid or absent decimal fiscal data', function (array $changes, string $key) {
    $invoice = createPdfDataInvoice([$this->line]);
    if ($changes === []) {
        $invoice->items()->delete();
    } else {
        $invoice->items()->sole()->update($changes);
    }
    try {
        $this->builder->build($invoice);
        $this->fail('Invalid fiscal data must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($key);
    }
})->with([
    [[], 'items'], [['vat_category' => null], 'items.0.vat_category'],
    [['unit_code' => 'BAD'], 'items.0.unit_code'], [['tva_rate' => '0'], 'items.0.tva_rate'],
    [['vat_category' => 'E', 'tva_rate' => '0'], 'items.0.exemption_reason'],
    [['exemption_reason' => 'Incompatible'], 'items.0.exemption_reason'],
]);

it('rejects conflicting exemption reasons instead of silently merging them', function () {
    $invoice = createPdfDataInvoice([$this->line, $this->line]);
    foreach ($invoice->items()->orderBy('id')->get() as $index => $line) {
        $line->update(['vat_category' => 'E', 'tva_rate' => '0', 'exemption_reason' => 'Motif ' . $index]);
    }
    expect(fn () => $this->builder->build($invoice))->toThrow(ValidationException::class);
});

it('keeps historical amounts without invoking fiscal validation or inventing breakdowns', function () {
    $invoice = createPdfDataInvoice([$this->line]);
    $invoice->update(['calculation_version' => null, 'subtotal_ht' => '7.00', 'total_tva' => '0.49', 'total_ttc' => '7.49']);
    $invoice->items()->sole()->update(['vat_category' => null, 'unit_code' => null, 'tva_rate' => '7', 'line_total_ht' => '99.99']);
    $preparation = $this->createMock(InvoiceLinePreparationService::class);
    $preparation->expects($this->never())->method('prepare');
    $data = (new InvoicePdfDataBuilder(new InvoiceFiscalDataPreparer($preparation)))->build($invoice);
    expect($data['calculation_version'])->toBeNull()->and($data['vat_breakdowns'])->toBeNull()
        ->and($data['items'][0]['vat_category'])->toBeNull()->and($data['items'][0]['unit_code'])->toBeNull()
        ->and($data['items'][0]['line_total_ht'])->toBe('99.99')
        ->and($data['totals']['total_ttc'])->toBe('7.49');
});

it('allows historical documents with no remaining lines without applying new rules', function () {
    $invoice = createPdfDataInvoice([$this->line]);
    $invoice->update(['calculation_version' => null]);
    $invoice->items()->delete();
    $data = $this->builder->build($invoice);
    expect($data['items'])->toBe([])->and($data['vat_breakdowns'])->toBeNull()->and($data['totals']['total_ttc'])->toBe('120.00');
});

it('delegates fiscal checks while preserving the complete PDF output contract', function () {
    $invoice = createPdfDataInvoice([$this->line]);
    $line = $invoice->items()->sole();
    $items = [[
        'id' => $line->id, 'description' => 'Prestation', 'quantity' => '1.00', 'unit_price_ht' => '100.00',
        'tva_rate' => '20.00', 'line_total_ht' => '100.00', 'vat_category' => 'S', 'unit_code' => 'HUR',
        'exemption_reason_code' => null, 'exemption_reason' => null,
    ]];
    $totals = ['subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00'];
    $fiscalData = app(InvoiceFiscalDataPreparer::class)->prepare($items, $totals, 'decimal-v1');
    $preparer = $this->createMock(InvoiceFiscalDataPreparer::class);
    $preparer->expects($this->once())->method('prepare')->with($items, $totals, 'decimal-v1')->willReturn($fiscalData);

    expect((new InvoicePdfDataBuilder($preparer))->build($invoice))->toBe([
        'invoice_id' => $invoice->id, 'invoice_number' => 'FAC-2026-0001',
        'calculation_version' => 'decimal-v1', 'document_status' => 'issued',
        'date' => '2026-11-05', 'due_date' => '2026-12-05',
        'items' => $items, 'vat_breakdowns' => $fiscalData['vat_breakdowns'],
        'totals' => array_merge($totals, ['amount_paid' => '0.00', 'issued_credit_total' => '0.00', 'remaining_amount' => '120.00']),
        'payments' => [], 'issued_credit_notes' => [],
    ]);
});

it('refuses unknown calculation versions and unpersisted invoices', function () {
    $invoice = createPdfDataInvoice([$this->line]);
    $invoice->update(['calculation_version' => 'decimal-v2']);
    expect(fn () => $this->builder->build($invoice))->toThrow(InvalidArgumentException::class, 'Version de calcul inconnue')
        ->and(fn () => $this->builder->build(new Invoice))->toThrow(InvalidArgumentException::class, 'facture enregistrée');
});

it('preserves exact remaining amount rules for payments and issued credits', function (array $payments, array $credits, string $paid, string $creditTotal, string $remaining) {
    $invoice = createPdfDataInvoice([$this->line]);
    // Le montant payé du modèle n'est pas l'historique de référence.
    $invoice->update(['amount_paid' => '99.99']);
    foreach ($payments as $amount) {
        $invoice->payments()->create(['amount' => $amount, 'paid_at' => '2026-11-05']);
    }
    foreach ($credits as [$amount, $status]) {
        addPdfDataCredit($invoice, $amount, $status);
    }
    $data = $this->builder->build($invoice);
    expect($data['totals'])->toBe([
        'subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00',
        'amount_paid' => $paid, 'issued_credit_total' => $creditTotal, 'remaining_amount' => $remaining,
    ]);
    expect(count($data['payments']))->toBe(count($payments));
})->with([
    [[], [], '0.00', '0.00', '120.00'],
    [['0.10', '0.20'], [], '0.30', '0.00', '119.70'],
    [['120.00'], [], '120.00', '0.00', '0.00'],
    [['130.00'], [], '130.00', '0.00', '0.00'],
    [['10.00'], [['20.00', 'issued'], ['5.00', 'issued'], ['50.00', 'draft']], '10.00', '25.00', '85.00'],
    [[], [['150.00', 'issued']], '0.00', '150.00', '0.00'],
]);

it('reloads stored data and performs only reads without changing the caller model', function () {
    $invoice = createPdfDataInvoice([$this->line]);
    $invoice->load('items', 'payments', 'creditNotes');
    $invoice->total_ttc = '999.99';
    $invoice->items->first()->unit_code = 'BAD';
    $invoice->payments()->create(['amount' => '0.30', 'paid_at' => '2026-11-05']);
    addPdfDataCredit($invoice, '10.00');
    $queries = [];
    DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
    $data = $this->builder->build($invoice);
    expect($data['totals']['total_ttc'])->toBe('120.00')->and($data['totals']['remaining_amount'])->toBe('109.70')
        ->and($data['items'][0]['unit_code'])->toBe('HUR')
        ->and($invoice->total_ttc)->toBe('999.99')->and($invoice->items->first()->unit_code)->toBe('BAD');
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query)))->toStartWith('select');
    }
    Http::assertNothingSent();
});
