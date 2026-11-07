<?php

use App\Http\Controllers\InvoiceController;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceLinePreparationService;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['app.debug' => false]);
    $this->user = User::factory()->create();
    $this->client = Client::create(['user_id' => $this->user->id, 'first_name' => 'Marie', 'last_name' => 'Dupont']);
    $this->actingAs($this->user);
    $this->item = [
        'description' => 'Prestation', 'quantity' => '1', 'unit_price_ht' => '100',
        'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
    ];
    $this->payload = [
        'action' => 'draft', 'client_id' => $this->client->id,
        'date' => '2026-11-05', 'due_date' => '2026-12-05', 'items' => [$this->item],
    ];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
});

it('updates decimal lines and totals without assigning a number or accepting forged document fields', function () {
    $item = array_replace($this->item, ['quantity' => '1.5', 'unit_price_ht' => '40', 'tva_rate' => '5.5', 'unit_code' => 'MTR']);
    $this->payload = array_merge($this->payload, [
        'items' => [$item], 'notes' => 'Modification', 'calculation_version' => null,
        'invoice_number' => 'FORGED', 'document_status' => 'issued', 'total_ttc' => '0.01',
    ]);
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasNoErrors()->assertRedirect();
    $invoice = $this->invoice->fresh();
    $line = $invoice->items()->sole();

    expect([$invoice->subtotal_ht, $invoice->total_tva, $invoice->total_ttc])->toBe(['60.00', '3.30', '63.30'])
        ->and($invoice->calculation_version)->toBe('decimal-v1')
        ->and($invoice->document_status)->toBe('draft')->and($invoice->invoice_number)->toBeNull()
        ->and([$line->vat_category, $line->unit_code, $line->tva_rate])->toBe(['S', 'MTR', '5.50'])
        ->and($invoice->notes)->toBe('Modification');
    $this->assertDatabaseCount('invoice_counters', 0);
});

it('preserves each fiscal treatment and its metadata during edits', function (string $treatment, array $metadata, string $category, ?string $code) {
    $this->payload['items'] = [array_replace($this->item, ['tax_treatment' => $treatment, 'tva_rate' => '0'], $metadata)];
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasNoErrors();
    $line = $this->invoice->items()->sole();
    expect($line->vat_category)->toBe($category)->and($line->exemption_reason_code)->toBe($code)
        ->and($line->exemption_reason)->toBe($metadata['exemption_reason'] ?? null)
        ->and($this->invoice->fresh()->total_ttc)->toBe('100.00');
})->with([
    ['franchise', ['exemption_reason' => 'Mention conservée'], 'E', 'VATEX-FR-FRANCHISE'],
    ['exempt', ['exemption_reason_code' => 'VATEX-EU-132', 'exemption_reason' => 'Autre motif'], 'E', 'VATEX-EU-132'],
    ['zero_rated', [], 'Z', null],
]);

it('uses grouped decimal VAT when replacing lines', function () {
    $line = array_replace($this->item, ['unit_price_ht' => '0.03']);
    $this->payload['items'] = [$line, $line, $line];
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasNoErrors();
    expect($this->invoice->fresh()->total_tva)->toBe('0.02')->and($this->invoice->items()->count())->toBe(3);
});

it('rolls back invalid edits without losing any original lines', function (array $changes, string $key) {
    $before = $this->invoice->getRawOriginal();
    $lines = $this->invoice->items()->get()->map->getRawOriginal()->all();
    $this->payload['items'][0] = array_replace($this->item, $changes);
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasErrors($key);

    expect($this->invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($this->invoice->items()->get()->map->getRawOriginal()->all())->toBe($lines);
})->with([
    [['unit_code' => null], 'items.0.unit_code'],
    [['tax_treatment' => null], 'items.0.tax_treatment'],
    [['tax_treatment' => 'exempt', 'tva_rate' => '0'], 'items.0.exemption_reason'],
    [['quantity' => '2', 'unit_price_ht' => '99999999'], 'items.0.line_total_ht'],
    [['vat_category' => 'Z'], 'items.0.vat_category'],
]);

it('refuses decimal edits when payments or inconsistent payment state exist', function (string $state) {
    if ($state === 'payment') {
        $this->invoice->payments()->create(['amount' => '1.00', 'paid_at' => '2026-11-05']);
    } elseif ($state === 'amount') {
        $this->invoice->update(['amount_paid' => '0.01']);
    } else {
        $this->invoice->update(['status' => 'partiellement_payee']);
    }
    $before = $this->invoice->refresh()->getRawOriginal();
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertStatus(409);
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
})->with(['payment', 'amount', 'status']);

it('preserves the legacy calculation and null version even if a version is submitted', function () {
    $this->invoice->update(['calculation_version' => null]);
    $this->payload['calculation_version'] = 'decimal-v1';
    $this->payload['items'] = [
        ['description' => 'Historique', 'quantity' => '1', 'unit_price_ht' => '10', 'tva_rate' => '7'],
    ];
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasNoErrors();
    expect($this->invoice->fresh()->calculation_version)->toBeNull()
        ->and($this->invoice->fresh()->total_tva)->toBe('0.70')
        ->and($this->invoice->items()->sole()->vat_category)->toBeNull();
});

it('refuses unknown calculation versions', function () {
    $this->invoice->update(['calculation_version' => 'decimal-v2']);
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertStatus(409);
});

it('reloads ownership status and version rather than trusting a stale route model', function (array $changes, int $status) {
    $stale = $this->invoice;
    Invoice::whereKey($stale->id)->update($changes);
    $before = Invoice::findOrFail($stale->id)->getRawOriginal();
    $request = Request::create('/invoices/' . $stale->id, 'PUT', $this->payload);

    try {
        app(InvoiceController::class)->update($request, $stale, app(InvoiceLinePreparationService::class));
        $this->fail('La relecture sous verrou devait refuser cette modification.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe($status);
    }
    expect(Invoice::findOrFail($stale->id)->getRawOriginal())->toBe($before);
})->with([
    [['document_status' => 'issued', 'invoice_number' => 'FAC-2026-0999'], 403],
    [['calculation_version' => 'unsupported'], 409],
    [['document_status' => 'unknown'], 403],
]);

it('rechecks a stale owner under the transaction lock', function () {
    $other = User::factory()->create();
    $stale = $this->invoice;
    Invoice::whereKey($stale->id)->update(['user_id' => $other->id]);
    try {
        app(InvoiceController::class)->update(Request::create('/invoices/' . $stale->id, 'PUT', $this->payload), $stale, app(InvoiceLinePreparationService::class));
        $this->fail('Le propriétaire doit être relu.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

it('shows prefilled fiscal fields and normalized rates for decimal edits', function (string $rate, string $storedRate, string $treatment, ?string $code) {
    $this->invoice->items()->first()->update([
        'tva_rate' => $storedRate, 'vat_category' => $treatment === 'taxable' ? 'S' : ($treatment === 'zero_rated' ? 'Z' : 'E'),
        'unit_code' => 'MTR', 'exemption_reason_code' => $code, 'exemption_reason' => $code ? 'Texte enregistré' : null,
    ]);
    $this->get(route('invoices.edit', $this->invoice))->assertOk()
        ->assertSee('value="' . $rate . '" selected', false)
        ->assertSee('value="' . $treatment . '" selected', false)
        ->assertSee('value="MTR" selected', false)
        ->assertSee('Aperçu indicatif')
        ->assertDontSee(' disabled', false);
})->with([
    ['20', '20.00', 'taxable', null], ['10', '10.00', 'taxable', null],
    ['5.5', '5.50', 'taxable', null], ['2.1', '2.10', 'taxable', null],
    ['0', '0.00', 'franchise', 'VATEX-FR-FRANCHISE'], ['0', '0.00', 'exempt', 'VATEX-EU-132'],
    ['0', '0.00', 'zero_rated', null],
]);

it('retains submitted fiscal values and errors on an invalid edit', function () {
    $this->payload['items'][0]['unit_code'] = 'BAD';
    $this->from(route('invoices.edit', $this->invoice))->put(route('invoices.update', $this->invoice), $this->payload)
        ->assertSessionHasErrors('items.0.unit_code')->assertSessionHasInput('items.0.unit_code', 'BAD');
    $this->get(route('invoices.edit', $this->invoice))->assertOk()->assertSee('Choisissez une unité autorisée');
});

it('rejects draft payments without writes for both historical and decimal documents', function (?string $version, string $method) {
    $this->invoice->update(['calculation_version' => $version]);
    $before = $this->invoice->refresh()->getRawOriginal();
    $route = $method === 'add' ? 'invoices.addPayment' : 'invoices.markAsPaid';
    $this->post(route($route, $this->invoice), ['amount' => '10', 'method' => 'virement'])->assertStatus(409);
    $this->assertDatabaseCount('payments', 0);
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
})->with([null, 'decimal-v1'])->with(['add', 'mark']);

it('rechecks documentary status under lock for stale payment requests', function (string $method) {
    $this->invoice->update(['document_status' => 'issued']);
    $stale = $this->invoice->fresh();
    Invoice::whereKey($stale->id)->update(['document_status' => 'draft']);
    try {
        $controller = app(InvoiceController::class);
        if ($method === 'add') {
            $controller->addPayment(Request::create('/payment', 'POST', ['amount' => '10', 'method' => 'virement']), $stale);
        } else {
            $controller->markAsPaid($stale);
        }
        $this->fail('Le statut documentaire doit être relu sous verrou.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    $this->assertDatabaseCount('payments', 0);
})->with(['add', 'mark']);

it('preserves partial full and rejected payments for issued invoices', function () {
    // La migration historique utilise change() sans créer method sur SQLite neuf.
    // Fixture limitée à cette base de test : aucune migration applicative n'est modifiée.
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    if (! Schema::hasColumn('payments', 'method')) {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method')->nullable();
        });
    }
    $this->invoice->update(['document_status' => 'issued']);
    $this->post(route('invoices.addPayment', $this->invoice), ['amount' => '20', 'method' => 'cheque'])->assertSessionHas('success');
    expect($this->invoice->fresh()->status)->toBe('partiellement_payee')
        ->and($this->invoice->fresh()->amount_paid)->toBe('20.00');
    $this->post(route('invoices.addPayment', $this->invoice), ['amount' => '101', 'method' => 'virement'])->assertSessionHas('error');
    $this->assertDatabaseCount('payments', 1);
    $this->post(route('invoices.markAsPaid', $this->invoice))->assertSessionHas('success');
    expect($this->invoice->fresh()->status)->toBe('payee')->and($this->invoice->fresh()->amount_paid)->toBe('120.00');
    $this->post(route('invoices.markAsPaid', $this->invoice))->assertSessionHas('error');
    $this->assertDatabaseCount('payments', 2);
});

it('preserves the existing refusal to add a payment to an invoice with an issued credit note', function () {
    $this->invoice->update(['document_status' => 'issued']);
    $this->invoice->creditNotes()->create([
        'user_id' => $this->user->id, 'credit_note_number' => 'AV-2026-0001',
        'date' => '2026-11-05', 'status' => 'issued', 'reason' => 'Annulation',
        'subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00',
    ]);
    $this->post(route('invoices.addPayment', $this->invoice), ['amount' => '10', 'method' => 'virement'])
        ->assertRedirect()->assertSessionHas('error');
    $this->assertDatabaseCount('payments', 0);
});
