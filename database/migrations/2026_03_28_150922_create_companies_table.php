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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            // L'entreprise appartient à un utilisateur
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Informations principales de l'entreprise
            $table->string('company_name');
            $table->string('siret')->nullable();
            $table->string('vat_number')->nullable();

            // Coordonnées
            $table->string('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();

            // Logo éventuel de l'entreprise
            $table->string('logo')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Annule la migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
