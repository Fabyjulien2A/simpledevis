<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // La facture appartient à un utilisateur, un client
            // et peut provenir d'un devis
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();

            // Numéro unique de facture
            $table->string('invoice_number')->unique();

            // Date de facture
            $table->date('date');

            // Statut : brouillon, émise, payée, annulée
            $table->string('status')->default('brouillon');

            // Totaux
            $table->decimal('subtotal_ht', 10, 2)->default(0);
            $table->decimal('total_tva', 10, 2)->default(0);
            $table->decimal('total_ttc', 10, 2)->default(0);

            // Notes éventuelles
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Annule la migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
