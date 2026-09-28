# Load test

The requirement is **10,000+ field activities a day with headroom**. This
test drives the real API the way the phones do.

**Phones** push a working day through `POST /farms/{farm}/sync/push`:
- check-in;
- for each task: start and submit, each with a five-point GPS track;
- a pull every ten pushes;
- check-out.

**Managers** read the manager dashboard, the task list, attendance and
workers at the same time.

## Run it

Use development or staging only. The prepare command refuses to run in
production.

```bash
cd sfmtp/backend
php artisan sync:loadtest-prepare --devices=60 --tasks=20 --out=/tmp/plan.json
node ../infra/load/sync-load.mjs /tmp/plan.json --api=http://localhost:8000/api/v1 --duration=120
```

- `sync:loadtest-prepare` creates a separate *Load Test Farm* with:
  - an owner and a manager;
  - N field workers, each with a phone token (valid 15 minutes);
  - one task per worker per activity.
- Options for `sync-load.mjs`:
  - `--push-every` (ms per phone, default 2500; the API allows 30 pushes a minute per device);
  - `--readers` (default 4) and `--read-every` (ms, default 2000);
  - `--p95-push` (default 500 ms), `--p95-read` and `--p95-pull` (default 800 ms).
- The script exits non-zero when a p95 budget is missed or more than 0.5% of
  requests fail.

## Budget

| | p95 | Errors |
|---|---|---|
| Sync push | < 500 ms | < 0.5% |
| Dashboard and list reads | < 800 ms | < 0.5% |
| Sync pull | < 800 ms | < 0.5% |

The rates the results are compared with:
- 10,000 activities a day is 0.12 a second on average.
- If 30% of them fall in one peak hour, that is 0.83 a second.

## Results (2026-09-28)

**Machine:** one 4-core container that runs everything: the API,
PostgreSQL 16, Redis and the load generator.

**API:** PHP 8.4 with OPcache, 8 worker processes, config and routes cached,
debug off. This matches the FrankenPHP image's one PHP process per request.

| Phones | Readers | Activities/s | × peak hour | Push p95 | Read p95 | Pull p95 | Errors | Result |
|---|---|---|---|---|---|---|---|---|
| 60 | 4 | 19.7 | 24× | 271 ms | 253 ms | 285 ms | 0 | **within budget** |
| 100 | 4 | 20.8 | 25× | 1,444 ms | 968 ms | 890 ms | 0 | over budget (saturated) |
| 150 | 8 | 30.7 | 37× | 2,359 ms | 2,196 ms | 1,779 ms | 35 reads rate-limited (one manager token) | over budget (saturated) |

What the runs show:
- **Within budget.** Sixty phones syncing every 2.5 seconds, with four
  dashboards open, stay within budget at 24 times a peak hour.
  - That is 2,388 activities and 12,000 GPS points in two minutes.
  - At that rate the whole 10,000-activity day would take under 9 minutes.
- **Saturation.** It starts between 60 and 100 phones on this one box.
  - Past that point latency grows but nothing fails.
  - The only errors were the per-user rate limit, which is doing its job:
    eight readers shared one manager's token.
- **Production sizing.** Run the API on its own host, apart from the
  database, and add API containers (they are stateless) to scale out. See
  the deployment guide (`docs/13-deployment.md`).
