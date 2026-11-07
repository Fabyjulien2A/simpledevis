<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createInvoiceForTaxMetadataStorage(): Invoice
{
    $user = User::factory()->create();
    $client = Client::create([
        'user_id' => $user->id,
        'first_name' => 'Marie',
        'last_name' => 'Dupont',
        'client_type' => 'individual',
        'country_code' => 'FR',
    ]);

    return Invoice::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'invoice_number' => 'FAC-2026-0001',
        'document_status' => 'issued',
        'date' => '2026-11-05',
        'status' => 'non_payee',
        'subtotal_ht' => '100.00',
        'total_tva' => '20.00',
        'total_ttc' => '120.00',
    ]);
}

it('persists optional line metadata through mass assignment', function (array $metadata) {
    $invoice = createInvoiceForTaxMetadataStorage();
    $invoiceBefore = $invoice->refresh()->getRawOriginal();
    $item = $invoice->items()->create(array_merge([
        'description' => 'Prestation',
        'quantity' => '1.00',
        'unit_price_ht' => '100.00',
        'tva_rate' => '20.00',
        'line_total_ht' => '100.00',
    ], $metadata));

    $item->refresh();

    foreach ($metadata as $field => $value) {
        expect($item->getAttribute($field))->toBe($value);
    }

    $this->assertDatabaseHas('invoice_items', array_merge(['id' => $item->id], $metadata));
    expect($item->line_total_ht)->toBe('100.00')
        ->and($invoice->fresh()->getRawOriginal())->toBe($invoiceBefore);
})->with([
    'taxable hours' => [[
        'vat_category' => 'S', 'exemption_reason_code' => null,
        'exemption_reason' => null, 'unit_code' => 'HUR',
    ]],
    'explicit franchise' => [[
        'vat_category' => 'E', 'exemption_reason_code' => 'VATEX-FR-FRANCHISE',
        'exemption_reason' => 'TVA non applicable, art. 293 B du CGI', 'unit_code' => 'C62',
    ]],
    'zero rated metres' => [[
        'vat_category' => 'Z', 'exemption_reason_code' => null,
        'exemption_reason' => null, 'unit_code' => 'MTR',
    ]],
]);

it('keeps new fields null when documents are saved without tax metadata', function () {
    $invoice = createInvoiceForTaxMetadataStorage();
    $item = $invoice->items()->create([
        'description' => 'Ancienne ligne',
        'quantity' => '1.00',
        'unit_price_ht' => '100.00',
        'tva_rate' => '20.00',
        'line_total_ht' => '100.00',
    ]);

    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'calculation_version' => null]);
    $this->assertDatabaseHas('invoice_items', [
        'id' => $item->id, 'vat_category' => null, 'exemption_reason_code' => null,
        'exemption_reason' => null, 'unit_code' => null,
    ]);
});

it('persists a calculation version without recalculating invoice amounts', function () {
    $invoice = createInvoiceForTaxMetadataStorage();
    $before = $invoice->only(['subtotal_ht', 'total_tva', 'total_ttc', 'invoice_number', 'document_status']);

    $invoice->update(['calculation_version' => 'decimal-v1']);
    $invoice->refresh();

    expect($invoice->calculation_version)->toBe('decimal-v1')
        ->and($invoice->only(array_keys($before)))->toBe($before);

    $invoice->update(['calculation_version' => null]);
    expect($invoice->fresh()->calculation_version)->toBeNull();
});

it('adds nullable columns to pre-existing documents without modifying their original data', function () {
    // Rejoue l'ancien schéma dans une seconde base exclusivement en mémoire.
    // Aucun rollback ni aucune modification de la base de développement.
    $previousConnection = DB::getDefaultConnection();
    $connectionName = 'tax_metadata_legacy_test';
    config(['database.connections.' . $connectionName => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    try {
        DB::setDefaultConnection($connectionName);
        Schema::clearResolvedInstance('db.schema');

        $migrationPaths = glob(database_path('migrations/*.php'));
        sort($migrationPaths);

        foreach ($migrationPaths as $path) {
            if (basename($path) < '2026_11_05_000001_add_tax_metadata_to_invoice_items_table.php') {
                (require $path)->up();
            }
        }

        expect(Schema::hasColumn('invoices', 'calculation_version'))->toBeFalse()
            ->and(Schema::hasColumn('invoice_items', 'vat_category'))->toBeFalse();

        $invoice = createInvoiceForTaxMetadataStorage();
        $item = $invoice->items()->create([
            'description' => 'Ligne historique émise', 'quantity' => '1.00',
            'unit_price_ht' => '100.00', 'tva_rate' => '20.00', 'line_total_ht' => '100.00',
        ]);
        $beforeInvoice = (array) DB::table('invoices')->where('id', $invoice->id)->first();
        $beforeItem = (array) DB::table('invoice_items')->where('id', $item->id)->first();

        (require database_path('migrations/2026_11_05_000001_add_tax_metadata_to_invoice_items_table.php'))->up();
        (require database_path('migrations/2026_11_05_000002_add_calculation_version_to_invoices_table.php'))->up();

        $afterInvoice = (array) DB::table('invoices')->where('id', $invoice->id)->first();
        $afterItem = (array) DB::table('invoice_items')->where('id', $item->id)->first();

        expect($afterInvoice)->toBe(array_merge($beforeInvoice, ['calculation_version' => null]))
            ->and($afterItem)->toBe(array_merge($beforeItem, [
                'vat_category' => null, 'exemption_reason_code' => null,
                'exemption_reason' => null, 'unit_code' => null,
            ]));
    } finally {
        DB::setDefaultConnection($previousConnection);
        Schema::clearResolvedInstance('db.schema');
        DB::purge($connectionName);
        config(['database.connections.' . $connectionName => null]);
    }
});
