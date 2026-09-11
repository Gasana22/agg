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
        Schema::create('crop_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('season_name');
            $table->date('planned_planting_date')->nullable();
            $table->date('actual_planting_date')->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->decimal('expected_yield', 12, 2)->nullable();
            $table->string('expected_yield_unit')->nullable();
            $table->string('status')->default('planning');
            $table->timestamps();
            $table->index(['farm_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_seasons');
    }
};
