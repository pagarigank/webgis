# PLANNING.md

**Project:** Philippine Parcel, Survey & Multi-User GIS Management Platform
**Document status:** DRAFT v0.1 — planning phase, implementation not started
**Owner:** lead architect / project sponsor (LGU)

---

## 1. Objective

Build a browser-based GIS and parcel management platform that lets authorised LGU staff manage Philippine land parcels, land titles, survey plans, technical descriptions, and survey control points; compute parcel geometry from technical descriptions; validate closure and area; split and consolidate parcels with full lineage; and do all of it under multi-user RBAC with record-level scoping, versioning, and a complete audit trail.

The platform is a **GIS computation and records-management system**. It is not a surveying service, not a boundary certification authority, and not a replacement for approved survey plans, government land records, or a licensed surveyor's verification. Every part of the design preserves that distinction.

---

## 2. Scope

### 2.1 In scope (v1)

Interactive web GIS (OpenLayers) · user-created layers and custom fields · point/line/polygon drawing and editing with snapping · attribute tables at scale · parcel records · land titles with PII separation · survey plans · technical descriptions (manual, pasted, parsed, OCR-assisted) · bearing and distance engines · tie points and control points · parcel computation from courses · closure and area validation · survey validation screen · manual parcel drawing · **parcel split and consolidation with lineage** · parcel versioning · approval workflow · documents · audit · RBAC with layer permissions and data scopes · import/export (GeoJSON, CSV, KML, Shapefile, GeoPackage, DXF) · basemap provider management with licence controls · spatial and global search · reports · automated tests at every level.

### 2.2 Explicitly out of scope (v1)

Full QGIS parity · raster analysis and processing · network/routing analysis · cross-layer topology editing · offline field collection · mobile data capture · automatic title adjudication or ownership determination · direct writes into an external RPT/assessment system · generation of legally certified survey documents · georeferencing of scanned plans · 3D/terrain · real-time collaborative editing of the same geometry · public-facing citizen portal.

Anything on this list that is later requested requires a new ADR in `architecture.md` and tasks in `todo.md` before work begins. This list exists to prevent the project drifting into "a web QGIS", which is risk R-09.

### 2.3 Deliberately deferred (post-v1, designed for)

Traverse adjustment (Compass/Transit) · curve courses in technical descriptions · geodetic-to-grid convergence correction · NTv2 transformation grids · RPT integration adapter · SSO/LDAP · vector-tile pre-seeding · read replica and horizontal scaling · Filipino localisation · dark mode.

---

## 3. Major architectural decisions (summary; full rationale in `architecture.md` §14)

1. PHP 8.3 + Slim 4 + PDO + Phinx; module-first repository structure with a uniform internal layering.
2. PostgreSQL 16 + PostGIS 3.4 as the single authoritative spatial store; MySQL is not used.
3. Geometry stored canonically in EPSG:4326; **computed and measured** in the appropriate PRS92 PTM zone (EPSG:3121–3125); historical coordinates preserved in their native CRS and never silently transformed.
4. Dynamic attributes in JSONB with metadata-driven validation, not EAV and not runtime DDL.
5. Access JWT (15 min) + rotating refresh cookie; three independent authorization layers (permission → layer → scope) with PostgreSQL RLS as a backstop.
6. Optimistic concurrency (`version` + `If-Match`), never last-write-wins.
7. The survey computation library is pure, framework-free, and independently unit-tested.
8. Computations are immutable and carry a full input snapshot; adjustments create new rows.
9. Split/consolidation execute in PostGIS inside one transaction; parents become `SUPERSEDED`, never deleted.
10. Basemaps are licence-bearing records with server-side key proxying; no path exists to ingest or rehost third-party imagery.
11. Response envelope `{success, data|error}` with a closed error-code set.
12. React + TypeScript + Vite + OpenLayers + Bootstrap 5 + Axios + TanStack Query + Zustand.

---

## 4. Open decisions

Tracked in `architecture.md` §0 as D-01 … D-19, each with an assumed default and the phase it blocks. The ones that most change the build if answered differently:

| ID | Question | Why it matters |
|---|---|---|
| D-02 / D-14 | Which **web** basemap licences does the LGU hold? | Determines whether imagery exists at all in v1. A desktop CAD-Earth licence does not cover web use |
| D-05 | Authoritative source of control-point coordinates | Everything computed downstream inherits the quality of these points |
| D-13 | Is Luzon 1911 data in scope at go-live? | Drives transformation UX, parameter sourcing, and a large slice of validation |
| D-03 | Real data volume | Decides whether vector tiles and partitioning are day-one or later |
| D-08 | Data Privacy Act posture and DPO involvement | Drives PII encryption, access model, and retention rules |
| D-17 | Who may split/consolidate, and what documentation is required | Configures workflow guards before the lineage module is built |

Work proceeds on the stated defaults; each default is revisited at the start of the phase it blocks.

---

## 5. Risks

Full register in `architecture.md` §22 (R-01 … R-10). The three that most deserve sponsor attention:

- **R-04 — users treating computed geometry as a legal boundary.** The single largest real-world harm this system could cause. Mitigated structurally (provenance is mandatory data, disclaimers are generated, status vocabulary is explicit), but it is also an institutional/training matter, not only a software one.
- **R-01/R-02 — datum and control-point quality.** If the LGU's control points or transformation parameters are wrong, every dependent parcel is confidently wrong. Mitigated by verification status, snapshots, and never transforming silently — but the underlying data question is outside the software.
- **R-03 — imagery licensing.** A desktop CAD-Earth or similar licence does not authorise web basemap use, caching, or rehosting. The platform is built to make violation impossible by omission of capability, but the LGU still needs an actual web licence to have imagery at all.

---

## 6. Development strategy

- **Documentation first.** Eight planning documents complete and mutually consistent before any application code (this phase).
- **Vertical slices after foundations.** Once database, auth, and RBAC exist, each feature ships end-to-end — migration, API, UI, validation, tests — rather than building all backends then all frontends.
- **One task at a time**, taken from `TASK.md` in dependency order. No task starts before its dependencies are `DONE`.
- **Test-alongside, not test-after.** Survey math is test-first with known-answer vectors; everything else ships with tests in the same task.
- **Retry protocol.** On a failing test: analyse → correct → rerun, to a maximum of 3 attempts. At the limit, mark the task `BLOCKED` in `TASK.md` and record the error, what was tried, the suspected cause, and the recommended next action. Do not loop.
- **Accomplishment tracking.** After each task: update `accomplish.md` (what shipped, decisions made, failed approaches), set `next_task.md`, update `TASK.md` status, and commit.
- **Architecture changes require an ADR.** No silent redesign; `architecture.md` §14 is amended first, then dependent documents.
- **Git discipline.** Conventional commits at logical milestones; `.env`, secrets, credentials, and build artefacts never committed.

---

## 7. Milestones

| M | Name | Contents | Exit criteria |
|---|---|---|---|
| M0 | Planning complete | 8 documents, ERD, API design, roadmap | documents consistent, sponsor approves, open decisions acknowledged |
| M1 | Foundation | repo, Docker, CI, PostGIS, migrations, seeds, health checks | `make up` yields a running stack; CI green on an empty app |
| M2 | Identity | auth, users, roles, permissions, scopes, RLS, audit core | RBAC integration suite passes, including negative scope tests |
| M3 | GIS core | layers, fields, styles, features, map, drawing, editing, attribute table | draw → save → edit → search a feature in a user-created layer with custom fields |
| M4 | Survey engine | bearing/distance engines, control points, tie points, technical descriptions, parser, computation, closure, area, validation | known-answer vectors pass; computation reproducible from snapshot |
| M5 | Parcel lifecycle | parcels, manual drawing, versions, workflow, documents, approval | core acceptance workflow (login → … → publish) passes E2E |
| M6 | Lineage | split, consolidation, relationships, lineage view, reports on lineage | split and consolidation E2E with rollback and lineage assertions |
| M7 | Data exchange | import/export, CAD/DXF, basemap manager, reports | import rejects a wrong CRS at preview; reports carry provenance |
| M8 | Hardening | performance, security review, accessibility, docs, backup/restore drill | NFR targets met at target volume; restore drill passes |
| M9 | Deployment | staging, production runbook, handover, training material | production deploy from a clean host following the runbook alone |

---

## 8. Dependencies and assumptions

**External:** PostgreSQL 16 + PostGIS 3.4 available; GDAL/`ogr2ogr` permitted on the host (D-12); a Linux host with Docker; TLS certificate; PSGC reference data; LGU-supplied control points; an actual web basemap licence if imagery is required; ODA converter if DWG is required.

**Organisational:** a named data owner for control points and parcels; a DPO or privacy contact (D-08); reviewers and approvers identified for the workflow; agreement that the system's `APPROVED` status is an internal record status and not an external certification.

**Assumptions:** online-only usage; English UI at v1; single LGU tenant (multi-tenancy is not designed in, and retrofitting it would be a significant change); Asia/Manila operating hours.

---

## 9. Non-goals restated

The platform will not, at any point and regardless of configuration: force survey closure; silently transform coordinates between datums; treat AI/OCR output as authoritative; delete parcel history; overwrite another user's edits without a conflict; enforce permissions only in the browser; ingest or rehost third-party imagery; or present a computed polygon as a certified legal boundary. These are architectural commitments, not defaults — changing any of them would require rewriting the corresponding subsystem, which is the intent.
