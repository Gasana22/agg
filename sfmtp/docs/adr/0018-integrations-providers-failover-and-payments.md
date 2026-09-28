# ADR-0018 — Integrations: provider directory, failover, adapters and verified online payments

**Status:** Accepted (2026-09-28, Phase 14)

## Context
Phase 2 let the platform admin store integration providers (kind,
provider, settings, default), but nothing called them. Phase 14 connects
SFMTP to the outside world:
- maps, weather, SMS, email and push;
- Flutterwave for subscriptions and customer invoices;
- extension points for accounting packages and IoT devices.

Five questions came up:
- how business modules call a provider without knowing which one is
  configured;
- what happens when a provider is down;
- how to take money online without trusting the browser or a forged
  webhook;
- which notices go beyond the inbox and who decides;
- how outside systems (accounting, sensors) connect without new
  per-vendor code.

## Decision
1. **A foundation module with a directory contract.** The new
   `Integrations` module holds the adapters and depends on no business
   module.
   - It reads providers through the `ProviderDirectory` contract.
   - Platform implements it (`PlatformProviderDirectory`) over
     `integration_providers`. Candidates are the active providers of a
     kind, ordered by default first, then `priority` (lower first), then
     name.
   - Secrets stay encrypted in the settings column. The API only ever
     shows which settings are set.
2. **Ordered failover with a circuit breaker.** `Router::run(kind, fn)`
   tries each candidate in order until one succeeds.
   - A connection error, an HTTP error or a retryable `ProviderFailure`
     moves on to the next provider.
   - A non-retryable failure stops at once, because another provider
     would refuse it too. Examples: an invalid phone number, a refused
     recipient, a bad request.
   - After 3 failures in a row a provider is skipped for 5 minutes, and
     is still tried last if nothing else is left.
   - Each success or failure is written to the provider row:
     `last_success_at`, `last_failure_at`, `last_error` and
     `consecutive_failures`. Admin shows this as ok, degraded, down or
     unknown.
   - With nothing left, `IntegrationUnavailable` names the kind and each
     provider's error.
   - Admin can test any SMS, email, weather, maps, payment or push
     provider with one button. The test goes to that provider only and
     uses no failover.
3. **Adapters per kind**, each with an HTTP contract test:

   | Kind | Adapters | Notes |
   |---|---|---|
   | SMS | Africa's Talking, Twilio | Numbers normalised to E.164 (+256 by default); 3 segments at most |
   | Email | SMTP, SendGrid | The `providers` Laravel mailer sends every app email through the gateway, and only logs when no provider is set |
   | Weather | OpenWeather (One Call 3.0), Tomorrow.io | One shape: current, hourly and daily forecast. Advisories: heavy rain, heat, spray window. Cached 30 min per ~1 km cell |
   | Maps | Mapbox, Google Map Tiles, OpenStreetMap | `GET /map-config` returns the base layer. Only public tokens are allowed (Mapbox `pk.`); OSM is the fallback |
   | Push | FCM | Admin's service account first, then the credentials file, then the log sender. APNs is reached through FCM |
   | Payments | Flutterwave | Hosted checkout for mobile money and cards |

4. **Online payments are verified, never trusted.** An `online_payments`
   row is created before the checkout. It holds:
   - a random `SFMTP-` reference;
   - the purpose, amount and currency;
   - who started it and where to return.

   Then:
   - The browser redirect and the webhook only trigger a check. SFMTP asks
     the gateway to verify the transaction by reference.
   - The payment only counts when the gateway reports success with the
     same currency and at least the amount.
   - Fulfilment runs once, under a row lock, so the redirect and the
     webhook arriving together book it once.
   - Webhooks need the shared secret hash (`verif-hash`) and are rate
     limited.
   - Payments never fail over. The checkout belongs to the provider that
     created it.
   - Business modules register a `PaymentPurpose` (tag
     `sfmtp.payment-purposes`):
     - Billing's `subscription` records the subscription payment and
       extends the period (ADR-0008).
     - Sales' `customer_invoice` books a confirmed payment against the
       invoice, capped at what is still owed and idempotent on the
       gateway reference (ADR-0012).
   - If booking fails after the gateway confirmed the money, the payment
     stays succeeded with the reason recorded, so staff can finish it.
   - Customers can pay a farm online only when the farm turns it on and
     gives its Flutterwave subaccount, so the money goes to the farm.
5. **Notice copies by kind, chosen by the member.**
   - The inbox (ADR-0015) is still the record.
   - A short list of notice kinds is also copied by email and/or SMS.
     Examples: a task assigned or rejected, a sales order, supplier
     dispatches and invoices, a finished export.
   - Each member picks their channels: email on and SMS off by default.
     SMS needs a phone number.
   - Copies are sent by a queued job, marked on the notice, and never
     block the action.
6. **Extension points instead of per-vendor code.**
   - **Accounting.** A `journal` standard report exports every ledger
     line with account codes, debit and credit, in CSV or Excel. Packages
     such as QuickBooks, Xero, Sage or Tally import it.
   - **IoT.** Farms register devices (tank level, soil probe, weather
     station, flow meter, …), each at a plot or location. Each device
     gets a bearer token (`sfmtpd_…`), shown once and stored only as a
     hash.
   - Devices post up to 100 readings per call to `POST /iot/readings`.
     The public route is rate limited. Each reading is a snake_case metric
     name, a value and an optional time, which must fall in the last
     7 days. Readings are append-only.

## Consequences
- A second provider of a kind is only configuration. The first one that
  answers wins, so admins order providers by cost or reliability.
- The circuit breaker state lives in the cache. After a cache flush each
  provider is tried again, which is harmless.
- Weather and map settings are platform-wide. Farms cannot yet bring
  their own keys.
- Payment amounts come from SFMTP, not the browser. A partial or wrong
  currency payment is marked failed and never booked. Refunds are made in
  the Flutterwave dashboard and recorded by hand.
- IoT readings are stored but not yet acted on: thresholds and alerts
  are future work.
- There is no direct push to an accounting package. The export covers
  the need until a farm asks for a live sync.
