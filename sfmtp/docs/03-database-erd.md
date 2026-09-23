# 03 — Database Design & ERD

PostgreSQL 16 (with PostGIS) is the primary engine. The MySQL notes are in
[02 §6](02-tenant-isolation.md#6-mysql-compatibility).

## 1. Conventions (apply to every table unless stated)

| Convention | Rule | Why |
|---|---|---|
| Primary keys | `id uuid` (UUIDv7) | The mobile app creates records offline; time-ordered UUIDs keep B-tree indexes efficient |
| Tenant column | `farm_id uuid not null` on every farm-owned table, first column of most indexes | Isolation plus fast per-farm scans |
| Composite uniqueness | `unique (farm_id, id)` on every farm table | Lets child tables use composite FKs `(farm_id, parent_id) → parent(farm_id, id)` so cross-farm references are impossible |
| Business codes | `unique (farm_id, code)` for farm codes; globally unique for `batch_code` and `qr_code` | Readable IDs that are public where required |
| Audit columns | `created_at`, `updated_at`, `created_by`, `updated_by` | Who and when |
| Sync columns | `version integer not null default 1` (optimistic locking), `client_created_at`, `device_id` on mobile-writable tables | Offline conflict detection |
| Soft delete | `deleted_at` on master data only (plots, items, suppliers…). **Never** on trace, audit, stock-ledger or finance-ledger tables; those are append-only | Keep history |
| Money | `numeric(14,2)` plus a `currency char(3)` column. Farm default currency in `farm_settings` | No floating-point money |
| Quantities | `numeric(14,3)` plus `unit_id` → `units` | Kg, litres, heads, bags… |
| Enums | `text` + `CHECK (col IN (…))`, mirrored by PHP backed enums | Portable across Postgres and MySQL |
| Status history | State changes are written to `<entity>_status_history` or trace events, not just overwritten | Auditability |
| Geo | GPS points as `latitude` / `longitude` decimals plus `gps_accuracy_m`; boundaries as GeoJSON with derived area, centroid and bounding box ([ADR-0009](adr/0009-geojson-geometry.md)). The diagrams below still say `geography` for brevity | Maps and geo-traceability on both engines |
| Large append-only tables | Range-partitioned by month: `trace_events`, `audit_logs`, `worker_gps`, `notifications`, `system_logs` | Performance and retention |

Diagrams show the key columns and relationships only. The complete column
lists are produced as migrations in Phase 1 and later phases.

## 2. Platform & identity

```mermaid
erDiagram
    users {
        uuid id PK
        text user_type "platform_admin | member | party"
        text name
        citext email UK
        text phone UK
        text password_hash
        text status "active | locked | disabled"
        timestamptz mfa_enabled_at
    }
    user_mfa_factors {
        uuid id PK
        uuid user_id FK
        text type "totp | sms"
        text secret_encrypted
    }
    user_devices {
        uuid id PK
        uuid user_id FK
        text platform
        text push_token
        timestamptz last_sync_at
        timestamptz revoked_at
    }
    refresh_tokens {
        uuid id PK
        uuid user_id FK
        uuid device_id FK
        uuid family_id
        text token_hash
        timestamptz expires_at
        timestamptz revoked_at
    }
    platform_roles {
        uuid id PK
        text key UK "super_admin | support | billing"
    }
    platform_user_roles {
        uuid user_id FK
        uuid platform_role_id FK
    }
    parties {
        uuid id PK
        text name
        text party_kind "supplier | customer | both"
        text status
    }
    party_users {
        uuid party_id FK
        uuid user_id FK
    }
    organizations {
        uuid id PK
        text name
        uuid owner_user_id FK
        text status
    }
    subscription_plans {
        uuid id PK
        text name
        numeric price
        char currency
        text billing_period "monthly | yearly"
        int max_farms
        int max_users
        bigint max_storage_mb
        jsonb features
        bool is_active
    }
    subscriptions {
        uuid id PK
        uuid organization_id FK
        uuid plan_id FK
        text status "trialing | active | grace | suspended | cancelled"
        date starts_on
        date expires_on
        date grace_until
    }
    subscription_payments {
        uuid id PK
        uuid subscription_id FK
        numeric amount
        text provider
        text provider_ref UK
        text status
    }
    platform_settings {
        text key PK
        jsonb value
    }
    integration_providers {
        uuid id PK
        text kind "sms | email | maps | weather | payment | push"
        text provider
        jsonb config_encrypted
        bool is_default
    }
    support_tickets {
        uuid id PK
        uuid organization_id FK
        uuid farm_id FK
        uuid opened_by FK
        text status
    }
    support_access_grants {
        uuid id PK
        uuid farm_id FK
        uuid ticket_id FK
        uuid granted_by FK
        timestamptz expires_at
    }
    system_logs {
        uuid id PK
        text level
        text channel
        jsonb context
        timestamptz created_at
    }

    users ||--o{ user_mfa_factors : has
    users ||--o{ user_devices : has
    users ||--o{ refresh_tokens : has
    users ||--o{ platform_user_roles : "admin only"
    platform_roles ||--o{ platform_user_roles : ""
    users ||--o{ organizations : owns
    parties ||--o{ party_users : ""
    users ||--o{ party_users : "portal login"
    organizations ||--o{ subscriptions : has
    subscription_plans ||--o{ subscriptions : ""
    subscriptions ||--o{ subscription_payments : ""
    organizations ||--o{ support_tickets : raises
    support_tickets ||--o{ support_access_grants : ""
```

**Global catalogues** (platform-owned, read-only for farms): `global_crops`,
`global_crop_varieties`, `global_animal_species`, `global_animal_breeds`,
`units`, `global_inventory_categories`, `global_activity_types`. Farms
reference them, and can add farm-local entries in `crops`, `animal_breeds`
etc., which optionally point to the global entry.

> The requirements list a platform `payments` table and a finance `payments`
> table. Here the platform one is **`subscription_payments`**, so the names
> don't clash and platform data is never mixed with farm data ([ADR-0004](adr/0004-table-naming.md)).

## 3. Farm, structure & membership

```mermaid
erDiagram
    farms {
        uuid id PK
        uuid organization_id FK
        text code UK
        text name
        text status "pending | active | suspended | closed"
        geography location
        text district
        text village
        numeric size_ha
        text timezone
        char currency
    }
    farm_settings {
        uuid farm_id PK
        jsonb settings
    }
    farm_users {
        uuid id PK
        uuid farm_id FK
        uuid user_id FK
        text status "invited | active | suspended"
        bool is_owner
    }
    farm_roles {
        uuid id PK
        uuid farm_id FK
        text key "owner | manager | agronomist | ..."
        text name
        bool is_system_template
    }
    permissions {
        uuid id PK
        text key UK "crops.harvest.create"
        text module
        text scope_support "all | assigned | own"
    }
    farm_role_permissions {
        uuid farm_role_id FK
        uuid permission_id FK
        text scope "all | assigned | own"
    }
    farm_user_roles {
        uuid farm_user_id FK
        uuid farm_role_id FK
    }
    farm_blocks {
        uuid id PK
        uuid farm_id FK
        text code
        text name
        geography boundary
        numeric area_ha
    }
    farm_sections {
        uuid id PK
        uuid farm_id FK
        uuid block_id FK
        text code
        geography boundary
    }
    farm_plots {
        uuid id PK
        uuid farm_id FK
        uuid section_id FK
        text code
        geography boundary
        numeric area_ha
        jsonb soil_profile
        text land_use "crop | pasture | fallow | other"
    }
    farm_locations {
        uuid id PK
        uuid farm_id FK
        text kind "store | building | paddock | housing | water | gate"
        uuid plot_id FK
        geography point
    }

    farms ||--|| farm_settings : ""
    farms ||--o{ farm_users : members
    farms ||--o{ farm_roles : defines
    farm_roles ||--o{ farm_role_permissions : grants
    permissions ||--o{ farm_role_permissions : ""
    farm_users ||--o{ farm_user_roles : holds
    farm_roles ||--o{ farm_user_roles : ""
    farms ||--o{ farm_blocks : ""
    farm_blocks ||--o{ farm_sections : ""
    farm_sections ||--o{ farm_plots : ""
    farms ||--o{ farm_locations : ""
```

Constraints

- `farm_users unique (farm_id, user_id)`. A partial unique index allows only
  one `is_owner = true` per farm.
- A trigger rejects `farm_users` rows whose user has
  `user_type = 'platform_admin'` ([02 §5](02-tenant-isolation.md#5-system-administrator-boundary)).
- `farm_plots.boundary` should lie inside its section's boundary, and plots
  should not overlap. The service checks both and returns warnings, not
  failures, because GPS data is imprecise ([ADR-0009](adr/0009-geojson-geometry.md)).
- `farm_sections.block_id`, `farm_plots.section_id` (nullable: a plot can sit
  directly under the farm) and `farm_locations.plot_id` are composite
  `(farm_id, …)` foreign keys. Structure rows are archived (`deleted_at`),
  and `code` is unique per farm including archived rows.
- `farm_invitations` holds a SHA-256 hash of the emailed token, the invited
  email, expiry (7 days) and accepted / revoked markers;
  `farm_invitation_roles` links it to `farm_roles` by composite key.

## 4. Crops

```mermaid
erDiagram
    crops {
        uuid id PK
        uuid farm_id FK
        uuid global_crop_id FK
        text name
        text variety
    }
    crop_seasons {
        uuid id PK
        uuid farm_id FK
        text name
        date starts_on
        date ends_on
    }
    crop_plans {
        uuid id PK
        uuid farm_id FK
        uuid season_id FK
        uuid crop_id FK
        numeric planned_area_ha
        numeric expected_yield
        numeric budget_amount
        text status "draft | approved | active | closed"
    }
    crop_cycles {
        uuid id PK
        uuid farm_id FK
        uuid plan_id FK
        uuid plot_id FK
        uuid crop_id FK
        text stage "nursery | planted | growing | harvesting | closed"
        date planted_on
        date expected_harvest_on
        numeric area_ha
    }
    crop_nursery_batches {
        uuid id PK
        uuid farm_id FK
        uuid cycle_id FK
        uuid seed_lot_id FK
        int seeds_sown
        int germinated
        int transplanted
    }
    crop_operations {
        uuid id PK
        uuid farm_id FK
        uuid cycle_id FK
        uuid activity_id FK
        text type "planting | weeding | irrigation | spraying | fertilizing | scouting | other"
        timestamptz occurred_at
        numeric cost_amount
    }
    crop_operation_inputs {
        uuid id PK
        uuid farm_id FK
        uuid operation_id FK
        uuid item_id FK
        uuid stock_lot_id FK
        numeric quantity
        uuid unit_id FK
    }
    crop_observations {
        uuid id PK
        uuid farm_id FK
        uuid cycle_id FK
        text kind "pest | disease | growth | soil | weather"
        text severity
        geography point
    }
    crop_treatments {
        uuid id PK
        uuid farm_id FK
        uuid observation_id FK
        uuid operation_id FK
        int withholding_days
    }
    crop_harvests {
        uuid id PK
        uuid farm_id FK
        uuid cycle_id FK
        date harvested_on
        numeric quantity
        uuid unit_id FK
        text quality_grade
    }
    crop_batches {
        uuid id PK
        uuid farm_id FK
        uuid harvest_id FK
        uuid trace_batch_id FK
        numeric quantity
    }

    crop_seasons ||--o{ crop_plans : ""
    crops ||--o{ crop_plans : ""
    crop_plans ||--o{ crop_cycles : ""
    crop_cycles ||--o{ crop_nursery_batches : ""
    crop_cycles ||--o{ crop_operations : ""
    crop_operations ||--o{ crop_operation_inputs : consumes
    crop_cycles ||--o{ crop_observations : ""
    crop_observations ||--o{ crop_treatments : ""
    crop_cycles ||--o{ crop_harvests : ""
    crop_harvests ||--o{ crop_batches : ""
```

`crop_cycles.plot_id` references `farm_plots` through `(farm_id, plot_id)`.
A partial unique index prevents two *active* cycles overlapping on the same
plot, unless intercropping is enabled for the farm.

## 5. Livestock

```mermaid
erDiagram
    animal_breeds {
        uuid id PK
        uuid farm_id FK
        uuid global_breed_id FK
        text species
        text name
    }
    animals {
        uuid id PK
        uuid farm_id FK
        text animal_code "farm-unique ID"
        text tag_number
        text rfid
        uuid breed_id FK
        text sex
        date birth_date
        uuid dam_id FK
        uuid sire_id FK
        uuid current_location_id FK
        text status "active | sold | dead | culled | transferred"
    }
    animal_health_records {
        uuid id PK
        uuid farm_id FK
        uuid animal_id FK
        text diagnosis
        int withholding_days
    }
    animal_vaccinations {
        uuid id PK
        uuid farm_id FK
        uuid animal_id FK
        uuid item_id FK
        date given_on
        date next_due_on
    }
    animal_feed_records {
        uuid id PK
        uuid farm_id FK
        uuid animal_id FK
        uuid group_id FK
        uuid item_id FK
        numeric quantity
    }
    animal_weight_records {
        uuid id PK
        uuid farm_id FK
        uuid animal_id FK
        numeric weight_kg
        date weighed_on
    }
    animal_breeding {
        uuid id PK
        uuid farm_id FK
        uuid dam_id FK
        uuid sire_id FK
        text method "natural | ai"
        date served_on
        date expected_due_on
        text outcome
    }
    animal_production {
        uuid id PK
        uuid farm_id FK
        uuid animal_id FK
        text product "milk | eggs | wool | other"
        numeric quantity
        date produced_on
        uuid trace_batch_id FK
    }
    animal_movements {
        uuid id PK
        uuid farm_id FK
        uuid animal_id FK
        uuid from_location_id FK
        uuid to_location_id FK
        timestamptz moved_at
    }
    animal_mortalities {
        uuid id PK
        uuid farm_id FK
        uuid animal_id FK
        date died_on
        text cause
    }
    animal_sales {
        uuid id PK
        uuid farm_id FK
        uuid animal_id FK
        uuid sales_order_id FK
    }

    animal_breeds ||--o{ animals : ""
    animals ||--o{ animal_health_records : ""
    animals ||--o{ animal_vaccinations : ""
    animals ||--o{ animal_feed_records : ""
    animals ||--o{ animal_weight_records : ""
    animals ||--o{ animal_breeding : "as dam"
    animals ||--o{ animal_production : ""
    animals ||--o{ animal_movements : ""
    animals ||--o| animal_mortalities : ""
    animals ||--o| animal_sales : ""
```

- `unique (farm_id, animal_code)`. Every animal also receives a global
  `trace_batch` of type `animal`, which gives it a public-safe unique ID.
- `dam_id` and `sire_id` are self-references within the same farm. Animals
  bought from outside the farm record their parentage as text.
- `animal_groups` (herds/flocks) and `animal_group_members` allow group
  feeding and group treatment for poultry and small stock.

## 6. Workers & activities

```mermaid
erDiagram
    workers {
        uuid id PK
        uuid farm_id FK
        uuid farm_user_id FK "nullable: not every worker logs in"
        text worker_code
        text employment_type "permanent | casual | contract"
        numeric daily_rate
    }
    activities {
        uuid id PK
        uuid farm_id FK
        uuid activity_type_id FK
        text subject_type "crop_cycle | animal | asset | plot | general"
        uuid subject_id
        uuid plot_id FK
        text status "pending | in_progress | submitted | verified | rejected"
        timestamptz occurred_at
    }
    worker_tasks {
        uuid id PK
        uuid farm_id FK
        uuid activity_id FK
        uuid assigned_worker_id FK
        uuid assigned_by FK
        date due_on
        text status "assigned | started | paused | submitted | verified | rejected | cancelled"
    }
    worker_task_logs {
        uuid id PK
        uuid farm_id FK
        uuid task_id FK
        text event "start | pause | resume | submit | verify | reject"
        timestamptz occurred_at
        geography point
        numeric quantity
        text note
    }
    worker_attendance {
        uuid id PK
        uuid farm_id FK
        uuid worker_id FK
        date work_date
        timestamptz check_in_at
        geography check_in_point
        uuid check_in_photo_id FK
        timestamptz check_out_at
    }
    worker_gps {
        uuid id PK
        uuid farm_id FK
        uuid worker_id FK
        timestamptz recorded_at
        geography point
        numeric accuracy_m
    }
    worker_photos {
        uuid id PK
        uuid farm_id FK
        uuid task_id FK
        uuid media_id FK
        geography point
    }
    worker_leave {
        uuid id PK
        uuid farm_id FK
        uuid worker_id FK
        date from_on
        date to_on
        text status
    }

    workers ||--o{ worker_tasks : "assigned"
    activities ||--o{ worker_tasks : ""
    worker_tasks ||--o{ worker_task_logs : ""
    worker_tasks ||--o{ worker_photos : ""
    workers ||--o{ worker_attendance : ""
    workers ||--o{ worker_gps : ""
    workers ||--o{ worker_leave : ""
```

`activities` is the **single, generic record of work done**. Crop operations,
animal treatments and asset maintenance each point to an activity, which
gives one timeline, one verification flow and one activity map across all
modules. Payroll lives in Finance (`payroll_runs`, `payroll_lines`), fed by
attendance and verified tasks.

## 7. Inventory & procurement

```mermaid
erDiagram
    inventory_categories {
        uuid id PK
        uuid farm_id FK
        text kind "seed | fertilizer | feed | chemical | drug | tool | equipment | harvest | packaging"
    }
    inventory_items {
        uuid id PK
        uuid farm_id FK
        uuid category_id FK
        text sku
        uuid unit_id FK
        numeric reorder_level
        bool tracks_lots
        bool tracks_expiry
    }
    stock_lots {
        uuid id PK
        uuid farm_id FK
        uuid item_id FK
        text lot_number
        date expires_on
        uuid supplier_id FK
        uuid trace_batch_id FK
        numeric unit_cost
    }
    stock_balances {
        uuid farm_id FK
        uuid item_id FK
        uuid location_id FK
        uuid lot_id FK
        numeric quantity
    }
    stock_transactions {
        uuid id PK
        uuid farm_id FK
        uuid item_id FK
        uuid lot_id FK
        uuid location_id FK
        text type "in | out | transfer_in | transfer_out | adjustment | issue | return"
        numeric quantity "signed"
        numeric unit_cost
        text source_type
        uuid source_id
        timestamptz occurred_at
    }
    stock_transfers {
        uuid id PK
        uuid farm_id FK
        uuid from_location_id FK
        uuid to_location_id FK
        text status
    }
    stock_adjustments {
        uuid id PK
        uuid farm_id FK
        text reason
        uuid approved_by FK
    }
    inventory_requests {
        uuid id PK
        uuid farm_id FK
        uuid requested_by FK
        uuid task_id FK
        text status
    }
    inventory_alerts {
        uuid id PK
        uuid farm_id FK
        uuid item_id FK
        text kind "low_stock | out_of_stock | expiring | variance"
    }
    suppliers {
        uuid id PK
        uuid farm_id FK
        uuid party_id FK "portal account, nullable"
        text name
    }
    purchase_requests {
        uuid id PK
        uuid farm_id FK
        uuid requested_by FK
        text status "draft | submitted | approved | rejected | ordered"
    }
    purchase_orders {
        uuid id PK
        uuid farm_id FK
        uuid supplier_id FK
        uuid purchase_request_id FK
        text po_number
        text status "draft | approved | sent | accepted | rejected | partially_delivered | delivered | closed | cancelled"
        numeric total_amount
    }
    purchase_order_items {
        uuid id PK
        uuid farm_id FK
        uuid purchase_order_id FK
        uuid item_id FK
        numeric quantity
        numeric unit_price
        numeric confirmed_quantity
    }
    deliveries {
        uuid id PK
        uuid farm_id FK
        uuid purchase_order_id FK
        text status "dispatched | received | disputed"
        uuid received_by FK
        uuid delivery_note_media_id FK
    }
    supplier_invoices {
        uuid id PK
        uuid farm_id FK
        uuid supplier_id FK
        uuid purchase_order_id FK
        numeric amount
        text status
    }

    inventory_categories ||--o{ inventory_items : ""
    inventory_items ||--o{ stock_lots : ""
    inventory_items ||--o{ stock_transactions : ""
    stock_lots ||--o{ stock_transactions : ""
    suppliers ||--o{ purchase_orders : ""
    purchase_requests ||--o{ purchase_orders : ""
    purchase_orders ||--o{ purchase_order_items : ""
    purchase_orders ||--o{ deliveries : ""
    purchase_orders ||--o{ supplier_invoices : ""
    suppliers ||--o{ stock_lots : supplied
```

- **`stock_transactions` is the append-only ledger.** `stock_balances` is a
  derived projection, updated inside the same transaction with a row lock.
  Adjustments are new transactions; ledger rows are never edited.
- A `CHECK` constraint plus a service rule keep `stock_balances.quantity >= 0`,
  unless the farm allows negative stock.
- Valuation uses **weighted average cost** per item and location. FIFO by lot
  is optional, per farm.

## 8. Finance & sales

```mermaid
erDiagram
    accounts {
        uuid id PK
        uuid farm_id FK
        text code
        text name
        text type "asset | liability | equity | income | expense"
    }
    transactions {
        uuid id PK
        uuid farm_id FK
        date posted_on
        text source_type
        uuid source_id
        text memo
    }
    transaction_lines {
        uuid id PK
        uuid farm_id FK
        uuid transaction_id FK
        uuid account_id FK
        numeric debit
        numeric credit
        text cost_center_type "crop_cycle | animal_group | asset | general"
        uuid cost_center_id
    }
    expenses {
        uuid id PK
        uuid farm_id FK
        uuid category_id FK
        numeric amount
        text status "draft | pending_approval | approved | paid"
    }
    income {
        uuid id PK
        uuid farm_id FK
        uuid category_id FK
        numeric amount
    }
    budgets {
        uuid id PK
        uuid farm_id FK
        uuid season_id FK
        text scope_type
        uuid scope_id
    }
    budget_lines {
        uuid id PK
        uuid budget_id FK
        uuid account_id FK
        numeric amount
    }
    customers {
        uuid id PK
        uuid farm_id FK
        uuid party_id FK
        text name
    }
    products {
        uuid id PK
        uuid farm_id FK
        text name
        uuid unit_id FK
        numeric list_price
        bool is_published
    }
    sales_orders {
        uuid id PK
        uuid farm_id FK
        uuid customer_id FK
        text status "requested | approved | invoiced | dispatched | delivered | cancelled"
        numeric total_amount
    }
    sales_order_lines {
        uuid id PK
        uuid farm_id FK
        uuid sales_order_id FK
        uuid product_id FK
        uuid trace_batch_id FK
        numeric quantity
        numeric unit_price
    }
    invoices {
        uuid id PK
        uuid farm_id FK
        uuid customer_id FK
        uuid sales_order_id FK
        text invoice_number
        numeric amount
        text status
    }
    payments {
        uuid id PK
        uuid farm_id FK
        text direction "in | out"
        text payable_type "invoice | supplier_invoice | payroll_run | expense"
        uuid payable_id
        numeric amount
        text method
        text provider_ref
    }
    payroll_runs {
        uuid id PK
        uuid farm_id FK
        date period_start
        date period_end
        text status "draft | approved | paid"
    }
    payroll_lines {
        uuid id PK
        uuid farm_id FK
        uuid payroll_run_id FK
        uuid worker_id FK
        numeric gross
        numeric deductions
        numeric net
    }

    transactions ||--|{ transaction_lines : ""
    accounts ||--o{ transaction_lines : ""
    budgets ||--o{ budget_lines : ""
    customers ||--o{ sales_orders : ""
    sales_orders ||--o{ sales_order_lines : ""
    products ||--o{ sales_order_lines : ""
    sales_orders ||--o{ invoices : ""
    invoices ||--o{ payments : ""
    payroll_runs ||--o{ payroll_lines : ""
```

- **Double-entry core.** Every expense, income, invoice, payment, payroll run
  and stock valuation change posts a balanced `transaction` (a deferred
  constraint trigger checks sum(debit) = sum(credit)). Profit & Loss, cash
  flow, and cost per crop or per acre all come from the same ledger, using
  cost centres such as `crop_cycle` and `animal_group`.
- The simple `expenses` / `income` tables are the user-facing documents;
  posting creates the ledger entries. Accountants never have to write
  journal entries for day-to-day work.
- Posted transactions are immutable. Corrections are made with reversing
  entries.

## 9. Assets, media, notifications

```mermaid
erDiagram
    assets {
        uuid id PK
        uuid farm_id FK
        text kind "vehicle | machinery | building | irrigation | equipment"
        text name
        date purchased_on
        numeric purchase_value
        text status
        uuid location_id FK
        uuid assigned_user_id FK
    }
    asset_maintenance_schedules {
        uuid id PK
        uuid farm_id FK
        uuid asset_id FK
        text interval_rule
        date next_due_on
    }
    asset_maintenance_records {
        uuid id PK
        uuid farm_id FK
        uuid asset_id FK
        uuid activity_id FK
        numeric cost_amount
    }
    media {
        uuid id PK
        uuid farm_id FK
        text storage_key
        text mime_type
        bigint size_bytes
        text sha256
        geography point
        timestamptz captured_at
        text scan_status
    }
    media_links {
        uuid media_id FK
        text subject_type
        uuid subject_id
        text role "photo | receipt | delivery_note | invoice | certificate | contract"
    }
    notifications {
        uuid id PK
        uuid farm_id FK
        uuid user_id FK
        text type
        jsonb data
        timestamptz read_at
    }
    notification_preferences {
        uuid user_id FK
        text type
        text channels
    }

    assets ||--o{ asset_maintenance_schedules : ""
    assets ||--o{ asset_maintenance_records : ""
    media ||--o{ media_links : ""
```

## 10. Traceability & audit

```mermaid
erDiagram
    trace_batches {
        uuid id PK
        uuid farm_id FK
        text batch_code UK "global, public-safe"
        text kind "seed_lot | input_lot | nursery | crop_lot | harvest | animal | animal_product | processed | packaged | shipment"
        uuid product_id FK
        numeric quantity
        uuid unit_id FK
        text status "open | closed | recalled"
        uuid origin_plot_id FK
    }
    trace_batch_links {
        uuid id PK
        uuid farm_id FK
        uuid parent_batch_id FK
        uuid child_batch_id FK
        text link_type "derived | split | merge | process | package | ship"
        numeric quantity
    }
    trace_events {
        uuid id PK
        uuid farm_id FK
        uuid batch_id FK
        text event_type
        timestamptz occurred_at
        timestamptz recorded_at
        uuid actor_user_id FK
        uuid worker_id FK
        uuid plot_id FK
        geography point
        text subject_type
        uuid subject_id
        jsonb payload
        uuid corrects_event_id FK
        bigint farm_seq
        bytea prev_hash
        bytea hash
    }
    trace_movements {
        uuid id PK
        uuid farm_id FK
        uuid batch_id FK
        uuid from_location_id FK
        uuid to_location_id FK
        text to_party
        numeric quantity
    }
    trace_locations {
        uuid id PK
        uuid farm_id FK
        uuid batch_id FK
        geography point
        text source
    }
    trace_documents {
        uuid id PK
        uuid farm_id FK
        uuid batch_id FK
        uuid media_id FK
        text doc_type "certificate | lab_result | delivery_note | invoice"
    }
    trace_approvals {
        uuid id PK
        uuid farm_id FK
        uuid batch_id FK
        jsonb public_fields
        uuid approved_by FK
        timestamptz approved_at
    }
    trace_qr_codes {
        uuid id PK
        uuid farm_id FK
        uuid batch_id FK
        text code UK
        text status "active | revoked"
        uuid approval_id FK
    }
    trace_audits {
        uuid id PK
        uuid farm_id FK
        uuid batch_id FK
        text check_type
        text result
    }
    product_journey {
        uuid batch_id PK
        uuid farm_id FK
        jsonb upstream
        jsonb downstream
        jsonb timeline
        timestamptz refreshed_at
    }
    audit_logs {
        uuid id PK
        uuid farm_id FK "null for platform"
        uuid user_id FK
        text action
        text entity_type
        uuid entity_id
        jsonb old_values
        jsonb new_values
        inet ip
        text device
        geography point
        timestamptz created_at
    }

    trace_batches ||--o{ trace_batch_links : "parent"
    trace_batches ||--o{ trace_batch_links : "child"
    trace_batches ||--o{ trace_events : ""
    trace_events ||--o| trace_events : corrects
    trace_batches ||--o{ trace_movements : ""
    trace_batches ||--o{ trace_locations : ""
    trace_batches ||--o{ trace_documents : ""
    trace_batches ||--o{ trace_approvals : ""
    trace_approvals ||--o{ trace_qr_codes : ""
    trace_batches ||--o{ trace_audits : ""
    trace_batches ||--|| product_journey : "read model"
```

Design details: [07 — Traceability & Audit](07-traceability-and-audit.md).

## 11. Sync support tables

| Table | Purpose |
|---|---|
| `sync_mutations` | Processed mobile mutations: `(device_id, mutation_id)` unique, plus result. This makes push idempotent |
| `sync_changes` | Per-farm change feed: `farm_seq bigserial`, `entity_type`, `entity_id`, `op`, `changed_at`. Pull reads it from a cursor |
| `sync_conflicts` | Conflicts waiting for a user decision |

## 12. Key indexes (initial set)

| Table | Index |
|---|---|
| All farm tables | `(farm_id, id)` unique; `(farm_id, updated_at)` for sync |
| `activities`, `worker_tasks` | `(farm_id, status, due_on)`, `(farm_id, assigned_worker_id, due_on)` |
| `trace_events` | `(farm_id, batch_id, occurred_at)`, `(farm_id, subject_type, subject_id)` |
| `trace_batch_links` | `(parent_batch_id)`, `(child_batch_id)` |
| `stock_transactions` | `(farm_id, item_id, occurred_at)` |
| `stock_balances` | unique `(farm_id, item_id, location_id, lot_id)` |
| `transaction_lines` | `(farm_id, account_id)`, `(farm_id, cost_center_type, cost_center_id)` |
| `worker_gps` | `(farm_id, worker_id, recorded_at)` + GiST on `point` |
| `farm_plots`, `farm_blocks` | GiST on `boundary` |
| `animals` | `(farm_id, status)`, unique `(farm_id, animal_code)` |
| `audit_logs` | `(farm_id, entity_type, entity_id, created_at)` |
