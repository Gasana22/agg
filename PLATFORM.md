# Farmsap — Smart Farm Management & Traceability Platform

This is the admin platform being built for AGG Farm, per the Farmsap spec
(Next.js admin portal + Laravel API + PostgreSQL; Flutter mobile app to
follow in a later phase). The repo root still holds the original static
marketing site (`index.html` etc.) — that is unrelated and untouched.

## Layout

- `backend/` — Laravel 12 REST API (JWT auth via `tymon/jwt-auth`, RBAC via
  `spatie/laravel-permission`).
- `frontend/` — Next.js 16 (TypeScript, Tailwind v4, hand-rolled ShadCN-style
  UI primitives, React Query, React Hook Form + Zod, Framer Motion).
- `docker-compose.yml` — local PostgreSQL for development.

## Local development

1. **Database**

   ```
   docker compose up -d postgres
   ```

   Or point `backend/.env` at any Postgres 16 instance. Defaults assume
   `sfmtp`/`sfmtp`/`sfmtp` (db/user/password) on `127.0.0.1:5432`.

2. **Backend**

   ```
   cd backend
   cp .env.example .env   # already done in this checkout
   composer install
   php artisan key:generate
   php artisan jwt:secret
   php artisan migrate --seed
   php artisan serve --port=8000
   ```

   The seeder creates the 10 Farmsap roles (`system_administrator`,
   `farm_owner`, `farm_manager`, `agronomist`, `livestock_manager`,
   `store_manager`, `accountant`, `field_worker`, `supplier`, `customer`)
   and a system admin user: `admin@farmsap.test` / `password`.

3. **Frontend**

   ```
   cd frontend
   cp .env.example .env.local   # set NEXT_PUBLIC_API_URL to the backend URL
   npm install
   npm run dev
   ```

   Visit `http://localhost:3000` — it redirects to `/login`. Sign in with
   the seeded admin, or register a new (customer-role) account.

## What's wired up

- JWT auth: register / login / me / logout / refresh (`routes/api.php`).
- RBAC roles seeded and attached to the JWT's custom claims.
- `farms` + `farm_user` tables model multi-farm ownership/staffing
  (Farm Structure Management, module 2 of the spec).
- Frontend: auth context (`src/lib/auth-context.tsx`) backed by a typed
  axios client, protected `/dashboard` shell with a sidebar listing every
  Farmsap module as a placeholder route.
- `routes/api.php` has commented route-group scaffolding for each of the
  16 modules in the spec (crops, livestock, workers, finance, inventory,
  assets, traceability, etc.) as a landing spot for the next phase of work.

## Not yet built

Everything module-specific: crop/livestock/worker/finance/inventory/asset
CRUD, GIS, QR-code traceability, reporting, notifications, the Flutter
mobile app, and Docker images for the app services themselves. This
scaffold's job was to get auth + RBAC + the two app shells talking to each
other on a real Postgres database, ready for those modules to be built
into it one at a time.
