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
        Schema::create('poultry_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poultry_flock_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('buyer_name');
            $table->decimal('sale_price', 12, 2);
            $table->date('sale_date');
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
        Schema::dropIfExists('poultry_sales');
    }
};
