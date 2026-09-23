# ADR-0001 — Organization (billing account) above farm

**Status:** Proposed — needs confirmation

## Context
The requirements say *"each farm is a tenant"* and *"farm subscriptions"*.
They also give plans **farm limits** (§35) and give the Farm Owner a **"My
Farms"** menu. So one owner can run several farms under one subscription.

## Decision
- Add an `organizations` table: the billing account that owns farms. The
  Farm Owner is the organization owner.
- `subscriptions` belong to the organization. Plan limits (`max_farms`,
  `max_users`, `max_storage_mb`) apply across its farms.
- **The farm stays the data isolation boundary.** Every operational row has
  `farm_id`. Membership, roles and permissions are granted per farm. The
  organization never grants access to data.
- The owner's multi-farm roll-up queries each farm separately in its own
  tenant context and combines the results.

## Consequences
- Adding a farm is checked against `max_farms`.
- Suspension can happen per farm (by the admin) or per organization (unpaid
  subscription).
- If the business later wants per-farm billing, a plan with `max_farms = 1`
  achieves it without schema changes.
