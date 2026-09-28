# 11 — Security

How SFMTP protects accounts, farm data and the platform: what exists, where
it lives in the code, and which test holds it. For incidents and secret
rotation, see the [operations runbook](12-operations.md) §7.6.

## 1. Accounts and sessions

| Control | As built |
|---|---|
| Passwords | At least 10 characters with letters and numbers; hashed with Argon2id (`HASH_DRIVER=argon2id`) |
| Lockout | 5 failed sign-ins lock the account for 15 minutes, growing with further failures; each failure is audited without the password |
| MFA | TOTP with 10 one-time recovery codes. **Required** for platform staff, farm owners, accountants, and every member of a farm that turns on "MFA for all" |
| Access tokens | JWT HS256, 15 minutes, bound to a server-side session (`sid`) checked on every request, so signing out or disabling an account ends it at once |
| Refresh tokens | 7 days (web) or 30 days (phone), sliding and rotated on every use. A refresh token used twice revokes the whole session (theft detection), with a 30-second grace for parallel refreshes (ADR-0007) |
| Web session | The browser never holds a token. The Next.js backend-for-frontend keeps them in `HttpOnly`, `SameSite=Lax` cookies (`Secure` in production) and checks a CSRF token on writes (ADR-0007) |
| Phones | Tokens in the Keystore / Keychain; the local database is encrypted (SQLite3MultipleCiphers, a random 256-bit key kept in the Keystore / Keychain); a revoked device wipes its local data on the next sync (ADR-0015) |
| Rate limits | Sign-in: 5/min per email+IP and 20/min per IP. Refresh: 30/min. API: 120/min per user. Sync push: 30/min per device. Public pages: 60/min per IP. Webhooks: 60/min. IoT readings: 120/min |

## 2. The API surface

- **Response headers**, on every response (`SecurityHeaders` middleware):
  - `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
    `Referrer-Policy: no-referrer`, `Cross-Origin-Resource-Policy: same-site`;
  - `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`
    on JSON;
  - HSTS (one year) over TLS;
  - `Cache-Control: no-store` on signed-in responses.
- **CORS**: only the web app's origin, no credentials. Browsers never call
  the API directly: they go through the BFF.
- **Errors** are problem+json with a request id and no stack traces
  (`APP_DEBUG=false` in the image).
- **Input**:
  - every endpoint validates its input (lengths, types, enums, UUIDs);
  - queries are parameterised, and the few raw SQL fragments use only code
    constants and server-computed dates (reviewed in Phase 15);
  - search terms are bound values, so `%` and `'` are data.
- **Idempotency**: phone writes need an `Idempotency-Key`. Repeats replay
  the first answer for 48 hours.
- **Optimistic locking**: edits carry the version they started from, and a
  stale edit is refused (409) or merged field by field (ADR-0015).

## 3. Tenant isolation

A farm's data is reachable only by that farm's members. Seven layers enforce
this ([02](02-tenant-isolation.md)):
1. routing;
2. membership check (404 for anything else);
3. permissions and record scopes;
4. the ORM farm scope;
5. composite foreign keys;
6. **PostgreSQL row-level security on every table with a `farm_id`**;
7. tests.

Row-level security (Phase 15, ADR-0019):
- Every farm table forces a policy.
- A row is visible only in its farm's context, under an explicit and
  reviewed bypass, or under one narrow extra condition:
  - the signed-in user's own memberships (`app.user_id`);
  - the farms they belong to;
  - platform administration for the platform-facing tables only
    (`app.platform`);
  - a portal party's own links.
- Operational tables have no extra condition at all.
- Security decisions about one known user never depend on session settings,
  so they cannot fail open: the MFA requirement, plan limits and support
  grants.
- The application role is not a superuser; backups use a separate
  read-only role with `BYPASSRLS`.

Portals (suppliers and customers) read a linked farm's records inside that
farm's context, filtered to their own supplier or customer record, and
never with a bypass (ADR-0016). Platform support reaches a farm only with
the owner's time-boxed, read-only grant, and every request is written to
the farm's audit log (ADR-0005).

## 4. Data protection

- **Secrets at rest.**
  - Integration settings (API keys, SMTP passwords, the FCM service
    account) are encrypted with `APP_KEY`. The API only shows *which*
    settings are set.
  - IoT device tokens are stored as SHA-256 hashes and shown once.
  - Invitation tokens are hashed.
- **Audit log**:
  - append-only (database triggers refuse UPDATE and DELETE);
  - passwords, tokens and codes are masked before they are stored;
  - records who, what, before and after, IP, device and request id.
- **Ledger and trace history** are append-only. Corrections are new
  entries, and the trace chain is hash-linked and verified nightly.
- **Files**:
  - photos and documents are stored by SHA-256 with an allow-list of
    types and a size limit;
  - they are served only through permission checks, with `nosniff`;
  - exports are private to the member who asked and expire after 24 hours.
- **Public pages** (QR scans) show only fields the farm approved, from a
  signed snapshot. Scan statistics are anonymous counts (ADR-0014).
- **Payments**:
  - card and mobile-money details never touch SFMTP; Flutterwave hosts the
    checkout;
  - every payment is verified with the gateway before anything is booked;
  - webhooks need the shared secret hash (ADR-0018).
- **Backups** hold every farm's data: encrypt them at rest and restrict
  access (runbook §4).

## 5. Dependencies

- **CI fails on known vulnerabilities:**
  - `composer audit` for the API;
  - `npm audit --omit=dev --audit-level=high` for the web.
- **Dependabot** opens weekly updates for Composer, npm, pub (Flutter),
  Docker base images and GitHub Actions (`.github/dependabot.yml`).
- **Phase 15 audit (2026-09-28):** Composer found no advisories, npm
  reported 0 vulnerabilities.

## 6. Security testing

These run on every build:

| Suite | Holds |
|---|---|
| `Tenancy/CrossTenantIsolationTest` | Every farm route, visited as another farm's owner with every route parameter pointing at the victim, is a 404, and no victim row changes |
| `Tenancy/FarmTenancyTest` | RLS on every farm table (a table without a forced policy fails the build), raw SQL isolation, own-membership reads, platform reads no operational data, composite keys |
| `Access/RoleBoundaryTest` and each module's boundary tests | The role boundaries of [04 §6](04-roles-and-permissions.md): field worker, agronomist, livestock, store, accountant, manager, platform admin, portals |
| `Security/SecurityTest` | Headers, CORS, forged tokens (`alg: none`, altered claims, wrong key, expired, an MFA token used as an access token), revocation on sign-out, hostile input on list endpoints |
| `Identity/*` | Lockout, MFA, refresh rotation and reuse detection |
| `Support/AuditLogTest` | Append-only audit, masking |
| `Traceability/JourneyTest` | The tamper check catches an altered event |

**Penetration test.** Phase 15 reviewed the application against the OWASP
API Security Top 10:

| Risk | Covered by |
|---|---|
| Broken object-level authorisation | Cross-tenant sweep and RLS |
| Broken authentication | Token tests, lockout, MFA |
| Broken property-level authorisation | Validated input, guarded mass assignment, money fields hidden by permission |
| Unrestricted resource use | Rate limits, pagination caps, export limits |
| Broken function-level authorisation | Role boundary suites |
| Sensitive business flows | Approval thresholds, four eyes, verified payments |
| SSRF | No user-supplied URLs are fetched; provider hosts are fixed per adapter |
| Security misconfiguration | Headers, debug off, CORS |
| Inventory | OpenAPI coverage test: every route is documented |
| Unsafe API consumption | Gateway verification, provider failures contained |

**Before launch**, an external penetration test of the staging environment
is still to be done (see the [launch checklist](16-launch-checklist.md)).

## 7. Reporting a vulnerability

Email the security contact in the launch checklist. Do not open a public
issue. Expect an acknowledgement within 2 working days.
