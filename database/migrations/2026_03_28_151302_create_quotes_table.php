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
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();

            // Le devis appartient à un utilisateur et à un client
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');

            // Numéro unique du devis
            $table->string('quote_number')->unique();

            // Date du devis
            $table->date('date');

            // Statut du devis : brouillon, envoyé, accepté, refusé
            $table->string('status')->default('brouillon');

            // Totaux financiers
            $table->decimal('subtotal_ht', 10, 2)->default(0);
            $table->decimal('total_tva', 10, 2)->default(0);
            $table->decimal('total_ttc', 10, 2)->default(0);

            // Notes facultatives
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Annule la migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};