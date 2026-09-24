# SFMTP API (Laravel 12)

Modular monolith: each business module lives in `app/Modules/<Module>` with its
own routes, migrations, domain, application services and HTTP layer
(see `../docs/01-system-architecture.md` §4).

| Module | Responsibility |
|---|---|
| `Identity` | Users, JWT access tokens, rotating refresh tokens, TOTP MFA, devices, password reset |
| `Tenancy` | Organizations, farms (tenants), memberships, `TenantContext`, `BelongsToFarm` scope |
| `Access` | Permission registry, role templates, custom roles and guard-rails, invitations and member management, workspaces |
| `Audit` | Append-only audit log |
| `Billing` | Plans, subscriptions, lifecycle, payments, plan limits (`SubscriptionGate`), owner billing API |
| `Platform` | Platform roles and capabilities, farm administration, accounts and staff, settings, integrations, system pages |
| `Catalog` | Global catalogues (crops, varieties, species, breeds, units, inventory categories, activity types) |
| `Crops` | Farm crops, seasons, crop plans, cycles, field operations and inputs, observations, harvests; writes the crop trace history |
| `Finance` | Double-entry ledger (ADR-0011, ADR-0012): chart of accounts, manual entries, expenses with approval, income, payments through a payable registry, payroll from attendance, budgets |
| `Inventory` | Items, lots (trace batches), the stock ledger and balances with average cost, issues, transfers, counts with approval, stock requests, alerts |
| `Procurement` | Suppliers, purchase requests, purchase orders with approval, deliveries into stock lots, supplier invoices matched to receipts |
| `Workforce` | Workers, activities and tasks (state machine, logs, verification), attendance, GPS points, task photos, leave; the `assigned` scope for crops and livestock |
| `Media` | Photo and document uploads, stored once per farm by SHA-256 (local disk or S3 / MinIO) |
| `Sync` | Offline push (ordered mutations through the normal services) and pull (snapshot or change feed) for the mobile app (ADR-0010) |
| `Sales` | Customers and customer invoices, including completed livestock sales; registers invoices as payable; shipments that end a batch's journey |
| `Livestock` | Animals and groups, breeding and births, health and vaccinations with withdrawal periods, feeding, weights, milk and egg production, movements, exits and sale requests; writes the animal trace history |
| `FarmStructure` | Blocks, sections, plots and locations with GeoJSON boundaries, soil profiles, geometry warnings (ADR-0009) |
| `Support` | Tickets, internal notes, owner-granted read-only support access |
| `Traceability` | Batch graph, hash-chained events, recorder, split / merge / process / package and recall (ADR-0013), journey views and the `product_journeys` projection, chain verification and alerts; public QR pages with approved fields, signed payloads, scan counts and PDF labels (ADR-0014) |
| `Reporting` | Server-driven role dashboards, "My farms" overview, the admin dashboard, financial reports (P&L, cash flow and forecast, cost per crop and animal group) |

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

The crop farm also has a full maize season on B-3 (seed lot → crop lot →
harvest → dried → packed, with a fall armyworm spray and its withholding
period) and the current season's cycles on A-1, A-2, B-1 (nursery) and B-2.
The agronomist and the field worker belong to both farms.

The mixed farm also has a small workforce, managed by the manager: Wilson
(the `worker@` account) and three casual workers, a week of attendance,
today's schedule with the milking under way, a deworming waiting for
verification, an overdue fence repair and a leave request. On the crop farm
Wilson has a weeding task on the A-1 maize, so he may record crop work there.

The mixed farm keeps a herd, recorded by the livestock manager: a dairy
herd with two weeks of milking, Ankole cattle, a goat flock with a death on
record and a layer flock, with a calving (calf linked to its dam and sire),
a mastitis treatment under milk withdrawal, a group vaccination and
deworming, weights and a pending sale request.

The mixed farm's feed store and vet cabinet hold stock, run by the store
manager: opening stock, a dewormer and feed purchase from request to
invoice (with a price variance), an order on its way and one waiting for the
owner's approval, a week of feed issued to the dairy herd, a dewormer
request from the livestock manager, a purchase request and a diesel count
waiting for the manager, a vaccine lot close to expiry, and mineral licks
below their reorder level.

Its books are kept by the accountant: opening balances, part of the
dewormer invoice paid, expenses (fuel for the dairy, a vet visit paid in
cash, a roof repair above the threshold waiting for the owner), manure sold
at the gate, milk invoiced to the co-op and half collected, last week's
payroll approved and paid, and a quarterly budget for the dairy herd.

Both farms have a mapped layout: paddocks and livestock buildings on the mixed
farm near Kakiri, and two blocks of three plots (with a soil test on B-3, the
origin of the demo maize batches) on the crop farm near Seeta. Invitation
emails go to the log with `MAIL_MAILER=log`.

The B-3 maize has a full journey:
- 1,020 kg harvested;
- 520 kg split off, dried to 500 kg and packed in 50 kg bags;
- 300 kg shipped to Kampala Millers and delivered;
- 100 kg dispatched to a market trader nine days ago and not yet
  confirmed, which raises a traceability alert.

The harvest's moisture reading is corrected. The bags are published with
a QR code and two weeks of scans; open `/q/<code>` on the web app signed
out to see the public page. `trace:refresh-journeys`
rebuilds the journey projection, and `trace:verify-chain` runs the tamper
check.

## Test

```bash
php artisan test                                   # PostgreSQL (default, see phpunit.xml)
DB_CONNECTION=mysql DB_PORT=3306 php artisan test  # MySQL 8
vendor/bin/pint --test                             # code style
```

Notable suites: `Tenancy/CrossTenantIsolationTest` (visits every farm route as
another farm's owner), `Inventory/StockConcurrencyTest` (forked processes
issue from one balance at once; needs the pcntl extension), `Access/RoleBoundaryTest`, `Traceability/TraceabilityTest`
(including hash-chain tamper detection) and `Support/OpenApiCoverageTest`
(routes ↔ `../packages/api-contracts/openapi.yaml`).

## Useful commands

```bash
php artisan access:sync-permissions   # after adding permissions (run on every deploy)
php artisan trace:verify-chain        # verify traceability hash chains (scheduled nightly)
php artisan billing:advance-subscriptions   # trial/period end → grace → suspended (scheduled daily)
php artisan platform:record-backup success --location=… --size=…   # called by infra/deploy/backup.sh
```
