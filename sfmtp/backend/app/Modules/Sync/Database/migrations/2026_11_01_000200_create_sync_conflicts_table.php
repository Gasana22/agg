<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field-level sync conflicts (docs/08 §4, ADR-0015): an offline edit of a
 * field that someone else also changed. The member chooses, per field,
 * keep mine or keep the server's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->foreignUuid('user_id')->constrained('users');
            $table->uuid('mutation_id');
            $table->string('entity', 40);                           // animals
            $table->uuid('record_id');
            $table->string('label', 150)->nullable();               // what the member sees, e.g. "COW-004 Bella"
            $table->unsignedInteger('base_version')->nullable();
            $table->unsignedInteger('server_version');
            $table->json('fields');                                 // [{field, base, mine, server}]
            $table->string('status', 10)->default('open');
            $table->json('resolution')->nullable();                 // {field: mine|server}
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['farm_id', 'user_id', 'status']);
        });
        Ddl::checkIn('sync_conflicts', 'status', ['open', 'resolved']);
        Ddl::tenantRls('sync_conflicts');
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
