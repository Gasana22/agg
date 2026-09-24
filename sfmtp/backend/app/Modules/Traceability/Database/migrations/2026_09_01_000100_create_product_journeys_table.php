<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: the `product_journey` read model (docs/07 §4). One row per batch
 * with its upstream and downstream graph, timeline and summary, refreshed
 * by a queued projector after each event or link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_journeys', function (Blueprint $table) {
            $table->uuid('batch_id')->primary();
            $table->uuid('farm_id');
            $table->json('upstream');
            $table->json('downstream');
            $table->json('timeline');
            $table->json('summary');
            $table->unsignedBigInteger('last_seq');                // farm_seq of the newest event it saw
            $table->timestamp('refreshed_at', 6);
            $table->foreign(['farm_id', 'batch_id'])->references(['farm_id', 'id'])->on('trace_batches');
            $table->foreign('farm_id')->references('id')->on('farms');
            $table->index(['farm_id', 'refreshed_at']);
        });
        Ddl::tenantRls('product_journeys');

        Schema::table('trace_events', function (Blueprint $table) {
            $table->index(['farm_id', 'worker_id']);
            $table->index(['farm_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::table('trace_events', function (Blueprint $table) {
            $table->dropIndex(['farm_id', 'worker_id']);
            $table->dropIndex(['farm_id', 'event_type']);
        });
        Schema::dropIfExists('product_journeys');
    }
};
