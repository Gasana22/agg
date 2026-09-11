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
        Schema::create('animals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('tag_number');
            $table->string('name')->nullable();
            $table->string('species');
            $table->string('breed')->nullable();
            $table->string('sex')->nullable();
            $table->date('birth_date')->nullable();
            $table->foreignId('dam_id')->nullable()->constrained('animals')->nullOnDelete();
            $table->foreignId('sire_id')->nullable()->constrained('animals')->nullOnDelete();
            $table->string('source')->nullable();
            $table->date('acquired_date')->nullable();
            $table->string('status')->default('active');
            $table->date('death_date')->nullable();
            $table->string('cause_of_death')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['farm_id', 'tag_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('animals');
    }
};
