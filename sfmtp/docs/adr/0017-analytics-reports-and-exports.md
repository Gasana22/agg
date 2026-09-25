# ADR-0017 — Analytics: metric catalogue, health scores, standard reports and exports

**Status:** Accepted (2026-09-25, Phase 13)

## Context
Phase 13 finishes the dashboards and adds reporting. Five questions came up:
- how to keep a number meaning the same thing on every screen;
- how to score crop and animal health in a way farmers can check;
- how to offer reports across modules without a free-form query builder;
- how to produce files without blocking requests or leaking data;
- how to show where work happens without exposing who did it.

## Decision
1. **One metric definition.** Each KPI is defined once in the dashboard
   registry, with its label, format, permission, value and, where it
   applies, its previous-period value. The metric catalogue
   (`GET /farms/{farm}/metrics`) lists those definitions with:
   - a plain-language description;
   - the module;
   - the dashboards that show it.

   `GET /farms/{farm}/metrics/{key}` adds the previous period, the change
   and, for period metrics, a series. The series is per day up to 31 days,
   then per week, then per month. Each point uses the same code as the
   dashboard. Metrics keep their dashboard permission and are cached the
   same way, with the permission fingerprint in the key.
2. **Health scores explain themselves.** Every open crop cycle and every
   active animal starts at 100.
   - A cycle loses 5, 15, 30 or 50 points for each open pest or disease
     incident of low, medium, high or critical severity.
   - An animal loses 20 for an overdue vaccination or deworming, 25 for
     losing 5% or more of its weight, and 15 for a treatment or injury in
     the last 30 days.

   The farm score is the average. Each score falls in a band: good (80 and
   above), watch (50 to 79) or poor (below 50). Each score carries the
   reasons it dropped. The rules are deliberately simple: a farmer can
   check them by hand, and changing a weight is a one-line change in
   `HealthScores`. The crop health widget places each cycle at its plot's
   centroid.
3. **Standard reports, not a query builder.** A report is a fixed query
   with:
   - declared parameters (`from` / `to`, or `days`);
   - typed columns (text, number, integer, money, percent, date, datetime);
   - optional totals.

   Nineteen reports cover inventory, purchasing, sales, crops, livestock,
   workforce, finance and traceability. Access rules:
   - Each report needs `reports.view` (finance ones
     `reports.finance.view | finance.view`) plus the permission of the
     data it reads.
   - A money column is left out, not blanked, for a member without that
     module's money permission.

   The same definition feeds the preview (500 rows) and every export
   (50,000 rows). Report code reads with an explicit `farm_id` under
   row-level security.
4. **Exports are queued, personal and short-lived.**
   `POST /farms/{farm}/exports` checks permissions and parameters at once.
   A queued job then builds the file inside the farm's context as the
   requester, so it holds what they would see on screen. A member who has
   left gets nothing.
   - **Formats:** CSV (UTF-8 with BOM; text that looks like a formula is
     prefixed with an apostrophe), a hand-written XLSX (numbers stay
     numbers) and a PDF table on A4 (landscape when wide), with totals and
     page numbers. No new libraries.
   - **Limits:** at most 3 exports in progress per farm and 30 per member
     per hour; beyond that the API answers 429 `export_limit`.
   - **Lifetime:** files live 24 hours on the exports disk and only the
     requester can list, read or download them. Everyone else gets a 404,
     and an expired file gets a 410. An hourly `exports:prune` deletes
     expired files.
   - **Notice:** the requester gets an inbox notice when the file is ready.
5. **Label print runs are exports too.** QR label sheets come in three A4
   templates: 3 × 8, 2 × 7 and 4 × 10. A print run covers up to 200
   active codes and 2,000 labels, and becomes one PDF export. Each label
   carries only its batch's approved public text. Each QR is drawn once
   as a vector form and reused on every label.
6. **Heat maps show counts, not people.**
   `GET /farms/{farm}/maps/activity` counts located records per square
   grid cell (10 m to 1 km) over a period. The layers are:
   - worker GPS points;
   - task check-ins and photos;
   - attendance check-ins;
   - crop operations;
   - pest reports;
   - trace events.

   Each layer needs the permission that shows those records, and the
   layers left out are named. Only counts per cell leave the server.
7. **Performance budget.** `php artisan reporting:bench` measures each
   dashboard as the owner: cold, with a custom period nobody asked for,
   and from cache. It checks p95 against 3 s cold and 800 ms cached, and a
   feature test holds the gate. On the demo farm, cold p95 is under
   110 ms and cached p95 under 10 ms.

## Consequences
- New reports are code, reviewed like any query, not user-defined SQL.
  Saved custom reports and scheduled email delivery remain future work.
- Scores are heuristics, not diagnoses. Farms may later want their own
  weights, which would be a farm setting.
- Exports need a queue worker in production (compose already runs one).
  In tests the queue is synchronous.
- The PDF uses the standard Helvetica fonts, so text outside Windows-1252
  is transliterated.
