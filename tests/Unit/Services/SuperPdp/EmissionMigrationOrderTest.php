<?php

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Saloon\Config;
use Tests\TestCase;

uses(TestCase::class);

it('applies both prepared migrations chronologically without backfilling 35 fictitious historical invoices', function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    $original = DB::getDefaultConnection();
    $isolated = 'emission_order_test';
    config(['database.connections.'.$isolated => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection($isolated);
    Schema::clearResolvedInstance('db.schema');
    try {
        expect(DB::connection()->getDatabaseName())->toBe(':memory:')->and(DB::connection()->getDriverName())->toBe('sqlite');
        $queries = [];
        DB::listen(function ($query) use (&$queries, $isolated) {
            expect($query->connectionName)->toBe($isolated);
            $queries[] = $query->sql;
        });
        $first = '2026_11_09_000001_add_invoice_emission_preparation_fields.php';
        $second = '2026_11_09_000002_add_outgoing_emission_orchestration.php';
        $paths = glob(database_path('migrations/*.php'));
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (basename($path) < $first) {
                (require $path)->up();
            }
        }
        expect(Schema::hasColumn('companies', 'country_code'))->toBeFalse()
            ->and(Schema::hasColumn('invoices', 'emission_context'))->toBeFalse()
            ->and(Schema::hasTable('electronic_invoice_attempts'))->toBeFalse();
        $owner = User::factory()->create();
        $owner->company()->create(['company_name' => 'Entreprise historique fictive']);
        $client = $owner->clients()->create(['first_name' => 'Ancien', 'last_name' => 'Client', 'client_type' => 'professional', 'country_code' => 'FR']);
        for ($i = 1; $i <= 35; $i++) {
            Invoice::create(['user_id' => $owner->id, 'client_id' => $client->id, 'invoice_number' => 'HISTORY-'.str_pad($i, 2, '0', STR_PAD_LEFT),
                'document_status' => 'issued', 'date' => '2025-01-01', 'status' => 'non_payee',
                'subtotal_ht' => 100, 'total_tva' => 20, 'total_ttc' => 120, 'amount_paid' => 0]);
        }
        $legacyId = DB::table('electronic_invoices')->insertGetId(['invoice_id' => Invoice::first()->id,
            'iopole_invoice_id' => 'legacy-reference', 'status' => 'legacy', 'request_payload' => '{"legacy":true}']);
        DB::table('electronic_invoice_statuses')->insert(['electronic_invoice_id' => $legacyId,
            'iopole_status_id' => 'legacy-event', 'status' => 'legacy-status', 'payload' => '{"preserved":true}']);
        $tables = ['users', 'companies', 'clients', 'invoices', 'electronic_invoices', 'electronic_invoice_statuses'];
        $columns = [];
        $history = [];
        foreach ($tables as $table) {
            $columns[$table] = Schema::getColumnListing($table);
            $history[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $assertHistory = function () use ($columns, $history) {
            foreach ($history as $table => $rows) {
                expect(DB::table($table)->orderBy('id')->get($columns[$table])->map(fn ($row) => (array) $row)->all())->toBe($rows);
            }
        };
        $assertNoBackfill = function () {
            expect(DB::table('invoices')->count())->toBe(35)
                ->and(DB::table('invoices')->whereNull('party_snapshot')->whereNull('emission_profile')->whereNull('emission_context')->count())->toBe(35)
                ->and(DB::table('companies')->whereNotNull('country_code')->count())->toBe(0)
                ->and(DB::table('companies')->whereNotNull('billing_electronic_address')->count())->toBe(0)
                ->and(DB::table('clients')->whereNotNull('billing_electronic_address')->count())->toBe(0);
        };
        $dataMigration = require database_path('migrations/'.$first);
        $orchestrationMigration = require database_path('migrations/'.$second);
        $applied = [];
        $dataMigration->up();
        $applied[] = $first;
        $assertHistory();
        $assertNoBackfill();
        expect(Schema::hasColumn('invoices', 'emission_context'))->toBeTrue()
            ->and(Schema::hasTable('electronic_invoice_attempts'))->toBeFalse();
        $orchestrationMigration->up();
        $applied[] = $second;
        expect($applied)->toBe([$first, $second])->and(Schema::hasTable('electronic_invoice_attempts'))->toBeTrue()
            ->and(DB::table('electronic_invoice_attempts')->count())->toBe(0)
            ->and(DB::table('electronic_invoices')->whereNotNull('dispatch_key')->count())->toBe(0);
        $assertHistory();
        $assertNoBackfill();
        // Vérifier aussi la réversibilité de ces DEUX migrations seulement, puis leur réapplication.
        $orchestrationMigration->down();
        $dataMigration->down();
        $assertHistory();
        expect(Schema::hasColumn('companies', 'country_code'))->toBeFalse()
            ->and(Schema::hasTable('electronic_invoice_attempts'))->toBeFalse();
        $dataMigration->up();
        $orchestrationMigration->up();
        $assertHistory();
        $assertNoBackfill();
        expect($queries)->not->toBeEmpty();
    } finally {
        DB::purge($isolated);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        Http::assertNothingSent();
        Config::allowStrayRequests();
    }
});
