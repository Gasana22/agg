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
        Schema::table('farms', function (Blueprint $table) {
            $table->json('boundary')->nullable()->after('gps_lng');
        });

        Schema::table('blocks', function (Blueprint $table) {
            $table->json('boundary')->nullable()->after('gps_lng');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('farms', function (Blueprint $table) {
            $table->dropColumn('boundary');
        });

        Schema::table('blocks', function (Blueprint $table) {
            $table->dropColumn('boundary');
        });
    }
};
