#!/bin/sh
# Daily logical backup of the SFMTP database (docs/01 §10).
# Continuous WAL archiving / point-in-time recovery is configured on the
# managed PostgreSQL service; this dump is the portable, restorable copy.
#
# Usage (cron, inside the api image or any host with pg_dump + php):
#   BACKUP_DIR=/backups PGHOST=... PGUSER=... PGPASSWORD=... PGDATABASE=sfmtp \
#     APP_DIR=/app infra/deploy/backup.sh
set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"
APP_DIR="${APP_DIR:-/app}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
FILE="${BACKUP_DIR}/sfmtp-$(date -u +%Y%m%dT%H%M%SZ).dump"

record() {
  php "${APP_DIR}/artisan" platform:record-backup "$@" --started-at="${STARTED_AT}" || true
}

mkdir -p "${BACKUP_DIR}"
if pg_dump --format=custom --no-owner --file="${FILE}"; then
  SIZE="$(wc -c < "${FILE}" | tr -d ' ')"
  SUM="$(sha256sum "${FILE}" | cut -d' ' -f1)"
  record success --location="${FILE}" --size="${SIZE}" --checksum="${SUM}"
  find "${BACKUP_DIR}" -name 'sfmtp-*.dump' -mtime +"${RETENTION_DAYS}" -delete
else
  record failed --message="pg_dump exited with an error"
  exit 1
fi
