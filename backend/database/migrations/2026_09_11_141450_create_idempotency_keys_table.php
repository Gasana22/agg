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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('route');
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamps();
            $table->unique(['key', 'user_id', 'route']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
