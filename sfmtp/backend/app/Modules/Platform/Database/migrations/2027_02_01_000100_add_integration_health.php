<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Provider health for the admin portal (ADR-0018). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_providers', function (Blueprint $table) {
            $table->unsignedSmallInteger('priority')->default(100);   // failover order after the default
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('integration_providers', function (Blueprint $table) {
            $table->dropColumn(['priority', 'last_success_at', 'last_failure_at', 'last_error', 'consecutive_failures']);
        });
    }
};
