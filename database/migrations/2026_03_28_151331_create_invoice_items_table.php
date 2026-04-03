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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            // Chaque ligne appartient à une facture
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');

            // Description de la prestation ou du produit
            $table->string('description');

            // Quantité
            $table->decimal('quantity', 10, 2)->default(1);

            // Prix unitaire HT
            $table->decimal('unit_price_ht', 10, 2)->default(0);

            // Taux de TVA
            $table->decimal('tva_rate', 5, 2)->default(20);

            // Total HT de la ligne
            $table->decimal('line_total_ht', 10, 2)->default(0);

            $table->timestamps();
        });
    }

    /**
     * Annule la migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};