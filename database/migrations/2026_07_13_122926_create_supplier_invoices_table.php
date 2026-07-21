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
        Schema::create('supplier_invoices', function (Blueprint $table) {

            $table->id();

            // Entreprise propriétaire de la facture
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            // Identifiant SUPER PDP
            $table->unsignedBigInteger('superpdp_invoice_id')
                ->nullable()
                ->unique();

            // Informations générales
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            // Fournisseur
            $table->string('supplier_name');
            $table->string('supplier_siren')->nullable();
            $table->string('supplier_vat_number')->nullable();

            // Devise
            $table->string('currency', 3)->default('EUR');

            // Totaux
            $table->decimal('total_ht', 15, 2);
            $table->decimal('total_vat', 15, 2);
            $table->decimal('total_ttc', 15, 2);

            // Statut
            $table->string('status')->default('received');

            // Documents
            $table->string('pdf_path')->nullable();
            $table->string('xml_path')->nullable();

            // Date de réception
            $table->timestamp('received_at')->nullable();

            // Données brutes SUPER PDP
            $table->json('payload')->nullable();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};