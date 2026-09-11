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
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('quantity', 10, 2);
            $table->date('date');
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['farm_id', 'inventory_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
