<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiat_price_histories', function (Blueprint $table) {
            $table->unique(['fiat_id', 'source_timestamp']);
        });
    }

    public function down(): void
    {
        Schema::table('fiat_price_histories', function (Blueprint $table) {
            $table->dropUnique(['fiat_id', 'source_timestamp']);
        });
    }
};
