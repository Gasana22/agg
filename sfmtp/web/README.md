# SFMTP web (Next.js 16)

Responsive web app for every SFMTP workspace: platform admin, farms, and the
supplier and customer portals.

## How it talks to the API

The browser never holds tokens. `src/app/api/*` is a backend-for-frontend:

| Route | Does |
|---|---|
| `POST /api/auth/login` | Signs in against the API; stores access + refresh tokens in `httpOnly` cookies (or a short-lived pending-MFA cookie) |
| `POST /api/auth/mfa` | Completes sign-in with an authenticator or recovery code |
| `POST /api/auth/logout` | Revokes the server session and clears cookies |
| `/api/proxy/*` | Forwards to `SFMTP_API_URL/api/v1/*` with the bearer token; refreshes an expired session once (deduplicated across parallel requests); refuses cross-site writes |

`src/proxy.ts` only redirects signed-out visitors to `/login`; every API call is
still authorised by the backend. Navigation and dashboards are built from the
permissions and widget lists the server returns (`/me/workspaces`,
`/farms/{farm}/dashboards/{dashboard}`).

The typed API client (`src/lib/api/schema.d.ts`) is generated from
`../packages/api-contracts/openapi.yaml`:

```bash
npm run api:types
```

## Maps

The farm map (`/farms/{id}/structure`) uses Leaflet with any XYZ tile server.
`NEXT_PUBLIC_MAP_TILE_URL` and `NEXT_PUBLIC_MAP_ATTRIBUTION` choose it; the
default is OpenStreetMap. Respect the provider's usage policy in production:
set a commercial or self-hosted tile URL. `NEXT_PUBLIC_*` values are fixed at
build time (`--build-arg` in the Docker image).

## Public pages

`/login`, the password reset pages, `/invite/{token}` and `/portal-invite/{token}`
work signed out. The BFF forwards `invitations/*` and `portal-invitations/*`
API calls without a session, so the invitation pages can read an invitation
and accept it by creating an account.

## Portals

Suppliers and customers sign in to `/supplier/{party}` and `/customer/{party}`
(ADR-0016). A person with several workspaces (farms and portals) switches
between them in the header.

## Reports and analytics

`/farms/{id}/reports` holds the finance statements (for those who read the
books), the standard reports with a preview and CSV / Excel / PDF export, the
member's own exports (kept 24 hours), and the metric catalogue with each
number's definition and trend (ADR-0017). The farm map has an *Activity*
layer (a heat map of located work), and the QR codes list prints labels for
several codes in one run.

## Develop

```bash
cp .env.example .env.local   # SFMTP_API_URL=http://localhost:8000
npm install
npm run dev                  # http://localhost:3000
```

## Check

```bash
npm run lint && npm run typecheck && npm test && npm run build
```
