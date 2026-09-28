# 16 — Production launch checklist

Tick every line before real farms go live. **Done** items were delivered
and checked in Phase 15, with the evidence listed. **Launch** items depend
on the production environment or the business, and are done by the team
running the launch.

## Security

| | Item | Evidence |
|---|---|---|
| ✅ Done | Row-level security on every farm table, enforced by a test | ADR-0019; `FarmTenancyTest::test_every_table_with_a_farm_has_forced_row_level_security` |
| ✅ Done | Cross-tenant sweep over every farm route, and the role boundaries of [04 §6](04-roles-and-permissions.md) | `CrossTenantIsolationTest`, `RoleBoundaryTest`, `FinanceBoundaryTest`, `PortalTest` (all green in CI) |
| ✅ Done | Security headers, CORS, CSP; forged, expired and revoked token tests | `SecurityTest`; browser check with no CSP violations |
| ✅ Done | Dependency audit, and CI failing on known vulnerabilities; Dependabot | Composer: no advisories; npm: 0 vulnerabilities (2026-09-28) |
| ✅ Done | OWASP API Top 10 review | [11 §6](11-security.md) |
| ☐ Launch | External penetration test of staging, findings fixed or accepted | Report on file |
| ☐ Launch | Production secrets generated fresh (`APP_KEY`, `JWT_SECRET`, database and backup passwords) and kept only in the secret store | |
| ☐ Launch | The first platform administrator created with `platform:create-admin`; MFA enrolled; staff accounts with the least role they need | |
| ☐ Launch | Security contact published (for [11 §7](11-security.md)) | |

## Data and recovery

| | Item | Evidence |
|---|---|---|
| ✅ Done | Backup role (`BYPASSRLS`, read-only), since the application role cannot dump the database | `infra/docker/postgres/10-app-role.sh`, runbook §4 |
| ✅ Done | Restore drill: row counts, policies, triggers, RLS on the copy, trace chains | `restore-drill.sh` PASS, 6.8 s ([12 §6](12-operations.md)) |
| ✅ Done | Point-in-time drill: recovery to the second from archived WAL | `pitr-drill.sh` PASS ([12 §6](12-operations.md)) |
| ☐ Launch | Production PostgreSQL with point-in-time recovery (≥ 7 days) and a standby | |
| ☐ Launch | Daily `backup.sh` scheduled as `sfmtp_backup`, copied off-site and encrypted; first run green on the admin **System** page | |
| ☐ Launch | Object storage versioning and a replica bucket | |
| ☐ Launch | Both drills repeated on production-sized data, meeting RPO ≤ 15 min and RTO ≤ 4 h; next drill in the calendar | Drill log |

## Performance

| | Item | Evidence |
|---|---|---|
| ✅ Done | Load test: 10,000+ activities a day with headroom | 60 phones at 19.7 activities/s (24 times a peak hour), push p95 271 ms, no errors ([infra/load](../infra/load/README.md)) |
| ✅ Done | Dashboard budget (p95 < 800 ms cached, < 3 s cold) | `reporting:bench`, its test |
| ✅ Done | The phone app under its size budget, on a 3G profile | CI Android build; Phase 11 offline suite |
| ☐ Launch | Load test repeated against staging sized like production | |

## Operations

| | Item | Evidence |
|---|---|---|
| ✅ Done | Runbook: monitoring, backups, restore, scenarios, secret rotation | [12](12-operations.md) |
| ✅ Done | Deployment guide: configuration, first deploy, rolling deploys | [13](13-deployment.md) |
| ☐ Launch | Alerts wired as in runbook §2 (5xx, latency, queue, failed jobs, backups, WAL lag, disk, tamper check) and tested once | |
| ☐ Launch | On-call rota and escalation contacts | |
| ☐ Launch | Status page or channel for farm users | |

## Integrations

| | Item | Evidence |
|---|---|---|
| ✅ Done | Adapters with contract tests and failover (SMS, email, weather, maps, push, payments) | ADR-0018, `IntegrationsTest` |
| ☐ Launch | Production accounts: Africa's Talking (sender ID approved), email domain (SPF, DKIM, DMARC), maps (public token), weather, Firebase, Flutterwave (live keys, webhook hash) | Each **Test** button green in `/admin/integrations` |
| ☐ Launch | One real payment end to end (subscription, then a customer invoice) and one refund in the Flutterwave dashboard | |

## Product and legal

| | Item | Evidence |
|---|---|---|
| ✅ Done | User guide and API guide | [14](14-user-guide.md), [15](15-api-guide.md) |
| ☐ Launch | Terms of service and privacy notice, linked from sign-in | |
| ☐ Launch | Registration with Uganda's Personal Data Protection Office (Data Protection and Privacy Act, 2019), and a data processing agreement for farms | |
| ☐ Launch | Phone app store listings (privacy labels, data safety form), production signing keys kept safe | |
| ☐ Launch | Pilot farms trained; support hours published | |

## Go / no-go

Launch when **every** line is ticked, CI is green on the release commit, and
the product owner signs off:

- Release commit: `__________`
- Date: `__________`
- Signed: `__________`
