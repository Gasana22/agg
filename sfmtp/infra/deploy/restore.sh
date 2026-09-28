#!/bin/sh
# Restore an SFMTP backup (from backup.sh) into a NEW, empty database
# (docs/12-operations.md §5).
#
#   PGHOST=... PGUSER=<admin> PGPASSWORD=... APP_DB_USER=sfmtp \
#     infra/deploy/restore.sh /backups/sfmtp-20261001T020000Z.dump sfmtp_restored
#
# PGUSER is an administrator that can create databases and bypass row-level
# security (the superuser, or a role with CREATEDB and BYPASSRLS). The
# restored objects belong to APP_DB_USER, so the application uses the
# restored database unchanged and row-level security still applies to it.
#
# The data is loaded as the administrator: every farm table forces
# row-level security, so its owner could not load other farms' rows.
set -eu

DUMP="${1:?usage: restore.sh <dump file> <new database>}"
TARGET="${2:?usage: restore.sh <dump file> <new database>}"
APP_DB_USER="${APP_DB_USER:-sfmtp}"
EXPECTED_SHA256="${EXPECTED_SHA256:-}"

if [ -n "${EXPECTED_SHA256}" ]; then
  echo "${EXPECTED_SHA256}  ${DUMP}" | sha256sum -c - >/dev/null || { echo "Checksum mismatch: ${DUMP}" >&2; exit 1; }
fi

if [ "$(psql -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname = '${TARGET}'")" = "1" ]; then
  echo "Database ${TARGET} already exists: restore into a new database, then switch the application to it." >&2
  exit 1
fi

createdb --owner="${APP_DB_USER}" "${TARGET}"
psql -v ON_ERROR_STOP=1 -q -d "${TARGET}" -c "ALTER SCHEMA public OWNER TO ${APP_DB_USER}"

# Tables, functions and types, owned by the application role.
pg_restore --exit-on-error --no-owner --role="${APP_DB_USER}" --section=pre-data -d "${TARGET}" "${DUMP}"
# Rows, loaded as the administrator (past row-level security).
pg_restore --exit-on-error --no-owner --section=data -d "${TARGET}" "${DUMP}"
# Indexes, constraints, triggers and row-level security policies.
pg_restore --exit-on-error --no-owner --role="${APP_DB_USER}" --section=post-data -d "${TARGET}" "${DUMP}"

psql -q -d "${TARGET}" -c "ANALYZE"
echo "Restored ${DUMP} into ${TARGET}."
