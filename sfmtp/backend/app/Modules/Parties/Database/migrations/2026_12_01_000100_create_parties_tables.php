<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier and customer portal accounts (ADR-0002, ADR-0016).
 *
 * - `parties` are global: one company or person, whatever farms it deals
 *   with. `party_users` are the people who sign in for it.
 * - `party_links` connect a party to one farm's supplier or customer
 *   record. Like farm_users, they are read across farms to find a
 *   party's workspaces, so they carry farm_id without tenant RLS; every
 *   portal read of farm data then runs inside that farm's context.
 * - `portal_invitations` are farm records: the emailed, one-time link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('tax_id', 40)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Ddl::checkIn('parties', 'status', ['active', 'suspended']);

        Schema::create('party_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('party_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->timestamp('created_at');
            $table->unique(['party_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('party_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('party_id')->constrained();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('kind', 20);                              // supplier | customer
            $table->uuid('record_id');                               // suppliers.id or customers.id in that farm
            $table->string('status', 20)->default('active');
            $table->foreignUuid('linked_by')->nullable()->constrained('users');
            $table->timestamp('linked_at');
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['farm_id', 'kind', 'record_id']);
            $table->index(['party_id', 'status']);
        });
        Ddl::checkIn('party_links', 'kind', ['supplier', 'customer']);
        Ddl::checkIn('party_links', 'status', ['active', 'revoked']);

        Schema::create('portal_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('kind', 20);
            $table->uuid('record_id');
            $table->string('email', 150);
            $table->string('token_hash', 64)->unique();
            $table->string('message', 500)->nullable();
            $table->foreignUuid('invited_by')->nullable()->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_user_id')->nullable()->constrained('users');
            $table->foreignUuid('party_id')->nullable()->constrained();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'kind', 'record_id']);
        });
        Ddl::checkIn('portal_invitations', 'kind', ['supplier', 'customer']);
        Ddl::tenantRls('portal_invitations');
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_invitations');
        Schema::dropIfExists('party_links');
        Schema::dropIfExists('party_users');
        Schema::dropIfExists('parties');
    }
};
