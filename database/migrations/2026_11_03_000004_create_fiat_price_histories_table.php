<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiat_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiat_id')->constrained('fiats')->cascadeOnDelete();
            $table->unsignedBigInteger('price_irr');
            $table->unsignedBigInteger('source_timestamp');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiat_price_histories');
    }
};
