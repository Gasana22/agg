<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 15: row-level security on every table with a farm_id (docs/02 §2,
 * layer 6). The tables left until now are read before a farm is chosen or by
 * platform administration, so their policies add narrow extra conditions to
 * the usual "current farm or explicit bypass" (ADR-0019).
 */
return new class extends Migration
{
    private const OWN_USER = "user_id::text = current_setting('app.user_id', true)";

    public function up(): void
    {
        // Memberships: the member's own rows, before a farm is chosen.
        Ddl::rls('farm_users', [self::OWN_USER, Ddl::PLATFORM]);

        // Roles, grants, settings and status of the farms the user belongs to
        // (workspaces, "My farms", MFA requirements).
        foreach (['farm_roles', 'farm_role_permissions', 'farm_user_roles', 'farm_settings', 'farm_status_history'] as $table) {
            Ddl::rls($table, [Ddl::MEMBER_OF_FARM, Ddl::PLATFORM]);
        }

        // Support: owners follow their farm's tickets and grants; the grantee uses a grant.
        Ddl::rls('support_tickets', [Ddl::MEMBER_OF_FARM, Ddl::PLATFORM]);
        Ddl::rls('support_access_grants', ["grantee_user_id::text = current_setting('app.user_id', true)", Ddl::MEMBER_OF_FARM, Ddl::PLATFORM]);

        // Portals: a party's own links (ADR-0016).
        Ddl::rls('party_links', [
            "party_id IN (SELECT pu.party_id FROM party_users pu WHERE pu.user_id::text = current_setting('app.user_id', true))",
            Ddl::PLATFORM,
        ]);

        // Online payments: the person who started one (a customer is not a
        // member), and subscription payments, which belong to no farm.
        Ddl::rls('online_payments', ["created_by::text = current_setting('app.user_id', true)", Ddl::MEMBER_OF_FARM, Ddl::PLATFORM]);

        // Audit: platform and sign-in events have no farm. Entries about a farm
        // may be written from outside it (a portal or a ticket), so inserts
        // are open; the table is append-only and reads stay isolated.
        Ddl::rls('audit_logs', ['farm_id IS NULL', Ddl::PLATFORM]);
        Ddl::rlsAllowInserts('audit_logs');

        // The trace chain counter is only ever used inside the farm.
        Ddl::rls('trace_sequences');
    }

    public function down(): void
    {
        foreach (['farm_users', 'farm_roles', 'farm_role_permissions', 'farm_user_roles', 'farm_settings', 'farm_status_history',
            'support_tickets', 'support_access_grants', 'party_links', 'online_payments', 'audit_logs', 'trace_sequences'] as $table) {
            Ddl::dropRls($table);
        }
    }
};
