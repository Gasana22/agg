# 10 — Development Roadmap

This roadmap keeps the 15 phases from the requirements, with **four
adjustments** that follow from the [dependency map](09-module-dependency-map.md):

1. **The traceability core (Recorder, batches, events, immutability) moves
   into Phase 1.** Crops, livestock, inventory and sales must emit trace
   events from their first day. Phase 9 then covers the *full* journey,
   views and projections, instead of retrofitting history.
2. **Dashboards are delivered with each module**, using the widget framework
   from Phase 1. Phase 13 adds advanced analytics, reports and exports.
   Otherwise no role would have a working home screen until Phase 13.
3. **The Flutter app starts in Phase 6** (worker tasks and attendance), not
   Phase 11. Field workers are the main mobile users, and the sync protocol
   must be proven early. Phase 11 completes and hardens it.
4. **The Finance ledger core starts in Phase 7**, alongside procurement,
   because supplier invoices and stock valuation post to it. Phase 8 adds
   the rest of Finance.

## Progress

### Phase 1 — delivered

Everything in the Phase 1 row below, with these notes:

- **Deferred to the phase that first needs it:** PostGIS columns (Phase 3,
  plots and boundaries; the Docker image already includes the extension), the
  Dart API client (Phase 6, with the Flutter app), object storage wiring
  (Phase 6, media), and monthly partitioning of `audit_logs` / `trace_events`
  (Phase 15).
- **Row-level security** is on for the traceability tables (the pilot). The
  other farm tables get it as they are created, and all farm tables by Phase 15.
- **Test gate met:** 77 API tests (PostgreSQL; 75 run on MySQL, and 2 need
  PostgreSQL features), including the cross-tenant sweep of every farm route,
  the role-boundary tests that apply to Phase 1, append-only enforcement and
  hash-chain tamper detection. The web app has unit tests, and the main role
  journeys were checked end to end in a browser.

## Phase plan

| Phase | Scope | Key deliverables | Exit criteria (test gate) |
|---|---|---|---|
| **0 — Design** *(this set)* | Architecture, ERD, tenancy, permissions, dashboards, API, roadmap | `sfmtp/docs/*` | Design reviewed; open ADRs answered |
| **1 — Architecture & foundation** | Monorepo, Docker, CI; Laravel modules skeleton; Identity (JWT, refresh rotation, MFA); Tenancy (organizations, farms, membership, `TenantContext`, global scope, composite FKs, RLS on pilot tables); permission registry and policies; audit log; traceability core; OpenAPI + generated clients; Next.js shell with workspace switcher and dashboard widget framework | Running stack via `docker compose up`; login + MFA on web; empty dashboards per role | Auth tests; cross-tenant test harness running on every route; append-only triggers verified; CI green on Postgres **and** MySQL |
| **2 — Platform administration** | Admin portal: farm approval / suspension, plans and pricing, subscriptions and grace periods, global catalogues, integrations config, logs, health, backups view, support tickets | Admin dashboard (3.1) | System Admin cannot reach farm routes; plan limits enforced; suspension blocks access |
| **3 — Farm management** | Farm profile and settings, blocks / sections / plots with map boundary drawing, locations, member invites, role editor (with guard-rails) | Owner dashboard skeleton; "My Farms" | Role guard-rails; composite FK rejects cross-farm plot references |
| **4 — Crop management** | Seasons, plans, cycles, nursery, operations with inputs, observations, treatments, harvest → crop and harvest batches | Agronomist dashboard | Crop lifecycle end-to-end, emitting trace events; agronomist has no access to finance or livestock |
| **5 — Livestock** | Animals, groups, breeding, feeding, health, vaccination, weights, production, movements, mortality, sale requests | Livestock dashboard | Animal history is complete and immutable; livestock manager has no access to crops or finance |
| **6 — Workers & activities** (+ mobile start) | Workers, generic activities, tasks + state machine, attendance with GPS / photo, leave, verification queue; **Flutter app v0**: login, today's tasks, check-in, task actions, photos, offline outbox + push / pull | Manager and Field Worker dashboards | Assign → execute → submit → verify flow on web and mobile; worker sees only own tasks and no money; offline test scenario passes |
| **7 — Procurement & inventory** (+ ledger core) | Items, lots, locations, stock ledger and balances, transfers, adjustments with approval, requests, low-stock / expiry alerts; suppliers, purchase requests, POs, deliveries, supplier invoices; double-entry ledger core | Store Manager dashboard | Concurrency test (parallel issues never make stock negative); request → PO → delivery → stock-in flow; input lots become trace batches |
| **8 — Finance** | Chart of accounts, income, expenses with approval thresholds, budgets, customer invoices, payments, payroll from attendance and tasks, P&L, cash flow, cost per crop / acre | Accountant dashboard; owner financial KPIs | Ledger always balances; reversals only; field worker blocked from all finance |
| **9 — Traceability (full)** | Split / merge / process / package, shipments, journey endpoints (backward / forward / workers / inputs / sales / locations), `product_journey` projector, hash-chain verification job, trace alerts | Traceability explorer UI (graph + timeline + map) | Seed → customer journey reconstructed for a seeded scenario; correction events; tamper check detects altered data |
| **10 — QR system** | Public-field approvals, QR issue / revoke, public scan page, scan stats | Printable labels (PDF) | Public payload contains only approved fields; revoked code shows a recall notice; rate limits hold |
| **11 — Mobile app (complete)** | Agronomist, livestock and manager flows on mobile; conflict UI; encrypted local DB; background sync; push notifications | Store builds (internal testing tracks) | Full offline test suite (08 §6); performance on low-end Android |
| **12 — Supplier & customer portals** | Party accounts and linking; supplier PO / delivery / invoice flows; products, marketplace, sales orders, customer orders, delivery confirmation, purchase history, QR view | Supplier and Customer dashboards | Supplier / customer isolation tests; order → invoice → dispatch → shipment batch → delivered |
| **13 — Analytics & reporting** | Metric catalogue complete, report builder for standard reports, PDF / Excel exports (queued), activity heat maps | All dashboards final | Dashboard p95 < 800 ms from cache and < 3 s cold; export correctness tests |
| **14 — Integrations** | Maps provider switch, weather, SMS (Twilio / Africa's Talking), email (SendGrid / SMTP), push (FCM / APNs), payment gateways (Flutterwave …) for subscriptions and customer payments; accounting and IoT extension points | Provider adapters + admin config | Contract tests per adapter; provider failover |
| **15 — Security & production** | Pen test, dependency audit, RLS on all farm tables, load test (10k+ activities/day × headroom), backup / restore drill, DR runbook, security, user, API and deployment documentation | Production launch checklist | All role-boundary tests (04 §6) and cross-tenant suite green; restore drill meets RPO / RTO |

## Definition of done (every phase)

- Migrations with constraints, composite FKs and indexes; factories; seeders
  (a demo farm scenario is extended in each phase).
- Services hold the business logic; controllers stay thin.
- Unit and feature tests for every use case, **including authorization and
  tenant-isolation cases**.
- OpenAPI spec updated, with web and mobile clients regenerated.
- Trace events and audit entries emitted for the phase's actions.
- Role dashboards for the phase's modules are working.
- An ADR for any significant decision.

## Seeded demo scenario

A realistic demo organization, **"AGG Farms"**, with two farms:

- one mixed farm with goats, cattle and dairy
- one crop farm with maize and eucalyptus

It includes users for every role, one supplier and one customer. Each phase
extends the scenario, so every phase can be demonstrated and the end-to-end
traceability test has real data.
