# 12 — Operations and disaster recovery runbook

This runbook is for whoever is on call for SFMTP in production. Set-up
lives in the [deployment guide](13-deployment.md), and security rules live
in the [security guide](11-security.md).

## 1. What runs where

| Component | Runs as | State | If it is lost |
|---|---|---|---|
| API | `sfmtp-api` image (FrankenPHP), 2 or more containers | none | Start another container |
| Queue worker | same image, `php artisan queue:work` | none (jobs live in Redis) | Start another container; unfinished jobs retry |
| Scheduler | same image, `php artisan schedule:work`, **one** container | none | Start one again; jobs use `onOneServer()` |
| Web | `sfmtp-web` image (Next.js standalone) | none | Start another container |
| PostgreSQL 16 | managed service, or self-managed with WAL archiving | **all business data** | §5 restore, §7 scenarios |
| Redis | managed or container | cache, queue, rate limits, circuit breaker | Restart it. The cache rebuilds, pending jobs are lost (see §7.5) |
| Object storage | S3 or compatible, with versioning | photos, documents, exports | §7.4 |

The scheduled jobs are defined in `backend/routes/console.php`:

| Job | When | What |
|---|---|---|
| `billing:advance-subscriptions` | 00:30 daily | Subscriptions move into grace, then suspension |
| `trace:verify-chain` | 02:00 daily | Tamper check of every farm's trace chain; alerts the owner |
| `exports:prune` | hourly | Deletes expired exports and fails stuck ones |
| `infra/deploy/backup.sh` | daily (cron on the DB host or a job) | Logical backup (§4) |

## 2. Monitoring

- **Health.**
  - `GET /up` returns 200 when the API boots. The image healthcheck uses it.
  - The admin portal **System** page shows database, queue, cache and
    storage health, failed jobs, and the last backup. A backup counts as
    *down* when the last run failed or none succeeded in 26 hours.
- **Integrations.**
  - `/admin/integrations` shows each provider's health (ok, degraded, down),
    its last error and its failures in a row (ADR-0018).
  - A provider is skipped for 5 minutes after 3 failures in a row.
- **Logs.**
  - Containers log to stderr (`LOG_CHANNEL=stderr`). Set
    `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` for JSON lines.
  - Every API response carries an `X-Request-Id`, which also appears in
    error bodies (problem+json `request_id`) and audit entries. Search logs
    by it.
- **Alert on:**
  - API 5xx above 1% for 5 minutes;
  - p95 latency above 800 ms for 10 minutes;
  - queue depth above 1,000 or its oldest job older than 15 minutes;
  - failed jobs above 0 per hour;
  - no successful backup in 26 hours;
  - WAL archiving lag above 5 minutes;
  - disk above 80%;
  - a `trace:verify-chain` failure (the owner is notified too).

## 3. Capacity

The load test ([infra/load](../infra/load/README.md)) drives the real sync
API as phones do.
- On **one 4-core machine running everything**, 60 phones stayed within
  budget:
  - 19.7 field activities a second, 24 times a peak hour of a
    10,000-activity day;
  - push p95 271 ms, no errors.
- Saturation starts between 60 and 100 phones on that machine. Past it,
  latency grows but nothing fails.
- **To scale:**
  - put the database on its own host;
  - add API containers, which are stateless;
  - keep one scheduler.
- Run the load test against staging before each major release, and after
  any change to sync or dashboards.

## 4. Backups

**Objectives:**
- RPO (data that may be lost) is at most 15 minutes.
- RTO (time to be back) is at most 4 hours.

| Layer | How | Retention |
|---|---|---|
| Continuous WAL archiving | `archive_mode = on`, `archive_timeout = 60`, or the managed service's point-in-time recovery | 7 days minimum |
| Daily base backup | managed snapshots, or `pg_basebackup` | 14 days |
| Daily logical dump | `infra/deploy/backup.sh` (`pg_dump -Fc`, SHA-256, recorded in the admin portal) | 30 days, copied off-site |
| Object storage | bucket versioning and a replica bucket in another region | 30 days of versions |

**Dumps need the backup role.** Every farm table forces row-level security,
so the application role cannot dump the database: `pg_dump` stops at the
first farm table. Backups therefore run as **`sfmtp_backup`**, created by
`infra/docker/postgres/10-app-role.sh`:
- a read-only login (`pg_read_all_data`);
- with `BYPASSRLS`;
- with nothing else.

Keep its password in the secret store, used only by the backup job.

Encrypt dumps at rest. Object storage encryption is enough, or use `age` or
`gpg` before copying off-site. Every dump holds every farm's data.

## 5. Restore

Always restore into a **new** database, check it, then point the
application at it. Never restore over the live database.

```bash
# 1. Get the dump and its checksum (admin portal → System → backups, or the bucket).
# 2. Restore as an administrator that can bypass row-level security (superuser,
#    or CREATEDB + BYPASSRLS); objects end up owned by the application role.
PGHOST=... PGUSER=postgres PGPASSWORD=... APP_DB_USER=sfmtp EXPECTED_SHA256=<sum> \
  infra/deploy/restore.sh /backups/sfmtp-20261001T020000Z.dump sfmtp_restored
# 3. Check it (row counts, policies, the application's view of it):
DB_DATABASE=sfmtp_restored php artisan migrate:status
DB_DATABASE=sfmtp_restored php artisan trace:verify-chain
# 4. Switch: set DB_DATABASE=sfmtp_restored for api, worker and scheduler, restart them.
# 5. Tell the phones nothing: they sync from their cursors; see §7.1 for data after the backup.
```

`restore.sh` loads in three passes:
1. the schema, as the application role;
2. the rows, as the administrator, past row-level security;
3. the indexes, constraints, triggers and policies, as the application
   role.

The copy therefore enforces the same isolation as the original.

**Point in time.** A managed service restores to a timestamp from its
console. Self-managed:
1. Restore the last base backup into a new data directory.
2. Set `restore_command` (copy from the WAL archive) and
   `recovery_target_time`.
3. Create `recovery.signal` and start. PostgreSQL replays WAL to the target
   and promotes.

`infra/deploy/pitr-drill.sh` scripts exactly these steps.

## 6. Drills

Run both drills **every quarter** and after any change to the database
set-up, then record the results here.

| Drill | Script | Proves |
|---|---|---|
| Restore | `infra/deploy/restore-drill.sh` | A fresh dump restores into a new database. All of the following match or hold: every table's row count, row-level security policies, append-only triggers, the application role seeing no farm without context, no pending migrations, intact trace chains. It is timed against the RTO. |
| Point in time | `infra/deploy/pitr-drill.sh` | Base backup plus archived WAL recovers to a chosen second: every commit before it and none after. It is measured against the RPO. |

### Drill log

| Date | Drill | Environment | Result |
|---|---|---|---|
| 2026-09-28 | Restore | Dev container (demo data plus load-test farms: 135 tables, 133,046 rows, 6.8 MB dump) | **PASS.** Backup 1.5 s, restore 4.2 s, verification 1.1 s, 6.8 s in all (RTO 4 h). 103 policies and 35 triggers restored. RLS holds on the copy; 7 trace chains verified. |
| 2026-09-28 | Point in time | Dev container, PostgreSQL 16, `archive_timeout = 60 s` | **PASS.** Base backup 6.0 s, recovery 1.8 s. Recovered to the second: markers 1–5 back, 6–10 absent. WAL shipped at least every 60 s, so at most a minute of commits is at risk (RPO 15 min). |

**Restore time grows with size.** The measured rate is about 32,000 rows a
second on a small container. A 4-hour RTO leaves room for several hundred
million rows. Re-measure on production-sized data before launch (see the
[launch checklist](16-launch-checklist.md)).

## 7. Scenarios

### 7.1 Database lost or corrupted
1. Stop the API, worker and scheduler: maintenance mode, or scale to zero.
   Phones keep working offline and queue their changes.
2. Restore to the latest point in time before the fault (§5) into a new
   database or instance.
3. Run the §5 checks, then switch and start.
4. Phones push their queued mutations, which are idempotent by
   `mutation_id`, so work done offline during the outage is not lost. Web
   entries made after the recovery point are lost; tell affected farms,
   using the audit log for who and what.
5. Write a post-incident note in §8.

### 7.2 Bad deployment or migration
- The application: roll back to the previous image tag.
- A migration that changed data: restore to just before the deploy (point
  in time, §5) into a new database, and compare the affected tables.
- Migrations only ever add. Their `down()` exists for development and is
  not relied on in production.

### 7.3 Data changed by mistake (one farm)
- Farm data is corrected through its documents: reversals, voids and
  correction events. Ledger entries and trace history are append-only by
  design.
- When records were really destroyed:
  1. Restore a point-in-time copy into a scratch database.
  2. Export that farm's rows (as the backup role, `WHERE farm_id = …`).
  3. Re-enter them through the application with the owner.
- Never copy rows straight into production tables.

### 7.4 Object storage lost
- Restore the bucket from versions, or from the replica bucket.
- Database rows keep each file's SHA-256, so the restored objects can be
  checked.
- Exports are temporary and are simply rebuilt.

### 7.5 Redis lost
- Restart it. The cache warms again, and the rate limits and circuit
  breaker start from zero.
- Queued jobs are lost, so re-run the day's work:
  - exports: users ask again;
  - notice copies: best effort, the inbox is the record;
  - push notifications: best effort.

### 7.6 A secret leaked
| Secret | Rotate | Effect |
|---|---|---|
| `JWT_SECRET` | New value, restart the API | Everyone signs in again; refresh tokens stop working |
| `APP_KEY` | Re-encrypt integration settings first (admin re-enters secrets), then change | Encrypted settings become unreadable if skipped |
| Database passwords | `ALTER ROLE … PASSWORD`, update the secret store, restart | None |
| Provider keys (SMS, email, payments, maps) | Rotate at the provider, update in `/admin/integrations` | None |
| An IoT device token | "Rotate token" on the device | The old token stops at once |

Then:
- Check the audit log for use of the leaked secret: search by IP and by
  user.
- End the sessions involved by disabling the accounts in the admin portal
  (Accounts). This revokes their sessions; re-enable them once they have a
  new password.

### 7.7 Payment gateway down
Online payments fail with `payment_gateway_error`, and nothing is booked
without the gateway's confirmation. Farms keep recording payments by hand.
When the gateway is back:
- opening a pending payment re-checks it;
- webhooks retry;
- a payment confirmed late is booked once.

## 8. Post-incident notes

Keep a dated note for each incident: impact, timeline, cause, what was done,
what changes. File it next to this runbook, or in the team's incident tracker.
