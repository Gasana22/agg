# SFMTP — Smart Farm Management & Traceability Platform

> Track every seed, every worker, every harvest, every sale.

SFMTP is a commercial, multi-tenant SaaS platform for farm operations and
end-to-end traceability. It has a responsive web application, an offline-first
mobile app for Android and iOS, supplier and customer portals, and a versioned
REST API.

This directory will hold the platform source code. It currently contains the
**architecture and design documents** (Phase 0). Implementation starts once
these are agreed.

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
