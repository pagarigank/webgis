#!/usr/bin/env bash
# =============================================================================
# backup.sh — Nightly PostgreSQL backup script for WebGIS
#
# Usage:
#   ./bin/backup.sh [--retention-days N]
#
# Environment variables (read from .env if present):
#   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
#   BACKUP_DIR   — where dumps are stored (default: /var/backups/webgis)
#   RETENTION_DAYS — how many days of backups to keep (default: 14)
#
# The script produces a pg_dump -Fc (custom format) file named:
#   ${BACKUP_DIR}/webgis_YYYY-MM-DD_HHMMSS.dump
#
# Exit codes:
#   0  — success
#   1  — dump failed
#   2  — retention cleanup failed (non-fatal, exits 0 after logging)
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/../.env"

# Source .env if it exists
if [[ -f "$ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
fi

# Defaults
DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-webgis}"
DB_USER="${DB_USER:-postgres}"
PGPASSWORD="${DB_PASS:-}"
export PGPASSWORD

BACKUP_DIR="${BACKUP_DIR:-/var/backups/webgis}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"

# Parse flags
while [[ $# -gt 0 ]]; do
    case "$1" in
        --retention-days) RETENTION_DAYS="$2"; shift 2 ;;
        *) echo "Unknown flag: $1" >&2; exit 1 ;;
    esac
done

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
DUMP_FILE="${BACKUP_DIR}/webgis_${TIMESTAMP}.dump"
LOG_PREFIX="[backup.sh ${TIMESTAMP}]"

echo "${LOG_PREFIX} Starting backup of ${DB_NAME}@${DB_HOST}:${DB_PORT}"
mkdir -p "${BACKUP_DIR}"

# ---------- 1. Database dump -------------------------------------------------
if pg_dump \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --username="${DB_USER}" \
    --format=custom \
    --compress=9 \
    --no-password \
    --file="${DUMP_FILE}" \
    "${DB_NAME}"; then
    echo "${LOG_PREFIX} Dump written to ${DUMP_FILE}"
else
    echo "${LOG_PREFIX} ERROR: pg_dump failed" >&2
    exit 1
fi

# ---------- 2. Retention cleanup ---------------------------------------------
echo "${LOG_PREFIX} Pruning backups older than ${RETENTION_DAYS} days"
if find "${BACKUP_DIR}" -name "webgis_*.dump" -mtime "+${RETENTION_DAYS}" -delete; then
    echo "${LOG_PREFIX} Retention cleanup complete"
else
    echo "${LOG_PREFIX} WARNING: retention cleanup failed (non-fatal)" >&2
fi

echo "${LOG_PREFIX} Backup complete: ${DUMP_FILE}"
