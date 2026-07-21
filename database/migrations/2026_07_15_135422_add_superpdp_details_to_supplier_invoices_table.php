<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('superpdp_company_id')
                ->nullable()
                ->after('superpdp_invoice_id');

            $table->string('direction', 10)
                ->nullable()
                ->after('superpdp_company_id');

            $table->string('type_code', 20)
                ->nullable()
                ->after('invoice_number');

            $table->decimal('amount_due', 15, 2)
                ->nullable()
                ->after('total_ttc');

            $table->string('supplier_email')
                ->nullable()
                ->after('supplier_vat_number');

            $table->string('supplier_address')
                ->nullable()
                ->after('supplier_email');

            $table->string('supplier_postal_code', 20)
                ->nullable()
                ->after('supplier_address');

            $table->string('supplier_city')
                ->nullable()
                ->after('supplier_postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'superpdp_company_id',
                'direction',
                'type_code',
                'amount_due',
                'supplier_email',
                'supplier_address',
                'supplier_postal_code',
                'supplier_city',
            ]);
        });
    }
};