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
        Schema::table('daily_tasks', function (Blueprint $table) {
            $table->decimal('cost', 10, 2)->nullable()->after('due_date');
            $table->decimal('gps_lat', 10, 7)->nullable()->after('cost');
            $table->decimal('gps_lng', 10, 7)->nullable()->after('gps_lat');
            $table->string('photo_path')->nullable()->after('gps_lng');
            $table->text('inputs_used')->nullable()->after('photo_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_tasks', function (Blueprint $table) {
            $table->dropColumn(['cost', 'gps_lat', 'gps_lng', 'photo_path', 'inputs_used']);
        });
    }
};
