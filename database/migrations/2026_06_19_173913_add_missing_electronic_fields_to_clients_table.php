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
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'client_type')) {
                $table->string('client_type')->default('individual')->after('company_name');
            }

            if (! Schema::hasColumn('clients', 'siret')) {
                $table->string('siret')->nullable()->after('client_type');
            }

            if (! Schema::hasColumn('clients', 'vat_number')) {
                $table->string('vat_number')->nullable()->after('siret');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['client_type', 'siret', 'vat_number']);
        });
    }
};
