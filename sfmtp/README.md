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
| 3 — Farm management | Next |

## Repository layout

```
sfmtp/
├── backend/                 Laravel 12 API (modular monolith)          → backend/README.md
├── web/                     Next.js 16 web app + backend-for-frontend  → web/README.md
├── mobile/                  Flutter app (added in Phase 6)
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
