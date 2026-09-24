# Architecture Decision Records

Each ADR records one decision: its context, the decision, and its
consequences.

| ADR | Title | Status |
|---|---|---|
| [0001](0001-organization-above-farm.md) | Organization (billing account) above farm; farm is the isolation boundary | Accepted |
| [0002](0002-external-parties.md) | Suppliers and customers as global party accounts linked per farm | Accepted |
| [0003](0003-sales-ownership.md) | Who owns sales and pricing | Accepted |
| [0004](0004-table-naming.md) | Table naming changes vs the requirements document | Accepted |
| [0005](0005-support-access.md) | Owner-granted, read-only, time-boxed support access | Accepted |
| [0006](0006-core-technical-choices.md) | Core technical choices (UUIDv7, BFF tokens, PostgreSQL RLS, double-entry ledger, monorepo) | Accepted |
| [0007](0007-web-session-handling.md) | Web session handling: BFF cookies, CSRF, refresh-race handling | Accepted |
| [0008](0008-subscription-lifecycle.md) | Subscription lifecycle and how billing gates farm access | Accepted |
| [0009](0009-geojson-geometry.md) | Farm geometry as GeoJSON, measured in the application | Accepted |
| [0010](0010-sync-protocol.md) | Offline sync as built: ordered mutations through the normal services, a change feed with a lag | Accepted |
| [0011](0011-stock-valuation-and-ledger-core.md) | Stock valuation and the ledger core: average cost per lot, row locks, entries corrected through their documents | Accepted |

## Product-owner decisions (2026-09-23)

The product owner accepted all the proposed defaults:

1. **Organization level (ADR-0001):** one subscription covers all of an
   owner's farms, and the plan limits the number of farms.
2. **Suppliers and customers (ADR-0002):** one supplier or customer account can
   deal with several farms on the platform.
3. **Sales (ADR-0003):** the Farm Owner sets prices and approves sales. A
   "Sales Manager" template can be added later from existing permissions.
4. **Support access (ADR-0005):** owner-granted, read-only, time-boxed support
   access is allowed.
5. **Target market:** Uganda first: UGX, English, Africa/Kampala time zone,
   Africa's Talking for SMS, and MTN / Airtel Mobile Money through Flutterwave.
   These are the farm defaults, and each farm can change them.
6. **Location:** SFMTP is built in this repository under `sfmtp/`.
