# ADR-0008 — Subscription lifecycle and how billing gates farm access

**Status:** Accepted (2026-09-23, Phase 2)

## Context
Requirements §35 ask for configurable plans, trial/expiry/renewal, failed
payments, suspension and a grace period. ADR-0001 puts one subscription on
each organization. Tenancy is lower in the module graph than Billing
(docs/09), but plan limits and lapsed subscriptions must still stop farm
actions.

## Decision
1. **States:** `trialing → active → grace → suspended`, and `cancelled`.
   `grace` is the requirements' "past due": farms stay open with a warning
   until `grace_until`. A daily job (`billing:advance-subscriptions`) moves
   ended periods into `grace` and expired grace periods into `suspended`. A
   scheduled cancellation becomes `cancelled` at the end of the period. Every
   transition writes append-only history and the audit log, and state changes
   that affect access email the owner.
2. **Periods are whole UTC dates, inclusive.** A successful payment (at least
   the plan price, in the plan currency, with a reference unique per provider)
   starts a new period today. If the subscription is active and not yet
   expired, the new period starts right after the current one instead. The
   trial length and grace days are platform settings; a plan can override the
   trial length.
3. **Enforcement through a Tenancy contract.** `Tenancy\Contracts\SubscriptionGate`
   (no-op by default) is bound by Billing:
   - `assertFarmUsable` runs in `ResolveFarmContext`. `suspended` or
     `cancelled` → `403 subscription_suspended`.
   - `assertCanAddFarm` / `assertCanAddMember` → `403 plan_limit_reached`.
     Users are counted as distinct people with an active membership across the
     organization's open farms, so joining a second farm is free.
4. **Billing stays reachable.** `/api/v1/billing/*` is organization-level and
   outside the farm route group, so the owner can still see and manage the
   subscription while farms are paused. The web app keeps Subscription and
   Support open on a paused farm.
5. **Plan changes** must fit current usage (`422 plan_limit_exceeded`
   otherwise). Owners may pick only public plans; billing staff may assign any
   active plan. Proration arrives with payment gateways (Phase 14).
6. **Payments** are append-only financial records (the database blocks
   DELETE). Until Phase 14 they are recorded by SFMTP billing staff.

## Consequences
- Tenancy, Access and the farm modules never import Billing classes.
- A missing default plan logs a warning and leaves the organization without a
  subscription. The gate then applies no limits; the admin can fix it by
  assigning a plan.
