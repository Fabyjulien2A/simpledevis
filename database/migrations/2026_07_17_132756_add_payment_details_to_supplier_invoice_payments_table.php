<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoice_payments', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)
                ->after('supplier_invoice_id');

            $table->date('paid_at')
                ->after('amount');

            $table->string('method', 50)
                ->after('paid_at');

            $table->string('reference')
                ->nullable()
                ->after('method');

            $table->text('notes')
                ->nullable()
                ->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoice_payments', function (Blueprint $table) {
            $table->dropColumn([
                'amount',
                'paid_at',
                'method',
                'reference',
                'notes',
            ]);
        });
    }
};