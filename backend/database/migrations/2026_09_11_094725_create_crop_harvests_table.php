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
        Schema::create('crop_harvests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crop_season_id')->constrained()->cascadeOnDelete();
            $table->date('harvest_date');
            $table->decimal('quantity', 12, 2);
            $table->string('unit');
            $table->string('quality_grade')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_harvests');
    }
};
