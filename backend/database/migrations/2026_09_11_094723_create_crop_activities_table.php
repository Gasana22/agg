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
        Schema::create('crop_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crop_season_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->date('date');
            $table->decimal('cost', 10, 2)->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['crop_season_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_activities');
    }
};
