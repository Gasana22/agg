<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline sync (docs/08).
 *
 * - sync_mutations: every mutation a phone pushed, with its result, so a
 *   retried push is answered from here instead of being applied twice.
 * - sync_changes: the farm's change feed. Its auto-increment id is the pull
 *   cursor; a row says "this record changed", and the pull sends the
 *   record's current state if the member may still see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_mutations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->foreignUuid('user_id')->constrained('users');
            $table->uuid('mutation_id');
            $table->uuid('device_id')->nullable();
            $table->string('entity', 40);
            $table->string('op', 20);
            $table->uuid('record_id')->nullable();
            $table->string('status', 20);
            $table->json('result');
            $table->timestamp('occurred_at', 6)->nullable();
            $table->timestamp('created_at', 6);
            $table->unique(['farm_id', 'user_id', 'mutation_id']);
            $table->index(['farm_id', 'device_id', 'created_at']);
        });
        Ddl::checkIn('sync_mutations', 'status', ['applied', 'conflict', 'rejected']);

        Schema::create('sync_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('entity', 40);
            $table->uuid('record_id');
            $table->timestamp('changed_at', 6);
            $table->index(['farm_id', 'id']);
        });

        Ddl::appendOnly('sync_mutations');
        Ddl::tenantRls('sync_mutations');
        Ddl::tenantRls('sync_changes');
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
        Schema::dropIfExists('sync_mutations');
    }
};
