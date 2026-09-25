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

Phases 1 through 15 (tasks 001–120) are complete, covering:
- **Foundation & Architecture** (ERD, PostGIS spatial schemas, RLS data access, seeding, fixtures, audit writer, backup/restore)
- **Identity & Admin** (authentication, JWT + refresh rotation, Argon2id, TOTP MFA, RBAC admin APIs, transport security)
- **GIS Core & Map Shell** (layers, fields, styles, basemap provider proxy, MapLibre GL workspace, LayerTree)
- **Drawing & Spatial Analysis** (feature CRUD, DrawManager with undo/redo, geometry validation, conflicts, measure/identify, spatial query engine)
- **Attribute Grid & Selection** (TanStack Table v9 grid, two-way map/table sync, row editing, GeoJSON/CSV export)
- **Parcels Core** (parcel CRUD, append-only versioning, search with historical filter, 9-tab editor shell, manual drawing with provenance enforcement)
- **Control Points & Survey Plans** (Phase 9: control point CRUD, projected/geographic coordinate derivations, verification, dependents tracking, GIST KNN nearest picker, frontend UI & amber UNVERIFIED badge, survey plan CRUD with parcel linkages, and external RPT stub provider).
- **Technical Descriptions & Parser** (Phase 10: pure domain bearing/azimuth & distance value objects, VR-01...VR-09 validation engine, freeform cadastral text parser with source spans and confidence scores, technical description revision CRUD, optimistic locking, PARSE_UNRESOLVED confirmation guard, OCR assist staging endpoint, Compound BearingInput UI control, TiePointTab with ControlPointPicker, TechnicalDescriptionTab with course table and paste-and-parse review modal, and live vector TraversePreviewMap with red dashed closure gap indicator).
- **Computation Engine** (Phase 11: pure domain traverse computation, linear closure & relative precision calculation, plane Shoelace area & PostGIS planar cross-check, immutable computation snapshot persistence, replay determinism, PTM zone recommendation and area-of-use validation, accept computation to parcel geometry, Bowditch Compass and Transit traverse adjustments, and explicit coordinate transformations).
- **Survey Validation** (Phase 12: 12-point FR-125 checklist VR-01..VR-20, spatial overlap detection with sliver filtering, submission guards blocking invalid closures, and full ValidationPanel UI with expanded warnings and mandatory FR-127 validation aid notice).
- **Workflow & Approvals** (Phase 13: table-driven workflow engine with seeded FR-135 matrix, transition permissions and reason/comment enforcement, re-validation guard at approval, approved-edit cycle with REOPEN transition preserving approved versions, WorkflowActionBar, ReviewerInboxPage, and notification bell).
- **History & Documents** (Phase 14: merged history timeline API with JSON export, VersionDiffService pure vertex pairing and diff, additive version restore, document storage with magic-byte/MIME sniff guards and SHA-256 deduplication, and HMAC single-use signed download tokens).
- **Split, Consolidation & Lineage** (Phase 15: SplitValidator VR-35..39, ConsolidationValidator VR-40..44, transactional split and consolidation services with deadlock-free parent locking, dry-run previews, and idempotency, generation-depth lineage BFS API, VR-45 cycle prevention trigger, default exclusion of superseded records, TD-derived split with closure computation, SplitTab with interactive cut-line map and preview gating, ConsolidationTab with map problem highlighting, and LineageTab with layered genealogy graph and JSON export).

Development is now queued for Phase 16 (Import and export), starting with TASK-121 (OGR adapter and format detection), alongside TASK-104a (version-compare/history UI pass).