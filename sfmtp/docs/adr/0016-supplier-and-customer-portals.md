# ADR-0016 — Supplier and customer portals: party links, per-farm reads, orders

**Status:** Accepted (2026-09-25, Phase 12)

## Context
[ADR-0002](0002-external-parties.md) decided that suppliers and customers
sign in as global *parties* linked to each farm's own supplier and customer
records. Phase 12 builds the portals on top of that. Four questions came up:
- how a party is linked;
- how portal reads cross farms without weakening tenant isolation;
- what suppliers may change in a farm's books;
- how customer orders fit the sales flow of [ADR-0003](0003-sales-ownership.md).

## Decision
1. **Links, invitations, people.** The tables are:
   - `parties`: the company or person;
   - `party_users`: the people who sign in for it;
   - `party_links`: one farm's supplier or customer record, opened to a
     party (`kind`, `record_id`, `active` or `revoked`).

   A farm invites an email address for one record (`suppliers.manage` or
   `customers.manage`). The link is emailed, works once and lasts 14 days.
   Accepting it joins a party in one of three ways:
   - the person's own party, if they have exactly one;
   - one they choose, if they have several;
   - a new party named after the record.

   Someone without an account gets a `party` account. A farm member keeps
   their account and sees the portal as another workspace, with one
   sign-in (docs/01 §5). Platform staff cannot join. The record's
   `party_id` mirrors its active link.

   Unlinking stops access on the next request and keeps the history.
   Parties are not farm data. Links are read across farms to build
   workspaces, like `farm_users`, so they carry `farm_id` without tenant
   row-level security. Invitations are farm records.
2. **Portal reads never bypass isolation.** A portal route names its party
   (`/supplier/{party}/…`, `/customer/{party}/…`). The caller must be one
   of the party's people; otherwise the route answers 404.
   - A list across farms runs once per active link, each inside that farm's
     `TenantContext`. Row-level security and the farm scope apply as they
     do for members.
   - A detail route also names its farm (`…/farms/{farm}/…`). It must be
     linked, and the route runs in that farm's context.
   - Every query is then filtered to the link's record (`supplier_id` /
     `customer_id`). Another supplier's order in the same farm is a 404.
   - A farm that is suspended, closed or unpaid drops out of the portal.

   A party has no farm membership, so farm routes stay 404 for it.
3. **Suppliers never write to the ledger.** In the portal a supplier can:
   - **answer** a sent order: accept, with confirmed quantities and a date,
     or reject with a reason;
   - announce a **dispatch**, with its delivery note, which the store
     receives against;
   - **submit an invoice** for goods received and not yet invoiced.

   The farm records a submitted invoice through the normal three-way
   match, which posts to the ledger, or sends it back with a reason.
   Deliveries (GRNs), stock and money stay farm actions.

   Suppliers see only orders that were sent: never drafts, internal notes
   or people. They also see what was received, what is recorded and what
   is paid. Their documents are farm media that the farm's receivers,
   buyers and finance can open. Workers' photos keep their narrower rule.
4. **Products and sales orders.** Products carry the farm's list price
   (`sales.pricing.manage`). Published ones appear in the shop of the
   farm's linked customers. A sales order is placed in the portal, at
   list price, or recorded by staff, who may agree another price.

   The flow is:
   - requested;
   - approved (or rejected);
   - invoiced;
   - dispatched;
   - delivered.

   Approval: under the farm's `sales_order` threshold, anyone with
   `sales.orders.create` approves, and a staff order is approved as it is
   recorded. Above it, only `sales.orders.approve`, and never whoever
   recorded it unless they are the owner.

   Invoicing drafts a customer invoice at the ordered prices. The
   accountant issues it as usual, so drafts stay with the farm.
   Dispatching creates a shipment from trace batches, linked to the order.
   The batch must be counted in the order's unit (unit conversion is not
   built yet). Delivery closes the order when everything has left and
   arrived; the store or the customer confirms it. A failed delivery gives
   its quantity back to the order.
5. **What customers see.** Customers see:
   - published products;
   - their orders with a timeline;
   - issued invoices, with what is paid and due;
   - shipments, with confirmation;
   - the batches they bought.

   A batch shows only what the farm approved for the public: the same
   payload as a QR scan ([ADR-0014](0014-public-traceability-and-qr-codes.md)),
   or nothing. Costs, margins, stock, workers, internal notes and ledger
   data never leave the farm (docs/04 §6 test 10).
6. **Dashboards and notices.**
   - Each portal has its own dashboard (docs/05 §3.9–3.10), summed across
     farms and split by currency.
   - Farm staff get inbox notices for supplier answers, dispatches and
     invoices, and for portal orders to approve.
   - Portal users get an email when a farm sends them an order.

## Consequences
- One login spans all of a party's farms. The portal's cost grows with
  the number of linked farms, which stays small in practice.
- A party's people are managed by the farms' invitations; there is no
  self-service team management yet.
- Customers pay outside the platform until online collection
  (Flutterwave) arrives in Phase 14. The portal shows what is due.
- Farms only see buyers they have linked: there is no public marketplace
  for strangers yet.
- Unit conversion between an order's unit and a batch's unit remains
  future work (it was deferred from Phase 9).
