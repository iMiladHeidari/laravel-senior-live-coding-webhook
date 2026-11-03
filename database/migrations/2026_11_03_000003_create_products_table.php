<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('fiat_id')->constrained('fiats')->cascadeOnDelete();
            $table->decimal('base_price_fiat', 12, 2);
            $table->decimal('profit_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('price_toman')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
