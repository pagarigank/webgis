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

Phases 1 through 9 (tasks 001–077b) are complete, covering:
- Foundation & Architecture (ERD, RLS data access, seeding, fixtures, audit writer, backup/restore)
- Identity & Admin (authentication, JWT + refresh rotation, Argon2id, TOTP MFA, RBAC admin APIs, transport security)
- GIS Core & Map Shell (layers, fields, styles, basemap provider proxy, MapLibre GL workspace, LayerTree)
- Drawing & Spatial Analysis (feature CRUD, DrawManager with undo/redo, geometry validation, conflicts, measure/identify, spatial query engine)
- Attribute Grid & Selection (TanStack Table v9 grid, two-way map/table sync, row editing, GeoJSON/CSV export)
- Parcels Core (parcel CRUD, append-only versioning, search with historical filter, 9-tab editor shell, manual drawing with provenance enforcement)
- Control Points & Survey Plans (Phase 9: control points CRUD, projected/geographic coordinate derivations, verification, dependents tracking, GIST KNN nearest picker, frontend UI & amber UNVERIFIED badge, survey plan CRUD with parcel linkages, and external RPT stub provider).

Development is now queued for Phase 10 (Technical descriptions and parser), starting with TASK-078 (bearing value objects).