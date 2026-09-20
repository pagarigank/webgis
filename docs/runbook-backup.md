# Backup & Restore Runbook

## Overview

This runbook covers the nightly backup procedure for the WebGIS PostgreSQL database and the documented restore procedure for disaster recovery.

---

## Backup

### Tool

[`bin/backup.sh`](../backend/bin/backup.sh) — wraps `pg_dump --format=custom --compress=9`.

### Schedule

Run nightly at **02:00 server time** via cron:

```cron
0 2 * * * /var/www/html/bin/backup.sh >> /var/log/webgis/backup.log 2>&1
```

### Configuration

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `postgres` | PostgreSQL host |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DB_NAME` | `webgis` | Database name |
| `DB_USER` | `postgres` | DB user |
| `DB_PASS` | *(none)* | DB password (set in `.env`) |
| `BACKUP_DIR` | `/var/backups/webgis` | Dump storage directory |
| `RETENTION_DAYS` | `14` | Days of backups to retain |

### Output

Dump files are named `webgis_YYYY-MM-DD_HHMMSS.dump` in `BACKUP_DIR`.

### Verification

After each run, check the log for the line:

```
[backup.sh ...] Backup complete: /var/backups/webgis/webgis_YYYY-MM-DD_HHMMSS.dump
```

---

## Restore

### Tool

[`bin/restore.sh`](../backend/bin/restore.sh) — wraps `pg_restore`.

### Procedure

> [!CAUTION]
> The `--drop-existing` flag will **permanently delete** all data in the target database. Only use it on a clean restore target.

#### 1. Locate the dump file

```bash
ls -lh /var/backups/webgis/
```

#### 2. Restore onto a clean container

```bash
./bin/restore.sh /var/backups/webgis/webgis_2026-09-20_020000.dump --drop-existing
```

#### 3. Without dropping (additive restore)

```bash
./bin/restore.sh /var/backups/webgis/webgis_2026-09-20_020000.dump
```

#### 4. Run migrations to apply any pending schema changes

```bash
vendor/bin/phinx migrate
```

#### 5. Verify

```bash
vendor/bin/phinx status
```

The script automatically verifies that the expected schemas (`app`, `audit`, `ref`, `staging`) are present after restore.

---

## Document Store Sync

If the application stores documents outside PostgreSQL (e.g., on a shared volume or object store), those files must also be backed up independently. Sync the document storage root nightly:

```bash
# Example: rsync to an offsite backup host
rsync -az --delete /var/www/html/storage/ backup@backup-host:/backups/webgis-docs/
```

---

## Restore Drill

A restore drill (TASK-160) should be run quarterly:

1. Spin up a clean Docker container with the same PostGIS version.
2. Copy the latest dump to the container.
3. Run `./bin/restore.sh <dump> --drop-existing`.
4. Run `vendor/bin/phinx status` and confirm all migrations show as `up`.
5. Run `vendor/bin/phpunit tests/Integration/` and confirm all tests pass.
6. Record the result and timestamp in the project change log.
