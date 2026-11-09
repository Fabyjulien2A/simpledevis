<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable();
            $table->json('billing_electronic_address')->nullable();
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->json('billing_electronic_address')->nullable();
        });
        Schema::table('invoices', function (Blueprint $table) {
            // Choix explicite sur le brouillon ; contexte figé uniquement à la finalisation.
            // Aucun remplissage des factures historiques.
            $table->string('emission_profile', 32)->nullable();
            $table->json('emission_context')->nullable();
        });
    }

    public function down(): void
    {
        foreach ([
            'companies' => ['country_code', 'billing_electronic_address'],
            'clients' => ['billing_electronic_address'],
            'invoices' => ['emission_profile', 'emission_context'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->whereNotNull($column)->exists()) {
                    throw new RuntimeException('Rollback refusé : des données de préparation électronique sont enregistrées. Aucune colonne supprimée.');
                }
            }
        }
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['emission_profile', 'emission_context']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('billing_electronic_address'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['country_code', 'billing_electronic_address']));
    }
};
