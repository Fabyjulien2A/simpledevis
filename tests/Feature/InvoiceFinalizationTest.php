<?php

use App\Http\Controllers\InvoiceController;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceLinePreparationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['app.debug' => false]);
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->travelTo(now()->setDate(2026, 11, 5));
    $this->user = User::factory()->create();
    $client = Client::create(['user_id' => $this->user->id, 'first_name' => 'Marie']);
    $this->actingAs($this->user);
    $this->item = [
        'description' => 'Prestation', 'quantity' => '1', 'unit_price_ht' => '100',
        'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
    ];
    $this->payload = [
        'action' => 'draft', 'client_id' => $client->id, 'date' => '2026-11-05',
        'due_date' => '2026-12-05', 'items' => [$this->item],
    ];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
});

it('finalizes each supported fiscal treatment without rewriting persisted data', function (array $changes, string $vat) {
    $this->payload['items'] = [array_replace($this->item, $changes)];
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasNoErrors();
    $before = $this->invoice->fresh();
    $lines = $before->items()->get()->map->getRawOriginal()->all();
    $this->post(route('invoices.finalize', $before))->assertRedirect(route('invoices.show', $before))->assertSessionHas('success');
    $after = $before->fresh();
    expect($after->document_status)->toBe('issued')->and($after->invoice_number)->toBe('FAC-2026-0001')
        ->and($after->total_tva)->toBe($vat)->and($after->calculation_version)->toBe('decimal-v1')
        ->and($after->only(['subtotal_ht', 'total_tva', 'total_ttc', 'date', 'due_date', 'notes']))
        ->toEqual($before->only(['subtotal_ht', 'total_tva', 'total_ttc', 'date', 'due_date', 'notes']))
        ->and($after->items()->get()->map->getRawOriginal()->all())->toBe($lines);
})->with([
    [['tva_rate' => '20'], '20.00'], [['tva_rate' => '10'], '10.00'],
    [['tva_rate' => '5.5'], '5.50'], [['tva_rate' => '2.1'], '2.10'],
    [['tax_treatment' => 'franchise', 'tva_rate' => '0'], '0.00'],
    [['tax_treatment' => 'exempt', 'tva_rate' => '0', 'exemption_reason' => 'Motif explicite'], '0.00'],
    [['tax_treatment' => 'zero_rated', 'tva_rate' => '0'], '0.00'],
]);

it('validates mixed rates and VAT rounded by breakdown rather than per line', function () {
    $tiny = array_replace($this->item, ['unit_price_ht' => '0.03']);
    $this->payload['items'] = [$tiny, $tiny, $tiny, array_replace($this->item, ['tva_rate' => '5.5', 'quantity' => '1.5'])];
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasNoErrors();
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    expect($this->invoice->fresh()->only(['subtotal_ht', 'total_tva', 'total_ttc']))
        ->toBe(['subtotal_ht' => '150.09', 'total_tva' => '8.27', 'total_ttc' => '158.36']);
});

it('refuses invalid stored fiscal lines without changing anything or consuming a number', function (array $changes, string $key) {
    $this->invoice->items()->sole()->update($changes);
    $before = $this->invoice->fresh()->getRawOriginal();
    $lines = $this->invoice->items()->get()->map->getRawOriginal()->all();
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHasErrors($key);
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($this->invoice->items()->get()->map->getRawOriginal()->all())->toBe($lines);
    $this->assertDatabaseCount('invoice_counters', 0);
})->with([
    [['vat_category' => null], 'items.0.vat_category'],
    [['vat_category' => 'X'], 'items.0.vat_category'],
    [['unit_code' => null], 'items.0.unit_code'],
    [['unit_code' => 'BAD'], 'items.0.unit_code'],
    [['tva_rate' => '7'], 'items.0.tva_rate'],
    [['tva_rate' => '0'], 'items.0.tva_rate'],
    [['vat_category' => 'E', 'tva_rate' => '0'], 'items.0.exemption_reason'],
    [['vat_category' => 'Z', 'tva_rate' => '20'], 'items.0.tva_rate'],
    [['exemption_reason' => 'Incompatible'], 'items.0.exemption_reason'],
    [['quantity' => '0'], 'items.0.quantity'],
    [['line_total_ht' => '100.01'], 'items.0.line_total_ht'],
]);

it('refuses missing lines or incompatible exemption reasons', function (string $case) {
    if ($case === 'empty') {
        $this->invoice->items()->delete();
    } else {
        $line = $this->invoice->items()->sole();
        $line->update(['vat_category' => 'E', 'tva_rate' => '0', 'exemption_reason' => 'Motif A']);
        $copy = $line->replicate();
        $copy->exemption_reason = 'Motif B';
        $copy->save();
    }
    $before = $this->invoice->fresh()->getRawOriginal();
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHasErrors('items');
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('invoice_counters', 0);
})->with(['empty', 'reasons']);

it('refuses each inconsistent recorded total and preserves an existing counter', function (string $field) {
    DB::table('invoice_counters')->insert(['user_id' => $this->user->id, 'year' => 2026, 'last_number' => 9]);
    $this->invoice->update([$field => '0.01']);
    $before = $this->invoice->refresh()->getRawOriginal();
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHasErrors($field);
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before)
        ->and((int) DB::table('invoice_counters')->value('last_number'))->toBe(9);
})->with(['subtotal_ht', 'total_tva', 'total_ttc']);

it('refuses incompatible document and payment state without numbering', function (string $case) {
    match ($case) {
        'payment' => $this->invoice->payments()->create(['amount' => '0.00', 'paid_at' => '2026-11-05']),
        'amount' => $this->invoice->update(['amount_paid' => '0.01']),
        'negative amount' => $this->invoice->update(['amount_paid' => '-0.01']),
        'status' => $this->invoice->update(['status' => 'payee']),
        'number' => $this->invoice->update(['invoice_number' => 'EXISTING']),
        'version' => $this->invoice->update(['calculation_version' => 'unsupported']),
    };
    $before = $this->invoice->refresh()->getRawOriginal();
    $this->post(route('invoices.finalize', $this->invoice))->assertStatus(409);
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('invoice_counters', 0);
    if ($case === 'payment') {
        $this->assertDatabaseCount('payments', 1);
    }
})->with(['payment', 'amount', 'negative amount', 'status', 'number', 'version']);

it('keeps historical fiscal data and amounts untouched during finalization', function () {
    $this->invoice->update(['calculation_version' => null, 'subtotal_ht' => '7.00', 'total_tva' => '0.49', 'total_ttc' => '7.49']);
    $this->invoice->items()->sole()->update(['vat_category' => null, 'unit_code' => null, 'tva_rate' => '7']);
    $lines = $this->invoice->items()->get()->map->getRawOriginal()->all();
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    expect($this->invoice->fresh()->only(['subtotal_ht', 'total_tva', 'total_ttc', 'calculation_version']))
        ->toBe(['subtotal_ht' => '7.00', 'total_tva' => '0.49', 'total_ttc' => '7.49', 'calculation_version' => null])
        ->and($this->invoice->items()->get()->map->getRawOriginal()->all())->toBe($lines);
});

it('does not issue or renumber an already issued document on repeated finalization', function () {
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    $before = $this->invoice->fresh()->getRawOriginal();
    $this->post(route('invoices.finalize', $this->invoice))->assertRedirect(route('invoices.show', $this->invoice))->assertSessionHas('error');
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before)
        ->and((int) DB::table('invoice_counters')->value('last_number'))->toBe(1);
});

it('reloads the owner and version before finalizing a stale route model', function (string $case) {
    Invoice::whereKey($this->invoice->id)->update($case === 'owner'
        ? ['user_id' => User::factory()->create()->id] : ['calculation_version' => 'unsupported']);
    try {
        app(InvoiceController::class)->finalize($this->invoice, app(InvoiceLinePreparationService::class));
        $this->fail('The fresh state must be checked.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe($case === 'owner' ? 403 : 409);
    }
    $this->assertDatabaseCount('invoice_counters', 0);
})->with(['owner', 'version']);

it('reloads issued status even for a stale draft model', function () {
    Invoice::whereKey($this->invoice->id)->update(['document_status' => 'issued', 'invoice_number' => 'ORIGINAL']);
    $response = app(InvoiceController::class)->finalize($this->invoice, app(InvoiceLinePreparationService::class));
    expect($response->getTargetUrl())->toBe(route('invoices.show', $this->invoice))
        ->and($this->invoice->fresh()->invoice_number)->toBe('ORIGINAL');
    $this->assertDatabaseCount('invoice_counters', 0);
});

it('rolls back number reservation if issuing fails after allocation', function () {
    $fail = true;
    Event::listen('eloquent.updating: ' . Invoice::class, function (Invoice $invoice) use (&$fail) {
        if ($fail && $invoice->document_status === 'issued') {
            throw new RuntimeException('Simulated write failure');
        }
    });
    $before = $this->invoice->getRawOriginal();
    try {
        app(InvoiceController::class)->finalize($this->invoice, app(InvoiceLinePreparationService::class));
        $this->fail('The write must fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated write failure');
    } finally {
        $fail = false;
    }
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('invoice_counters', 0);
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    expect($this->invoice->fresh()->invoice_number)->toBe('FAC-2026-0001');
});

it('blocks marking decimal drafts unpaid without deleting payments', function () {
    $this->invoice->payments()->create(['amount' => '1.00', 'paid_at' => '2026-11-05']);
    $before = $this->invoice->getRawOriginal();
    $this->post(route('invoices.markAsUnpaid', $this->invoice))->assertStatus(409);
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('payments', 1);
});

it('preserves marking historical drafts and issued invoices unpaid', function (string $state) {
    $this->invoice->update($state === 'historical' ? ['calculation_version' => null] : ['document_status' => 'issued', 'invoice_number' => 'ORIGINAL']);
    $this->invoice->update(['amount_paid' => '10.00', 'status' => 'partiellement_payee']);
    $this->invoice->payments()->create(['amount' => '10.00', 'paid_at' => '2026-11-05']);
    $before = $this->invoice->fresh();
    $this->post(route('invoices.markAsUnpaid', $this->invoice))->assertRedirect(route('invoices.show', $this->invoice))->assertSessionHas('success');
    expect($this->invoice->fresh()->status)->toBe('non_payee')->and($this->invoice->fresh()->amount_paid)->toBe('0.00')
        ->and($this->invoice->fresh()->invoice_number)->toBe($before->invoice_number)
        ->and($this->invoice->fresh()->total_ttc)->toBe($before->total_ttc);
    $this->assertDatabaseCount('payments', 0);
})->with(['historical', 'issued']);

it('deletes drafts but refuses issued invoices under lock', function (string $state) {
    if ($state === 'issued') {
        $this->invoice->update(['document_status' => 'issued']);
        $this->delete(route('invoices.destroy', $this->invoice))->assertForbidden();
        $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id]);
    } else {
        $this->delete(route('invoices.destroy', $this->invoice))->assertRedirect(route('invoices.index'));
        $this->assertDatabaseMissing('invoices', ['id' => $this->invoice->id]);
        $this->assertDatabaseCount('invoice_items', 0);
    }
})->with(['draft', 'issued']);

it('rechecks owner under lock for unpaid and delete actions', function (string $method) {
    Invoice::whereKey($this->invoice->id)->update(['user_id' => User::factory()->create()->id]);
    try {
        app(InvoiceController::class)->$method($this->invoice);
        $this->fail('The fresh owner must be checked.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id]);
})->with(['markAsUnpaid', 'destroy']);

it('rechecks documentary status under lock before deletion', function () {
    Invoice::whereKey($this->invoice->id)->update(['document_status' => 'issued']);
    try {
        app(InvoiceController::class)->destroy($this->invoice);
        $this->fail('An issued invoice must survive.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id]);
});

it('rechecks draft status under lock before marking unpaid', function () {
    $this->invoice->update(['document_status' => 'issued']);
    $stale = $this->invoice->fresh();
    Invoice::whereKey($stale->id)->update(['document_status' => 'draft']);
    try {
        app(InvoiceController::class)->markAsUnpaid($stale);
        $this->fail('The fresh draft status must be checked.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
});

it('no longer announces that decimal draft finalization is unavailable', function () {
    $this->get(route('invoices.edit', $this->invoice))->assertOk()
        ->assertDontSee('La validation définitive de ce brouillon reste temporairement indisponible.');
});

it('continues the existing numbering sequence across draft finalization and direct issue', function () {
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    $this->payload['action'] = 'issue';
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    expect(Invoice::orderBy('id')->pluck('invoice_number')->all())->toBe(['FAC-2026-0001', 'FAC-2026-0002'])
        ->and((int) DB::table('invoice_counters')->value('last_number'))->toBe(2);
});
