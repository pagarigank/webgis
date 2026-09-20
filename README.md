# webgis

Philippine Parcel, Survey & Multi-User GIS Management Platform.

PostgreSQL 16 + PostGIS 3.4 backend (Slim 4, PHP) and a React 18 + TypeScript + OpenLayers 9 frontend, packaged with Docker Compose. Supports parcel records, land titles, technical descriptions, geodetic survey control, and role-scoped multi-tenant data access enforced by PostgreSQL Row-Level Security.

## Repository layout

| Path | Purpose |
|---|---|
| `backend/` | Slim 4 API, PSR-15 pipeline, Auth / RBAC / Audit services |
| `frontend/` | React 18 + Vite + OpenLayers web client |
| `database/` | Phinx migrations and seeds |
| `docker/` | Docker Compose stack and container configuration |
| `docs/` | Runbooks (e.g. `docs/runbook-backup.md`) |

## Documentation index

Read the authoritative document for the layer you are touching before changing it.

| File | Role |
|---|---|
| `PLANNING.md` | Scope, objectives, risks, retry protocol |
| `architecture.md` | Architecture and ADRs — read before structural work |
| `specification.md` | Functional, non-functional, verification, survey requirements |
| `database.md` | Data model and conventions — read before migrations |
| `api.md` | API contract and error-code set — read before endpoints |
| `frontend.md` | Frontend stack and architecture — read before UI |
| `todo.md` | Full ordered roadmap, task by task |
| `TASK.md` | Active implementation queue and live status |
| `next_task.md` | The single next task and its entry criteria |
| `accomplish.md` | Per-task accomplishment log |

## Quick start

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec php-fpm vendor/bin/phpunit
```

See `docs/runbook-backup.md` for backup and restore procedures.

## Status

M0 planning and the M1 foundation (tasks 001–026) are complete, including the ERD, RLS data access, seeding, synthetic fixtures, the audit writer, and backup/restore scripts. Development is in Phase 3 (Identity): authentication, RBAC, and audit — tasks 027–033 are DONE and next up is TASK-034 (user/role/permission/organisation/scope admin APIs).