<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Sans valeur par défaut ni reprise des mentions fictives ou des PDF.
            $table->text('invoice_recovery_indemnity_text')->nullable(); // PMT
            $table->text('invoice_late_payment_penalties_text')->nullable(); // PMD
            $table->text('invoice_early_payment_discount_text')->nullable(); // AAB
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_recovery_indemnity_text',
                'invoice_late_payment_penalties_text',
                'invoice_early_payment_discount_text',
            ]);
        });
    }
};
