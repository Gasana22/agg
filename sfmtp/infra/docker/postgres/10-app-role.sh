#!/bin/sh
# The application connects as a NON-superuser that owns its database:
# PostgreSQL skips row-level security for superusers (docs/02 §2 layer 6).
#
# Backups use a separate read-only role that may bypass row-level security,
# since a dump must see every farm (docs/12-operations.md §4). It can read
# everything and write nothing.
set -eu
BACKUP_DB_USER="${BACKUP_DB_USER:-sfmtp_backup}"
BACKUP_DB_PASSWORD="${BACKUP_DB_PASSWORD:-backup-secret}"
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" <<SQL
CREATE ROLE ${APP_DB_USER} LOGIN PASSWORD '${APP_DB_PASSWORD}' NOSUPERUSER NOCREATEROLE NOCREATEDB;
CREATE DATABASE ${APP_DB_NAME} OWNER ${APP_DB_USER};
CREATE ROLE ${BACKUP_DB_USER} LOGIN PASSWORD '${BACKUP_DB_PASSWORD}' NOSUPERUSER NOCREATEROLE NOCREATEDB BYPASSRLS;
GRANT pg_read_all_data TO ${BACKUP_DB_USER};
\connect ${APP_DB_NAME}
ALTER SCHEMA public OWNER TO ${APP_DB_USER};
CREATE EXTENSION IF NOT EXISTS postgis;
SQL
