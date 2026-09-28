# 13 — Deployment

How to run SFMTP in production. Local development uses
`infra/docker/compose.yaml` (see the [README](../README.md)). Operations
after launch are in the [runbook](12-operations.md).

## 1. Shape

```
             ┌──────────── TLS (load balancer / ingress) ────────────┐
 browsers →  │  web (Next.js BFF, :3000)  ──►  api (FrankenPHP, :8000)│  ← phones, gateways, devices
             └───────────────────────────────────────────────────────┘
                                  │ worker (queue:work) ×1+    scheduler (schedule:work) ×1
                                  ▼
                 PostgreSQL 16 (+ WAL archiving) · Redis 7 · S3-compatible storage
```

- **Images.** CI builds both on every push:
  - `infra/docker/api.Dockerfile`: one image runs the **api**, **worker**,
    **scheduler** and one-off **migrate** containers;
  - `infra/docker/web.Dockerfile`: the web app.
- **Public hostnames.**
  - The web app (`app.example.com`) for browsers.
  - The API (`api.example.com/api/v1`) for:
    - phones;
    - payment gateway webhooks (`/api/v1/webhooks/payments/{provider}`);
    - IoT devices (`/api/v1/iot/readings`).
- **Browsers never call the API directly.** CORS allows only the web origin.
- **Scaling.** api, web and worker are stateless: run two or more for
  availability. Run **exactly one** scheduler.

## 2. Prerequisites

| Service | Requirement |
|---|---|
| PostgreSQL | 16, with point-in-time recovery (managed) or WAL archiving (runbook §4). Two roles, below |
| Redis | 7; persistence optional (cache, queue, limits) |
| Object storage | S3 or compatible, **versioning on**, private bucket |
| Email | An SMTP or SendGrid account, set later in the admin portal |
| TLS | Certificates for both hostnames; the load balancer passes `X-Forwarded-*` |
| Push | A Firebase project (Android and iOS apps), service account JSON |

**Database roles.** Use `infra/docker/postgres/10-app-role.sh`, or its SQL
on a managed service:
- **Application role** (`sfmtp`): a login, **not** a superuser, owner of the
  database and the `public` schema. Row-level security applies to it.
- **Backup role** (`sfmtp_backup`): a login with `pg_read_all_data` and
  `BYPASSRLS`, used only by the backup job.

## 3. Configuration

**API, worker and scheduler** (environment variables; keep secrets in the
platform's secret store):

| Variable | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` (the image defaults) |
| `APP_KEY` | `php artisan key:generate --show`; **keep it safe**, it encrypts integration secrets |
| `APP_URL` | `https://api.example.com` |
| `JWT_SECRET` | 64 random characters (`openssl rand -hex 32`) |
| `HASH_DRIVER` | `argon2id` |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `pgsql`, the application role |
| `REDIS_HOST`, `REDIS_PASSWORD`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis` | |
| `SFMTP_MEDIA_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT` (if not AWS), `EXPORTS_DISK=s3` | Photos, documents and exports |
| `MAIL_MAILER` | `providers` (email goes through the providers set in the admin portal) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Default sender |
| `SFMTP_WEB_URL` | `https://app.example.com` (links in emails, CORS) |
| `TRUSTED_PROXIES` | The load balancer's addresses (or `*` behind a private network) |
| `SFMTP_FCM_CREDENTIALS` | Path to the Firebase service account file, unless it is set in the admin portal |
| `LOG_CHANNEL=stderr`, `LOG_LEVEL=warning`, `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` | |

**Web:**

| Variable | Value |
|---|---|
| `SFMTP_API_URL` | The API as the web container reaches it, e.g. `http://api:8000` |
| `NEXT_PUBLIC_API_URL` (build argument) | `https://api.example.com`, shown to admins as the payment webhook base |
| `NEXT_PUBLIC_MAP_TILE_URL`, `NEXT_PUBLIC_MAP_ATTRIBUTION` (build arguments, optional) | The fallback map; the live map provider is set in the admin portal |

**Phone app** (build flags, see `mobile/README.md`):
`--dart-define=SFMTP_API_URL=https://api.example.com/api/v1` and the
Firebase settings. Release builds come from the *SFMTP mobile release*
workflow.

## 4. First deployment

```bash
# 1. Database roles and database (once).
# 2. Migrate and seed the permission list and global catalogues (never demo data outside local/testing):
docker run --rm --env-file api.env sfmtp-api sh -c "php artisan migrate --force && php artisan db:seed --force"
# 3. Start api (2+), worker (1+), scheduler (1), web (2+) behind TLS.
# 4. The first platform administrator (gets an email to choose a password; MFA at first sign-in):
docker run --rm --env-file api.env sfmtp-api php artisan platform:create-admin ops@example.com "Ops Lead"
```

Email must work for step 4. Until a provider is added in the admin portal,
the `providers` mailer only writes messages to the log. To get the first
link out, either:
- set `MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` and
  `MAIL_PASSWORD` for the first run; or
- read the reset link from the log once, then add an email provider.

Then, in the admin portal:
1. **Integrations**: add email, SMS, maps, weather, push and Flutterwave.
   Press **Test** on each, and paste the webhook URL into Flutterwave with
   its secret hash.
2. **Plans**: check prices and limits.
3. **Settings**: platform name, support contact.
4. **System**: every check green, and a first backup recorded (runbook §4).

## 5. Every deployment

1. CI is green on the commit: API on PostgreSQL and MySQL, contract, web,
   mobile, images and dependency audits.
2. Build and tag the images with the commit SHA; never deploy `latest`.
3. Run the migrations (`php artisan migrate --force`). Then run
   `php artisan access:sync-permissions`; `db:seed` already does this.
4. Roll api, worker and web: new containers first, then the old ones
   drain. Restart the scheduler last.
5. Smoke test:
   - `GET /up`;
   - sign in to the web app;
   - open a farm dashboard;
   - admin **System** is green.

Migrations only add (new tables, columns and policies), so the previous
release keeps running while the new one starts. A change that removes
anything ships in two releases: stop using it, then remove it.

**Rollback.** Redeploy the previous image tag. If a migration changed data,
see runbook §7.2.

## 6. Scheduled work

| What | Where |
|---|---|
| Subscriptions, tamper check, export pruning | The scheduler container (`schedule:work`) |
| Daily backup | Cron or a scheduled job running `infra/deploy/backup.sh` as the backup role (runbook §4) |
| Restore and point-in-time drills | Quarterly, by hand (runbook §6) |
| Dependency updates | Dependabot, weekly |

## 7. Sizing

A starting point for a few hundred farms:

| Component | Size |
|---|---|
| api | 2 × (2 vCPU, 2 GB) |
| worker | 1 × (1 vCPU, 1 GB) |
| web | 2 × (1 vCPU, 1 GB) |
| PostgreSQL | 4 vCPU, 16 GB, SSD, with a standby replica |
| Redis | 1 GB |

On one 4-core machine running everything, 60 phones syncing constantly
stayed within the latency budget, at 24 times the peak hour of a
10,000-activity day (runbook §3). Watch p95 latency and the database's CPU,
and add api containers first.
