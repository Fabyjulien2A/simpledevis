<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $exists = Schema::hasColumn('payments', 'method');
        Schema::table('payments', function (Blueprint $table) use ($exists) {
            $column = $table->string('method')->nullable();
            if ($exists) {
                $column->change();
            }
        });
    }
};
