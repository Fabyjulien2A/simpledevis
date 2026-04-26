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
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'is_electronic')) {
                $table->boolean('is_electronic')->default(false)->after('notes');
            }

            if (!Schema::hasColumn('invoices', 'electronic_format')) {
                $table->string('electronic_format')->nullable()->after('is_electronic');
            }

            if (!Schema::hasColumn('invoices', 'pdp_id')) {
                $table->string('pdp_id')->nullable()->after('electronic_format');
            }

            if (!Schema::hasColumn('invoices', 'pdp_reference')) {
                $table->string('pdp_reference')->nullable()->after('pdp_id');
            }

            if (!Schema::hasColumn('invoices', 'pdp_status')) {
                $table->string('pdp_status')->nullable()->after('pdp_reference');
            }

            if (!Schema::hasColumn('invoices', 'xml_generated_at')) {
                $table->timestamp('xml_generated_at')->nullable()->after('pdp_status');
            }

            if (!Schema::hasColumn('invoices', 'sent_to_pdp_at')) {
                $table->timestamp('sent_to_pdp_at')->nullable()->after('xml_generated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $columns = [
                'is_electronic',
                'electronic_format',
                'pdp_id',
                'pdp_reference',
                'pdp_status',
                'xml_generated_at',
                'sent_to_pdp_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};