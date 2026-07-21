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
        Schema::create('electronic_invoice_events', function (Blueprint $table) {

            $table->id();

            // Facture concernée
            $table->foreignId('supplier_invoice_id')
                ->constrained()
                ->cascadeOnDelete();

            // Identifiant de l'événement chez SUPER PDP
            $table->unsignedBigInteger('superpdp_event_id')
                ->nullable()
                ->unique();

            // Type d'événement
            $table->string('event_type');

            // Statut après l'événement
            $table->string('status')->nullable();

            // Date de l'événement
            $table->timestamp('event_date')->nullable();

            // Données complètes reçues de SUPER PDP
            $table->json('payload')->nullable();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('electronic_invoice_events');
    }
};