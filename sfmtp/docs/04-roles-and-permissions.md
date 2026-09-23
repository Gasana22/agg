# 04 — Role & Permission Matrix

## 1. Model

- **Permissions are data, not code.** Each permission has the key
  `module.resource.action` (e.g. `crops.harvest.create`). The full list is in
  the `permissions` table, seeded from a single PHP registry
  (`PermissionRegistry`). Policies check permission keys and **never check
  role names**, so the code contains no hard-coded roles.
- **Roles are templates.** The ten roles in the requirements are seeded as
  *system templates*. The platform-level ones (System Admin) are fixed. Each
  farm gets a copy of the eight farm-staff templates, which the Farm Owner
  can adjust within limits (§4) or clone into custom roles (e.g. "Dairy
  Supervisor").
- **Scope per grant.** Each role–permission grant carries a scope:
  - `all` — every record in the farm
  - `assigned` — only records the member is assigned to (tasks, crop
    cycles, animal groups, stores)
  - `own` — only records the member created or that belong to them
- **Several roles per member.** A member can hold more than one role in a
  farm. Their effective permissions are the union, with the widest scope
  for each permission.
- **Field-level visibility.** Money fields (cost, price, value, wage) are
  output only when the member holds `finance.values.view`. API Resources apply
  this, so an agronomist sees the quantity of fertilizer applied but not what
  it cost.

## 2. Surfaces and identities

| Role | `user_type` | Surface | Scope |
|---|---|---|---|
| System Administrator | `platform_admin` | `/admin` | Platform only |
| Farm Owner | `member` | `/farms/{farm}` | Whole farm + organization billing |
| Farm Manager | `member` | `/farms/{farm}` | Farm operations |
| Agronomist | `member` | `/farms/{farm}` | Crops |
| Livestock Manager | `member` | `/farms/{farm}` | Livestock |
| Store Manager | `member` | `/farms/{farm}` | Inventory |
| Accountant | `member` | `/farms/{farm}` | Finance |
| Field Worker | `member` | `/farms/{farm}` (mobile-first) | Own assigned tasks |
| Supplier | `party` | `/supplier` | Own purchase orders, across farms |
| Customer | `party` | `/customer` | Own orders, across farms + public traceability |

## 3. Module permission matrix (default templates)

Legend: **F** full (create/edit/archive) · **A** approve · **C** create/record ·
**V** view · **R** request only · **o** scope `own` · **s** scope
`assigned` · **$** includes money values · **—** no access

| Module / capability | Owner | Manager | Agronomist | Livestock Mgr | Store Mgr | Accountant | Field Worker |
|---|---|---|---|---|---|---|---|
| Farm profile & settings | F | V | V | V | V | V | — |
| Farm structure (blocks, sections, plots) | F | F | V (edit soil data) | V | V | V | V s |
| Users, roles & permissions | F | invite workers only | — | — | — | — | — |
| Subscription & billing | F | — | — | — | — | — | — |
| Crop plans & cycles | F A $ | F | F | — | — | V $ | — |
| Crop operations & observations | F $ | F A | F A | — | — | V $ | C s |
| Harvest recording | F $ | F A | F | — | V | V $ | C s |
| Animals & records | F $ | F A | — | F | — | V $ | C s |
| Livestock sales | A $ | R | — | R | — | V $ | — |
| Workers (profiles) | F $ | F | V | V | — | V $ | V o |
| Tasks & activities | F A | F A | F A (crop) | F A (livestock) | C (store tasks) | — | C s o |
| Attendance & leave | F A | F A | V | V | — | V | C o |
| Worker GPS / photos | V | V | V (crop) | V (livestock) | — | — | C o |
| Inventory items & balances | F $ | V | V (inputs) | V (feed/drugs) | F | V $ | — |
| Stock in / out / transfer / adjust | A $ | A | R | R | F (adjust needs A) | V $ | R (task issue) |
| Purchase requests | A $ | C A | R | R | C | V $ | — |
| Purchase orders & deliveries | F A $ | V | — | — | receive deliveries | C $ | — |
| Supplier management | F | V | — | — | V | F | — |
| Income, expenses, invoices, payments | F A $ | R (expense request) | — | — | — | F $ | — |
| Budgets | F A $ | V (ops) | V (crop) | V (livestock) | — | F $ | — |
| Payroll | A $ | V (hours, no wages) | — | — | — | F $ | — |
| Sales: products, prices, orders | F A $ | C (fulfil) | — | — | fulfil / dispatch | C (invoice) $ | — |
| Customers | F | V | — | — | — | F | — |
| Assets & maintenance | F $ | F | V | V | V | V $ | C s (maintenance) |
| Traceability batches & journey | F | F | V + C (crop) | V + C (animal) | V + C (storage) | V | — |
| Publish traceability / QR | A | C | C (crop) | C (livestock) | — | — | — |
| Maps | V | V | V (crop) | V (livestock) | V (stores) | — | V s |
| Reports & exports | V $ | V (ops) | V (crop) | V (livestock) | V (stock) | V $ | — |
| Documents | F | F | C | C | C | F | C o |
| Audit log | V | V (ops) | — | — | — | V (finance) | — |

Notes

- The **sales owner** ([ADR-0003](adr/0003-sales-ownership.md)) is the Farm
  Owner, who sets prices and approves sales. The Accountant invoices and
  collects payment. The Farm Manager and Store Manager fulfil and dispatch.
- **Approval thresholds** are farm settings, e.g. expenses above X, purchase
  orders above Y, stock adjustments above Z% need owner approval. Below the
  threshold the Manager's approval is enough.
- **Store Manager** can request purchases but cannot create POs or set prices,
  as the requirements specify.
- **Accountant** has no permission to create or change operational records
  (activities, crop operations, animal records).

## 4. Guard-rails the owner cannot override

Some permissions are *locked* to protect the core rules, even in custom
roles:

| Rule | Enforcement |
|---|---|
| Only the owner can delete the farm, transfer ownership, or manage the subscription | Permissions `farm.delete`, `farm.transfer`, `billing.*` are grantable only to the owner role |
| A role with `worker.self` (Field Worker) can never be granted `finance.*`, `finance.values.view`, `inventory.values.view`, `payroll.*` | Validation when editing roles |
| Nobody can hard-delete traceability or audit records | No permission exists for it. The DB rejects it ([07](07-traceability-and-audit.md)) |
| Approvals: whoever creates a record above the threshold cannot also approve it (four-eyes), unless they are the owner | Checked in the approval policy |

## 5. Platform and portal permissions

**System Administrator** (platform roles: `super_admin`, `support`, `billing`)

| Capability | super_admin | support | billing |
|---|---|---|---|
| Farms: approve / suspend / unsuspend | ✔ | — | ✔ (suspend for non-payment) |
| Trigger owner password reset | ✔ | ✔ | — |
| Plans & pricing | ✔ | — | ✔ |
| Subscriptions & platform payments | ✔ | V | ✔ |
| Global catalogues (crops, breeds, units) | ✔ | — | — |
| Integrations & platform settings | ✔ | — | — |
| System logs, health, backups | ✔ | V | — |
| Support tickets | ✔ | ✔ | V |
| Time-boxed read-only support access (granted by owner) | — | ✔ | — |
| **Any farm operational data** | **—** | **—** | **—** |

**Supplier portal**: view and respond to POs addressed to them (accept,
reject, confirm quantities); confirm dispatch; upload delivery notes and
invoices; view payment status of their invoices; edit their profile.

**Customer portal**: browse published products; place and track orders;
view their invoices, payments and deliveries; confirm delivery; view purchase
history; view **approved** public traceability for batches they bought, or
any batch via QR.

## 6. Mandatory role-boundary tests

These become permanent feature tests (requirements §42):

| # | Test | Expected |
|---|---|---|
| 1 | Farm A member calls any Farm B route | 404, no data change |
| 2 | Field Worker calls finance, payroll, stock-value, sales-value endpoints | 403 |
| 3 | Field Worker lists tasks | Only their own assigned tasks |
| 4 | Field Worker views an inventory item | No `unit_cost` / `value` fields in the response |
| 5 | Agronomist calls livestock or finance endpoints | 403 |
| 6 | Livestock Manager calls crop-operation or finance endpoints | 403 |
| 7 | Store Manager creates a purchase order or sets a product price | 403 |
| 8 | Accountant edits a crop operation or an animal record | 403 |
| 9 | Supplier reads farm operations, workers, inventory or another supplier's PO | 403/404 |
| 10 | Customer reads internal costs, workers, inventory, margins or non-approved traceability | 403/404, and the public payload contains only allow-listed fields |
| 11 | System Admin calls any `/farms/{farm}/…` route or modifies an operational record | 403/404 |
| 12 | Farm Manager deletes the farm or changes ownership | 403 |
| 13 | Anyone updates or deletes a `trace_events` or `audit_logs` row | Rejected by DB |
