# 15 — API guide

For developers connecting to SFMTP: a phone app, an accounting import, a
sensor, a payment gateway. Where to find things:
- **The contract**: every route, field and error in
  [`packages/api-contracts/openapi.yaml`](../packages/api-contracts/openapi.yaml)
  (OpenAPI 3.1).
- **Conventions**: [06 — API contracts](06-api-contracts.md).
- **This page**: how to use them.

## 1. Basics

- **Base URL**: `https://api.example.com/api/v1`. JSON in and out, UTF-8,
  UUIDs for ids, ISO 8601 UTC timestamps.
- **Versioning.** `v1` only grows: new endpoints and new optional fields.
  A breaking change would be `v2`, with notice. Ignore fields you do not
  know.
- **Envelope.** Data is under `data`, lists add `meta` and `links`, and
  errors are `application/problem+json`:

```json
{ "type": "https://docs.sfmtp.app/errors/validation-failed", "title": "The given data was invalid.",
  "status": 422, "code": "validation_failed", "request_id": "01J…", "errors": { "email": ["…"] } }
```

- **Error codes.** Rely on `code`, not the title. Common ones:
  - `unauthenticated` (401);
  - `mfa_setup_required`, `forbidden` (403);
  - `not_found` (404; also used for another farm's records);
  - `version_conflict` (409);
  - `validation_failed` (422);
  - `rate_limited` (429).
- **Request ids.** Quote `X-Request-Id` (or `request_id`) when you report a
  problem.
- **Pagination.** `?cursor=…&per_page=25` (max 100). Follow `links.next`
  until it is null.

## 2. Signing in

```http
POST /auth/login
{ "email": "…", "password": "…", "client": "mobile", "device": { "platform": "android", "name": "Tecno Spark", "push_token": "…" } }
```

- Without MFA, the answer is
  `{"data": {"mfa_required": false, "token_type": "Bearer", "access_token", "expires_in": 900, "refresh_token", "refresh_expires_in"}}`.
- With MFA, you get `{"mfa_required": true, "mfa_token"}`. Then:

```http
POST /auth/mfa/challenge
{ "mfa_token": "…", "code": "123456" }      // or "recovery_code"
```

Use it:
- Send `Authorization: Bearer <access_token>`.
- When a call returns 401, refresh once:
  `POST /auth/refresh {"refresh_token": "…"}` returns a new pair. The old
  refresh token is spent, and using it again ends the session.
- Sign out with `POST /auth/logout`.

Access tokens last 15 minutes. Refresh tokens last 7 days for `web` and
30 days for `mobile`, sliding.

After sign-in:
- `GET /me/workspaces` lists the farms (and portals) the person belongs
  to, with their permissions and dashboards.
- Farm data lives under `/farms/{farm}/…`.

## 3. Writing safely

- **`Idempotency-Key: <uuid>`** on every write from a phone or script
  (required for `client=mobile`). A retry with the same key gets the first
  answer back for 48 hours, and nothing is done twice.
- **Versions.**
  - Records carry a `version`. Send it back as `If-Match: "<version>"` or
    `"version": n` when editing.
  - If someone changed the record meanwhile you get `409 version_conflict`:
    reload, re-apply, retry.
- **Money** is a number, in the farm's currency, shown next to it (for
  example `"amount": 250000, "currency": "UGX"`). It only appears for those
  allowed to see money.

## 4. Offline sync (phones)

The phone app's protocol, open to any client (ADR-0010,
[08](08-offline-sync.md)):

- **Push.** `POST /farms/{farm}/sync/push {"mutations": [...]}` sends up to
  200 mutations in the order they happened. Each mutation is:

  ```
  { mutation_id, entity, op, id, occurred_at, data }
  ```

  - Each is applied through the same rules as the web and answers
    `applied`, `duplicate`, `rejected` (with the reason) or `conflict`.
  - `mutation_id` makes a retried push safe.
- **Pull.** `GET /farms/{farm}/sync/pull?cursor=…` returns everything the
  member may see that changed since the cursor.
- **Limits.** 30 pushes a minute per device.

## 5. Webhooks and devices

**Payment gateways** post to `POST /webhooks/payments/{provider}`.
- The admin portal shows the exact URL for each provider.
- Flutterwave must send the secret hash set in the admin portal as
  `verif-hash`; anything else is refused with 401.
- The body is only a hint. SFMTP asks the gateway to verify the payment
  before booking it, and books it once.

**IoT devices**:
1. Register the device on the farm's structure page, which gives a token
   `sfmtpd_…` shown once.
2. The device posts readings to `POST /iot/readings`:

```http
POST /iot/readings
Authorization: Bearer sfmtpd_…
{ "readings": [ { "metric": "soil_moisture_pct", "value": 31.5, "recorded_at": "2026-10-01T06:00:00Z" } ] }
```

- Up to 100 readings a call, and 120 calls a minute.
- `metric` is snake_case (`^[a-z][a-z0-9_]{1,39}$`).
- `recorded_at` is optional and must be within the last 7 days.
- A lost token is rotated from the same page.

## 6. Exports for other systems

- **Accounting.** The `journal` report exports every ledger line with
  account codes, debits and credits, for import into QuickBooks, Xero, Sage
  or Tally:

  ```
  POST /farms/{farm}/exports {"report": "journal", "format": "csv"|"xlsx", "params": {"from": "…", "to": "…"}}
  ```

  This answers 202. Poll `GET /farms/{farm}/exports/{id}` until it is
  ready, then fetch `…/download`. Exports are built in the background and
  kept 24 hours.
- **Any standard report** (stock, sales, harvests, attendance, …) exports
  the same way. `GET /farms/{farm}/standard-reports` lists them with their
  columns and parameters.

## 7. Limits

| What | Limit |
|---|---|
| Sign-in | 5 a minute per email and IP |
| API (signed in) | 120 a minute per user |
| Sync push | 30 a minute per device |
| Public pages (QR scans) | 60 a minute per IP |
| Webhooks | 60 a minute |
| IoT readings | 120 a minute |
| Exports | 3 in progress per farm, 30 an hour per person |

A `429` carries `Retry-After`. Back off, then retry with the same
`Idempotency-Key`.
