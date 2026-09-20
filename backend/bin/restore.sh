#!/usr/bin/env bash
# =============================================================================
# restore.sh — Restore a WebGIS PostgreSQL backup onto a clean container
#
# Usage:
#   ./bin/restore.sh <dump-file> [--drop-existing]
#
# Options:
#   --drop-existing   Drop and recreate the target database before restoring.
#                     USE WITH EXTREME CAUTION in production.
#
# Environment variables (read from .env if present):
#   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
#
# The dump file must be a pg_dump --format=custom file produced by backup.sh.
#
# Exit codes:
#   0  — success
#   1  — restore failed or bad arguments
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/../.env"

if [[ -f "$ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
fi

DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-webgis}"
DB_USER="${DB_USER:-postgres}"
PGPASSWORD="${DB_PASS:-}"
export PGPASSWORD

DUMP_FILE=""
DROP_EXISTING=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --drop-existing) DROP_EXISTING=true; shift ;;
        -*) echo "Unknown flag: $1" >&2; exit 1 ;;
        *)  DUMP_FILE="$1"; shift ;;
    esac
done

if [[ -z "$DUMP_FILE" ]]; then
    echo "Usage: $0 <dump-file> [--drop-existing]" >&2
    exit 1
fi

if [[ ! -f "$DUMP_FILE" ]]; then
    echo "ERROR: dump file not found: ${DUMP_FILE}" >&2
    exit 1
fi

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
LOG_PREFIX="[restore.sh ${TIMESTAMP}]"

echo "${LOG_PREFIX} Restoring ${DUMP_FILE} → ${DB_NAME}@${DB_HOST}:${DB_PORT}"

# ---------- Optional: drop and recreate DB -----------------------------------
if [[ "$DROP_EXISTING" == "true" ]]; then
    echo "${LOG_PREFIX} WARNING: dropping existing database ${DB_NAME}"
    psql --host="${DB_HOST}" --port="${DB_PORT}" --username="${DB_USER}" \
        --command="DROP DATABASE IF EXISTS ${DB_NAME};" postgres
    psql --host="${DB_HOST}" --port="${DB_PORT}" --username="${DB_USER}" \
        --command="CREATE DATABASE ${DB_NAME};" postgres
    echo "${LOG_PREFIX} Database recreated"
fi

# ---------- Restore ----------------------------------------------------------
if pg_restore \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --username="${DB_USER}" \
    --dbname="${DB_NAME}" \
    --no-password \
    --exit-on-error \
    --verbose \
    "${DUMP_FILE}"; then
    echo "${LOG_PREFIX} Restore complete"
else
    echo "${LOG_PREFIX} ERROR: pg_restore failed" >&2
    exit 1
fi

# ---------- Post-restore verification ----------------------------------------
echo "${LOG_PREFIX} Verifying schema presence..."
TABLE_COUNT=$(psql \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --username="${DB_USER}" \
    --dbname="${DB_NAME}" \
    --no-password \
    --tuples-only \
    --command="SELECT count(*) FROM information_schema.tables WHERE table_schema IN ('app','audit','ref','staging');")

echo "${LOG_PREFIX} Found ${TABLE_COUNT// /} tables in app/audit/ref/staging schemas"

echo "${LOG_PREFIX} Restore and verification complete"
