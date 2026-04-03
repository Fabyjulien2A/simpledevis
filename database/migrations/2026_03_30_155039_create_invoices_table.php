<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('quote_id')->unique()->constrained()->onDelete('cascade');

            $table->string('invoice_number')->unique();
            $table->date('date');
            $table->string('status')->default('non_payee');

            $table->decimal('subtotal_ht', 10, 2)->default(0);
            $table->decimal('total_tva', 10, 2)->default(0);
            $table->decimal('total_ttc', 10, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};