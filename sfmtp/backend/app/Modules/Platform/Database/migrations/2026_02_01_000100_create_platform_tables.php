<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Platform administration (Phase 2). Platform tables have no farm_id: they
 * belong to the System Administrator (docs/02 §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fixed platform roles (docs/04 §5); their capabilities live in code.
        Schema::create('platform_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });
        foreach (['super_admin' => 'Super admin', 'support' => 'Support', 'billing' => 'Billing'] as $key => $name) {
            DB::table('platform_roles')->insert(['id' => (string) Str::uuid7(), 'key' => $key, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('platform_user_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('platform_role_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'platform_role_id']);
        });

        Schema::table('farms', function (Blueprint $table) {
            $table->foreignUuid('approved_by')->nullable()->after('approved_at')->constrained('users');
            $table->string('suspension_reason', 30)->nullable()->after('suspended_at');
        });

        // Every farm status change, append-only.
        Schema::create('farm_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason_code', 30)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $table->index(['farm_id', 'created_at']);
        });
        Ddl::appendOnly('farm_status_history');

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('integration_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 20);
            $table->string('provider', 40);
            $table->string('name', 100);
            $table->text('config');                         // encrypted JSON (model cast)
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['kind', 'provider']);
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 10);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('location', 500)->nullable();
            $table->string('checksum', 128)->nullable();
            $table->string('message', 1000)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('finished_at');
        });
        Ddl::checkIn('backup_runs', 'status', ['success', 'failed']);
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('integration_providers');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('farm_status_history');
        Schema::table('farms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('suspension_reason');
        });
        Schema::dropIfExists('platform_user_roles');
        Schema::dropIfExists('platform_roles');
    }
};
