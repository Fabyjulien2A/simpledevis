<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
{
    Schema::create('company_settings', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();

        $table->string('company_name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('address')->nullable();
        $table->string('siret')->nullable();
        $table->string('tva_number')->nullable();

        $table->timestamps();
    });
}
};
