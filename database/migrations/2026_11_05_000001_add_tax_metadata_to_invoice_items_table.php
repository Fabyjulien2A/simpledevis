<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // Aucune qualification fiscale ou unité n'est déduite pour l'historique.
            $table->string('vat_category', 3)->nullable();
            $table->string('exemption_reason_code')->nullable();
            $table->text('exemption_reason')->nullable();
            $table->string('unit_code', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['vat_category', 'exemption_reason_code', 'exemption_reason', 'unit_code']);
        });
    }
};
