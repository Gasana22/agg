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
        Schema::create('poultry_production_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poultry_flock_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('product_type');
            $table->decimal('quantity', 10, 2);
            $table->string('unit');
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
        Schema::dropIfExists('poultry_production_records');
    }
};
