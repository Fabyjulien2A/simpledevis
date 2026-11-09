<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('preserves payment rows when adding or making the historical method column nullable', function (bool $exists) {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    $original = DB::getDefaultConnection();
    $name = 'payment_method_migration_test';
    config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection($name);
    Schema::clearResolvedInstance('db.schema');
    try {
        Schema::create('invoices', fn (Blueprint $table) => $table->id());
        (require database_path('migrations/2026_04_01_145924_create_payments_table.php'))->up();
        if ($exists) {
            Schema::table('payments', fn (Blueprint $table) => $table->string('method'));
        }
        DB::table('invoices')->insert(['id' => 1]);
        $row = ['invoice_id' => 1, 'amount' => '25.00', 'paid_at' => '2026-11-09'];
        if ($exists) {
            $row['method'] = 'virement';
        }
        DB::table('payments')->insert($row);
        (require database_path('migrations/2026_04_12_172414_add_method_to_payments_table.php'))->up();
        $stored = DB::table('payments')->sole();
        expect(Schema::hasColumn('payments', 'method'))->toBeTrue()
            ->and((float) $stored->amount)->toBe(25.0)->and($stored->paid_at)->toBe('2026-11-09')
            ->and($stored->method)->toBe($exists ? 'virement' : null);
        DB::table('payments')->insert(['invoice_id' => 1, 'amount' => '10.00', 'paid_at' => '2026-11-10', 'method' => null]);
        expect(DB::table('payments')->count())->toBe(2);
    } finally {
        DB::purge($name);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
    }
})->with([false, true]);
