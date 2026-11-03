<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->uuid('message_id');
            $table->string('consumer', 191);
            $table->timestamp('processed_at')->useCurrent()->index();

            $table->primary(['message_id', 'consumer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
