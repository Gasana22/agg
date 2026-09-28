# ADR-0019 — Row-level security on every farm table, and production readiness

**Status:** Accepted (2026-09-28, Phase 15)

## Context
Until Phase 15, row-level security (RLS) covered 90 farm tables. Twelve
tables with a `farm_id` had none. They are read *before* a farm is chosen,
or across farms:
- memberships, roles and their permissions, farm settings and status;
- support tickets and grants;
- portal links;
- online payments;
- the audit log;
- the trace sequence counter.

The plain policy ("the current farm, or an explicit bypass") would have
broken sign-in, "My farms", portals and administration. Phase 15 also had
to show the platform is ready for production: security, load, backups and
recovery, documentation.

## Decision
1. **Every table with a `farm_id` forces RLS**, and a test fails the build
   when one does not. `Ddl::rls(table, also)` keeps the base condition
   (the current farm, or an explicit bypass) and adds **narrow, named
   conditions** per table.
2. **The session tells the policies three things**, all set by
   `TenantContext`:

   | Setting | Set by | Means |
   |---|---|---|
   | `app.farm_id` | entering a farm (as before) | The current farm |
   | `app.user_id` | `ShareUserWithDatabase`, on every API request | Who is signed in |
   | `app.platform` | `RequirePlatformAdmin`, on `/admin` routes | Platform administration is running |

3. **Per-table conditions:**

   | Tables | Also visible to |
   |---|---|
   | `farm_users` | The member's own rows; platform |
   | `farm_roles`, `farm_role_permissions`, `farm_user_roles`, `farm_settings`, `farm_status_history`, `support_tickets` | Members of that farm; platform |
   | `support_access_grants` | The grantee, the farm's members; platform |
   | `party_links` | The party's own users; platform |
   | `online_payments` | The person who started it, the farm's members; platform |
   | `audit_logs` | Rows with no farm (sign-in, platform); platform. **Inserts are open** (append-only trail written from portals and tickets), reads are not |
   | `trace_sequences` | Nobody else |

   Operational tables (crops, animals, stock, money, workers and so on)
   gain **no** condition. Platform administration therefore cannot read
   them, even by mistake.
4. **Security decisions never rely on session settings.** A check about
   one known user or organization reads with an explicit, commented
   bypass, so a missing setting can never make it fail open:
   - the MFA requirement;
   - plan user limits;
   - a staff member's usable support grants;
   - the signed payment webhook's lookup by reference.

   Farm creation and new members are written inside the new farm's
   context. Console commands look owners up inside each farm. The demo
   seeder runs as a documented bypass.
5. **Backups use a separate role** (`sfmtp_backup`: read-only,
   `pg_read_all_data`, `BYPASSRLS`). The application role cannot dump a
   database whose farm tables force RLS. Restores load schema and
   policies as the application role and rows as an administrator, so the
   copy keeps its isolation.
6. **Production readiness is evidence, not intent.** Each item is a
   script or a test in the repository, with results in the docs:
   - API security headers and a locked-down CORS; CSP and HSTS on the web;
   - dependency audits that fail CI, and Dependabot;
   - a security test suite;
   - a load test driving the real sync API;
   - a restore drill and a point-in-time drill;
   - the runbook, and the deployment, user and API guides;
   - a launch checklist.

## Consequences
- A new farm table needs `Ddl::tenantRls()` or `Ddl::rls()` in its
  migration, or the build fails.
- Code that reads farm tables outside a farm must say why: its own-user
  condition, the platform flag, or an explicit bypass with a comment.
- Test fixtures and database assertions read unscoped (`unscoped()`, and
  the `assertDatabase*` overrides), because they inspect stored data rather
  than application behaviour.
- `app.platform` makes platform-facing tables readable on every `/admin`
  route, not only on those that need them. This is accepted: those tables
  describe farms rather than farm operations, and staff actions are
  audited.
- MySQL has no RLS. There, isolation rests on the other six layers
  (docs/02 §6), and the RLS tests skip.
