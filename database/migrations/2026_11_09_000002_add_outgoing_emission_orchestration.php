<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            // Clés nullables : aucune reprise ou requalification des anciennes lignes.
            $table->string('dispatch_key', 100)->nullable()->unique();
            $table->uuid('local_reference')->nullable()->unique();
            $table->string('execution_mode', 20)->nullable();
            $table->string('environment', 20)->nullable();
            $table->unsignedBigInteger('provider_company_id')->nullable();
            $table->unsignedBigInteger('provider_invoice_id')->nullable();
            $table->string('provider_binding_key', 150)->nullable()->unique();
            $table->string('document_reference', 512)->nullable();
            $table->char('cii_sha256', 64)->nullable();
            $table->string('business_status', 120)->nullable();
            $table->unsignedBigInteger('business_event_id')->nullable();
            $table->unsignedBigInteger('event_cursor')->nullable();
        });
        Schema::create('electronic_invoice_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('electronic_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->uuid('reference')->unique();
            $table->string('status', 30);
            $table->char('cii_sha256', 64);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('transport_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('provider_invoice_id')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->json('history');
            $table->json('reconciliation')->nullable();
            $table->timestamps();
            $table->unique(['electronic_invoice_id', 'number'], 'ei_attempt_number_unique');
        });
        Schema::table('electronic_invoice_statuses', function (Blueprint $table) {
            $table->string('event_key', 180)->nullable()->unique();
            $table->unsignedBigInteger('provider_event_id')->nullable();
            $table->char('payload_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('electronic_invoice_attempts')->exists()) {
            throw new RuntimeException('Rollback refusé : des tentatives électroniques sont enregistrées. Aucune table ni colonne supprimée.');
        }
        foreach ([
            'electronic_invoices' => ['dispatch_key', 'local_reference', 'execution_mode', 'environment', 'provider_company_id',
                'provider_invoice_id', 'provider_binding_key', 'document_reference', 'cii_sha256', 'business_status', 'business_event_id', 'event_cursor'],
            'electronic_invoice_statuses' => ['event_key', 'provider_event_id', 'payload_sha256'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->whereNotNull($column)->exists()) {
                    throw new RuntimeException('Rollback refusé : des données de suivi électronique sont enregistrées. Aucune table ni colonne supprimée.');
                }
            }
        }
        Schema::table('electronic_invoice_statuses', function (Blueprint $table) {
            $table->dropUnique(['event_key']);
            $table->dropColumn(['event_key', 'provider_event_id', 'payload_sha256']);
        });
        Schema::dropIfExists('electronic_invoice_attempts');
        Schema::table('electronic_invoices', function (Blueprint $table) {
            foreach (['dispatch_key', 'local_reference', 'provider_binding_key'] as $column) {
                $table->dropUnique([$column]);
            }
            $table->dropColumn(['dispatch_key', 'local_reference', 'execution_mode', 'environment', 'provider_company_id',
                'provider_invoice_id', 'provider_binding_key', 'document_reference', 'cii_sha256', 'business_status', 'business_event_id', 'event_cursor']);
        });
    }
};
