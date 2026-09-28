#!/bin/sh
# Quarterly restore drill (docs/12-operations.md §6): back up the live
# database, restore it into a new one, prove the copy is complete and usable,
# and time it against the recovery time objective (RTO ≤ 4 h).
#
#   ADMIN_PGUSER=postgres ADMIN_PGPASSWORD=... BACKUP_PGUSER=sfmtp_backup BACKUP_PGPASSWORD=... \
#   PGHOST=127.0.0.1 PGDATABASE=sfmtp APP_DB_USER=sfmtp APP_DB_PASSWORD=... \
#   APP_DIR=sfmtp/backend infra/deploy/restore-drill.sh
#
# KEEP=1 keeps the restored database for inspection.
set -eu

HERE="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="$(cd "${APP_DIR:-${HERE}/../../backend}" && pwd)"
SOURCE="${PGDATABASE:-sfmtp}"
TARGET="${SOURCE}_drill_$(date -u +%Y%m%d%H%M%S)"
WORK="$(mktemp -d)"
now() { date +%s.%N; }
elapsed() { echo "$2 - $1" | bc; }
as_backup() { PGUSER="${BACKUP_PGUSER:-sfmtp_backup}" PGPASSWORD="${BACKUP_PGPASSWORD:-}" "$@"; }
as_admin() { PGUSER="${ADMIN_PGUSER:-postgres}" PGPASSWORD="${ADMIN_PGPASSWORD:-}" "$@"; }
as_app() { PGUSER="${APP_DB_USER:-sfmtp}" PGPASSWORD="${APP_DB_PASSWORD:-}" "$@"; }

counts() { # every table's row count, in name order (the backup log itself changes)
  as_backup psql -d "$1" -Atc "SELECT string_agg(format('SELECT %L AS t, count(*) AS n FROM %I.%I', tablename, schemaname, tablename), ' UNION ALL ' ORDER BY tablename)
    FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'backup_runs'" | as_backup psql -d "$1" -At | sort
}

echo "== Restore drill: ${SOURCE} → ${TARGET}"

# Counted just before the dump; run the drill on a quiet copy (staging, or a
# replica promoted for the drill) so nothing changes in between.
counts "${SOURCE}" > "${WORK}/source.txt"
t0=$(now)
as_backup env BACKUP_DIR="${WORK}" APP_DIR="${APP_DIR}" PGDATABASE="${SOURCE}" "${HERE}/backup.sh"
DUMP="$(ls "${WORK}"/sfmtp-*.dump)"
SUM="$(sha256sum "${DUMP}" | cut -d' ' -f1)"
t1=$(now)

as_admin env EXPECTED_SHA256="${SUM}" APP_DB_USER="${APP_DB_USER:-sfmtp}" "${HERE}/restore.sh" "${DUMP}" "${TARGET}"
t2=$(now)

echo "== Verify"
counts "${TARGET}" > "${WORK}/restored.txt"
if ! diff -q "${WORK}/source.txt" "${WORK}/restored.txt" >/dev/null; then
  echo "FAIL: row counts differ"; diff "${WORK}/source.txt" "${WORK}/restored.txt" | head -20; exit 1
fi
TABLES=$(wc -l < "${WORK}/restored.txt" | tr -d ' ')
ROWS=$(awk -F'|' '{s += $2} END {print s}' "${WORK}/restored.txt")
echo "Row counts match: ${TABLES} tables, ${ROWS} rows"

POLICIES_SRC=$(as_backup psql -d "${SOURCE}" -Atc "SELECT count(*) FROM pg_policies WHERE schemaname = 'public'")
POLICIES=$(as_backup psql -d "${TARGET}" -Atc "SELECT count(*) FROM pg_policies WHERE schemaname = 'public'")
TRIGGERS_SRC=$(as_backup psql -d "${SOURCE}" -Atc "SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal")
TRIGGERS=$(as_backup psql -d "${TARGET}" -Atc "SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal")
[ "${POLICIES}" = "${POLICIES_SRC}" ] && [ "${TRIGGERS}" = "${TRIGGERS_SRC}" ] || { echo "FAIL: policies ${POLICIES}/${POLICIES_SRC}, triggers ${TRIGGERS}/${TRIGGERS_SRC}"; exit 1; }
echo "Row-level security policies (${POLICIES}) and append-only triggers (${TRIGGERS}) restored"

# The application role still cannot see any farm without a farm context.
LEAK=$(as_app psql -d "${TARGET}" -Atc "SELECT count(*) FROM trace_batches")
[ "${LEAK}" = "0" ] || { echo "FAIL: the application role reads ${LEAK} batches without a farm context"; exit 1; }
echo "Row-level security holds on the restored copy"

# The application runs on the copy: no pending migrations, trace chains intact.
cd "${APP_DIR}"
DB_DATABASE="${TARGET}" php artisan migrate:status --pending 2>&1 | grep -q "No pending migrations" \
  || DB_DATABASE="${TARGET}" php artisan migrate --pretend 2>&1 | grep -q "Nothing to migrate" \
  || { echo "FAIL: the restored schema has pending migrations"; exit 1; }
DB_DATABASE="${TARGET}" php artisan trace:verify-chain | tee "${WORK}/chain.txt"
grep -q "FAIL" "${WORK}/chain.txt" && { echo "FAIL: trace chain broken on the copy"; exit 1; }
t3=$(now)

SIZE=$(wc -c < "${DUMP}" | tr -d ' ')
echo "== Result"
printf 'Backup:  %6.1f s (%s bytes, sha256 %s)\n' "$(elapsed "$t0" "$t1")" "${SIZE}" "${SUM}"
printf 'Restore: %6.1f s\n' "$(elapsed "$t1" "$t2")"
printf 'Verify:  %6.1f s\n' "$(elapsed "$t2" "$t3")"
printf 'Total:   %6.1f s (RTO objective: 4 h)\n' "$(elapsed "$t0" "$t3")"

if [ "${KEEP:-0}" != "1" ]; then
  as_admin dropdb "${TARGET}"
fi
rm -rf "${WORK}"
echo "PASS"
