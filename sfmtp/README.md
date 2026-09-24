# SFMTP — Smart Farm Management & Traceability Platform

> Track every seed, every worker, every harvest, every sale.

SFMTP is a commercial, multi-tenant SaaS platform for farm operations and
end-to-end traceability. It has a responsive web application, an offline-first
mobile app for Android and iOS, supplier and customer portals, and a versioned
REST API.

## Status

| Phase | State |
|---|---|
| 0 — Design | ✅ Done ([docs](docs/)) |
| 1 — Architecture & foundation | ✅ Done: identity + MFA, multi-tenancy, permissions, audit log, traceability core, dashboards framework, web shell, CI |
| 2 — Platform administration | ✅ Done: platform roles, farm approval/suspension, plans & subscriptions with limits and lifecycle, owner billing page, global catalogues, integrations, settings, system health, support tickets with owner-granted read-only access |
| 3 — Farm management | ✅ Done: farm map with blocks / sections / plots / locations and soil tests, member invitations, member management, custom roles and the role editor, farm policies, "My farms" |
| 4 — Crop management | ✅ Done: crop list and seasons, plans with approval, cycles from nursery to harvest, field work with inputs and withholding periods, pest and disease reports, harvests, all feeding traceability; agronomist dashboard |
| 5 — Livestock | ✅ Done: animals and groups with lineage, breeding and births, feeding, health and vaccinations with milk and meat withdrawal periods, weights, milk and egg records with daily lots, movements, deaths and culls, sale requests with approval, all feeding traceability; livestock dashboard |
| 6 — Workers & activities | ✅ Done: workers, activities and tasks with a state machine and verification, attendance with GPS and photos, leave, photo uploads, the offline sync API, manager and field-worker dashboards, and the Flutter field app v0 (offline outbox, push / pull) |
| 7 — Procurement & inventory | ✅ Done: items, lots as trace batches, the stock ledger with average cost, issues, transfers, counts with approval, stock requests, alerts; suppliers, purchase requests and orders with approval, deliveries and supplier invoices; the double-entry ledger core; Store Manager dashboard |
| 8 — Finance | ✅ Done: accounts and manual entries, expenses with approval thresholds, income, customer invoices (with livestock sales), payments against any document, payroll from attendance charged to the work done, budgets, profit and loss, cash flow with a forecast, cost per crop and per acre; accountant dashboard and the owner's financial KPIs |
| 9 — Traceability (full) | ✅ Done: split / merge / process / package with quantity checks, recall to the customer, shipments, journey views (timeline, workers, inputs, customers, map), the journey projection, integrity checks and alerts; the traceability explorer |
| 10 — QR system | ✅ Done: approved public fields with preview, random QR codes with revoke and recall notices, the public scan page, signed payloads, rate limits, anonymous scan statistics, printable PDF labels |
| 11 — Mobile app (complete) | ✅ Done: agronomist, livestock and supervisor flows on the phone, a sync feed filtered by permission, field-level merge with conflicts resolved in the app, a notification inbox with FCM push, an encrypted local database, background sync, remote wipe, and store builds for the internal testing tracks |
| 12 — Supplier & customer portals | Next |

## Repository layout

```
sfmtp/
├── backend/                 Laravel 12 API (modular monolith)          → backend/README.md
├── web/                     Next.js 16 web app + backend-for-frontend  → web/README.md
├── mobile/                  Flutter app for every farm role (offline)  → mobile/README.md
├── packages/api-contracts/  OpenAPI 3.1 contract (source of the typed clients)
├── infra/docker/            Dockerfiles and the local compose stack
└── docs/                    Architecture and design documents
```

## Quick start (Docker)

```bash
cd sfmtp
cp infra/docker/.env.example infra/docker/.env   # fill APP_KEY and JWT_SECRET
docker compose -f infra/docker/compose.yaml up --build
```

Open http://localhost:3000 and sign in with a demo account (password
`Password123!`), e.g. `agronomist@aggfarms.test`, `manager@aggfarms.test`, or
`owner@aggfarms.test` (the owner is asked to set up MFA first). Platform staff:
`admin@sfmtp.test`, `support@sfmtp.test`, `billing@sfmtp.test`. The full list is
in [backend/README.md](backend/README.md).

To run without Docker, follow [backend/README.md](backend/README.md) and
[web/README.md](web/README.md).

## Quality gates

CI (`.github/workflows/sfmtp-ci.yml`) runs on every change under `sfmtp/`:

- API tests on **PostgreSQL 16** (as a non-superuser, so row-level security is
  exercised) and on **MySQL 8**, plus code style
- the cross-tenant sweep: every `/farms/{farm}` route is called as another
  farm's owner and must answer 404 without changing data
- OpenAPI lint, and a check that routes, contract and generated web types agree
- web lint, type-check, unit tests and production build
- mobile analyze, unit and widget tests (the docs/08 §6 offline suite), the
  offline scenarios against a live API, and release APKs with a size budget
- Docker image builds

## Design documents

| # | Document | What it answers |
|---|----------|-----------------|
| 01 | [System Architecture](docs/01-system-architecture.md) | How the system is structured, from the deployment view down to the code layers |
| 02 | [Tenant Isolation Strategy](docs/02-tenant-isolation.md) | How Farm A is kept from ever seeing Farm B's data |
| 03 | [Database ERD](docs/03-database-erd.md) | Tables, relationships, keys and constraints |
| 04 | [Role & Permission Matrix](docs/04-roles-and-permissions.md) | Who can do what, at module, record and field level |
| 05 | [Dashboard Architecture](docs/05-dashboard-architecture.md) | Ten role-specific dashboards and how their data is computed |
| 06 | [API Conventions & Dashboard Contracts](docs/06-api-contracts.md) | `/api/v1` conventions, dashboard payloads, traceability and sync endpoints |
| 07 | [Traceability & Audit Design](docs/07-traceability-and-audit.md) | Batch graph, append-only events, QR codes, audit trail |
| 08 | [Offline Sync Design](docs/08-offline-sync.md) | Mobile outbox, pull/push protocol, conflict resolution |
| 09 | [Module Dependency Map](docs/09-module-dependency-map.md) | Which modules depend on which, and the build order that follows |
| 10 | [Development Roadmap](docs/10-roadmap.md) | Phases, exit criteria and test gates |
| — | [Architecture Decision Records](docs/adr/README.md) | Decisions taken to fill gaps in the requirements, with open questions |

## Source of truth

The master requirements document (*SFMTP Master System Requirements &
Development Prompt*) takes precedence. Where these documents go beyond it or
resolve an ambiguity in it, the reasoning is recorded as an ADR.
