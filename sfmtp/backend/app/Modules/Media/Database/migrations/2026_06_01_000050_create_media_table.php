<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded files: task and attendance photos now, documents later
 * (docs/08 §3). A file is stored once per farm under its SHA-256, so a
 * retried upload from a phone returns the same media id. Rows are never
 * changed; records point at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->char('sha256', 64);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk', 20);
            $table->string('path', 255);
            $table->string('original_name', 200)->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'sha256']);
        });

        Ddl::appendOnly('media');
        Ddl::tenantRls('media');
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
