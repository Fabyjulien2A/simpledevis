<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoice_payments', function (Blueprint $table) {
            $table->foreignId('supplier_invoice_id')
                ->after('id')
                ->constrained('supplier_invoices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoice_payments', function (Blueprint $table) {
            $table->dropForeign([
                'supplier_invoice_id',
            ]);

            $table->dropColumn(
                'supplier_invoice_id'
            );
        });
    }
};