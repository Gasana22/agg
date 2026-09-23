# SFMTP web (Next.js 16)

Responsive web app for every SFMTP workspace (platform admin, farms; supplier
and customer portals arrive in Phase 12).

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
