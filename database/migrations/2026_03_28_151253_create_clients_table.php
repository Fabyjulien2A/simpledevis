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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();

            // Le client appartient à l'utilisateur connecté
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Informations client
            $table->string('company_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            // Coordonnées
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();

            // Notes libres
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Annule la migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
