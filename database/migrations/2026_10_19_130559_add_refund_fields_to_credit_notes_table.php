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
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->date('refunded_at')->nullable();
            $table->decimal('refunded_amount', 12, 2)->nullable();
            $table->string('refund_method', 30)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn([
                'refunded_at',
                'refunded_amount',
                'refund_method',
            ]);
        });
    }
};
