#!/bin/sh
# The application connects as a NON-superuser that owns its database:
# PostgreSQL skips row-level security for superusers (docs/02 §2 layer 6).
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" <<SQL
CREATE ROLE ${APP_DB_USER} LOGIN PASSWORD '${APP_DB_PASSWORD}' NOSUPERUSER NOCREATEROLE NOCREATEDB;
CREATE DATABASE ${APP_DB_NAME} OWNER ${APP_DB_USER};
\connect ${APP_DB_NAME}
ALTER SCHEMA public OWNER TO ${APP_DB_USER};
CREATE EXTENSION IF NOT EXISTS postgis;
SQL
