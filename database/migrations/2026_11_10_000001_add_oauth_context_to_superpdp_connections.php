<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('superpdp_connections', function (Blueprint $table) {
            // No inferred environment or backfill for existing credentials: reconnect.
            $table->string('environment', 16)->nullable();
            $table->string('oauth_client_id')->nullable();
            $table->string('api_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('superpdp_connections', function (Blueprint $table) {
            $table->dropColumn(['environment', 'oauth_client_id', 'api_url']);
        });
    }
};
