# Architecture Decision Records

Each ADR records one decision: its context, the decision, and its
consequences. Status is **Proposed** until the product owner confirms it.

| ADR | Title | Status |
|---|---|---|
| [0001](0001-organization-above-farm.md) | Organization (billing account) above farm; farm is the isolation boundary | Proposed — needs confirmation |
| [0002](0002-external-parties.md) | Suppliers and customers as global party accounts linked per farm | Proposed — needs confirmation |
| [0003](0003-sales-ownership.md) | Who owns sales and pricing | Proposed — needs confirmation |
| [0004](0004-table-naming.md) | Table naming changes vs the requirements document | Proposed |
| [0005](0005-support-access.md) | Owner-granted, read-only, time-boxed support access | Proposed — needs confirmation |
| [0006](0006-core-technical-choices.md) | Core technical choices (UUIDv7, BFF tokens, PostgreSQL RLS, double-entry ledger, monorepo) | Proposed |

## Open questions for the product owner

1. **Organization level (ADR-0001).** Do you agree that one subscription covers
   all of an owner's farms, with the plan limiting the number of farms?
2. **Suppliers and customers (ADR-0002).** Should one supplier or customer
   account be able to deal with several farms on the platform (as
   proposed), or be limited to one farm?
3. **Sales (ADR-0003).** Is the Farm Owner the only role that sets prices and
   approves sales, or should a "Sales Manager" role template be added?
4. **Support access (ADR-0005).** Is owner-granted, read-only support access
   acceptable, or must the admin have no access at all, even with consent?
5. **Target market.** Default currency, languages, and which SMS and payment
   providers to integrate first (e.g. Uganda: UGX, English, Africa's Talking,
   MTN / Airtel Mobile Money via Flutterwave)?
6. **Monorepo location.** Build SFMTP in this repository (alongside the
   AGG Farms website) under `sfmtp/`, or in a dedicated repository?
