<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {

            $table->string('payment_status')
                ->default('unpaid')
                ->after('status');

            $table->decimal('paid_amount', 15, 2)
                ->default(0)
                ->after('payment_status');

            $table->decimal('remaining_amount', 15, 2)
                ->nullable()
                ->after('paid_amount');

            $table->date('paid_at')
                ->nullable()
                ->after('remaining_amount');

            $table->string('payment_method')
                ->nullable()
                ->after('paid_at');

            $table->string('payment_reference')
                ->nullable()
                ->after('payment_method');

            $table->text('payment_notes')
                ->nullable()
                ->after('payment_reference');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {

            $table->dropColumn([
                'payment_status',
                'paid_amount',
                'remaining_amount',
                'paid_at',
                'payment_method',
                'payment_reference',
                'payment_notes',
            ]);

        });
    }
};