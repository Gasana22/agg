# 09 — Module Dependency Map

Arrows mean "depends on / calls". Dependencies only point **downwards**:
foundation modules never depend on business modules. Business modules
communicate through service interfaces and domain events, never through each
other's tables.

```mermaid
flowchart TB
    subgraph Foundation
        ID[Identity & Auth<br/>users, MFA, tokens, devices]
        TEN[Tenancy<br/>organizations, farms, membership, TenantContext]
        RBAC[Permissions<br/>roles, grants, policies]
        AUD[Audit]
        MED[Media & Documents]
        NOT[Notifications]
        INT[Integration adapters<br/>SMS, email, push, maps, weather, payments]
    end

    PLAT[Platform Admin<br/>plans, subscriptions, catalogues, support]
    FS[Farm Structure<br/>blocks, sections, plots, locations]
    WF[Workforce & Activities<br/>workers, tasks, attendance, GPS]
    TR[Traceability<br/>batches, events, QR]
    INV[Inventory]
    PROC[Procurement]
    FIN[Finance<br/>ledger, invoices, payments, payroll]
    CROP[Crops]
    LIV[Livestock]
    AST[Assets]
    SAL[Sales & Customers]
    SUPP[Supplier Portal]
    CUST[Customer Portal]
    MAP[Maps & GIS]
    SYNC[Offline Sync]
    REP[Reporting & Dashboards]

    TEN --> ID
    RBAC --> TEN
    PLAT --> TEN
    PLAT --> INT
    FS --> RBAC
    WF --> FS
    WF --> MED
    TR --> FS
    TR --> AUD
    INV --> FS
    INV --> TR
    PROC --> INV
    FIN --> RBAC
    PROC --> FIN
    CROP --> WF
    CROP --> INV
    CROP --> TR
    LIV --> WF
    LIV --> INV
    LIV --> TR
    AST --> WF
    AST --> FIN
    WF -.payroll data.-> FIN
    SAL --> INV
    SAL --> FIN
    SAL --> TR
    SUPP --> PROC
    CUST --> SAL
    CUST --> TR
    MAP --> FS
    MAP --> WF
    SYNC --> WF
    SYNC --> CROP
    SYNC --> LIV
    REP --> CROP
    REP --> LIV
    REP --> FIN
    REP --> INV
    REP --> WF
    NOT --> INT
```

Audit, Notifications and Media are used by nearly every module; only a few of
those arrows are drawn.

## Key interfaces between modules

| Provider | Interface | Used by |
|---|---|---|
| Tenancy | `TenantContext`, `BelongsToFarm` trait, `ResolveFarmContext` middleware | All farm modules |
| Permissions | `Gate` / policies with `can('crops.harvest.create')`, `ScopeResolver` (all / assigned / own) | All |
| Workforce | `ActivityService::create/submit/verify`, events `ActivityVerified` | Crops, Livestock, Assets, Inventory (store tasks) |
| Inventory | `StockService::receive/issue/transfer/adjust` (transactional, row-locked) | Procurement, Crops, Livestock, Sales |
| Finance | `LedgerService::post(JournalEntry)`, `CostCenter` value object | Procurement, Inventory (valuation), Sales, Workforce (payroll), Assets |
| Finance | `Payables` registry and `Payable` contract: each module registers the documents payments can settle (ADR-0012) | Sales (customer invoices), Procurement (supplier invoices) |
| Livestock | Completed `SaleRequest`s, billed on customer invoices | Sales |
| Traceability | `Recorder::createBatch/link/record/correct` | Crops, Livestock, Inventory, Procurement, Sales |
| Media | `MediaService::attach`, signed URLs | All |
| Notifications | `Notifier::send(Notification, recipients)` (queued, multi-channel) | All |

## Build order that follows from the graph

1. Identity → Tenancy → Permissions → Audit → Media → Notifications (foundation)
2. Platform Admin
3. Farm Structure
4. **Workforce & Activities, plus the Traceability core** (batches, events,
   recorder). These come before Crops and Livestock because both depend on
   them. Adding traceability afterwards would leave gaps in the history.
5. Inventory → Finance ledger core → Procurement
6. Crops, Livestock (can run in parallel)
7. Sales & Customers → Supplier and Customer portals
8. QR / public traceability, Maps, Reporting, Sync hardening, Integrations

[10 — Roadmap](10-roadmap.md) maps this onto the 15 phases in the
requirements.
