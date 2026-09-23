# ADR-0004 — Table naming changes vs the requirements document

**Status:** Proposed

| Requirements name | Design name | Reason |
|---|---|---|
| `payments` (platform group) | `subscription_payments` | The requirements used `payments` in both the platform and finance groups. Platform and farm data must not mix |
| `roles`, `permissions`, `role_permissions` (platform) | `platform_roles`, `platform_user_roles`; one shared `permissions` registry | Keeps platform roles separate from farm roles |
| `farm_roles`, `farm_permissions` | `farm_roles`, `farm_role_permissions`, `farm_user_roles` | Permissions are a global registry; farms grant them to roles with a scope |
| `animal_breeding` / `animal_production` | kept | — |
| — (new) | `organizations`, `parties`, `activities`, `stock_lots`, `stock_balances`, `transaction_lines`, `sales_orders`, `products`, `trace_batch_links`, `sync_*` | Needed for multi-farm billing, portals, the generic activity model, lot traceability, double-entry and sync |
| `product_journey` | kept, as a **read model** (projection), not a source of truth | Traceability source of truth is `trace_events` + `trace_batch_links` |
