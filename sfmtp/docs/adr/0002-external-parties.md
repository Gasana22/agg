# ADR-0002 — Suppliers and customers as global party accounts

**Status:** Accepted (confirmed by the product owner, 2026-09-23)

## Context
Suppliers and customers must see only their own orders and never internal
farm data. In practice a seed supplier or a buyer deals with many farms on
the platform, and should not need a separate login for each.

## Decision
- A portal user is a `users` row with `user_type = party`, linked to a
  `parties` row (a company or person, with a type of supplier, customer or
  both).
- Each farm keeps its **own** `suppliers` / `customers` rows (with
  `farm_id`, the farm's own terms and notes). These can link to a
  `party_id` once the farm invites the party to the portal and the party
  accepts.
- Portal queries are scoped by `party_id` through those link rows. They only
  expose portal-safe resources (POs, deliveries, invoices, payments,
  published products, their orders, approved traceability).
- A farm can also keep suppliers and customers who have no portal account.

## Consequences
- One login shows POs or orders from several farms, each labelled with the
  farm name.
- Farm-private supplier data (ratings, internal notes, negotiated prices on
  other POs) is never exposed through the link.
