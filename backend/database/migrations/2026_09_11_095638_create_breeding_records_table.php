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
        Schema::create('breeding_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dam_id')->constrained('animals')->cascadeOnDelete();
            $table->foreignId('sire_id')->nullable()->constrained('animals')->nullOnDelete();
            $table->date('breeding_date');
            $table->date('expected_due_date')->nullable();
            $table->date('actual_birth_date')->nullable();
            $table->unsignedInteger('offspring_count')->nullable();
            $table->string('status')->default('bred');
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
        Schema::dropIfExists('breeding_records');
    }
};
