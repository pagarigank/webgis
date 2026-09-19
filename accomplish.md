# Accomplishments

## TASK-012: CI pipeline
- **What shipped**: Created GitHub Actions workflow (`.github/workflows/ci.yml`). Configured jobs to check out the repository, run PHPStan, PHP-CS-Fixer, and PHPUnit (against a PostGIS sidecar) for the backend. Configured parallel jobs for Vite build, Prettier formatting check, Oxlint, and Vitest for the frontend. Added composer and npm vulnerability scans.
- **Decisions made**: Added a PostGIS service container directly to the backend test job so migrations can be run on a true spatial database.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-013 (first task of Phase 2).

## TASK-013: Database roles and RLS
- **What shipped**: Created the database migration for `app.users`, `app.roles`, and `app.user_roles`. Added default-deny Row Level Security (RLS) on these tables and `audit_logs` as an authorization backstop. Created PostgreSQL roles (`app_rw`, `app_ro`, `app_migrator`). Wrote `Integration\RlsTest` proving an unprivileged query without `app.user_id` returns 0 rows.
- **Decisions made**: Relied entirely on PostgreSQL RLS with session variables (`current_setting('app.user_id')`) ensuring tenant isolation at the database level (ADR-06).
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-014 (Core entity schemas).

## TASK-014: `ref` schema and CRS registry
- **What shipped**: Created the `ref` schema and its initial tables: `psgc_areas`, `crs_registry`, and `units`. Authored `RefSeeder` to pre-populate EPSG:4326, EPSG:3857, PRS92 PTM Zones (3121-3125), Luzon 1911 Zones (25391-25395, flagged historical), and basic conversion factors for length and area. Wrote `CrsRegistryTest` and `UnitConversionTest`.
- **Decisions made**: Separated CRS logic out into `ref` schema instead of hardcoding it, ensuring that legacy and future datums can be added as data rather than code changes.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-015 (PSGC reference data load).
