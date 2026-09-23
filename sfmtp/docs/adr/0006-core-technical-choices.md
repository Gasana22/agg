# ADR-0006 — Core technical choices

**Status:** Accepted (2026-09-23)

| Decision | Choice | Why | Alternatives rejected |
|---|---|---|---|
| Primary keys | UUIDv7 | Offline creation on mobile; time-ordered for index locality; not guessable | Auto-increment (offline ID collisions); UUIDv4 (index fragmentation) |
| Web auth token storage | Next.js BFF with httpOnly cookies; the API still uses JWT bearer tokens | JWT as required, without exposing tokens to XSS | JWT in localStorage |
| Tenant enforcement | App-level scope + composite FKs + PostgreSQL RLS | Defence in depth; RLS protects raw SQL and reports | Schema-per-tenant (hard to operate at scale; cross-farm owner roll-ups awkward); DB-per-tenant |
| Finance | Double-entry ledger with cost centres | Consistent P&L, cash flow, cost per crop / acre; immutable with reversals | Separate income / expense tables only (reports drift apart) |
| Inventory | Append-only stock ledger + locked balance projection | Auditable stock; no negative stock under concurrency | Mutable quantity column only |
| Traceability | Batch graph + append-only hash-chained events | Backward / forward trace with one query; tamper evidence | Only linking domain tables (no single journey; history lost on edits) |
| Backend structure | Modular monolith (Laravel modules) | One deployable, clear module boundaries, can be split later | Microservices (premature for team size and phase plan) |
| Repo | Monorepo `backend/ web/ mobile/ packages/ infra/` + OpenAPI-generated clients | Contract stays in sync across three apps | Separate repos per app |
| Mobile local DB | Drift (SQLite) + SQLCipher | Typed queries, migrations, encryption | Hive / Isar (weaker relational querying) |
| Maps | Adapter over Google Maps / Mapbox; PostGIS on server | Provider choice is configurable (requirement §36) | Direct SDK coupling |
| Passwords | Argon2id | Current best practice | bcrypt (acceptable fallback) |
| Password resets by admin | Admin can only trigger a reset link to the owner; never set or see passwords | Farm data belongs to the owner; an admin who could set a password could log in as the owner | Admin sets a temporary password |
