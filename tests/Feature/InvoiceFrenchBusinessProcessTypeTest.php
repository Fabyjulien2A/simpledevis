<?php

use App\Http\Controllers\InvoiceController;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\InvoiceLinePreparationService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->client = $this->owner->clients()->create(['first_name' => 'Client de test']);
    $this->actingAs($this->owner);
    $this->payload = [
        'action' => 'draft', 'client_id' => $this->client->id, 'date' => '2026-11-07',
        'items' => [[
            'description' => 'Fourniture et pose', 'quantity' => '1', 'unit_price_ht' => '100',
            'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable',
        ]],
    ];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
});

afterEach(function () {
    Http::assertNothingSent();
});

dataset('French invoice process codes', ['B1', 'S1', 'M1']);
dataset('French invoice calculation versions', ['decimal' => ['decimal-v1'], 'historical' => [null]]);

it('leaves a new draft unspecified without inferring from company or line metadata', function () {
    $this->owner->company()->create(['company_name' => 'Artisan', 'vat_regime' => 'subject_to_vat']);
    expect($this->invoice->french_business_process_type)->toBeNull()
        ->and($this->invoice->document_status)->toBe('draft')->and($this->invoice->invoice_number)->toBeNull();
    $this->assertDatabaseCount('invoice_counters', 0);
    $this->get(route('invoices.create'))->assertOk()->assertSee('value="" selected', false)
        ->assertSee('Biens — B1')->assertSee('Services — S1')->assertSee('Biens et services — M1');
});

it('creates a draft or a directly issued invoice with each explicit code', function (string $code, string $action) {
    $this->payload['french_business_process_type'] = $code;
    $this->payload['action'] = $action;
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $invoice = Invoice::latest('id')->first();
    expect($invoice->french_business_process_type)->toBe($code)
        ->and($invoice->document_status)->toBe($action === 'issue' ? 'issued' : 'draft');
    if ($action === 'issue') {
        expect($invoice->invoice_number)->not->toBeNull();
    } else {
        expect($invoice->invoice_number)->toBeNull();
    }
})->with('French invoice process codes')->with(['draft', 'issue']);

it('allows unspecified draft creation but refuses direct issue without a code', function (array $field) {
    $this->post(route('invoices.store'), $field + $this->payload)->assertSessionHasNoErrors();
    expect(Invoice::latest('id')->first()->french_business_process_type)->toBeNull();
    $before = Invoice::count();
    $this->post(route('invoices.store'), $field + array_replace($this->payload, ['action' => 'issue']))
        ->assertSessionHasErrors('french_business_process_type');
    expect(Invoice::count())->toBe($before);
    $this->assertDatabaseCount('invoice_counters', 0);
})->with([[[]], [['french_business_process_type' => null]], [['french_business_process_type' => '']]]);

it('edits and displays each explicit code on decimal and historical drafts', function (string $code, ?string $version) {
    $this->invoice->update(['calculation_version' => $version]);
    $this->payload['french_business_process_type'] = $code;
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasNoErrors();
    expect($this->invoice->fresh()->french_business_process_type)->toBe($code)
        ->and($this->invoice->fresh()->calculation_version)->toBe($version);
    $this->get(route('invoices.edit', $this->invoice))->assertOk()->assertSee('value="'.$code.'" selected', false);
})->with('French invoice process codes')->with('French invoice calculation versions');

it('preserves an omitted code and clears it only when explicitly emptied', function (?string $version, mixed $empty) {
    $this->invoice->update(['calculation_version' => $version, 'french_business_process_type' => 'M1']);
    $this->put(route('invoices.update', $this->invoice), $this->payload)->assertSessionHasNoErrors();
    expect($this->invoice->fresh()->french_business_process_type)->toBe('M1');
    $this->put(route('invoices.update', $this->invoice), ['french_business_process_type' => $empty] + $this->payload)
        ->assertSessionHasNoErrors();
    expect($this->invoice->fresh()->french_business_process_type)->toBeNull();
})->with('French invoice calculation versions')->with([[null], [''], ['   ']]);

it('rejects invalid codes without changing the draft or its lines', function (mixed $code, ?string $version) {
    $this->invoice->update(['calculation_version' => $version, 'french_business_process_type' => 'S1']);
    $before = $this->invoice->fresh()->getRawOriginal();
    $lines = $this->invoice->items()->get()->map->getRawOriginal()->all();
    $this->put(route('invoices.update', $this->invoice), ['french_business_process_type' => $code] + $this->payload)
        ->assertSessionHasErrors('french_business_process_type');
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($this->invoice->items()->get()->map->getRawOriginal()->all())->toBe($lines);
    $this->post(route('invoices.store'), ['french_business_process_type' => $code] + $this->payload)
        ->assertSessionHasErrors('french_business_process_type');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('invoice_counters', 0);
})->with([['B2'], ['s1'], ['M2'], ['UNKNOWN'], [1], [false], [true], [['S1']]])
    ->with('French invoice calculation versions');

it('retains the submitted choice or blank after an unrelated validation error', function (string $code) {
    $this->invoice->update(['french_business_process_type' => 'B1']);
    $this->from(route('invoices.edit', $this->invoice))->put(route('invoices.update', $this->invoice), [
        'french_business_process_type' => $code, 'date' => 'invalid',
    ] + $this->payload)->assertSessionHasErrors('date');
    $this->get(route('invoices.edit', $this->invoice))->assertOk()->assertSee('value="'.$code.'" selected', false);
    expect($this->invoice->fresh()->french_business_process_type)->toBe('B1');
})->with(['M1', '']);

it('finalizes each registered code without accepting a request-only replacement', function (string $code, ?string $version) {
    $this->invoice->update(['calculation_version' => $version, 'french_business_process_type' => $code]);
    $before = $this->invoice->fresh();
    $this->post(route('invoices.finalize', $this->invoice), ['french_business_process_type' => 'REPLACEMENT'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    $after = $this->invoice->fresh();
    expect($after->french_business_process_type)->toBe($code)->and($after->document_status)->toBe('issued')
        ->and($after->invoice_number)->not->toBeNull()
        ->and($after->only(['subtotal_ht', 'total_tva', 'total_ttc', 'calculation_version']))
        ->toBe($before->only(['subtotal_ht', 'total_tva', 'total_ttc', 'calculation_version']));
})->with('French invoice process codes')->with('French invoice calculation versions');

it('blocks finalization of an unspecified draft even with a request-only code', function (?string $version) {
    $this->invoice->update(['calculation_version' => $version]);
    $before = $this->invoice->fresh()->getRawOriginal();
    $this->post(route('invoices.finalize', $this->invoice), ['french_business_process_type' => 'S1'])
        ->assertSessionHasErrors('french_business_process_type');
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('invoice_counters', 0);
})->with('French invoice calculation versions');

it('checks the persisted code even when the route model is stale', function (?string $stored, bool $valid) {
    $this->invoice->french_business_process_type = $valid ? null : 'S1';
    DB::table('invoices')->where('id', $this->invoice->id)->update(['french_business_process_type' => $stored]);
    try {
        app(InvoiceController::class)->finalize($this->invoice, app(InvoiceLinePreparationService::class));
        expect($valid)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($valid)->toBeFalse()->and($exception->errors())->toHaveKey('french_business_process_type');
    }
    expect($this->invoice->fresh()->document_status)->toBe($valid ? 'issued' : 'draft')
        ->and($this->invoice->fresh()->french_business_process_type)->toBe($stored);
    $this->assertDatabaseCount('invoice_counters', $valid ? 1 : 0);
})->with([['M1', true], [null, false]]);

it('rereads the code during direct issue and rolls back if storage no longer has it', function () {
    Event::listen('eloquent.created: '.Invoice::class, function (Invoice $invoice) {
        DB::table('invoices')->where('id', $invoice->id)->update(['french_business_process_type' => null]);
    });
    $this->post(route('invoices.store'), ['action' => 'issue', 'french_business_process_type' => 'S1'] + $this->payload)
        ->assertSessionHasErrors('french_business_process_type');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('invoice_items', 1);
    $this->assertDatabaseCount('invoice_counters', 0);
});

it('freezes the issued code while allowing payment and PDP updates', function (string $code) {
    $this->invoice->update(['french_business_process_type' => $code]);
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    $invoice = $this->invoice->fresh();
    $before = $invoice->getRawOriginal();
    foreach (array_diff(['B1', 'S1', 'M1', null], [$code]) as $replacement) {
        $attempt = $invoice->fresh();
        expect(fn () => $attempt->update(['french_business_process_type' => $replacement]))->toThrow(ValidationException::class);
        expect($invoice->fresh()->getRawOriginal())->toBe($before);
    }
    $this->put(route('invoices.update', $invoice), ['french_business_process_type' => null] + $this->payload)->assertForbidden();
    $this->get(route('invoices.edit', $invoice))->assertForbidden();
    // Fixture SQLite existante : la migration historique change() ne crée pas method.
    if (! Schema::hasColumn('payments', 'method')) {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method')->nullable();
        });
    }
    $this->post(route('invoices.markAsPaid', $invoice))->assertSessionHas('success');
    $invoice->refresh()->update(['pdp_status' => 'accepted', 'pdp_reference' => 'TEST-REFERENCE']);
    expect($invoice->fresh()->french_business_process_type)->toBe($code)
        ->and($invoice->fresh()->status)->toBe('payee')->and($invoice->fresh()->pdp_status)->toBe('accepted');
})->with('French invoice process codes');

it('blocks stale model changes and simultaneous status downgrades after finalization', function (bool $downgrade) {
    $this->invoice->update(['french_business_process_type' => 'S1']);
    $stale = $this->invoice->fresh();
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    $changes = ['french_business_process_type' => 'M1'];
    if ($downgrade) {
        $changes['document_status'] = 'draft';
    }
    expect(fn () => $stale->update($changes))->toThrow(ValidationException::class);
    expect($stale->fresh()->document_status)->toBe('issued')->and($stale->fresh()->french_business_process_type)->toBe('S1');
})->with([false, true]);

it('keeps a historical issued null code immutable while allowing unrelated updates', function () {
    $this->invoice->update(['calculation_version' => null, 'document_status' => 'issued', 'invoice_number' => 'HISTORICAL']);
    expect(fn () => $this->invoice->update(['french_business_process_type' => 'S1']))->toThrow(ValidationException::class);
    $this->invoice->refresh()->update(['pdp_status' => 'generated']);
    expect($this->invoice->fresh()->french_business_process_type)->toBeNull();
});

it('duplicates into an unspecified draft and requires a fresh explicit choice', function (string $code) {
    $this->invoice->update(['french_business_process_type' => $code]);
    $this->post(route('invoices.finalize', $this->invoice))->assertSessionHas('success');
    $this->post(route('invoices.duplicate', $this->invoice), ['french_business_process_type' => $code])->assertRedirect();
    $copy = Invoice::latest('id')->first();
    expect($copy->id)->not->toBe($this->invoice->id)->and($copy->document_status)->toBe('draft')
        ->and($copy->invoice_number)->toBeNull()->and($copy->french_business_process_type)->toBeNull();
    $this->post(route('invoices.finalize', $copy))->assertSessionHasErrors('french_business_process_type');
})->with('French invoice process codes');

it('converts a quote into an unspecified draft without taking a request-only choice', function () {
    $quote = Quote::create([
        'user_id' => $this->owner->id, 'client_id' => $this->client->id, 'quote_number' => 'DEV-TEST',
        'date' => '2026-11-07', 'status' => 'brouillon', 'subtotal_ht' => '100', 'total_tva' => '20', 'total_ttc' => '120',
    ]);
    $quote->items()->create([
        'description' => 'Prestation', 'quantity' => '1', 'unit_price_ht' => '100', 'tva_rate' => '20', 'line_total_ht' => '100',
    ]);
    $this->post(route('quotes.convertToInvoice', $quote), ['french_business_process_type' => 'M1'])->assertRedirect();
    $invoice = $quote->invoice()->sole();
    expect($invoice->document_status)->toBe('draft')->and($invoice->french_business_process_type)->toBeNull()
        ->and($invoice->invoice_number)->toBeNull();
    $this->post(route('invoices.finalize', $invoice))->assertSessionHasErrors('french_business_process_type');
});

it('isolates draft editing finalization duplication and form access between owners', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $before = $this->invoice->fresh()->getRawOriginal();
    $this->get(route('invoices.edit', $this->invoice))->assertForbidden();
    $this->put(route('invoices.update', $this->invoice), ['french_business_process_type' => 'M1'] + $this->payload)->assertForbidden();
    $this->post(route('invoices.finalize', $this->invoice), ['french_business_process_type' => 'M1'])->assertForbidden();
    $this->post(route('invoices.duplicate', $this->invoice))->assertForbidden();
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
    $this->post(route('invoices.store'), ['french_business_process_type' => 'M1'] + $this->payload)->assertNotFound();
    $this->assertDatabaseCount('invoice_counters', 0);
});

it('adds only a nullable constrained column without altering historical invoices', function () {
    $previousConnection = DB::getDefaultConnection();
    $connectionName = 'invoice_french_process_legacy_test';
    config(['database.connections.'.$connectionName => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    try {
        DB::setDefaultConnection($connectionName);
        Schema::clearResolvedInstance('db.schema');
        $migrationName = '2026_11_07_000003_add_french_business_process_type_to_invoices_table.php';
        $paths = glob(database_path('migrations/*.php'));
        sort($paths);
        foreach ($paths as $path) {
            if (basename($path) < $migrationName) {
                (require $path)->up();
            }
        }
        $user = User::factory()->create();
        $client = $user->clients()->create(['first_name' => 'Client historique']);
        foreach (['draft', 'issued'] as $status) {
            Invoice::create([
                'user_id' => $user->id, 'client_id' => $client->id, 'date' => '2026-11-07',
                'document_status' => $status, 'status' => 'non_payee',
            ]);
        }
        $before = DB::table('invoices')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        (require database_path('migrations/'.$migrationName))->up();
        $after = DB::table('invoices')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        expect($after)->toBe(array_map(fn ($row) => [...$row, 'french_business_process_type' => null], $before));
        $column = collect(Schema::getColumns('invoices'))->firstWhere('name', 'french_business_process_type');
        expect($column['nullable'])->toBeTrue()->and($column['default'])->toBeNull();
    } finally {
        DB::setDefaultConnection($previousConnection);
        Schema::clearResolvedInstance('db.schema');
        DB::purge($connectionName);
        config(['database.connections.'.$connectionName => null]);
    }
});

it('also rejects unknown stored codes at database level', function () {
    expect(fn () => DB::table('invoices')->where('id', $this->invoice->id)->update([
        'french_business_process_type' => 'B2',
    ]))->toThrow(QueryException::class);
    expect($this->invoice->fresh()->french_business_process_type)->toBeNull();
});
