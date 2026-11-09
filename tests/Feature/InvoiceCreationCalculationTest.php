<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::create([
        'user_id' => $this->user->id, 'first_name' => 'Marie', 'last_name' => 'Dupont',
        'client_type' => 'individual', 'country_code' => 'FR',
    ]);
    $this->actingAs($this->user);
    $this->item = [
        'description' => 'Prestation', 'quantity' => '1.50', 'unit_price_ht' => '40.00',
        'tva_rate' => '10', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
    ];
    $this->payload = [
        'french_business_process_type' => 'S1',
        'action' => 'draft', 'client_id' => $this->client->id,
        'date' => '2026-11-05', 'due_date' => '2026-12-05',
        'notes' => 'Note conservée', 'items' => [$this->item],
    ];
});

it('creates a complete draft or directly issued invoice using decimal calculation', function (string $action) {
    $this->payload['action'] = $action;
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));

    $invoice = Invoice::sole();
    $item = $invoice->items()->sole();
    expect($invoice->calculation_version)->toBe('decimal-v1')
        ->and($invoice->document_status)->toBe($action === 'issue' ? 'issued' : 'draft');
    if ($action === 'issue') {
        expect($invoice->invoice_number)->toMatch('/^FAC-\d{4}-0001$/');
    } else {
        expect($invoice->invoice_number)->toBeNull();
        $this->assertDatabaseCount('invoice_counters', 0);
    }
    expect([$invoice->subtotal_ht, $invoice->total_tva, $invoice->total_ttc])->toBe(['60.00', '6.00', '66.00'])
        ->and($invoice->status)->toBe('non_payee')->and($invoice->amount_paid)->toBe('0.00')
        ->and($invoice->notes)->toBe('Note conservée')
        ->and($invoice->date->format('Y-m-d'))->toBe('2026-11-05')
        ->and($invoice->due_date->format('Y-m-d'))->toBe('2026-12-05')
        ->and([$item->vat_category, $item->unit_code, $item->line_total_ht])->toBe(['S', 'HUR', '60.00']);
    $this->assertDatabaseCount('payments', 0);
})->with(['draft', 'issue']);

it('persists the four supported rates with separate rounded VAT calculations', function () {
    $this->payload['items'] = array_map(fn ($rate) => array_replace($this->item, [
        'quantity' => '1', 'unit_price_ht' => '100', 'tva_rate' => $rate,
    ]), ['20', '10', '5.5', '2.1']);
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();

    expect([$invoice->subtotal_ht, $invoice->total_tva, $invoice->total_ttc])->toBe(['400.00', '37.60', '437.60'])
        ->and($invoice->items()->orderBy('id')->pluck('tva_rate')->map(fn ($rate) => (string) $rate)->all())
        ->toBe(['20.00', '10.00', '5.50', '2.10']);
});

it('stores VAT of 0.02 for three 0.03 lines at 20 percent', function () {
    $item = array_replace($this->item, ['quantity' => '1', 'unit_price_ht' => '0.03', 'tva_rate' => '20']);
    $this->payload['items'] = [$item, $item, $item];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();

    $invoice = Invoice::sole();
    expect([$invoice->subtotal_ht, $invoice->total_tva, $invoice->total_ttc])->toBe(['0.09', '0.02', '0.11']);
});

it('stores explicit franchise exemption and zero rated metadata', function (string $treatment, array $metadata, string $category, ?string $code) {
    $this->payload['items'] = [array_replace($this->item, ['tax_treatment' => $treatment, 'tva_rate' => '0'], $metadata)];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $item = $invoice->items()->sole();

    expect($item->vat_category)->toBe($category)
        ->and($item->exemption_reason_code)->toBe($code)
        ->and($item->exemption_reason)->toBe($metadata['exemption_reason'] ?? null)
        ->and([$invoice->total_tva, $invoice->total_ttc])->toBe(['0.00', '60.00']);
})->with([
    ['franchise', [], 'E', 'VATEX-FR-FRANCHISE'],
    ['exempt', ['exemption_reason' => 'Justification fournie'], 'E', null],
    ['zero_rated', [], 'Z', null],
]);

it('ignores forged totals and calculation version from the browser', function () {
    $this->payload = array_merge($this->payload, ['total_ttc' => '0.01', 'calculation_version' => 'legacy']);
    $this->payload['items'][0]['line_total_ht'] = '0.01';
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();

    expect(Invoice::sole()->total_ttc)->toBe('66.00')
        ->and(Invoice::sole()->calculation_version)->toBe('decimal-v1')
        ->and(Invoice::sole()->items()->sole()->line_total_ht)->toBe('60.00');
});

it('refuses incomplete or inconsistent creation without writes or number allocation', function (array $changes, string $error) {
    $this->payload['action'] = 'issue';
    $this->payload['items'][0] = array_replace($this->item, $changes);
    $this->from(route('invoices.create'))->post(route('invoices.store'), $this->payload)
        ->assertRedirect(route('invoices.create'))->assertSessionHasErrors($error);

    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('invoice_items', 0);
    $this->assertDatabaseCount('invoice_counters', 0);
})->with([
    [['unit_code' => null], 'items.0.unit_code'],
    [['tax_treatment' => null], 'items.0.tax_treatment'],
    [['tax_treatment' => ['taxable']], 'items.0.tax_treatment'],
    [['tva_rate' => '7'], 'items.0.tva_rate'],
    [['tax_treatment' => 'taxable', 'tva_rate' => '0'], 'items.0.tva_rate'],
    [['tax_treatment' => 'exempt', 'tva_rate' => '0'], 'items.0.exemption_reason'],
    [['tax_treatment' => 'franchise', 'tva_rate' => '20'], 'items.0.tva_rate'],
    [['tax_treatment' => 'franchise', 'tva_rate' => '0', 'exemption_reason_code' => 'OTHER'], 'items.0.exemption_reason_code'],
    [['tax_treatment' => 'exempt', 'tva_rate' => '0', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE'], 'items.0.tax_treatment'],
    [['vat_category' => 'E'], 'items.0.vat_category'],
    [['exemption_reason_code' => 'VATEX-FR-FRANCHISE'], 'items.0.exemption_reason_code'],
    [['quantity' => '2', 'unit_price_ht' => '90000000'], 'items.0.line_total_ht'],
]);

it('rejects invalid drafts too', function () {
    unset($this->payload['items'][0]['unit_code']);
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasErrors('items.0.unit_code');
    $this->assertDatabaseCount('invoices', 0);
});

it('does not consume another number when an invalid creation follows an issued invoice', function () {
    $this->payload['action'] = 'issue';
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    unset($this->payload['items'][0]['unit_code']);
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasErrors('items.0.unit_code');

    $this->assertDatabaseCount('invoices', 1);
    expect((int) DB::table('invoice_counters')->value('last_number'))->toBe(1);
});

it('rejects a client belonging to another account', function () {
    $other = User::factory()->create();
    $this->payload['client_id'] = Client::create(['user_id' => $other->id, 'first_name' => 'Autre'])->id;
    $this->post(route('invoices.store'), $this->payload)->assertNotFound();
    $this->assertDatabaseCount('invoices', 0);
});

it('finalizes decimal drafts without changing their amounts or lines', function () {
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->only(['subtotal_ht', 'total_tva', 'total_ttc', 'calculation_version']);
    $beforeItems = $invoice->items()->get()->map->getRawOriginal()->all();

    $this->post(route('invoices.finalize', $invoice))->assertSessionHasNoErrors()->assertRedirect(route('invoices.show', $invoice));

    expect($invoice->fresh()->only(['subtotal_ht', 'total_tva', 'total_ttc', 'calculation_version']))
        ->toBe($before)
        ->and($invoice->items()->get()->map->getRawOriginal()->all())->toBe($beforeItems);
    expect($invoice->fresh()->document_status)->toBe('issued')->and($invoice->fresh()->invoice_number)->not->toBeNull();
    $this->assertDatabaseCount('invoice_counters', 1);
});

it('preserves the legacy update and finalization paths for historical drafts', function (string $operation) {
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $invoice->update(['calculation_version' => null]);

    if ($operation === 'update') {
        $this->payload['notes'] = 'Modification historique';
        $this->put(route('invoices.update', $invoice), $this->payload)->assertSessionHasNoErrors()->assertRedirect();
        expect($invoice->fresh()->notes)->toBe('Modification historique');
    } else {
        $this->post(route('invoices.finalize', $invoice))->assertSessionHasNoErrors()->assertRedirect();
        expect($invoice->fresh()->document_status)->toBe('issued')->and($invoice->fresh()->invoice_number)->not->toBeNull();
    }
})->with(['update', 'finalize']);

it('continues refusing updates of already issued invoices', function () {
    $this->payload['action'] = 'issue';
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $before = $invoice->getRawOriginal();
    $this->put(route('invoices.update', $invoice), $this->payload)->assertForbidden();
    expect($invoice->fresh()->getRawOriginal())->toBe($before);
});

it('shows creation choices and indicative preview without changing the historical edit fields', function () {
    $this->get(route('invoices.create'))->assertOk()
        ->assertSee('name="items[0][unit_code]"', false)
        ->assertSee('name="items[0][tax_treatment]"', false)
        ->assertSee('5,5 %')->assertSee('2,1 %')->assertSee('Aperçu indicatif')
        ->assertSee('plusieurs taux de TVA');

    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::sole();
    $invoice->update(['calculation_version' => null]);
    $this->get(route('invoices.edit', $invoice))->assertOk()
        ->assertDontSee('name="items[0][unit_code]"', false)
        ->assertDontSee('name="items[0][tax_treatment]"', false)
        ->assertDontSee('Aperçu indicatif');
});

it('restores submitted fiscal choices and displays errors after rejection', function () {
    $this->payload['items'][0] = array_replace($this->item, [
        'tax_treatment' => 'exempt', 'tva_rate' => '0', 'unit_code' => 'MTR',
    ]);
    $this->from(route('invoices.create'))->post(route('invoices.store'), $this->payload)
        ->assertSessionHasErrors('items.0.exemption_reason')->assertSessionHasInput('items.0.unit_code', 'MTR');
    $this->get(route('invoices.create'))->assertOk()
        ->assertSee('Renseignez un code ou un motif justifiant cette exonération.')
        ->assertSee('value="exempt" selected', false)->assertSee('value="MTR" selected', false);
});
