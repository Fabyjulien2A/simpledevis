<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function invoiceNumberIndexMigration(): object
{
    return require database_path('migrations/2026_11_05_000003_remove_global_invoice_number_unique_index.php');
}

function invoiceNumberIndexSnapshot(): array
{
    return array_column(Schema::getIndexes('invoices'), null, 'name');
}

function invoiceNumberDocument(User $user, ?string $number): Invoice
{
    $client = Client::create(['user_id' => $user->id, 'first_name' => 'Marie']);

    return Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'invoice_number' => $number, 'date' => '2026-11-05',
        'document_status' => $number === null ? 'draft' : 'issued',
        'status' => 'non_payee', 'amount_paid' => '0.00',
        'subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00',
    ]);
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->user = User::factory()->create();
});

it('allows the same invoice number for different users while preserving the composite index', function () {
    $first = invoiceNumberDocument($this->user, 'FAC-2026-0001');
    $second = invoiceNumberDocument(User::factory()->create(), 'FAC-2026-0001');
    expect($first->id)->not->toBe($second->id);
    $indexes = invoiceNumberIndexSnapshot();
    expect($indexes)->not->toHaveKey('invoices_invoice_number_unique')
        ->and($indexes['invoices_user_number_unique']['unique'])->toBeTrue()
        ->and($indexes['invoices_user_number_unique']['columns'])->toBe(['user_id', 'invoice_number']);
});

it('rejects duplicate numbers for the same user at database level', function () {
    invoiceNumberDocument($this->user, 'FAC-2026-0001');
    expect(fn () => invoiceNumberDocument($this->user, 'FAC-2026-0001'))->toThrow(QueryException::class);
    $this->assertDatabaseCount('invoices', 1);
});

it('allows multiple null draft numbers for the same user and after restoring global uniqueness', function () {
    invoiceNumberDocument($this->user, null);
    invoiceNumberDocument($this->user, null);
    invoiceNumberIndexMigration()->down();
    invoiceNumberDocument($this->user, null);
    invoiceNumberIndexMigration()->up();
    expect(Invoice::whereNull('invoice_number')->count())->toBe(3);
});

it('preserves documents counters and credit notes across migration and rollback', function () {
    $invoice = invoiceNumberDocument($this->user, 'FAC-2026-0009');
    $invoice->items()->create([
        'description' => 'Prestation', 'quantity' => '1.00', 'unit_price_ht' => '100.00',
        'tva_rate' => '20.00', 'line_total_ht' => '100.00',
    ]);
    $invoice->payments()->create(['amount' => '10.00', 'paid_at' => '2026-11-05']);
    $invoice->creditNotes()->create([
        'user_id' => $this->user->id, 'credit_note_number' => 'AV-2026-0001',
        'date' => '2026-11-05', 'status' => 'issued', 'reason' => 'Annulation',
        'subtotal_ht' => '100.00', 'total_tva' => '20.00', 'total_ttc' => '120.00',
    ]);
    DB::table('invoice_counters')->insert(['user_id' => $this->user->id, 'year' => 2026, 'last_number' => 9]);
    DB::table('credit_note_counters')->insert(['user_id' => $this->user->id, 'year' => 2026, 'last_number' => 1]);
    $snapshot = fn () => collect(['invoices', 'invoice_items', 'payments', 'credit_notes', 'invoice_counters', 'credit_note_counters'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    $before = $snapshot();

    invoiceNumberIndexMigration()->down();
    $legacyIndexes = invoiceNumberIndexSnapshot();
    expect($legacyIndexes['invoices_invoice_number_unique']['unique'])->toBeTrue()
        ->and($legacyIndexes['invoices_invoice_number_unique']['columns'])->toBe(['invoice_number']);
    invoiceNumberIndexMigration()->up();
    $expectedIndexes = $legacyIndexes;
    unset($expectedIndexes['invoices_invoice_number_unique']);
    expect(invoiceNumberIndexSnapshot())->toBe($expectedIndexes)->and($snapshot())->toBe($before);

    invoiceNumberIndexMigration()->down();
    expect(invoiceNumberIndexSnapshot())->toBe($legacyIndexes)->and($snapshot())->toBe($before);
});

it('restores global enforcement on rollback without dropping the composite index', function () {
    invoiceNumberDocument($this->user, 'FAC-2026-0001');
    invoiceNumberIndexMigration()->down();
    expect(fn () => invoiceNumberDocument(User::factory()->create(), 'FAC-2026-0001'))->toThrow(QueryException::class);
    expect(invoiceNumberIndexSnapshot())->toHaveKeys(['invoices_invoice_number_unique', 'invoices_user_number_unique']);
});

it('refuses rollback with shared non null numbers without changing data or indexes', function (string $number) {
    invoiceNumberDocument($this->user, $number);
    invoiceNumberDocument(User::factory()->create(), $number);
    $before = DB::table('invoices')->orderBy('id')->get()->toJson();
    $indexes = invoiceNumberIndexSnapshot();
    expect(fn () => invoiceNumberIndexMigration()->down())->toThrow(RuntimeException::class, 'Rollback refusé');
    expect(DB::table('invoices')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(invoiceNumberIndexSnapshot())->toBe($indexes);
})->with(['FAC-2026-0001', '']);

it('refuses unexpected index definitions before dropping anything', function (string $name, ?array $columns, bool $unique) {
    invoiceNumberIndexMigration()->down();
    invoiceNumberDocument($this->user, 'FAC-2026-0001');
    Schema::table('invoices', function (Blueprint $table) use ($name, $columns, $unique) {
        $table->dropUnique($name);
        if ($columns !== null) {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        }
    });
    $indexes = invoiceNumberIndexSnapshot();
    $before = DB::table('invoices')->get()->toJson();
    expect(fn () => invoiceNumberIndexMigration()->up())->toThrow(RuntimeException::class, 'Schéma invoices inattendu');
    expect(invoiceNumberIndexSnapshot())->toBe($indexes)->and(DB::table('invoices')->get()->toJson())->toBe($before);
})->with([
    ['invoices_user_number_unique', null, true],
    ['invoices_user_number_unique', ['user_id', 'invoice_number'], false],
    ['invoices_user_number_unique', ['invoice_number', 'user_id'], true],
    ['invoices_user_number_unique', ['user_id', 'date'], true],
    ['invoices_invoice_number_unique', null, true],
    ['invoices_invoice_number_unique', ['invoice_number'], false],
    ['invoices_invoice_number_unique', ['invoice_number', 'date'], true],
]);

it('refuses rollback if the protective composite index is missing', function () {
    Schema::table('invoices', fn (Blueprint $table) => $table->dropUnique('invoices_user_number_unique'));
    $indexes = invoiceNumberIndexSnapshot();
    expect(fn () => invoiceNumberIndexMigration()->down())->toThrow(RuntimeException::class, 'invoices_user_number_unique');
    expect(invoiceNumberIndexSnapshot())->toBe($indexes);
});

it('refuses rollback when the global index is already present without changing indexes', function () {
    invoiceNumberIndexMigration()->down();
    $indexes = invoiceNumberIndexSnapshot();
    expect(fn () => invoiceNumberIndexMigration()->down())->toThrow(RuntimeException::class, 'existe déjà');
    expect(invoiceNumberIndexSnapshot())->toBe($indexes);
});

it('keeps independent numbering sequences using the unchanged generator', function () {
    $this->travelTo(now()->setDate(2026, 11, 5));
    $other = User::factory()->create();
    $issue = fn (User $user) => DB::transaction(fn () => invoiceNumberDocument($user, Invoice::generateInvoiceNumber($user->id)));

    expect($issue($this->user)->invoice_number)->toBe('FAC-2026-0001')
        ->and($issue($other)->invoice_number)->toBe('FAC-2026-0001')
        ->and($issue($this->user)->invoice_number)->toBe('FAC-2026-0002')
        ->and((int) DB::table('invoice_counters')->where('user_id', $this->user->id)->value('last_number'))->toBe(2)
        ->and((int) DB::table('invoice_counters')->where('user_id', $other->id)->value('last_number'))->toBe(1);
});
