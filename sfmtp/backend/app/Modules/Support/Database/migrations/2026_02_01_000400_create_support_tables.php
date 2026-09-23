<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets and owner-granted, read-only support access (ADR-0005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 20)->unique();          // human-friendly, e.g. SUP-7K2Q9X
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('farm_id')->nullable()->constrained();
            $table->foreignUuid('opened_by')->constrained('users');
            $table->string('subject', 200);
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('normal');
            $table->foreignUuid('assigned_to')->nullable()->constrained('users');
            $table->timestamp('last_activity_at');
            $table->timestamps();
            $table->index(['status', 'last_activity_at']);
            $table->index(['organization_id', 'last_activity_at']);
        });
        Ddl::checkIn('support_tickets', 'status', ['open', 'pending', 'resolved', 'closed']);
        Ddl::checkIn('support_tickets', 'priority', ['low', 'normal', 'high', 'urgent']);

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained('support_tickets');
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body');
            $table->boolean('is_internal')->default(false);     // staff-only note
            $table->timestamp('created_at', 6);
            $table->index(['ticket_id', 'created_at']);
        });
        Ddl::appendOnly('support_ticket_messages');

        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->foreignUuid('ticket_id')->constrained('support_tickets');
            $table->foreignUuid('granted_by')->constrained('users');
            $table->foreignUuid('grantee_user_id')->nullable()->constrained('users');   // null = any support staff
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->index(['farm_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_grants');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
