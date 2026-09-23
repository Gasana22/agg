# ADR-0003 — Sales and pricing ownership

**Status:** Proposed — needs confirmation

## Context
The requirements include sales, products and customers, but no role owns
selling prices or sale approval. Store Managers are explicitly barred from
setting prices.

## Decision
| Step | Role (default template) |
|---|---|
| Create products, set list prices, publish to marketplace | Farm Owner (permission `sales.pricing.manage`, grantable to a custom role) |
| Approve sales orders above threshold; approve livestock sales | Farm Owner |
| Record direct / offline sales orders | Farm Owner, Farm Manager |
| Invoice, record customer payment | Accountant |
| Pick, dispatch and deliver (stock out, shipment batch) | Store Manager, Farm Manager |

A "Sales Manager" template can be added later, just by combining existing
permissions.

## Consequences
- The sale flow is: order (customer portal or internal) → approval if above
  threshold → invoice → dispatch → delivered, with trace events at dispatch
  and delivery.
