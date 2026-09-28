#!/bin/sh
# Point-in-time recovery drill (docs/12-operations.md §6), for a self-managed
# PostgreSQL with WAL archiving (a managed service does the same from its
# console). It proves the recovery point objective (RPO ≤ 15 min):
#
#  1. take a base backup;
#  2. commit marker rows, note a target time, commit more markers;
#  3. recover the base backup plus archived WAL to the target time, in a
#     scratch cluster on another port;
#  4. check every commit before the target is back and none after it.
#
# Run as the postgres OS user on the database host:
#   ARCHIVE=/var/lib/postgresql/wal-archive PGPORT=5432 infra/deploy/pitr-drill.sh
set -eu

ARCHIVE="${ARCHIVE:-/var/lib/postgresql/wal-archive}"
PGPORT="${PGPORT:-5432}"
BIN="${PG_BIN:-$(dirname "$(readlink -f "$(command -v pg_ctl 2>/dev/null || echo /usr/lib/postgresql/16/bin/pg_ctl)")")}"
[ -x "${BIN}/pg_ctl" ] || BIN=/usr/lib/postgresql/16/bin
DRILL_PORT="${DRILL_PORT:-5439}"
WORK="$(mktemp -d)"
DB=pitr_drill
q() { PGOPTIONS="-c client_min_messages=warning" psql -p "${PGPORT}" -v ON_ERROR_STOP=1 -Atq "$@"; }
now() { date +%s.%N; }

[ "$(q -c 'SHOW archive_mode')" = "on" ] || { echo "WAL archiving is off (archive_mode)."; exit 1; }
echo "== PITR drill (archive ${ARCHIVE}, archive_timeout $(q -c 'SHOW archive_timeout'))"

q -c "DROP DATABASE IF EXISTS ${DB}" -c "CREATE DATABASE ${DB}"
q -d "${DB}" -c "CREATE TABLE markers (n int PRIMARY KEY, at timestamptz NOT NULL DEFAULT clock_timestamp())"

t0=$(now)
pg_basebackup -p "${PGPORT}" -D "${WORK}/base" --wal-method=none --checkpoint=fast --no-sync
echo "Base backup taken"

for n in 1 2 3 4 5; do q -d "${DB}" -c "INSERT INTO markers (n) VALUES (${n})"; done
sleep 1
TARGET="$(q -c "SELECT clock_timestamp()")"
sleep 1
for n in 6 7 8 9 10; do q -d "${DB}" -c "INSERT INTO markers (n) VALUES (${n})"; done
echo "Markers 1-5 committed before ${TARGET}, 6-10 after"

# Close the current WAL segment so it is archived now rather than at archive_timeout.
SEG="$(q -c "SELECT pg_walfile_name(pg_switch_wal())")"
for i in $(seq 1 30); do [ -f "${ARCHIVE}/${SEG}" ] && break; sleep 1; done
[ -f "${ARCHIVE}/${SEG}" ] || { echo "FAIL: WAL segment ${SEG} was not archived"; exit 1; }
echo "WAL archived up to ${SEG}"

t1=$(now)
cat >> "${WORK}/base/postgresql.auto.conf" <<EOF
port = ${DRILL_PORT}
archive_mode = off
restore_command = 'cp ${ARCHIVE}/%f %p'
recovery_target_time = '${TARGET}'
recovery_target_action = 'promote'
EOF
touch "${WORK}/base/recovery.signal"
# Debian packages keep the configuration outside the data directory.
[ -f "${WORK}/base/postgresql.conf" ] || : > "${WORK}/base/postgresql.conf"
[ -f "${WORK}/base/pg_hba.conf" ] || printf 'local all all trust\n' > "${WORK}/base/pg_hba.conf"
chmod 700 "${WORK}/base"
"${BIN}/pg_ctl" -D "${WORK}/base" -l "${WORK}/recovery.log" -o "-c unix_socket_directories=${WORK} -c listen_addresses=''" -w -t 300 start >/dev/null
for i in $(seq 1 60); do
  [ "$(psql -h "${WORK}" -p "${DRILL_PORT}" -Atc 'SELECT pg_is_in_recovery()' postgres 2>/dev/null)" = "f" ] && break; sleep 1
done
t2=$(now)

GOT="$(psql -h "${WORK}" -p "${DRILL_PORT}" -Atc "SELECT string_agg(n::text, ',' ORDER BY n) FROM markers" "${DB}")"
"${BIN}/pg_ctl" -D "${WORK}/base" -m fast -w stop >/dev/null
q -c "DROP DATABASE ${DB}"
rm -rf "${WORK}"

echo "Recovered to ${TARGET}: markers ${GOT}"
printf 'Base backup %.1f s, recovery %.1f s\n' "$(echo "${t1} - ${t0}" | bc)" "$(echo "${t2} - ${t1}" | bc)"
[ "${GOT}" = "1,2,3,4,5" ] || { echo "FAIL: expected markers 1-5 only"; exit 1; }
echo "PASS: every commit before the target recovered, none after it"
