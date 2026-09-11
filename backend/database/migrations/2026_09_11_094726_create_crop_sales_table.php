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
        Schema::create('crop_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crop_harvest_id')->constrained()->cascadeOnDelete();
            $table->string('buyer_name');
            $table->decimal('quantity_sold', 12, 2);
            $table->decimal('unit_price', 12, 2);
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
        Schema::dropIfExists('crop_sales');
    }
};
