<?php

use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traceability core (docs/07-traceability-and-audit.md).
 * Events and links are append-only; batches can never be deleted.
 * These are the Phase 1 row-level-security pilot tables (docs/02 §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('batch_code', 20)->unique();          // global, public-safe
            $table->string('kind', 20);
            $table->string('name', 150)->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 20)->nullable();              // FK to units catalogue in Phase 2
            $table->string('status', 20)->default(BatchStatus::Open->value);
            $table->uuid('product_id')->nullable();              // FK in Phase 12 (products)
            $table->uuid('origin_plot_id')->nullable();          // FK in Phase 3 (farm_plots)
            $table->string('source_type', 60)->nullable();       // domain record that created it
            $table->uuid('source_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'kind', 'status']);
            $table->index(['farm_id', 'created_at']);
            $table->index(['farm_id', 'source_type', 'source_id']);
        });
        Ddl::checkIn('trace_batches', 'kind', BatchKind::values());
        Ddl::checkIn('trace_batches', 'status', BatchStatus::values());
        Ddl::check('trace_batches', 'trace_batches_quantity_check', 'quantity IS NULL OR quantity >= 0');

        Schema::create('trace_batch_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->uuid('parent_batch_id');
            $table->uuid('child_batch_id');
            $table->string('link_type', 20);
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            // Both ends must be batches of the same farm.
            $table->foreign(['farm_id', 'parent_batch_id'])->references(['farm_id', 'id'])->on('trace_batches');
            $table->foreign(['farm_id', 'child_batch_id'])->references(['farm_id', 'id'])->on('trace_batches');
            $table->foreign('farm_id')->references('id')->on('farms');
            $table->unique(['parent_batch_id', 'child_batch_id', 'link_type'], 'trace_batch_links_edge_unique');
            $table->index('child_batch_id');
            $table->index(['farm_id', 'created_at']);
        });
        Ddl::checkIn('trace_batch_links', 'link_type', LinkType::values());
        Ddl::check('trace_batch_links', 'trace_batch_links_not_self', 'parent_batch_id <> child_batch_id');

        Schema::create('trace_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->uuid('batch_id');
            $table->string('event_type', 40);
            $table->dateTime('occurred_at', 6);                  // device / real-world time (UTC)
            $table->dateTime('recorded_at', 6);                  // server time (UTC)
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $table->uuid('worker_id')->nullable();               // FK in Phase 6 (workers)
            $table->uuid('plot_id')->nullable();                 // FK in Phase 3 (farm_plots)
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->string('subject_type', 60)->nullable();      // domain record holding the details
            $table->uuid('subject_id')->nullable();
            $table->json('payload');
            $table->uuid('corrects_event_id')->nullable();
            $table->unsignedBigInteger('farm_seq');              // per-farm sequence for the hash chain
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->foreign(['farm_id', 'batch_id'])->references(['farm_id', 'id'])->on('trace_batches');
            $table->foreign('farm_id')->references('id')->on('farms');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'farm_seq']);
            $table->index(['farm_id', 'batch_id', 'occurred_at']);
            $table->index(['farm_id', 'subject_type', 'subject_id']);
            $table->index(['farm_id', 'recorded_at']);
        });
        Schema::table('trace_events', function (Blueprint $table) {
            // A correction must point at an event of the same farm.
            $table->foreign(['farm_id', 'corrects_event_id'])->references(['farm_id', 'id'])->on('trace_events');
        });

        // Head of each farm's hash chain. Locked while appending an event.
        Schema::create('trace_sequences', function (Blueprint $table) {
            $table->foreignUuid('farm_id')->primary()->constrained();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->char('last_hash', 64);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('trace_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('batch_id')->nullable();
            $table->string('check_type', 40);                    // hash_chain, ...
            $table->string('result', 10);                         // pass | fail
            $table->json('details')->nullable();
            $table->timestamp('created_at', 6);
            $table->index(['farm_id', 'created_at']);
        });
        Ddl::checkIn('trace_audits', 'result', ['pass', 'fail']);

        Ddl::appendOnly('trace_events');
        Ddl::appendOnly('trace_batch_links');
        Ddl::appendOnly('trace_audits');
        Ddl::appendOnly('trace_batches', ['DELETE']);

        foreach (['trace_batches', 'trace_batch_links', 'trace_events', 'trace_audits'] as $table) {
            Ddl::tenantRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_audits');
        Schema::dropIfExists('trace_sequences');
        Schema::dropIfExists('trace_events');
        Schema::dropIfExists('trace_batch_links');
        Schema::dropIfExists('trace_batches');
    }
};
