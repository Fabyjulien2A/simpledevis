<?php

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedEmissionDatabase;
use Tests\TestCase;

uses(TestCase::class, IsolatedEmissionDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->company = $this->owner->company()->create(['company_name' => 'Fictitious rollback seller']);
    $this->client = $this->owner->clients()->create(['first_name' => 'Fictitious', 'client_type' => 'professional', 'country_code' => 'FR']);
    $this->invoice = Invoice::create(['user_id' => $this->owner->id, 'client_id' => $this->client->id, 'date' => '2026-11-09',
        'document_status' => 'issued', 'invoice_number' => 'FICTITIOUS-ROLLBACK', 'status' => 'non_payee',
        'subtotal_ht' => 100, 'total_tva' => 20, 'total_ttc' => 120, 'amount_paid' => 0]);
});

it('refuses preparation rollback before any DDL when a new field contains data', function (string $table, string $column, string $value) {
    $id = match ($table) {
        'companies' => $this->company->id, 'clients' => $this->client->id, 'invoices' => $this->invoice->id
    };
    DB::table($table)->where('id', $id)->update([$column => $value]);
    $before = DB::table($table)->where('id', $id)->first();
    $migration = require database_path('migrations/2026_11_09_000001_add_invoice_emission_preparation_fields.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Rollback refusé');
    expect(Schema::hasColumn('invoices', 'emission_context'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'country_code'))->toBeTrue()
        ->and(DB::table($table)->where('id', $id)->first())->toEqual($before);
})->with([
    ['companies', 'country_code', 'FR'], ['companies', 'billing_electronic_address', '{"scheme":"0225","value":"732829320"}'],
    ['clients', 'billing_electronic_address', '{"scheme":"0225","value":"552100554"}'],
    ['invoices', 'emission_profile', 'fr_b2b_380_eur'], ['invoices', 'emission_context', '{"schema_version":1}'],
]);

it('refuses orchestration rollback without deleting attempts registry or event metadata', function (string $case) {
    $record = ElectronicInvoice::create(['invoice_id' => $this->invoice->id, 'status' => 'legacy']);
    if ($case === 'attempt') {
        $record->attempts()->create(['number' => 1, 'reference' => (string) Str::uuid(), 'status' => 'unknown', 'cii_sha256' => str_repeat('a', 64), 'history' => []]);
    } elseif ($case === 'registry') {
        DB::table('electronic_invoices')->where('id', $record->id)->update(['document_reference' => 'fictitious-reference']);
    } else {
        DB::table('electronic_invoice_statuses')->insert(['electronic_invoice_id' => $record->id, 'event_key' => 'production:123:1']);
    }
    $snapshot = fn () => collect(['electronic_invoices', 'electronic_invoice_attempts', 'electronic_invoice_statuses'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $migration = require database_path('migrations/2026_11_09_000002_add_outgoing_emission_orchestration.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Rollback refusé');
    expect($snapshot())->toBe($before)->and(Schema::hasColumn('electronic_invoice_statuses', 'event_key'))->toBeTrue();
})->with(['attempt', 'registry', 'event']);
