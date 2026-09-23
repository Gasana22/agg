<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member invitations. Only a SHA-256 hash of the emailed token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('email');                              // lower-cased
            $table->char('token_hash', 64)->unique();
            $table->string('message', 500)->nullable();
            $table->foreignUuid('invited_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->unsignedSmallInteger('send_count')->default(1);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_user_id')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'email']);
            $table->index(['farm_id', 'created_at']);
        });
        Ddl::tenantRls('farm_invitations');

        Schema::create('farm_invitation_roles', function (Blueprint $table) {
            $table->uuid('farm_id');
            $table->uuid('invitation_id');
            $table->uuid('farm_role_id');
            $table->primary(['invitation_id', 'farm_role_id']);
            // Composite FKs: invitation and role must belong to the same farm.
            $table->foreign(['farm_id', 'invitation_id'])->references(['farm_id', 'id'])->on('farm_invitations')->cascadeOnDelete();
            $table->foreign(['farm_id', 'farm_role_id'])->references(['farm_id', 'id'])->on('farm_roles');
            $table->index(['farm_id', 'farm_role_id']);
        });
        Ddl::tenantRls('farm_invitation_roles');
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_invitation_roles');
        Schema::dropIfExists('farm_invitations');
    }
};
