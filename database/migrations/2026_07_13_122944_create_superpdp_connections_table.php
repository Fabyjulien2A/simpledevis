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
        Schema::create('superpdp_connections', function (Blueprint $table) {

            $table->id();

            // Entreprise SimpleDevis
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete()
                ->unique();

            // Identifiant de l'entreprise chez SUPER PDP
            $table->unsignedBigInteger('superpdp_company_id')->nullable();

            // OAuth
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            $table->timestamp('access_token_expires_at')->nullable();

            // Adresse de réception (SIREN ou autre identifiant)
            $table->string('directory_identifier')->nullable();

            // Réception activée
            $table->boolean('reception_enabled')->default(false);

            // Dernière synchronisation
            $table->timestamp('last_sync_at')->nullable();

            // Derniers IDs synchronisés
            $table->unsignedBigInteger('last_invoice_id')->default(0);
            $table->unsignedBigInteger('last_event_id')->default(0);

            // État de la connexion
            $table->enum('status', [
                'connected',
                'disconnected',
                'expired',
                'error',
            ])->default('disconnected');

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('superpdp_connections');
    }
};