<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_counters', function (Blueprint $table) {
            $table->id();

            // Compteur propre à chaque utilisateur
            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            // Une séquence par année
            $table->unsignedSmallInteger('year');

            // Dernier numéro attribué
            $table->unsignedInteger('last_number')->default(0);

            $table->timestamps();

            // Un seul compteur par utilisateur et par année
            $table->unique(
                ['user_id', 'year'],
                'credit_note_counters_user_year_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_counters');
    }
};
