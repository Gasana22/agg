<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queued exports (ADR-0017): a standard report as CSV, Excel or PDF, or a
 * bulk run of QR labels. The file lives for 24 hours and only the member
 * who asked for it may download it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('kind', 20);                        // report | labels
            $table->string('report', 60)->nullable();
            $table->string('title', 150);
            $table->json('params');
            $table->string('format', 10);
            $table->string('status', 20)->default('queued');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->unsignedInteger('row_count')->nullable();
            $table->string('file_path', 300)->nullable();
            $table->string('file_name', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'requested_by', 'created_at']);
            $table->index(['farm_id', 'status']);
            $table->index('expires_at');
        });
        Ddl::checkIn('report_exports', 'kind', ['report', 'labels']);
        Ddl::checkIn('report_exports', 'format', ['csv', 'xlsx', 'pdf']);
        Ddl::checkIn('report_exports', 'status', ['queued', 'running', 'ready', 'failed', 'expired']);
        Ddl::tenantRls('report_exports');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
