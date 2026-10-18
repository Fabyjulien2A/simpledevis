<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();

            // Propriétaire de l'avoir
            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            // Facture d'origine
            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->restrictOnDelete();

            // Numérotation indépendante des factures
            $table->string('credit_note_number')->nullable();

            // Informations de l'avoir
            $table->date('date');
            $table->string('status', 20)->default('issued');
            $table->string('reason', 500);

            // Montants positifs stockés en base
            // Le document affichera ces montants en déduction.
            $table->decimal('subtotal_ht', 12, 2);
            $table->decimal('total_tva', 12, 2);
            $table->decimal('total_ttc', 12, 2);

            $table->text('notes')->nullable();

            $table->timestamps();

            // Un numéro ne peut pas être utilisé deux fois
            // par un même utilisateur.
            $table->unique(
                ['user_id', 'credit_note_number'],
                'credit_notes_user_number_unique'
            );

            $table->index(['user_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};

