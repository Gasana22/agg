<?php

use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global registry, synced from PermissionRegistry (access:sync-permissions).
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('module', 40);
            $table->string('description');
            $table->json('scopes');
            $table->boolean('owner_only')->default(false);
            $table->boolean('money')->default(false);
            $table->timestamps();
        });

        Schema::create('farm_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('key', 60);
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);   // seeded from a template
            $table->boolean('is_locked')->default(false);   // owner role: never editable
            $table->timestamps();
            $table->unique(['farm_id', 'key']);
            $table->unique(['farm_id', 'id']);
        });

        Schema::create('farm_role_permissions', function (Blueprint $table) {
            $table->uuid('farm_id');
            $table->uuid('farm_role_id');
            $table->foreignUuid('permission_id')->constrained();
            $table->string('scope', 10)->default(PermissionScope::All->value);
            $table->primary(['farm_role_id', 'permission_id']);
            // Composite FK: the role must belong to the same farm.
            $table->foreign(['farm_id', 'farm_role_id'])->references(['farm_id', 'id'])->on('farm_roles')->cascadeOnDelete();
            $table->index('farm_id');
        });
        Ddl::checkIn('farm_role_permissions', 'scope', PermissionScope::values());

        Schema::create('farm_user_roles', function (Blueprint $table) {
            $table->uuid('farm_id');
            $table->uuid('farm_user_id');
            $table->uuid('farm_role_id');
            $table->timestamp('created_at')->nullable();
            $table->primary(['farm_user_id', 'farm_role_id']);
            // Composite FKs: member and role must belong to the same farm.
            $table->foreign(['farm_id', 'farm_user_id'])->references(['farm_id', 'id'])->on('farm_users')->cascadeOnDelete();
            $table->foreign(['farm_id', 'farm_role_id'])->references(['farm_id', 'id'])->on('farm_roles')->cascadeOnDelete();
            $table->index(['farm_id', 'farm_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_user_roles');
        Schema::dropIfExists('farm_role_permissions');
        Schema::dropIfExists('farm_roles');
        Schema::dropIfExists('permissions');
    }
};
