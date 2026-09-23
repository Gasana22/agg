# SFMTP API (Laravel 12)

Modular monolith: each business module lives in `app/Modules/<Module>` with its
own routes, migrations, domain, application services and HTTP layer
(see `../docs/01-system-architecture.md` §4).

| Module | Responsibility |
|---|---|
| `Identity` | Users, JWT access tokens, rotating refresh tokens, TOTP MFA, devices, password reset |
| `Tenancy` | Organizations, farms (tenants), memberships, `TenantContext`, `BelongsToFarm` scope |
| `Access` | Permission registry, role templates, guard-rails, farm permissions, workspaces |
| `Audit` | Append-only audit log |
| `Billing` | Plans, subscriptions, lifecycle, payments, plan limits (`SubscriptionGate`), owner billing API |
| `Platform` | Platform roles and capabilities, farm administration, accounts and staff, settings, integrations, system pages |
| `Catalog` | Global catalogues (crops, varieties, species, breeds, units, inventory categories, activity types) |
| `Support` | Tickets, internal notes, owner-granted read-only support access |
| `Traceability` | Batch graph, hash-chained events, recorder, journeys, chain verification |
| `Reporting` | Server-driven role dashboards and the admin dashboard |

Shared plumbing is in `app/Support` (problem+json errors, request IDs,
idempotency keys, engine-specific DDL for triggers and row-level security).

## Run locally

Requirements: PHP 8.4 (pdo_pgsql, redis, intl), Composer, PostgreSQL 16, Redis.

```bash
cp .env.example .env
php artisan key:generate
# set JWT_SECRET (≥ 32 chars), e.g.: openssl rand -base64 48
# create a NON-superuser DB role that owns the database (RLS does not apply to superusers)
php artisan migrate --seed
php artisan serve
```

The demo seeder creates the "AGG Farms" organization; every account's password
is `Password123!`:

| Account | Role |
|---|---|
| `admin@sfmtp.test` | Platform super admin (MFA enrolment required) |
| `support@sfmtp.test` | Platform support staff (MFA enrolment required) |
| `billing@sfmtp.test` | Platform billing staff (MFA enrolment required) |
| `owner@aggfarms.test` | Owner of AGG Mixed Farm and AGG Crop Farm (MFA enrolment required) |
| `manager@`, `agronomist@`, `livestock@`, `store@`, `accountant@`, `worker@aggfarms.test` | One role each on AGG Mixed Farm |

## Test

```bash
php artisan test                                   # PostgreSQL (default, see phpunit.xml)
DB_CONNECTION=mysql DB_PORT=3306 php artisan test  # MySQL 8
vendor/bin/pint --test                             # code style
```

Notable suites: `Tenancy/CrossTenantIsolationTest` (visits every farm route as
another farm's owner), `Access/RoleBoundaryTest`, `Traceability/TraceabilityTest`
(including hash-chain tamper detection) and `Support/OpenApiCoverageTest`
(routes ↔ `../packages/api-contracts/openapi.yaml`).

## Useful commands

```bash
php artisan access:sync-permissions   # after adding permissions (run on every deploy)
php artisan trace:verify-chain        # verify traceability hash chains (scheduled nightly)
php artisan billing:advance-subscriptions   # trial/period end → grace → suspended (scheduled daily)
php artisan platform:record-backup success --location=… --size=…   # called by infra/deploy/backup.sh
```
