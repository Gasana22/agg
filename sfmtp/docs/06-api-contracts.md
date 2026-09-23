# 06 — API Conventions & Dashboard Contracts

This document defines the rules every endpoint follows, the dashboard
payloads, and the traceability and sync endpoints. The machine-readable
source of truth will be the OpenAPI 3.1 spec in `packages/api-contracts`,
written in Phase 1.

## 1. Conventions

### Base, versioning, format
- Base URL `/api/v1`. A breaking change means a new `/api/v2`. Additive
  changes stay in v1.
- JSON in both directions, `snake_case` fields, UTC timestamps in ISO 8601
  (`2026-09-23T08:15:00Z`), UUIDv7 IDs as strings.
- Money is `{ "amount": "1250.00", "currency": "UGX" }`, with the amount as a
  string to avoid float errors. Quantities are `{ "value": "12.500", "unit": "kg" }`.

### Authentication
- `Authorization: Bearer <access_jwt>` (mobile). The web app sends a cookie
  to its Next.js BFF, and the BFF adds the bearer header.
- Every endpoint except `/auth/*` (login, refresh, MFA challenge) and
  `/public/*` requires authentication.

### Response envelope

```json
// single resource
{ "data": { "id": "0192…", "type": "crop_cycle", "...": "..." } }

// collection
{
  "data": [ ... ],
  "meta": { "per_page": 25, "next_cursor": "eyJpZCI6…", "prev_cursor": null },
  "links": { "next": "/api/v1/farms/…/crop-cycles?cursor=eyJpZCI6…" }
}
```

### Pagination, filtering, sorting, search
| Concern | Syntax |
|---|---|
| Pagination | `?cursor=…&per_page=25` (max 100). Cursor-based by default. `?page=` is only available on admin tables that need page numbers |
| Filtering | `?filter[status]=active&filter[plot_id]=…&filter[occurred_at][gte]=2026-09-01` |
| Sorting | `?sort=-occurred_at,name` (`-` = descending). Only allow-listed fields |
| Search | `?q=maize` (full-text search on the resource's searchable fields) |
| Includes | `?include=plot,crop` (allow-listed relations) |
| Sparse fields | `?fields[crop_cycle]=id,stage,planted_on` |

### Errors — RFC 9457 `application/problem+json`

```json
{
  "type": "https://docs.sfmtp.app/errors/validation",
  "title": "The given data was invalid.",
  "status": 422,
  "code": "validation_failed",
  "errors": { "plot_id": ["The selected plot does not belong to this farm."] },
  "request_id": "01J…"
}
```

| Status | `code` examples | When |
|---|---|---|
| 400 | `bad_request` | Malformed JSON or parameters |
| 401 | `unauthenticated`, `mfa_required`, `token_expired` | Auth problems |
| 403 | `forbidden`, `subscription_suspended`, `plan_limit_reached` | Authenticated but not allowed |
| 404 | `not_found` | Missing record **or record in a farm you can't access** (the two are indistinguishable) |
| 409 | `version_conflict`, `invalid_state_transition`, `duplicate` | Optimistic lock failure, state machine violation |
| 422 | `validation_failed`, `insufficient_stock`, `approval_required` | Business or validation rules |
| 429 | `rate_limited` | With a `Retry-After` header |

### Writes
- `POST` to create (201 + `Location`). `PATCH` for partial update, which needs
  `If-Match: "<version>"` or `version` in the body; a stale version returns
  409. `PUT` is accepted as a synonym for the routes named in the requirements.
- **State changes are explicit action endpoints**, not `PATCH status`:
  `POST …/tasks/{id}/start`, `…/submit`, `…/verify`, `…/reject`,
  `POST …/purchase-orders/{id}/approve`. Each action runs one use case
  with its checks.
- `Idempotency-Key: <uuid>` is accepted on every POST and required from
  mobile. A repeat within 48 h returns the original response.
- **`DELETE` archives** master data (soft delete). On ledgers, trace and
  audit data it returns **405 `append_only`**. Those are corrected with
  `POST …/corrections`.

### Rate limits (defaults, configurable)
| Group | Limit |
|---|---|
| `/auth/login`, `/auth/mfa/*` | 5 / min per IP + account lockout |
| Authenticated API | 120 / min per user; sync push 30 / min per device |
| `/public/trace/*` | 60 / min per IP |
| Exports | 10 / hour per farm |

## 2. Resource route map (overview)

```
/api/v1/auth/{login,refresh,logout,mfa/challenge,mfa/setup,password/forgot,password/reset}
/api/v1/me                              profile
/api/v1/me/workspaces                   farms + portals the user can enter, with permissions
/api/v1/me/devices                      register / revoke devices

/api/v1/admin/dashboard
/api/v1/admin/farms[/{id}/{approve,suspend,unsuspend,reset-owner-password}]
/api/v1/admin/{organizations,subscriptions,plans,subscription-payments}
/api/v1/admin/catalog/{crops,breeds,units,inventory-categories,activity-types}
/api/v1/admin/{integrations,settings,logs,backups,health,support-tickets}

/api/v1/farms                           GET list mine · POST create (→ pending approval)
/api/v1/farms/{farm}                    GET · PUT/PATCH · DELETE (owner; archive + retention)
/api/v1/farms/{farm}/dashboards/{dashboard}[/widgets/{widget}]
/api/v1/farms/{farm}/{members,roles,permissions}
/api/v1/farms/{farm}/{blocks,sections,plots,locations}
/api/v1/farms/{farm}/{crops,seasons,crop-plans,crop-cycles,crop-operations,crop-observations,crop-treatments,harvests}
/api/v1/farms/{farm}/{animals,animal-groups,breeding,vaccinations,feedings,weights,production,movements,mortalities}
/api/v1/farms/{farm}/{workers,activities,tasks,attendance,leave}
/api/v1/farms/{farm}/{inventory-items,stock-lots,stock-transactions,stock-transfers,stock-adjustments,inventory-requests}
/api/v1/farms/{farm}/{suppliers,purchase-requests,purchase-orders,deliveries,supplier-invoices}
/api/v1/farms/{farm}/{accounts,expenses,income,budgets,invoices,payments,payroll-runs}
/api/v1/farms/{farm}/{products,customers,sales-orders}
/api/v1/farms/{farm}/{assets,maintenance}
/api/v1/farms/{farm}/traceability/...  (see §4)
/api/v1/farms/{farm}/{media,documents,notifications,reports,exports,map-layers}
/api/v1/farms/{farm}/sync/{push,pull,conflicts}

/api/v1/supplier/{dashboard,purchase-orders,deliveries,invoices,payments,profile}
/api/v1/customer/{dashboard,products,orders,invoices,payments,deliveries,history,profile}
/api/v1/public/trace/{code}             QR traceability (approved fields only)
/api/v1/public/marketplace/products     published products
```

## 3. Dashboard contracts

All dashboards share one response shape. The server decides which KPIs and
widgets appear, based on the member's permissions.

```
GET /api/v1/farms/{farm}/dashboards/{dashboard}?period=today|7d|30d|season|ytd&from=&to=
    dashboard ∈ owner | manager | agronomist | livestock | store | accountant | worker
GET /api/v1/farms/{farm}/dashboards/{dashboard}/widgets/{widget}?period=…
GET /api/v1/admin/dashboard
GET /api/v1/supplier/dashboard
GET /api/v1/customer/dashboard
```

Returns **403** if the member has no role that grants the requested
dashboard.

### Common shape

```json
{
  "data": {
    "dashboard": "owner",
    "farm": { "id": "0192…", "name": "AGG Farm", "currency": "UGX", "timezone": "Africa/Kampala" },
    "period": { "key": "30d", "from": "2026-08-25", "to": "2026-09-23" },
    "generated_at": "2026-09-23T08:15:00Z",
    "kpis": [
      {
        "key": "finance.net_profit",
        "label": "Net profit",
        "value": { "amount": "18250000.00", "currency": "UGX" },
        "format": "money",
        "delta": { "value": 0.124, "direction": "up", "vs": "previous_period" },
        "drilldown": "/farms/0192…/reports/profit-and-loss?from=2026-08-25"
      }
    ],
    "widgets": [
      { "key": "approvals", "type": "action_list", "inline": true,  "data": { "items": [ ... ], "total": 7 } },
      { "key": "revenue_vs_expenses", "type": "chart", "inline": false,
        "href": "/api/v1/farms/0192…/dashboards/owner/widgets/revenue_vs_expenses?period=30d" }
    ],
    "quick_actions": [
      { "key": "approve_expense", "label": "Approve expense", "target": "/farms/0192…/approvals?type=expense" }
    ],
    "alerts": [
      { "severity": "high", "type": "disease_outbreak", "message": "Fall armyworm reported on Plot B-3", "href": "…" }
    ]
  }
}
```

- `kpis[].format` ∈ `number | money | percent | quantity | duration`.
  Money KPIs are **omitted** (not zeroed) without `finance.values.view`.
- `widgets[].inline = true` means the data is included. `false` means the
  client fetches `href` separately, for charts and maps.
- Chart widget payload:

```json
{
  "data": {
    "key": "revenue_vs_expenses",
    "type": "chart",
    "chart": "bar_line",
    "x": { "type": "month", "values": ["2025-10", "2025-11", "…"] },
    "series": [
      { "key": "revenue",  "label": "Revenue",  "unit": "UGX", "values": ["12000000.00", "…"] },
      { "key": "expenses", "label": "Expenses", "unit": "UGX", "values": ["8000000.00", "…"] }
    ]
  }
}
```

### Per-dashboard KPI and widget keys

| Dashboard | KPI keys | Widget keys |
|---|---|---|
| `admin` | `farms.registered`, `farms.pending`, `farms.active`, `farms.suspended`, `subs.active`, `platform.mrr`, `platform.revenue`, `users.total`, `health.api`, `health.db`, `storage.used`, `backup.last`, `errors.24h`, `tickets.open` | `farm_approvals`, `failed_payments`, `expiring_subscriptions`, `signups_trend`, `revenue_trend`, `error_rate` |
| `owner` | `farm.area`, `finance.revenue`, `finance.expenses`, `finance.net_profit`, `crop.performance`, `livestock.production`, `inventory.value`, `workers.productivity`, `finance.receivables`, `finance.payables`, `approvals.pending` | `approvals`, `todays_activities`, `low_stock`, `upcoming_harvests`, `payments_due`, `disease_alerts`, `unverified_activities`, `trace_alerts`, `revenue_vs_expenses`, `profit_trend`, `crop_yield`, `cost_yield_per_acre`, `livestock_production`, `farm_map`, `my_farms` |
| `manager` | `tasks.today`, `tasks.completed`, `tasks.pending`, `tasks.overdue`, `workers.present`, `workers.absent`, `activities.active`, `inventory.requests`, `approvals.pending` | `schedule`, `verification_queue`, `leave_requests`, `worker_activity`, `crop_livestock_activities`, `overdue_tasks`, `alerts`, `recent_activity` |
| `agronomist` | `crop.active_cycles`, `crop.planted_area`, `crop.near_harvest`, `crop.expected_yield`, `crop.actual_yield`, `crop.yield_per_ha`, `crop.health_score`, `crop.incidents_open`, `crop.treatments_active` | `crop_calendar`, `crop_health_map`, `pest_disease_alerts`, `operations_due`, `expected_vs_actual_yield`, `weather` |
| `livestock` | `livestock.total`, `livestock.new`, `livestock.pregnant`, `livestock.vaccinations_due`, `livestock.under_treatment`, `livestock.mortality_rate`, `livestock.production`, `livestock.adg`, `livestock.sold` | `animal_health`, `vaccinations_due`, `breeding_calendar`, `production_trend`, `movements` |
| `store` | `inventory.items`, `inventory.low`, `inventory.out`, `inventory.value`, `inventory.received_today`, `inventory.issued_today`, `inventory.requests_pending`, `deliveries.expected` | `inventory_status`, `recent_movements`, `pending_requests`, `deliveries_to_receive`, `expiring_lots` |
| `accountant` | `finance.revenue`, `finance.expenses`, `finance.net_profit`, `finance.cash_balance`, `finance.receivables`, `finance.payables`, `payroll.current`, `budget.total`, `budget.variance` | `income_vs_expenses`, `recent_transactions`, `supplier_invoices_due`, `customer_invoices_overdue`, `payroll_pending`, `budget_vs_actual`, `cash_flow_forecast` |
| `worker` | `tasks.today`, `tasks.done_today`, `attendance.status` | `today_tasks`, `notifications`, `attendance_week`, `sync_status` (client-side) |
| `supplier` | `po.new`, `po.pending`, `deliveries.in_progress`, `orders.completed`, `invoices.outstanding`, `payments.received` | `purchase_orders`, `delivery_status` |
| `customer` | `products.available`, `orders.active`, `orders.delivered`, `payments.pending`, `purchases.total` | `marketplace`, `order_tracking` |

The Field Worker "dashboard" is also available as
`GET /api/v1/farms/{farm}/me/today`, which returns tasks, attendance and
notifications in one call for the mobile home screen. It works from the local
database when offline.

## 4. Traceability endpoints

The requirements list `/api/v1/traceability/...`. Because every internal
traceability read is farm data, these routes live under the farm prefix. The
public QR route is separate:

| Method & path | Purpose | Who |
|---|---|---|
| `GET /farms/{farm}/traceability/batches` | List / search batches | trace view |
| `POST /farms/{farm}/traceability/batches` | Create manual batch (processing, packaging) | trace create |
| `GET /farms/{farm}/traceability/batches/{id}` | Batch details | trace view |
| `GET /farms/{farm}/traceability/batches/{id}/journey?direction=backward\|forward\|both&depth=…` | Full graph: upstream sources and downstream destinations | trace view |
| `GET /farms/{farm}/traceability/batches/{id}/events` | Append-only event timeline | trace view |
| `GET /farms/{farm}/traceability/batches/{id}/workers` | Workers involved (with activities) | trace view |
| `GET /farms/{farm}/traceability/batches/{id}/inputs` | Seeds, fertilizers, chemicals, feeds, drugs applied (lot numbers, withholding periods) | trace view |
| `GET /farms/{farm}/traceability/batches/{id}/sales` | Orders and customers the batch went to ($ only with permission) | trace view |
| `GET /farms/{farm}/traceability/batches/{id}/locations` | Geographic trail (plots, stores, GPS) | trace view |
| `POST /farms/{farm}/traceability/batches/{id}/split` · `/merge` · `/process` · `/package` | Transform batches (creates links and events) | trace create |
| `POST /farms/{farm}/traceability/events/{id}/corrections` | Correct an event (new event referencing the old one) | trace create |
| `POST /farms/{farm}/traceability/batches/{id}/approvals` | Approve public fields for QR | trace publish |
| `POST /farms/{farm}/traceability/batches/{id}/qr-codes` | Issue a QR code | trace publish |
| `POST /farms/{farm}/traceability/qr-codes/{id}/revoke` | Revoke a QR code | trace publish |
| `GET /public/trace/{code}` (alias `GET /traceability/qr/{code}`) | Public journey: only approved fields | anyone |

Journey response (abridged):

```json
{
  "data": {
    "batch": { "id": "…", "batch_code": "SFM-7KQ2-9XA4", "kind": "packaged", "quantity": { "value": "500.000", "unit": "kg" } },
    "backward": {
      "nodes": [
        { "id": "…", "batch_code": "SFM-…", "kind": "harvest", "plot": { "code": "B-3" }, "date": "2026-08-30" },
        { "id": "…", "batch_code": "SFM-…", "kind": "seed_lot", "supplier": { "name": "Seed Co Ltd" }, "lot_number": "SC-2291" }
      ],
      "edges": [ { "from": "…", "to": "…", "link_type": "process", "quantity": "520.000" } ]
    },
    "forward": { "nodes": [ ... ], "edges": [ ... ] },
    "evidence_summary": { "events": 42, "photos": 18, "gps_points": 35, "documents": 3 }
  }
}
```

Public QR response: contains only fields listed in the batch's
`trace_approvals.public_fields`, for example product, farm name, district,
production and harvest dates, batch code, processing steps and
certifications. **Never** costs, prices, margins, worker names, stock or GPS
coordinates more precise than the district, unless explicitly approved.

## 5. Sync endpoints (mobile)

Details are in [08](08-offline-sync.md).

```
POST /api/v1/farms/{farm}/sync/push
{
  "device_id": "…",
  "mutations": [
    { "mutation_id": "0192…", "entity": "worker_task_logs", "op": "insert",
      "id": "0192…", "base_version": null, "occurred_at": "2026-09-23T07:02:11Z",
      "data": { "task_id": "…", "event": "start", "point": { "lat": 0.31, "lng": 32.58, "accuracy_m": 8 } } }
  ]
}
→ 200 { "data": { "results": [ { "mutation_id": "0192…", "status": "applied|duplicate|conflict|rejected", "version": 1, "error": null } ] } }

GET /api/v1/farms/{farm}/sync/pull?cursor=<farm_seq>&entities=tasks,plots,items&limit=500
→ 200 { "data": { "changes": [ { "entity": "worker_tasks", "op": "upsert", "id": "…", "version": 3, "data": { … } } ],
                  "next_cursor": "184223", "has_more": false } }

GET  /api/v1/farms/{farm}/sync/conflicts
POST /api/v1/farms/{farm}/sync/conflicts/{id}/resolve   { "resolution": "keep_server|keep_client|merge", "data": { … } }
POST /api/v1/farms/{farm}/media/uploads                 (resumable, checksum; returns media_id)
```
