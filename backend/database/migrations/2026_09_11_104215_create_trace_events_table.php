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
        Schema::create('trace_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trace_batch_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->date('date');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['trace_batch_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trace_events');
    }
};
