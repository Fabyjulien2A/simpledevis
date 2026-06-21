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
        Schema::create('electronic_invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained();

            $table->string('iopole_invoice_id')->nullable();

            $table->string('status')->default('draft');

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            $table->text('last_error')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('electronic_invoices');
    }
};
