<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL already indexes foreign keys; SQLite does not.
        if (Schema::hasIndex('products', ['fiat_id'])) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->index('fiat_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('products', 'products_fiat_id_index')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['fiat_id']);
        });
    }
};
