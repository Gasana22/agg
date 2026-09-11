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
        Schema::create('poultry_mortality_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poultry_flock_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('quantity');
            $table->string('cause')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['poultry_flock_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_mortality_logs');
    }
};
