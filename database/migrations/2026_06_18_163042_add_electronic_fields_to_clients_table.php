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
        if (! Schema::hasColumn('clients', 'client_type')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('client_type')->default('individual')->after('company_name');
            });
        }

        if (! Schema::hasColumn('clients', 'siret')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('siret')->nullable()->after('client_type');
            });
        }

        if (! Schema::hasColumn('clients', 'vat_number')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('vat_number')->nullable()->after('siret');
            });
        }
    }

    public function down(): void
    {
        // Retour arrière volontairement non destructif : ces colonnes peuvent
        // préexister à cette migration. On les conserve avec leurs données,
        // même si le rollback retire cette migration de l'historique.
    }
};
