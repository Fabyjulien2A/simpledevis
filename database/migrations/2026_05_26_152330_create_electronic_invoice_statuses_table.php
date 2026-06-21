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
        Schema::create('electronic_invoice_statuses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('electronic_invoice_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('iopole_status_id')->nullable();
            $table->string('status')->nullable();

            $table->json('payload')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('electronic_invoice_statuses');
    }
};
