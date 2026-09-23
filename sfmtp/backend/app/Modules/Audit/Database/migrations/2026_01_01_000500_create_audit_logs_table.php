<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only (docs/07 §7). Monthly partitioning is added in Phase 15.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->nullable()->constrained();   // null = platform-level action
            $table->foreignUuid('user_id')->nullable()->constrained();
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->uuid('device_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('created_at', 6);
            $table->index(['farm_id', 'created_at']);
            $table->index(['farm_id', 'entity_type', 'entity_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Ddl::appendOnly('audit_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
