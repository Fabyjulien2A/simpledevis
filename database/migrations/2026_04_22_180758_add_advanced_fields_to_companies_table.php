<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('legal_status')->nullable();
            $table->string('iban')->nullable();
            $table->string('bic')->nullable();
            $table->text('payment_terms')->nullable();
            $table->string('quote_validity')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'legal_status',
                'iban',
                'bic',
                'payment_terms',
                'quote_validity',
            ]);
        });
    }
};