
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
        Schema::create('supplier_invoice_items', function (Blueprint $table) {
            $table->id();

            // Facture fournisseur liée
            $table->foreignId('supplier_invoice_id')
                ->constrained()
                ->cascadeOnDelete();

            // Numéro ou ordre de ligne dans la facture
            $table->unsignedInteger('line_number')->nullable();

            // Désignation
            $table->text('description');

            // Quantité et unité
            $table->decimal('quantity', 15, 4)->default(1);
            $table->string('unit_code', 10)->nullable();

            // Prix
            $table->decimal('unit_price_ht', 15, 4);
            $table->decimal('line_total_ht', 15, 2);

            // TVA
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);

            // Total TTC de la ligne
            $table->decimal('line_total_ttc', 15, 2)->nullable();

            // Remise éventuelle
            $table->decimal('discount_amount', 15, 2)->default(0);

            // Données brutes reçues depuis SUPER PDP
            $table->json('payload')->nullable();

            $table->timestamps();

            // Évite deux lignes avec le même numéro pour une même facture
            $table->unique([
                'supplier_invoice_id',
                'line_number',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_items');
    }
};