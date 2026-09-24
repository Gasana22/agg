<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10: public traceability (docs/07 §5, ADR-0014).
 *
 * An approval fixes which fields of a batch are public and keeps the exact
 * payload that was reviewed. QR codes are random and globally unique; scans
 * keep only a day and a country, never who scanned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->uuid('batch_id');
            $table->json('public_fields');                         // allow-listed keys
            $table->json('payload');                               // what the public sees
            $table->string('note', 500)->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at', 6);
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'batch_id', 'approved_at']);
            $table->foreign(['farm_id', 'batch_id'])->references(['farm_id', 'id'])->on('trace_batches');
            $table->foreign('farm_id')->references('id')->on('farms');
        });
        Ddl::appendOnly('trace_approvals');

        Schema::create('trace_qr_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->uuid('batch_id');
            $table->string('code', 16)->unique();                  // public, random, not the batch id
            $table->string('status', 10)->default('active');
            $table->uuid('approval_id');                           // approval it was issued under
            $table->string('label', 120)->nullable();
            $table->foreignUuid('issued_by')->nullable()->constrained('users');
            $table->timestamp('issued_at');
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 300)->nullable();
            $table->unsignedBigInteger('scan_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'batch_id']);
            $table->foreign(['farm_id', 'batch_id'])->references(['farm_id', 'id'])->on('trace_batches');
            $table->foreign(['farm_id', 'approval_id'])->references(['farm_id', 'id'])->on('trace_approvals');
            $table->foreign('farm_id')->references('id')->on('farms');
        });
        Ddl::checkIn('trace_qr_codes', 'status', ['active', 'revoked']);

        // One row per code, day and country: counts, no personal data.
        Schema::create('trace_qr_scans', function (Blueprint $table) {
            $table->uuid('farm_id');
            $table->uuid('qr_code_id');
            $table->date('day');
            $table->char('country', 2)->default('ZZ');             // ZZ = unknown
            $table->unsignedInteger('scans')->default(0);
            $table->primary(['qr_code_id', 'day', 'country']);
            $table->index(['farm_id', 'day']);
            $table->foreign(['farm_id', 'qr_code_id'])->references(['farm_id', 'id'])->on('trace_qr_codes');
        });

        foreach (['trace_approvals', 'trace_qr_codes', 'trace_qr_scans'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_qr_scans');
        Schema::dropIfExists('trace_qr_codes');
        Schema::dropIfExists('trace_approvals');
    }
};
