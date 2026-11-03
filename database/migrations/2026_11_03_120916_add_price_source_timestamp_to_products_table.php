<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source timestamp of the fiat rate the current price_toman was computed from. Reprices only
     * move it forward, so an older rate can never overwrite a newer price.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('price_source_timestamp')->nullable()->after('price_toman');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('price_source_timestamp');
        });
    }
};
