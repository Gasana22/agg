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

### Phase 2 — delivered

Everything in the Phase 2 row below, with these notes:

- **Subscription statuses** are `trialing → active → grace → suspended`, plus
  `cancelled`. The requirements' "past due" is the `grace` state, when farms
  stay open with a warning ([ADR-0008](adr/0008-subscription-lifecycle.md)).
- **Payments are recorded by SFMTP staff** (mobile money, bank transfer,
  cash). Online checkout through payment gateways is Phase 14. Integration
  providers can already be configured; the adapters that call them come in
  Phase 14.
- **Support access** follows ADR-0005: the owner grants it from a ticket, for
  up to 72 h. It is read-only, and every request is written to the farm's
  audit log.
- **Deferred:** storage usage is shown against the plan limit from Phase 6
  (media). Member invitations, and the plan's user limit applied to them,
  arrive in Phase 3; the limit is already enforced by `FarmService::addMember`.
- **Test gate met:** 133 API tests on PostgreSQL (131 on MySQL, plus 2 that
  need PostgreSQL features) and 26 web unit tests. The admin, owner, support
  and billing journeys were checked end to end in a browser. CI is green,
  including the Docker image builds.

### Phase 3 — delivered

Everything in the Phase 3 row below, with these notes:

- **Geometry is GeoJSON, not PostGIS** ([ADR-0009](adr/0009-geojson-geometry.md)),
  so the same code runs on PostgreSQL and MySQL. Area, centroid and bounding
  box are computed on save; "outside its parent" and "overlaps another plot"
  are warnings, not failures.
- **Hierarchy:** block → section → plot, and a plot may also sit directly
  under the farm, for small farms. Locations (stores, houses, water points…)
  are pins, areas or both. Structure is archived, never deleted, and codes
  are never reused.
- **Invitations** are emailed one-time links (7 days). The invitee signs up,
  or signs in with the invited email. The plan's user limit is checked when
  inviting and again when accepting; pending invitations do not hold seats.
- **Field workers** have `structure.view` with scope `assigned`. Until tasks
  exist (Phase 6) they see no plots.
- **Approval thresholds** are stored and editable now; finance and inventory
  enforce them from Phases 7–8.
- **Test gate met:** 163 API tests on PostgreSQL (160 on MySQL, plus 3 that
  need PostgreSQL features) and 31 web unit tests. They include the
  role guard-rails, the composite keys that reject cross-farm plot
  references (structure and trace tables), and the cross-tenant sweep over
  every new route. The owner, manager and invitee journeys were checked end
  to end in a browser.

### Phase 4 — delivered

Everything in the Phase 4 row below, with these notes:

- **Traceability is automatic.** Starting a cycle creates the crop lot (or a
  nursery batch for transplanted crops), derived from the seed lot. Verified
  field work adds `operation` and `input_applied` events, findings add
  `observation` events, and each harvest creates a harvest batch derived
  from the crop lot. All of it happens in the same transaction as the crop
  record.
- **Food safety:** inputs carry their withholding (pre-harvest) days. A
  harvest before the safe date is refused unless someone allowed to verify
  crop work gives an override reason, which stays on the harvest and in its
  trace event.
- **Verification:** work recorded by someone who may verify it
  (`crops.operations.approve`) is verified at once; anyone else's waits for
  verification, and nobody verifies their own. Plans follow the same
  four-eyes rule, except for the owner.
- **Field workers** hold `crops.operations.record` with scope `assigned`, so
  they record crop work once tasks assign them to cycles (Phase 6).
- **Money:** plan budgets and operation costs are stored now, visible only
  with `finance.values.view`. Costs reach the ledger in Phases 7–8.
- **Inputs** are named by hand, optionally with their input-lot batch.
  Picking inventory items and deducting stock comes with inventory in
  Phase 7.
- **Deferred:** the crop health score KPI and the crop health map widget
  (Phase 13, delivered), and the weather widget (Phase 14, weather provider).
- **Test gate met:** 172 API tests on PostgreSQL (169 on MySQL, plus 3 that
  need PostgreSQL features) and 36 web unit tests. They include the crop
  lifecycle from plan to packed grain with a backward journey to the seed
  lot, the withholding rule, verification and scopes, the agronomist,
  accountant, livestock, store and field-worker boundaries, and the
  cross-tenant sweep over every crop route. The agronomist's journey was
  checked end to end in a browser.

### Phase 5 — delivered

Everything in the Phase 5 row below, with these notes:

- **Every animal is a trace batch.** Registration creates it, births link
  the calf from its dam and sire, and health, feeding, weighing, movement,
  breeding, exit and sale events land on it in the same transaction as the
  record. Each day's milk or eggs form a lot derived from the animals that
  gave it.
- **History is immutable.** Records are append-only at the database; a
  mistake is voided with a reason, and the void is recorded too.
- **Food safety:** treatments carry milk and meat withdrawal days. Milk
  produced inside a milk withdrawal window must be recorded as discarded,
  and a sale inside a meat withdrawal needs an override reason from someone
  who may approve sales.
- **Sales** are requested by the livestock manager or farm manager and
  approved by the owner, or by a custom role granted
  `livestock.sales.approve` (nobody approves their own request, except the
  owner). Prices are visible only with `finance.values.view`; income
  reaches the ledger in Phases 7–8.
- **Boundaries:** the livestock manager sees livestock, the map and
  traceability, and gets 403 on crops and money fields.
- **Deferred:** feed drawn from inventory (Phase 7), vet visits as worker
  tasks (Phase 6), and animal health score widgets (Phase 13, delivered).
- **Test gate met:** 181 API tests on PostgreSQL (178 on MySQL, plus 3 that
  need PostgreSQL features) and 39 web unit tests. They include lineage
  from dam and sire to offspring, the milk and meat withdrawal rules, day lots, group records,
  sale approval, append-only records and voids, the role boundaries, and
  the cross-tenant sweep over every livestock route. The livestock
  manager's journey was checked end to end in a browser.

### Phase 6 — delivered

Everything in the Phase 6 row below, with these notes:

- **Work model.** An activity is work on a subject (a crop cycle, plot,
  location, animal, group, or general work) with one task per worker. Tasks
  follow assigned → in progress ⇄ paused → submitted → verified or rejected
  (rework), or cancelled; every step is an append-only log entry with the
  time and place it happened. Worked time comes from the log. The activity
  completes when all its tasks are verified or cancelled.
- **Who sees what.** Work belongs to a module (from the activity type):
  crop roles plan and verify crop work, livestock roles animal work; general
  work is visible to all of them. Field workers see only their own tasks,
  no rates and no other workers. Nobody verifies their own work.
- **Assignments drive the `assigned` scope.** An open task on a crop cycle
  (or its plot) lets a field worker record crop work there; on an animal or
  group, animal records. Submitting the task ends it.
- **Traceability.** Verified work on a crop cycle or an animal adds a
  `work_done` event to its batch, with the worker's code, the place and the
  quantity (no names or money). Crop operations, animal records and trace
  events now have foreign keys to workers, and operational records an
  `activity_id` for linking them to work (used by the mobile crop and
  livestock flows in Phase 11).
- **Attendance** is one record per worker and day (farm time zone), from the
  phone or entered by a manager with an audited reason. GPS points are
  kept only during a work session. Leave needs approval; work cannot be
  assigned on approved leave.
- **Media and sync** are separate modules: photos are stored once per farm by
  SHA-256 (MinIO in Docker), and the sync API applies ordered offline
  mutations through the same services and pulls a change feed
  ([ADR-0010](adr/0010-sync-protocol.md)).
- **Flutter app v0** (`sfmtp/mobile`): sign-in, farm choice, check in and
  out, today's tasks with start / pause / resume / done, photos, and a sync
  screen; offline-first with a Drift database and an ordered outbox.
- **Deferred:** payroll from attendance (Phase 8); background GPS tracks,
  push notifications, the encrypted local database, conflict screens for
  editable records and the other roles' mobile flows (Phase 11); a
  generated Dart client (Phase 11); trimming the change feed (Phase 11/15).
- **Test gate met:** 193 API tests on PostgreSQL (190 on MySQL, plus 3 that
  need PostgreSQL features), 43 web unit tests, and 5 Flutter tests plus a
  live one. They include assign → execute → submit → verify with the trace
  event, reject and rework, own-tasks-only and no-money for field workers,
  module rules and four-eyes, the assigned scope on crop cycles,
  attendance, GPS and leave, the airplane-mode day with duplicates,
  conflicts, rejections and removals, and the cross-tenant sweep over every
  workforce, media and sync route. The Flutter app runs the offline day
  against the real API in CI, and the manager's and field worker's web
  journeys were checked in a browser.

### Phase 7 — delivered

Everything in the Phase 7 row below, with these notes
([ADR-0011](adr/0011-stock-valuation-and-ledger-core.md)):

- **Stock.** Items (lot tracking on by default, expiry optional), any farm
  location as a store, an append-only stock ledger with balances per item,
  store and lot, weighted-average cost per balance row, first-expiry-first-out
  issues to a crop cycle, plot, location, animal, group or general use,
  transfers, low-stock and expiry alerts. Balance rows are locked before
  every change: the concurrency test forks ten processes against one
  balance on both engines.
- **Counts** are entered against the shelf and change nothing until someone
  else approves (the owner above the farm's `stock_adjustment_pct`). The
  difference from the book at the time of the count is applied, so
  movements in between are kept; a count that no longer fits must be
  taken again.
- **Stock requests** from crop, livestock and field staff (optionally from a
  task, which gives the subject) are approved and then issued in full or in
  part by the store.
- **Purchasing.** Suppliers; purchase requests with approval; orders as
  drafts that someone other than the buyer approves (only the owner above
  `approval_thresholds.purchase_order`), sent, received in one or more
  deliveries (each line a stock lot at the order price), and matched to the
  supplier's invoice (no more than received; price differences to
  variance). Orders close by themselves when received and invoiced.
- **Traceability.** Every lot is an `input_lot` batch naming the supplier,
  order and supplier lot number; every issue adds an `issued` event with
  its subject. Prices never enter trace payloads.
- **Ledger core.** A system chart of accounts, balanced append-only entries
  (a PostgreSQL trigger also checks at commit), JE numbers per farm, and a
  trial balance. Deliveries, invoices, issues, stock-in and counts post
  automatically; the Inventory account equals the stock value. Only manual
  entries can be reversed; documents are corrected through stock or
  purchasing.
- **Money visibility.** The store manager handles stock and deliveries
  with stock values but without purchase prices; prices need
  `procurement.orders.manage` or `finance.values.view`.
- **Dashboards.** Store Manager dashboard (stock KPIs, requests, deliveries
  to receive, expiring lots, low stock, recent movements, value by
  category); stock and payables for the owner, manager and accountant.
- **Web.** Inventory, Purchasing and Ledger pages, an item page with its
  lots linked to their trace batches, and an order page from approval to
  invoice.
- **Deferred:** payments, customer invoices and the rest of finance
  (Phase 8); returns to suppliers and supplier credit notes (Phase 8);
  supplier self-service for orders and deliveries (Phase 12); mobile stock
  flows (Phase 11); inventory reports and exports (Phase 13, delivered).
- **Test gate met:** 204 API tests on PostgreSQL (200 on MySQL, plus 4 that
  need PostgreSQL features) and 50 web unit tests. They include
  the forked-process concurrency test (parallel issues never make stock
  negative), request → order → approval → partial and full delivery →
  stock lots → invoice with price variance, input lots as trace batches with
  issue events, first-expiry issues, average cost and transfers, counts
  with four-eyes, the owner threshold and stale counts, requests with
  partial issue, the ledger balancing after every flow, append-only
  entries, money visibility per role, and the cross-tenant sweep over
  every new route and request body. The store manager's, manager's,
  accountant's and owner's journeys were checked in a browser.

### Phase 8 — delivered

Everything in the Phase 8 row below, with these notes
([ADR-0012](adr/0012-finance-documents-and-payables.md)):

- **Documents, not journal entries.** Expenses, income, customer and
  supplier invoices, payments and payroll each post their own entry. A
  mistake is voided, which posts the reversal; a document with payments has
  them voided first. Manual entries (opening balances, capital, transfers)
  cannot touch control accounts, so receivables, payables, stock and wages
  always equal their documents.
- **Approvals.** Expense requests are approved by someone else: finance
  within the farm's expense threshold, the owner above it. The accountant's
  own receipts within the threshold post at once. Payroll needs a second
  person with `finance.payroll.approve`.
- **Payments** settle one document each (customer or supplier invoice,
  expense, payroll) through a registry each module plugs into, never more
  than is owed.
- **Payroll** comes from attendance at each worker's daily rate, with bonus
  and deductions. Wages are charged to the crop cycles, animals and groups
  of the tasks verified in the period, by time, so labour shows in the cost
  of each crop.
- **Sales module:** customers and invoices, including completed livestock
  sales (billed once, charged to the animal).
- **Budgets** per account for the farm or a cost centre, against the
  ledger.
- **Reports:** profit and loss (also per cost centre), 12 months of income
  and expenses, cash flow with a 13-week forecast from open documents, and
  cost and margin per crop cycle (per hectare, acre and kilogram) and per
  animal group.
- **Dashboards:** the Accountant dashboard (revenue, expenses, profit,
  cash, receivables, payables, wages, budget, with the approval and
  collection queues and the charts) and the owner's financial KPIs and
  pending approvals.
- **Web:** Finance (expenses, income, invoices, payments, payroll, budgets,
  customers), Reports, and accounts and journal entries on the Ledger page.
- **Deferred:** payment approval above a threshold, credit notes and
  returns, VAT, bank reconciliation, statutory payroll deductions (PAYE,
  NSSF) and payslips (entered as deductions for now), finance exports
  (Phase 13, delivered), mobile money collection through Flutterwave (Phase 14), sales
  orders and products (Phase 12).
- **Test gate met:** 214 API tests on PostgreSQL (210 on MySQL, plus 4 that
  need PostgreSQL features) and 53 web unit tests. They include the
  trial balance balancing after every flow and each entry balancing on
  its own, ledger rows that cannot change, voids by reversal for every
  document, approval thresholds and four eyes, payments capped at what is
  owed, payroll from attendance with its cost split, invoicing a livestock
  sale once, budgets and every report against hand-computed figures, the
  field worker and the crop, livestock and store leads refused on every
  finance, sales and report route, and the cross-tenant sweep over every
  new route and request body. The accountant's, manager's and owner's
  journeys were checked in a browser.

### Phase 13 — delivered

Everything in the Phase 13 row below, with these notes
([ADR-0017](adr/0017-analytics-reports-and-exports.md)):

- **Metric catalogue.**
  - Every dashboard KPI with its definition, module and the dashboards
    that show it.
  - One metric with its previous period, change and a series per day,
    week or month, computed by the same code as the dashboard.
- **Final dashboards.**
  - Crop health score and a crop health map widget (cycles at their
    plots, coloured by band, with the reasons they lost points).
  - Animal health score and a list of animals needing attention.
  - Cost per hectare of the open cycles.
- **Standard reports.** 19 reports:
  - inventory: stock valuation, movements, expiring lots;
  - purchasing and sales: purchases by supplier, sales by customer and by
    product;
  - crops and livestock: harvests, field work, milk, animal health;
  - workforce: task completion, attendance;
  - finance: profit and loss, cash flow, cost per crop cycle and animal
    group, aged receivables and payables;
  - traceability: batches.

  Typed columns with totals; money columns only for those who may see
  money.
- **Exports.**
  - CSV, Excel and PDF, built in the background as the member who asked.
  - Kept 24 hours and visible to that member only.
  - Three in progress per farm at most.
  - An inbox notice when ready; an hourly prune.
- **Labels.**
  - A4 label templates: 24, 14 or 40 per sheet.
  - Bulk print runs of many QR codes in one PDF.
- **Activity heat map** on the farm map: GPS trails, task and attendance
  check-ins, field work, pest reports and trace events, counted per grid
  cell, each layer behind its permission.
- **Web**:
  - Reports gains standard reports, exports and metrics tabs, and shows
    for everyone with `reports.view`;
  - the farm map gains an activity layer;
  - the QR codes list prints labels in bulk.
- **Demo**: a week of worker GPS trails and located check-ins and field
  reports on both farms.
- **Deferred:**
  - saved custom reports and scheduled email delivery;
  - farm-specific health score weights.
- **Test gate met.**
  - The dashboard bench (`php artisan reporting:bench`) and its test hold
    every dashboard under p95 800 ms cached and 3 s cold. On the demo
    farm, cold p95 is under 110 ms and cached under 10 ms.
  - Export correctness: CSV, Excel and PDF carry the same rows and totals
    as the preview. A formula-like name is defused in CSV and kept as text
    in Excel.
  - Also covered: exports are private, limited and expire; permissions
    and money columns on reports; health scores; the cross-tenant sweep
    over the new routes.
  - 246 API tests on PostgreSQL (241 on MySQL, plus 5 that need PostgreSQL
    features) and 68 web unit tests.
  - Reports, exports, metrics, widgets, the heat map and label printing
    were checked in a browser, including phone width.

### Phase 12 — delivered

Everything in the Phase 12 row below, with these notes
([ADR-0016](adr/0016-supplier-and-customer-portals.md)):

- **Party accounts.**
  - A farm invites an email for one of its suppliers or customers.
  - Accepting links that record to a party: the person's company, which
    can span several farms, with one sign-in.
  - A farm member keeps their account and sees the portal as another
    workspace.
  - Farms see who has access and can withdraw invitations or stop access.
- **Isolation.** Portal reads run once per linked farm, inside that farm's
  tenant context (no bypass), and only for the linked record. Farm routes
  stay closed to parties.
- **Supplier portal.**
  - Orders from every farm, once sent.
  - Accept with confirmed quantities and a date, or decline with a reason.
  - Announce dispatches with the delivery note; the store receives against
    them.
  - Send invoices with the document; the farm records them through the
    three-way match or sends them back.
  - See what is recorded, paid and outstanding.
  - A dashboard, and an email when an order is sent.
- **Products and sales orders.**
  - List prices set by the owner, published to the portal.
  - Orders from the portal or recorded by staff.
  - Approval with a sales order threshold and four eyes.
  - Draft invoice from the order.
  - Dispatch from trace batches in the order's unit.
  - Delivered once everything arrived; failed deliveries give the quantity
    back.
- **Customer portal.**
  - A shop with a cart (one order per farm).
  - Orders with a timeline; cancel while waiting.
  - Deliveries to confirm; invoices with what is due.
  - The batches bought, with only the fields the farm approved for the
    public and their QR page.
  - A dashboard.
- **Web**:
  - both portals and the public invitation page;
  - Sales and Portal access on the farm side;
  - the supplier's answer, dispatches and invoices on purchase orders.
- **Demo**: `supplier@aggfarms.test` (Kakiri Agro-Vet) and
  `customer@aggfarms.test` (Kampala Millers).
- **Deferred:**
  - online payment by customers (Phase 14);
  - a public marketplace for buyers the farm has not linked;
  - self-service team management for parties;
  - unit conversion between order and batch units.
- **Test gate met.**
  - 238 API tests on PostgreSQL (233 on MySQL, plus 5 that need PostgreSQL
    features) and 62 web unit tests. They include:
    - invitations and one party across farms;
    - the supplier's answer → dispatch → receipt → invoice → recording;
    - order → approval above the threshold → invoice → dispatch → delivered
      confirmed by the customer;
    - supplier and customer isolation (docs/04 §6 tests 9 and 10): other
      parties' orders, unlinked farms, internal routes and suspended farms
      all 404;
    - the cross-tenant sweep over every new farm route.
  - Both portals and the farm side were checked in a browser, including
    phone width.

### Phase 11 — delivered

Everything in the Phase 11 row below, with these notes
([ADR-0015](adr/0015-mobile-roles-conflicts-and-notifications.md)):

- **Every role on the phone**, with the tabs chosen by the member's
  permissions:
  - the field worker's day (Phase 6);
  - the agronomist records operations with inputs and withholding, and
    reports problems at the phone's location;
  - the livestock team records health, weight and milk / eggs, and edits
    animals;
  - supervisors approve submitted work or send it back with a reason.

  Sign-in handles the authenticator code, and a member of several farms
  chooses one.
- **Sync for all of it.** The feed is filtered by permission on the server.
  New push handlers go through the same services and validation as the
  web.
- **Field-level merge and conflicts.** Offline animal edits merge per
  field. A field changed on both sides becomes a `sync_conflicts` entry,
  with a notification, resolved in the Inbox field by field (keep mine /
  keep the server's), offline too. A late check of work that changed in
  the meantime is a conflict with the server's record.
- **Notifications.** A new Notifications module:
  - a member inbox on the API, also synced to phones;
  - notices for work assigned, approved, sent back or refused, and for
    conflicts;
  - Firebase Cloud Messaging push (HTTP v1) when configured, and phone
    notifications after every sync otherwise.
- **Encrypted local database** (SQLite3MultipleCiphers, key in the
  Keystore / Keychain):
  - v0 plain databases are encrypted in place with their unsent work;
  - only the app makes keys;
  - a lost key starts the app empty instead of failing.
- **Background sync** every 15 minutes (WorkManager / BGTask), plus on
  reconnect and when the app returns to the foreground. A phone signed out
  from the web wipes its data.
- **Store builds.**
  - CI builds release APKs split per ABI, with a size budget.
  - A manual workflow signs and uploads to the Play internal track and to
    TestFlight, using repository secrets.
- **Low-end Android.** Checked through:
  - small per-ABI downloads;
  - detail screens that read one record, not the whole herd;
  - lists capped with search;
  - pushes batched at 200;
  - 1,000 queued mutations in about 25 s over a simulated 3G link
    (latency, bandwidth and server time).

  Checks on a physical low-end phone remain a manual step before each
  store release.
- **Deferred:**
  - mobile stock flows and harvest or treatment approvals on the phone
    (Phase 12 / 13);
  - offline editing of records other than animals (via `FieldMerge`);
  - a generated Dart client;
  - trimming the change feed (Phase 15);
  - SMS and email channels for notifications (Phase 14).
- **Test gate met.**
  - 233 API tests on PostgreSQL (228 on MySQL, plus 5 that need
    PostgreSQL features) and 56 web unit tests.
  - 16 Flutter tests covering docs/08 §6:
    - airplane mode and reconnect;
    - duplicate push;
    - a late check giving a conflict and a notification;
    - two phones editing one animal (merge plus conflict, resolved from
      the Inbox UI);
    - 1,000 mutations under 60 s on 3G;
    - encryption, the key rules and the upgrade;
    - push tokens;
    - remote wipe;
    - role tabs with the authenticator code.
  - Live scenarios against the demo API in CI: the worker's day, the
    agronomist offline, and two phones editing one animal.
  - The backend `MobileSyncTest` covers the same flows through the API,
    including 1,000 mutations and the cross-tenant sweep over the new
    routes.

### Phase 10 — delivered

Everything in the Phase 10 row below, with these notes
([ADR-0014](adr/0014-public-traceability-and-qr-codes.md)):

- **Approvals.**
  - Choose from twelve allow-listed public fields and preview the result.
  - The approval stores the exact payload; a new approval replaces it for
    every code of the batch.
  - Prices, costs, people, quantities and GPS cannot be published.
- **QR codes.**
  - Codes are random, unique across farms, and forgiving to type.
  - They can be issued, revoked with a reason, and are revoked
    automatically by a recall.
  - SVG previews and A4 label sheets (3 × 8) as PDF.
- **Public page.**
  - `/q/{code}` needs no sign-in and works at phone width.
  - It shows only the approved fields; a withdrawn code or recalled batch
    shows a notice.
  - The API behind it is limited to 60 requests a minute per IP and signs
    its payload with Ed25519 (public key published).
- **Scan statistics**: counts per day and country with nothing about the
  person, a QR scans KPI on the owner dashboard, and a QR codes tab on
  Traceability.
- **Demo**: the B-3 maize bags are published with a code and two weeks of
  scans.
- **Deferred:**
  - label templates for other stock and a bulk print run (Phase 13
    exports, delivered);
  - signing-key history for rotation;
  - the customer portal view of bought batches (Phase 12).
- **Test gate met:** 225 API tests on PostgreSQL (220 on MySQL, plus 5
  that need PostgreSQL features) and 56 web unit tests. They include:
  - the public payload holding only approved fields, with no secret
    leaking even when every field is approved;
  - signature verification, and a tampered payload failing it;
  - withdrawn and recalled notices, and recall revoking codes;
  - the 61st request in a minute refused while another IP is not;
  - scans counted with only day and country;
  - labels (PDF structure; every label decodes);
  - publishing permissions;
  - the cross-tenant sweep over the new routes.

  Publishing, printing, scanning signed out, and revoking were checked in
  a browser.

### Phase 9 — delivered

Everything in the Phase 9 row below, with these notes
([ADR-0013](adr/0013-batch-operations-journeys-and-shipments.md)):

- **Operations.** Split, merge, process and package, with quantities
  checked under row locks. Each batch shows what is left, and a batch that
  is used up closes itself. Manual links that take quantity follow the same
  rule.
- **Recall** follows the product downstream to every batch and shipment
  made from it, and needs `trace.publish`.
- **Shipments** (Sales): dispatch from any batches to a customer, with
  delivery confirmed or failed. They end the forward journey. The store
  dispatches without seeing prices.
- **Journey views** over a batch's lineage:
  - a timeline with corrections folded in;
  - workers and recorders;
  - seed and input lots with everything applied, including withholding
    periods;
  - customers reached;
  - plots, GPS points and moves.
- **The `product_journeys` projection**, refreshed by a queued job after
  each change and rebuilt by `trace:refresh-journeys`.
- **Integrity**: the nightly chain check now emails the owner on failure,
  and the check can also be run on demand. **Alerts**: broken or unchecked
  chain, recalled product at customers, undelivered shipments, products
  without a source, crops without a seed source. They also appear on the
  owner and manager dashboards.
- **Web**: a traceability explorer with these parts:
  - the journey graph;
  - the timeline with correction history;
  - seeds and inputs, workers, customers;
  - a map of plots and GPS points;
  - the operation, recall and correction dialogs;
  - Alerts and Integrity tabs;
  - a Shipments page.
- **Demo**: the B-3 maize is split, dried, packed and shipped to two
  customers (one delivery not yet confirmed). Its harvest moisture is
  corrected.
- **Deferred:**
  - unit conversion and stock movement with trace operations (Phase 12,
    with products and sales orders);
  - QR and public pages (Phase 10);
  - trace documents and certificates (with media links);
  - a daily anchor digest.
- **Test gate met:** 220 API tests on PostgreSQL (215 on MySQL, plus 5 that
  need PostgreSQL features) and 56 web unit tests. They include:
  - the seed → customer journey rebuilt from normal work through the API
    (backward, forward, sales, inputs, places, workers, timeline);
  - quantities that cannot be used twice;
  - a recall that reaches the customer and raises the alert;
  - corrections shown in the journey while the original row and the chain
    stay intact;
  - an altered event caught by the on-demand check and the nightly job,
    with the owner emailed;
  - the cross-tenant sweep over every new route.

  The owner's and store manager's journeys were checked in a browser.

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
