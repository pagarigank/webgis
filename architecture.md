# architecture.md

**Project:** Philippine Parcel & Multi-User GIS Web Application
**Document status:** DRAFT v0.2 — for review and approval
**Scope:** authoritative description of system, data, GIS, survey-computation, parcel-lifecycle, basemap, and security architecture.

**Document set and authority.** Eight planning documents form the source of truth. Where they overlap, the authoritative one is named here and the others summarise and cross-reference it:

| Document | Authoritative for |
|---|---|
| `PLANNING.md` | objectives, scope/non-scope, risks, milestones, strategy, open decisions |
| `architecture.md` (this) | system, GIS, survey-computation, security, lifecycle, basemap, CAD architecture; ADRs |
| `specification.md` | functional and non-functional requirements, validation rules, acceptance criteria |
| `frontend.md` | UI architecture, components, state, permission-aware UI |
| `database.md` | ERD, tables, columns, constraints, indexes, migrations, seeds |
| `api.md` | endpoints, schemas, error format, pagination, spatial and survey endpoints |
| `todo.md` | full dependency-aware roadmap, tasks, acceptance, tests |
| `TASK.md` | the active implementation queue and status |

Section 3 of this document is an architectural summary of the data model; **`database.md` is authoritative for the ERD.** Section 6.2 of `specification.md` is an endpoint summary; **`api.md` is authoritative for the API contract.** No implementation may begin until all eight are complete and mutually consistent.

---

## 0. Open decisions requiring stakeholder input

These materially affect architecture. Defaults are stated so planning can proceed, but each must be confirmed before the phase that depends on it.

| # | Question | Assumed default | Blocks phase |
|---|---|---|---|
| D-01 | Deployment target: on-premise LGU server, private VM, or cloud? | Single on-premise Linux VM (Docker Compose), with a documented path to multi-node | PHASE 1, 20 |
| D-02 | Satellite imagery provider and licence (Google/Bing/Esri/Mapbox/NAMRIA)? | None bundled; OSM only until a licence is supplied. Provider is a runtime config | PHASE 5 |
| D-03 | Expected data volume (parcels, features, concurrent users)? | 250k parcels, 2M features, 50 concurrent users | PHASE 2, 19 |
| D-04 | Is there an existing RPT/assessment system, and what is its DB/API? | None reachable. Integration via an outbound adapter interface only | PHASE 9 |
| D-05 | Authoritative source of control-point coordinates (DENR-LMB / NAMRIA / LGU records)? | LGU-supplied CSV, every point flagged `UNVERIFIED` until a verifier signs off | PHASE 10 |
| D-06 | Document storage: local filesystem, NAS, or S3-compatible object store? | Local filesystem behind a storage abstraction; MinIO/S3 driver stubbed | PHASE 1, 30 |
| D-07 | Must the system produce printed outputs treated as official (e.g. plan prints)? | No. All output is watermarked as a GIS computation, not a certification | PHASE 13, 43 |
| D-08 | RA 10173 (Data Privacy Act) posture: is the LGU's DPO involved, is there an existing privacy impact assessment? | Owner/party data is treated as sensitive personal information by default | PHASE 3, 9 |
| D-09 | SSO / directory integration (LDAP, Azure AD, Google Workspace)? | Local accounts only; auth provider is pluggable | PHASE 3 |
| D-10 | Offline or low-connectivity field use required? | No. Online-only; field capture deferred | — |
| D-11 | Backup RPO/RTO targets and who operates backups? | RPO 24h (nightly), RTO 4h; LGU IT operates | PHASE 20 |
| D-12 | Is a server-side GDAL/ogr2ogr binary permitted on the host (needed for Shapefile/KML import)? | Yes, containerised | PHASE 16 |
| D-13 | Legacy datum coverage: are Luzon 1911 records in scope at go-live? | Yes, read/transform with explicit, logged parameters | PHASE 15, 12 |
| D-14 | Which **web** basemap licences does the LGU actually hold (provider, seat/tile limits, redistribution terms)? A CAD-Earth or desktop licence does not cover this | OSM only until a written web licence is produced; provider slots exist but ship disabled | PHASE 5, 29 |
| D-15 | Does the LGU own orthophotos/aerial imagery it may self-host, and in what format (GeoTIFF, MBTiles, WMS)? | None; local-orthophoto provider type is implemented but unconfigured | PHASE 29 |
| D-16 | DWG support: is an ODA File Converter (or equivalent) licence available on the server, or is DXF-only acceptable? | DXF only via GDAL; DWG requires user-side conversion | PHASE 16 |
| D-17 | Split/consolidation authority: which roles may initiate, and does an LGU policy require an external approved plan reference before a split is published? | `parcel.split`/`parcel.consolidate` for GIS Manager and Survey user; a plan reference is required to reach APPROVED but not to draft | PHASE 14 |
| D-18 | Report outputs: are PDF report templates required at v1, or is on-screen + CSV/GeoJSON sufficient? | PDF for Survey Computation, Parcel Profile and Closure reports; others on-screen + CSV | PHASE 18 |
| D-19 | Is GeoPackage import/export required at v1? | Yes if GDAL is present (D-12), otherwise deferred | PHASE 16 |

---

## 1. System architecture

### 1.1 Logical view

```text
┌──────────────────────────────────────────────────────────────────────┐
│ Browser (SPA)                                                        │
│  React 18 + TypeScript + Vite + Bootstrap 5 + OpenLayers 9           │
│  TanStack Query (server state) · Zustand (map/UI state)              │
└───────────────┬──────────────────────────────────────────────────────┘
                │ HTTPS · JSON (application/json, application/geo+json)
                │        · MVT (application/vnd.mapbox-vector-tile)
┌───────────────▼──────────────────────────────────────────────────────┐
│ Reverse proxy (nginx)                                                │
│  TLS · static SPA assets · gzip/brotli · rate limiting · tile cache  │
└───────────────┬──────────────────────────────────────────────────────┘
                │ FastCGI
┌───────────────▼──────────────────────────────────────────────────────┐
│ API tier — PHP 8.3, Slim 4 (PSR-7/15), stateless                     │
│  ┌─────────────┬───────────────┬───────────────┬──────────────────┐  │
│  │ HTTP layer  │ Application   │ Domain        │ Infrastructure   │  │
│  │ controllers │ services,     │ entities,     │ PDO repositories,│  │
│  │ middleware  │ use cases,    │ value objects,│ storage, mailer, │  │
│  │ validators  │ authorization │ survey math   │ GDAL adapter     │  │
│  └─────────────┴───────────────┴───────────────┴──────────────────┘  │
└───────────────┬───────────────────────────────┬──────────────────────┘
                │ PDO (pgsql)                   │ file/object I/O
┌───────────────▼───────────────────┐  ┌────────▼─────────────────────┐
│ PostgreSQL 16 + PostGIS 3.4       │  │ Document store               │
│  spatial + relational + RLS       │  │  scans, plans, photos        │
│  audit (partitioned) · versions   │  │  SHA-256 addressed           │
└───────────────────────────────────┘  └──────────────────────────────┘
                │
┌───────────────▼──────────────────────────────────────────────────────┐
│ Worker (PHP CLI, cron/supervisor)                                     │
│  imports · exports · tile pre-seed · audit partition rollover · backup │
└───────────────────────────────────────────────────────────────────────┘
```

### 1.2 Backend technology decision (rationale required by §2 of the master prompt)

**Chosen:** PHP 8.3 + **Slim 4** (PSR-7/PSR-15) + **PHP-DI** + **raw PDO repositories** + **Phinx** migrations.

Rationale:

- PostGIS work is dominated by hand-written spatial SQL (`ST_AsMVT`, `ST_Transform`, `ST_Area`, `ST_MakeValid`, `ST_Intersects`, window functions). A full ORM (Eloquent/Doctrine ORM) adds friction and hides the SQL we must tune and audit. PDO with explicit SQL matches the master prompt's requirement and the workload.
- Slim is a thin PSR-15 pipeline. Authorization, tenancy (`SET LOCAL app.user_id` for RLS), audit context, and rate limiting are all middleware — easy to prove they run on every request.
- Stateless by construction: no server-side session state in PHP; identity is re-derived per request from a token.
- Laravel was considered and **rejected as the primary framework** (weight, ORM-first idioms, magic that complicates auditability), but its component libraries may be pulled in individually (`illuminate/validation` is explicitly permitted). Symfony components (`symfony/console`, `symfony/validator`) are permitted.
- If this decision is reversed, it must be reversed **here first**, with the reason recorded in §14.

Supporting libraries: `firebase/php-jwt` or `lcobucci/jwt` (tokens), `respect/validation` or `symfony/validator` (input), `monolog` (logging), `phpunit` (tests), `phpstan` level 8 + `php-cs-fixer` (static analysis/style), `brick/math` where exact decimal arithmetic is required for areas and distances.

### 1.3 Process/runtime view

| Component | Runtime | Scaling | State |
|---|---|---|---|
| nginx | container | vertical, then multiple behind LB | tile cache (disk, expendable) |
| php-fpm | container, N workers | horizontal (stateless) | none |
| PostgreSQL/PostGIS | container or host service | vertical first; read replica later | **all authoritative state** |
| Worker | container, supervisor | 1 per job class | job rows in DB |
| Document store | volume or S3 | — | immutable blobs |

Because the API tier holds no state, horizontal scaling requires only: a shared token signing key (env), a shared document store, and a shared DB.

### 1.4 Deployment architecture

```text
PHASE 1–19 (single node)
  docker compose: nginx · php-fpm · postgres/postgis · worker · (minio optional)
  volumes: pgdata · documents · tilecache · backups

PHASE 20 (production hardening)
  nginx  → TLS (Let's Encrypt or LGU cert), HSTS, security headers
  postgres → dedicated volume, WAL archiving, nightly pg_dump + weekly base backup
  secrets → .env file, 0600, never in the image, never in the repo
  logs → JSON to stdout → host log rotation/collector
```

Environments: `local` → `staging` (production-like, synthetic data only) → `production`. Migrations run in CI against a throwaway PostGIS container before ever touching staging.

### 1.5 Backup and recovery

- **Nightly** `pg_dump -Fc` of the full database, retained 30 days; **weekly** retained 12 weeks.
- **Continuous** WAL archiving to a separate volume once the platform is in production (target RPO ≤ 15 min; default assumption D-11 is 24h until WAL archiving is enabled).
- Document store: nightly rsync/replication; blobs are immutable and content-addressed so incremental backup is cheap.
- **Restore drills are a task, not an aspiration** (TASK-152). A backup that has never been restored does not count.
- Audit log and version tables are included in every backup; they are never truncated by application code.

---

## 2. Component architecture (backend)

```text
src/
  Http/                  PSR-15 pipeline
    Middleware/          Cors, RequestId, RateLimit, Authenticate, DbSessionContext,
                         Authorize, ValidateJson, AuditContext, ErrorHandler
    Controller/          thin: parse → call use case → serialize
    Response/            Problem+JSON, GeoJSON, MVT writers
  Application/
    Auth/                LoginUser, RefreshToken, LogoutUser, ChangePassword
    Access/              PermissionResolver, LayerAccessResolver, DataScopeResolver
    Gis/                 CreateLayer, UpdateLayerFields, UpsertFeature, QueryFeatures
    Parcel/              CreateParcel, SaveTechnicalDescription, ComputeParcel,
                         ValidateParcel, SubmitParcel, TransitionParcel
    Survey/              ManageControlPoints, TransformCoordinates
    Docs/                UploadDocument, LinkDocument
    Io/                  StartImport, PreviewImport, CommitImport, StartExport
  Domain/
    Survey/              ← PURE, NO I/O, NO FRAMEWORK
      Bearing.php        quadrant/DMS/azimuth value object
      Azimuth.php
      Distance.php       canonical metres
      Course.php         line or curve
      Traverse.php       course list + tie line
      TraverseComputer.php
      ClosureResult.php
      AreaCalculator.php
      Adjustment/        CompassRule, TransitRule (PHASE 12+)
      Parser/            TechnicalDescriptionParser, tokenizer, normalizer
    Gis/                 GeometryType, LayerDefinition, FieldDefinition, StyleRule
    Access/              Permission, Role, DataScope, AccessDecision
    Workflow/            StateMachine, Transition, Guard
  Infrastructure/
    Db/                  PdoFactory, TransactionManager, repositories, GeometryCodec
    Crs/                 CrsRegistry, TransformationService (PostGIS-backed)
    Storage/             LocalFilesystemStore, S3Store (interface: DocumentStore)
    Geo/                 OgrAdapter (shapefile/KML via ogr2ogr subprocess)
    Audit/               AuditWriter
  Support/               Clock, Uuid, Json, Result
```

**Mapping onto the prescribed repository layout.** The master prompt specifies `backend/src/{Auth,GIS,Parcels,Survey,Titles,Documents,Users,RBAC,Audit,Core}`. The layered design above is the *internal* shape of each module, not a competing tree. The reconciled structure is module-first, layer-second:

```text
backend/src/
  Core/          Http middleware pipeline, DI container, Db (PdoFactory, TransactionManager,
                 GeometryCodec), Crs, Storage, Geo/OgrAdapter, Support, Errors
  Auth/          Http/ Application/ Domain/ Infrastructure/
  RBAC/          Http/ Application/ Domain/ Infrastructure/   (permissions, scopes, layer perms)
  Users/         …
  GIS/           … (layers, fields, styles, features, tiles, spatial ops, basemaps)
  Parcels/       … (parcels, lifecycle, split, consolidation, lineage, versions)
  Survey/        Domain/  ← the pure computation library lives here
                 Http/ Application/ Infrastructure/ (control points, tie points, plans, TD, parser)
  Titles/        …
  Documents/     …
  Audit/         …
  Reports/       …
  ImportExport/  …
```

Every module has the same four internal folders (`Http`, `Application`, `Domain`, `Infrastructure`) so the dependency rule is uniform: `Http → Application → Domain`, with `Infrastructure` implementing interfaces declared in `Domain`. Cross-module calls go through `Application` services, never repository-to-repository.

**Hard rule:** `Survey/Domain` has zero dependencies on PDO, HTTP, or any framework. It takes numbers and value objects and returns numbers and value objects. This is what makes §41's unit-test requirement achievable and is the single most important structural constraint in the codebase.

---

## 3. Database architecture

### 3.1 Conventions

- PostgreSQL 16, PostGIS 3.4. Schema `app` for application tables, `audit` for audit/versioning, `ref` for reference data (CRS registry, PSGC codes, enumerations), `staging` for import work tables.
- Primary keys: `bigint GENERATED ALWAYS AS IDENTITY` for high-volume tables; `uuid` for externally referenced entities (`parcels`, `documents`, `gis_features`) so IDs can be minted client-side and survive export/import.
- All mutable tables carry: `version int NOT NULL DEFAULT 1`, `created_by`, `created_at`, `updated_by`, `updated_at`, and where applicable `deleted_at` (soft delete) — **never** hard-delete a parcel, title, control point, or version row.
- Timestamps `timestamptz`, stored UTC, rendered in Asia/Manila.
- Money/area/distance: `numeric`, never `float`, except transient computation. Areas persisted as `numeric(18,4)` square metres.
- Every `ALTER`/`CREATE` ships as a numbered Phinx migration; no manual DDL in any environment.

### 3.2 Spatial storage policy (the single most consequential GIS decision)

| Purpose | CRS | Where |
|---|---|---|
| **Canonical interoperable geometry** | EPSG:4326 | `geom geometry(Geometry, 4326)` on every spatial table |
| **Display/tiles** | EPSG:3857 | derived on the fly (`ST_Transform` inside the MVT query) |
| **Survey computation & area of record** | PRS92 PTM zone (EPSG:3121–3125) selected per parcel | `parcel_vertices` (N/E numerics) + `compute_srid` on the computation |
| **Historical/original survey coordinates** | as recorded (e.g. Luzon 1911, EPSG:25391–25395; or a local plane system) | `parcel_vertices.native_*` + `native_srid`, never overwritten |

Consequences, all deliberate:

1. Geometry is stored once, in one SRID, so there is exactly one GIST index per table and no ambiguity about "which geometry is real".
2. **Area is never computed in 4326.** `ST_Area` on geographic coordinates is meaningless for a parcel. Area of record = shoelace on the computed plane coordinates in the compute CRS; a cross-check uses `ST_Area(ST_Transform(geom, compute_srid))`. Both are stored; if they disagree beyond tolerance that is a validation warning, not a silent pick.
3. The 4326 geometry is **derived** from the vertices for computed parcels. The vertices are authoritative; the polygon is a projection of them. Regenerating the polygon from stored inputs must be byte-reproducible (TASK-094).
4. `ST_Transform` is never applied implicitly on read of historical survey coordinates. Any datum change is an explicit, logged operation writing a `coordinate_transformations` row (§15 of the master prompt).

### 3.3 Dynamic attributes: JSONB, not EAV, not table-per-layer

`gis_features.attributes jsonb` holds user-defined field values; `gis_layer_fields` holds the metadata that defines and validates them.

- **Rejected — EAV** (`feature_id, field_id, value_text/value_num/...`): row explosion, every read becomes a pivot, filtering and sorting are slow and ugly.
- **Rejected — physical table per layer**: the application would issue DDL at runtime (a privilege we do not want the app role to hold), migrations become unmanageable, and RLS/permissions must be re-declared per table.
- **Chosen — JSONB**: one table, one index strategy, metadata-driven validation in the application *and* a server-side check, cheap schema evolution.
- Performance answer for hot fields: `CREATE INDEX ... ON app.gis_features ((attributes->>'lot_no')) WHERE layer_id = N` — expression indexes created by migration for fields flagged `searchable`, plus a `GIN (attributes jsonb_path_ops)` catch-all.
- Type/required validation runs in the application (rich errors) **and** is enforced by a `validate_feature_attributes()` trigger that reads `gis_layer_fields` (defence in depth; §25 "never rely only on the frontend" applies to the DB boundary too).

Parcels are **not** a dynamic layer. They are a first-class relational subsystem (§3.5) with a companion row in `gis_features` only if a site chooses to expose them in the generic layer stack; the relational tables remain authoritative.

### 3.4 ERD — identity, access, organisation

```text
organizations ──┬─< users >──┬─< user_roles >── roles ──< role_permissions >── permissions
   │ (self ref)  │            └─< data_scopes
   └─< organizations (parent_id, PSGC hierarchy)

roles ──< layer_permissions >── gis_layers
users ──< refresh_tokens
users ──< user_sessions (audit of logins, not auth state)
```

```sql
ref.psgc_areas(code PK, level, name, parent_code)        -- province/city/muni/barangay
app.organizations(id PK, code UQ, name, org_type, parent_id FK, psgc_code FK, status,
                  created_at, updated_at)
app.users(id PK, username UQ CI, email UQ CI, password_hash, full_name, position,
          org_id FK, status, mfa_enabled, mfa_secret_enc, must_change_password,
          failed_login_count, locked_until, last_login_at, password_changed_at,
          version, created_by, created_at, updated_by, updated_at, deleted_at)
app.roles(id PK, code UQ, name, description, is_system bool, created_at, updated_at)
app.permissions(id PK, code UQ, module, description)      -- seeded, immutable set
app.role_permissions(role_id FK, permission_id FK, PK(role_id, permission_id))
app.user_roles(user_id FK, role_id FK, org_id FK NULL, granted_by, granted_at,
               PK(user_id, role_id, COALESCE(org_id,0)))
app.data_scopes(id PK, user_id FK, scope_type, scope_ref_code, access_level,
                geom geometry(MultiPolygon,4326) NULL, valid_from, valid_to,
                granted_by, granted_at)
   -- scope_type ∈ (ORGANIZATION, PROVINCE, MUNICIPALITY, BARANGAY, CUSTOM_AREA)
   -- access_level ∈ (NONE, VIEW, EDIT, APPROVE)   NONE is an explicit deny and wins
app.layer_permissions(layer_id FK, role_id FK, can_view, can_create, can_update,
                      can_delete, can_approve, PK(layer_id, role_id))
app.refresh_tokens(id PK, user_id FK, token_hash UQ, issued_at, expires_at,
                   rotated_from, revoked_at, user_agent, ip)
```

Indexes: `users(lower(username))`, `users(org_id)`, `data_scopes(user_id, scope_type)`, GIST on `data_scopes.geom`, `refresh_tokens(token_hash)`, `refresh_tokens(user_id) WHERE revoked_at IS NULL`.

### 3.5 ERD — GIS core

```sql
app.gis_layers(id PK, code UQ, name, description, group_path, geometry_type, srid,
               status, is_system, visible_default, opacity_default, display_order,
               label_field, min_zoom, max_zoom, feature_count_cache,
               version, created_by, created_at, updated_by, updated_at, deleted_at)
   -- geometry_type ∈ (POINT, LINESTRING, POLYGON, MULTIPOINT, MULTILINESTRING,
   --                  MULTIPOLYGON, GEOMETRY)
app.gis_layer_fields(id PK, layer_id FK, field_name, label, data_type, required,
                     default_value, options jsonb, validation_rules jsonb,
                     display_order, searchable, visible, editable, is_pii,
                     created_at, updated_at, UQ(layer_id, field_name))
   -- data_type ∈ (text,long_text,integer,decimal,boolean,date,datetime,dropdown,
   --              multi_select,email,phone,url)
app.gis_layer_styles(id PK, layer_id FK, style_type, attribute_field, rules jsonb,
                     default_rule jsonb, label_config jsonb, is_active,
                     created_by, created_at, updated_at)
   -- style_type ∈ (SINGLE, CATEGORIZED, GRADUATED)
app.gis_features(id uuid PK, layer_id FK, geom geometry(Geometry,4326) NOT NULL,
                 attributes jsonb NOT NULL DEFAULT '{}', status, org_id FK NULL,
                 psgc_barangay FK NULL, source_ref, version int,
                 created_by, created_at, updated_by, updated_at, deleted_at)
audit.gis_feature_versions(id PK, feature_id FK, version, geom, attributes jsonb,
                           status, changed_by, changed_at, change_reason, operation)
```

Constraints and indexes:

- `CHECK (ST_IsValid(geom))` is **not** used as a table constraint (it blocks legitimate staging of imported data); instead `ST_IsValid` is enforced in the write path and a nightly job reports invalid geometries. Rationale: rejecting at the constraint level turns a fixable data-quality issue into an unrecoverable import failure.
- Trigger `enforce_layer_geometry_type()` rejects a feature whose `GeometryType(geom)` is incompatible with its layer's `geometry_type`.
- `CREATE INDEX gis_features_geom_gix ON app.gis_features USING GIST (geom);`
- `CREATE INDEX gis_features_layer_idx ON app.gis_features (layer_id) WHERE deleted_at IS NULL;`
- `CREATE INDEX gis_features_attrs_gin ON app.gis_features USING GIN (attributes jsonb_path_ops);`
- Partitioning by `layer_id` (LIST) is deferred; revisit at > 5M rows (D-03). The migration path is documented in TASK-146.

### 3.6 ERD — parcel, survey, title

```sql
ref.crs_registry(id PK, srid, code UQ, name, datum, zone, is_projected,
                 proj4text, wkt, area_of_use_note, epoch, is_active)
   -- seeded: 4326, 3857, 3121..3125 (PRS92 PTM zones 1-5),
   --         25391..25395 (Luzon 1911 zones I-V), plus local systems on request

app.survey_plans(id PK, plan_number UQ, plan_type, survey_date, approved_date,
                 approving_agency, surveyor_name, surveyor_license,
                 lot_count, crs_id FK, psgc_barangay, remarks,
                 version, created_by, created_at, updated_by, updated_at)
   -- plan_type ∈ (Psd, Psu, Pcs, Csd, Ccs, Bsd, Vs, Fls, Rs, As, Swo, Msi, OTHER)

app.survey_control_points(id PK, point_name, point_type, monument_type,
                          easting numeric(14,4), northing numeric(14,4),
                          native_srid FK, latitude numeric(12,9), longitude numeric(12,9),
                          elevation numeric(10,4) NULL, datum, zone, geom geometry(Point,4326),
                          source, accuracy_class, accuracy_value_m, description,
                          survey_reference, status, psgc_barangay,
                          verified_by FK NULL, verified_at, version,
                          created_by, created_at, updated_by, updated_at, deleted_at)
   -- point_type ∈ (BLLM, MBM, PBM, GCP, CONTROL_POINT, TIE_POINT, REFERENCE_POINT, OTHER)
   -- status ∈ (UNVERIFIED, VERIFIED, DISPUTED, RETIRED)

app.parcels(id uuid PK, parcel_code UQ, lot_number, block_number,
            survey_plan_id FK NULL, survey_type, title_number_ref,
            tax_declaration_no, source_area_sqm numeric(18,4), source_area_unit,
            psgc_barangay FK, psgc_municipality FK, psgc_province FK,
            location_description, status, provenance, source_document_id FK NULL,
            remarks, geom geometry(MultiPolygon,4326) NULL,
            current_computation_id FK NULL, org_id FK,
            version, created_by, created_at, updated_by, updated_at, deleted_at)
   -- status ∈ (DRAFT, SUBMITTED, UNDER_REVIEW, RETURNED, VERIFIED, APPROVED, PUBLISHED, ARCHIVED)
   -- provenance ∈ (SURVEY_COORDINATES, COMPUTED_FROM_TECHNICAL_DESCRIPTION,
   --               TRANSFORMED_FROM_HISTORICAL_SURVEY, IMPORTED_GIS,
   --               DIGITIZED_FROM_IMAGERY, APPROXIMATE)

app.technical_descriptions(id PK, parcel_id FK, revision int, survey_plan_id FK NULL,
                           tie_point_id FK NULL, bearing_reference, distance_unit,
                           source_type, raw_text, source_document_id FK NULL,
                           compute_srid FK, status, is_current bool,
                           created_by, created_at, updated_by, updated_at,
                           UQ(parcel_id, revision))
   -- bearing_reference ∈ (GRID, GEODETIC, MAGNETIC, ASSUMED)
   -- source_type ∈ (MANUAL_ENTRY, PARSED_TEXT, OCR_ASSISTED, IMPORTED)

app.tie_lines(id PK, technical_description_id FK, from_control_point_id FK,
              to_point_label, bearing_quadrant, deg, min, sec,
              azimuth_dd numeric(12,8), distance numeric(12,4), distance_unit,
              distance_m numeric(12,4), remarks)
   -- a parcel may have >1 tie line (tie to BLLM then to corner 1)

app.technical_description_courses(id PK, technical_description_id FK, seq int,
        course_type, from_point_label, to_point_label,
        bearing_quadrant, deg smallint, min smallint, sec numeric(6,3),
        azimuth_dd numeric(12,8), distance numeric(12,4), distance_unit,
        distance_m numeric(12,4),
        curve_direction, radius_m, arc_length_m, chord_bearing_azimuth_dd,
        chord_length_m, central_angle_dd, tangent_in_azimuth_dd,
        remarks, UQ(technical_description_id, seq))
   -- course_type ∈ (LINE, CURVE)
   -- bearing_quadrant ∈ (NE, SE, SW, NW)  |  DUE_N/DUE_S/DUE_E/DUE_W handled as deg=0/90

app.parcel_computations(id PK, parcel_id FK, technical_description_id FK,
        compute_srid FK, method, adjustment_method, adjustment_params jsonb,
        base_computation_id FK NULL,          -- adjusted runs point at the original
        start_easting numeric(14,4), start_northing numeric(14,4),
        close_easting numeric(14,4), close_northing numeric(14,4),
        closure_de numeric(12,4), closure_dn numeric(12,4),
        linear_error_m numeric(12,4), perimeter_m numeric(14,4),
        relative_precision_denominator numeric(14,2),
        computed_area_sqm numeric(18,4), postgis_area_sqm numeric(18,4),
        source_area_sqm numeric(18,4), area_diff_sqm numeric(18,4),
        area_diff_pct numeric(10,6),
        closure_status, validation_result jsonb, input_snapshot jsonb NOT NULL,
        engine_version, computed_by FK, computed_at, is_current bool)
   -- closure_status ∈ (WITHIN_TOLERANCE, EXCEEDS_TOLERANCE, NOT_CLOSED, INDETERMINATE)
   -- input_snapshot = frozen copy of tie point, tie lines, courses, CRS, tolerances

app.parcel_vertices(id PK, computation_id FK, seq int, point_label,
        easting numeric(14,4), northing numeric(14,4), compute_srid FK,
        native_easting numeric(14,4) NULL, native_northing numeric(14,4) NULL,
        native_srid FK NULL, latitude numeric(12,9), longitude numeric(12,9),
        is_tie_vertex bool, UQ(computation_id, seq))

audit.parcel_versions(id PK, parcel_id FK, version int, snapshot jsonb NOT NULL,
        geom geometry(MultiPolygon,4326), status, change_summary, change_reason,
        changed_by FK, changed_at, UQ(parcel_id, version))

app.coordinate_transformations(id PK, entity_type, entity_id, source_srid FK,
        target_srid FK, method, parameters jsonb, parameter_source, accuracy_m,
        performed_by FK, performed_at, notes)
   -- method ∈ (POSTGIS_PROJ, HELMERT_7PARAM, MOLODENSKY_3PARAM, NTV2_GRID, MANUAL)

app.parties(id PK, party_type, full_name_enc, identifiers_enc jsonb,
            address_enc, contact_enc, is_sensitive bool DEFAULT true,
            created_by, created_at, updated_by, updated_at, deleted_at)
app.land_titles(id PK, title_number, title_type, title_date, registry_office,
        survey_plan_id FK NULL, lot_number, area_sqm numeric(18,4),
        location_description, psgc_barangay, source_document_id FK NULL,
        status, remarks, version, created_by, created_at, updated_by, updated_at,
        deleted_at, UQ(title_number, registry_office))
   -- title_type ∈ (OCT, TCT, CCT, FREE_PATENT, HOMESTEAD, CLOA, EP, TAX_DECLARATION_ONLY, OTHER)
app.title_parties(title_id FK, party_id FK, role, share_numerator, share_denominator,
                  effective_from, effective_to)
app.parcel_titles(parcel_id FK, title_id FK, relationship, PK(parcel_id, title_id))
```

### 3.7 ERD — documents, workflow, audit, I/O

```sql
app.documents(id uuid PK, storage_key UQ, original_filename, mime_type, byte_size,
              sha256 UQ, doc_type, classification, description, page_count,
              uploaded_by FK, uploaded_at, deleted_at)
   -- classification ∈ (PUBLIC, INTERNAL, RESTRICTED, SENSITIVE_PERSONAL)
app.document_links(id PK, document_id FK, entity_type, entity_id, link_role,
                   linked_by, linked_at, UQ(document_id, entity_type, entity_id, link_role))

app.workflow_definitions(id PK, code UQ, entity_type, name, is_active)
app.workflow_states(id PK, definition_id FK, code, name, is_initial, is_terminal, display_order)
app.workflow_transitions(id PK, definition_id FK, from_state_id FK, to_state_id FK,
                         action_code, required_permission, requires_reason, guard_expression)
app.workflow_instances(id PK, definition_id FK, entity_type, entity_id,
                       current_state_id FK, assigned_to FK NULL, due_at,
                       created_at, updated_at, UQ(entity_type, entity_id))
app.workflow_history(id PK, instance_id FK, from_state_id, to_state_id, action_code,
                     actor_id FK, reason, comment, acted_at, request_id)

audit.audit_logs(id bigint, occurred_at timestamptz, user_id, username_snapshot,
                 action, entity_type, entity_id, old_values jsonb, new_values jsonb,
                 changed_fields text[], reason, ip inet, user_agent, request_id,
                 PRIMARY KEY (id, occurred_at)) PARTITION BY RANGE (occurred_at);

app.import_jobs(id PK, source_format, source_document_id FK, target_layer_id FK NULL,
                target_entity, declared_srid FK, field_mapping jsonb, options jsonb,
                status, total_rows, valid_rows, invalid_rows, error_report jsonb,
                created_by, created_at, committed_by, committed_at)
app.import_job_rows(id PK, job_id FK, row_number, raw jsonb, normalized jsonb,
                    geom geometry(Geometry,4326), validation jsonb, is_valid, action)
app.export_jobs(id PK, format, query_spec jsonb, target_srid FK, status,
                document_id FK NULL, requested_by, requested_at, completed_at)
app.notifications(id PK, user_id FK, type, title, body, entity_type, entity_id,
                  read_at, created_at)
```

Audit indexes: monthly partitions with BRIN on `occurred_at`, BTREE on `(entity_type, entity_id, occurred_at DESC)` and `(user_id, occurred_at DESC)`. Partition rollover is a worker task.

### 3.8 Index summary (the ones that matter)

| Table | Index | Reason |
|---|---|---|
| `gis_features` | GIST(geom) | every map query, bbox, intersect |
| `gis_features` | GIN(attributes jsonb_path_ops) | attribute filter/search |
| `gis_features` | expression idx per searchable field | sortable/filterable grid columns |
| `parcels` | GIST(geom); BTREE(lot_number), (tax_declaration_no), (psgc_barangay, status) | map + search |
| `parcels` | `gin_trgm_ops` on lot_number, title_number_ref | fuzzy global search |
| `survey_control_points` | GIST(geom); BTREE(point_name), (point_type, status) | nearest tie point |
| `parcel_computations` | (parcel_id, is_current), (technical_description_id) | current result lookup |
| `parcel_vertices` | (computation_id, seq) | ordered replay |
| `audit_logs` | BRIN(occurred_at) + BTREE(entity_type, entity_id, occurred_at DESC) | history views |
| `data_scopes` | (user_id, scope_type); GIST(geom) | authorization on every request |

---

## 4. GIS architecture

### 4.1 Rendering strategy by layer size

| Feature count | Transport | Notes |
|---|---|---|
| < 2,000 | GeoJSON over REST, bbox-filtered | full attributes, fully editable client-side |
| 2,000 – 50,000 | GeoJSON, bbox + zoom-dependent `ST_SimplifyPreserveTopology` | attributes trimmed to label + id |
| > 50,000 | **MVT vector tiles** `GET /api/v1/tiles/{layer}/{z}/{x}/{y}.mvt` via `ST_AsMVT` | editing fetches the single feature as GeoJSON on click |
| Points > 20,000 | MVT + client clustering, or server-side clustering at low zoom | |

Tiles are generated by PostGIS, cached on disk by nginx keyed by `layer_id + z/x/y + style_version + user_scope_hash`. **Scope-sensitive layers are never cached across users** — the cache key includes a scope hash, and layers containing restricted data set `Cache-Control: private`.

The frontend **never** requests a layer without a bbox or tile coordinate. This is enforced at the API: an unbounded feature query returns `422` unless the caller passes an explicit `limit` ≤ 1000 with pagination.

### 4.2 OpenLayers usage

- One `ol/Map`, a `LayerManager` service that reconciles the layer tree from the API into OL layer instances, and a single `ol/interaction` controller so that only one edit interaction is armed at a time.
- Editing: `Draw`, `Modify`, `Snap`, `Translate`, `Select`. Snapping sources include the layer being edited plus any layer flagged "snap target".
- Geometry validation client-side (self-intersection, minimum vertices, ring closure) gives immediate feedback; the server repeats **every** check with PostGIS (`ST_IsValid`, `ST_IsSimple`, area/length sanity) and the server's answer is the one that counts.
- Measurement, identify, and spatial-search tools are OL interactions that call server endpoints for anything requiring PostGIS (buffer, intersect, nearest).
- Parcel preview during technical-description entry renders from the *computation response*, not from a saved geometry — the map shows what the numbers produce before anything is persisted.

### 4.3 CRS handling in the client

`proj4` + `ol/proj/proj4` register PRS92 zones and Luzon 1911 zones at boot from `ref.crs_registry` (served by `GET /api/v1/crs`). The client displays coordinates in the user's chosen CRS but transmits geometry in 4326 only. Client-side transformation is for **display and input convenience**; any transformation whose result is persisted is redone server-side and logged.

---

## 5. Survey computation architecture

### 5.1 Placement

All of it lives in `backend/src/Survey/Domain` (referred to throughout this document set as the *survey domain library*; earlier drafts called it `Domain/Survey` — the canonical path is the former). It is a pure library: no DB, no HTTP, no globals, deterministic, fully unit-testable. The API layer's job is to load inputs, call it, and persist the result with its input snapshot.

### 5.2 Bearing model

Input forms accepted: quadrant bearing (`N 25°30' E`, `N 25-30-00 E`, `N25d30mE`), decimal-degree quadrant (`N 25.5 E`), azimuth (`025-30-00`, `25.5°`), and cardinal (`DUE NORTH`). Internal canonical form is **azimuth in decimal degrees, clockwise from grid north, 0 ≤ Az < 360**.

```text
Quadrant → azimuth
  N α E → α
  S α E → 180 − α
  S α W → 180 + α
  N α W → 360 − α
  (0 ≤ α ≤ 90; α = 0 or 90 must be checked against the quadrant for ambiguity)

DMS → decimal:  dd = d + m/60 + s/3600
Validation: 0 ≤ d ≤ 90 (quadrant) or 0 ≤ d < 360 (azimuth); 0 ≤ m < 60; 0 ≤ s < 60
```

Round-trip requirement: `azimuth → quadrant → azimuth` must be exact to 1e-9 degrees for all test vectors, and the displayed DMS must never drift (store the entered DMS *and* the derived decimal; render from the entered values).

### 5.3 Traverse computation

```text
Given: tie point P0 (E0, N0) in compute CRS
       tie line(s) → point of beginning POB
       courses 1..n

For each course i:
  LINE:
    ΔN_i = D_i · cos(Az_i)
    ΔE_i = D_i · sin(Az_i)
  CURVE (PHASE 12+):
    chord solution from radius, central angle, direction; tangent continuity checked

  E_i = E_{i-1} + ΔE_i
  N_i = N_{i-1} + ΔN_i

Closure:
  ΔE_close = E_n − E_POB
  ΔN_close = N_n − N_POB
  linear_error = sqrt(ΔE_close² + ΔN_close²)
  perimeter = Σ D_i
  relative_precision = 1 : (perimeter / linear_error)      [∞ when linear_error = 0]
  error_bearing = atan2(ΔE_close, ΔN_close) → azimuth

Area (shoelace on plane coordinates, POB..P_n):
  A = ½ · |Σ (E_i · N_{i+1} − E_{i+1} · N_i)|
  Cross-check: ST_Area(ST_Transform(geom, compute_srid))
```

**Bearings are treated as grid bearings in the compute CRS by default** (`bearing_reference = GRID`). If a description is recorded as geodetic, convergence correction is required before use; until that module exists (PHASE 12+), a `GEODETIC` or `MAGNETIC` reference raises a blocking validation warning rather than being silently treated as grid. This is exactly the kind of assumption that must not be buried.

### 5.4 Tolerances (configurable, seeded as defaults)

| Check | Default | Configurable at |
|---|---|---|
| Relative precision | warn below 1:5,000; fail below 1:1,000 | system + per survey_type |
| Linear closure | warn > 0.10 m | system |
| Area difference vs source | warn > 0.5 %; flag > 2 % | system |
| Minimum courses for polygon | 3 | fixed |
| Duplicate/zero-length course | error | fixed |

Tolerance values, the engine version, and the tolerance set used are all written into `parcel_computations.input_snapshot` so an old result can be interpreted against the rules that were in force when it was produced.

### 5.5 Non-negotiables

- Closure is reported, never forced. The original computation is immutable.
- An adjustment (Compass/Transit) creates a **new** `parcel_computations` row with `base_computation_id` pointing at the original and `adjustment_method` set. Both remain queryable forever.
- Area agreement is a validation aid, never a legal determination (master prompt §19, §43).
- Every computation stores `input_snapshot` — tie point coordinates as they were at computation time, every course, the CRS, the tolerances, the engine version. If the control point is later corrected, the old computation still explains itself.
- Parser output is never authoritative: `PARSED → USER REVIEW → CONFIRM → COMPUTE`. The parser writes to a staging structure with per-field confidence; confirmation is a distinct user action recorded in the audit log.

---

## 6. Security architecture

### 6.1 Authentication

- Password hashing: `password_hash()` with Argon2id (fallback bcrypt cost 12), rehash-on-login when parameters change.
- **Tokens:** short-lived access JWT (15 min, HS256 with a rotating server key, claims: `sub`, `roles`, `scope_version`, `jti`, `exp`) returned in the JSON body and held in memory by the SPA; long-lived refresh token (14 days) as an **httpOnly, Secure, SameSite=Strict** cookie, stored hashed server-side, **rotated on every use** with reuse detection (a replayed refresh token revokes the whole family).
- Because the refresh endpoint is cookie-authenticated it is CSRF-exposed: it requires a double-submit CSRF token and an `Origin` check. All other endpoints use the `Authorization: Bearer` header and are therefore not CSRF-reachable.
- `scope_version` in the token forces re-authorization when a user's roles or scopes change — permission changes take effect within one access-token lifetime, and immediately for refresh.
- Lockout after N failed attempts with exponential backoff; every attempt logged.
- MFA (TOTP) supported for roles flagged `requires_mfa` (administrators, approvers).

### 6.2 Authorization — three independent layers

```text
Layer 1  PERMISSION      does the role hold `parcel.approve`?
Layer 2  LAYER/OBJECT    does the role hold that permission on *this layer*?
Layer 3  DATA SCOPE      does the user's scope cover this record's barangay/area?
                         + workflow guard: is this transition legal from this state?
```

All three are evaluated **server-side in middleware and in the use case**, never only in the controller and never only in React. The frontend receives the same decision set purely to decide what to render (`GET /api/v1/me` returns effective permissions, per-layer capabilities, and scopes). A hidden button is a UX nicety; the server refusal is the control.

Scope resolution order: explicit `NONE` deny → most specific grant (barangay > municipality > province > organization > custom area) → default deny.

### 6.3 PostgreSQL role model and RLS

```text
db role  app_migrator   DDL only, used by Phinx in deploys
db role  app_rw         DML on app.*, SELECT on ref.*, INSERT-only on audit.*
db role  app_ro         SELECT only (reporting, read replica)
```

- The API connects as `app_rw`, which **cannot** UPDATE or DELETE anything in `audit`. Audit rows are append-only at the database privilege level, not merely by convention.
- Per request: `SET LOCAL app.user_id`, `app.role_codes`, `app.scope_ids`, `app.request_id` inside the transaction. RLS policies read these via `current_setting('app.user_id', true)`.
- RLS is applied to the tables where record-level exposure is a real risk: `parcels`, `gis_features`, `land_titles`, `parties`, `documents`, `technical_descriptions`. It is a **backstop**, not the primary mechanism — application authorization still runs, because RLS cannot produce good error messages or express workflow guards.
- Application RBAC and database privileges are deliberately separate concerns (master prompt §26) and are documented separately so nobody assumes one implies the other.

### 6.4 PII handling (RA 10173)

- `app.parties` and PII-flagged fields are classified `SENSITIVE_PERSONAL`. Reading them requires `title.view_owner` (a distinct permission from `title.view`), and every read of a party record is written to the audit log — reads, not just writes, because unauthorised *lookup* is the realistic abuse here.
- Column-level encryption (libsodium, app-managed key from env) for name/address/contact/identifier fields, so a stolen `pg_dump` is not a stolen owner registry.
- The API has three response shapes for a parcel: **public GIS attributes** / **property information** / **sensitive personal information**, assembled per the caller's permissions. Field omission happens in the serializer, not the client.
- Document `classification` gates download; signed, short-lived, single-use URLs for file retrieval; no direct filesystem paths are ever exposed.

### 6.5 Application security controls

| Control | Implementation |
|---|---|
| SQL injection | PDO prepared statements everywhere; `ATTR_EMULATE_PREPARES = false`; identifiers (sort columns, JSON keys) validated against a metadata allow-list, never interpolated raw |
| XSS | React escaping by default; `dangerouslySetInnerHTML` banned by lint rule; API returns data, never HTML; CSP with no `unsafe-inline` |
| CSRF | Bearer tokens for the API; double-submit + Origin check on the cookie-authenticated refresh/logout endpoints |
| Upload safety | extension + MIME sniff (`finfo`) + magic-byte check, size cap (default 25 MB), random storage keys outside the web root, images re-encoded, PDFs scanned and never executed, `X-Content-Type-Options: nosniff` on download |
| Rate limiting | nginx global + per-user token bucket in PHP on auth, search, compute, import endpoints |
| Headers | HSTS, CSP, `Referrer-Policy: same-origin`, `X-Frame-Options: DENY`, `Permissions-Policy` |
| Secrets | env only, never in the repo, never in the SPA bundle, never in migrations; startup fails loudly if a required secret is missing |
| Least privilege | separate DB roles, container runs non-root, document store not web-served |
| Logging | structured JSON with `request_id`; PII never logged; failed authz logged with actor and target |

---

## 7. Concurrency and versioning

- **Optimistic concurrency** on every mutable entity: the client sends `If-Match: "<version>"`; the server runs `UPDATE ... WHERE id = ? AND version = ?` and returns `409 Conflict` with both versions if zero rows change. No last-write-wins, ever.
- Version rows are written in the same transaction as the mutation (`audit.parcel_versions`, `audit.gis_feature_versions`) by an application-side writer plus a trigger safety net.
- Long-running edit sessions may take a **soft advisory lock** (`app.edit_locks`, TTL 15 min, heartbeat) that warns other users; it is advisory and never blocks the optimistic check.
- Geometry edits from concurrent users on the same feature are conflicts, not merges. The system refuses and shows both versions.
- Version numbers are monotonic per entity; history is never renumbered and versions are never deleted (master prompt §29).

---

## 8. Audit architecture

Every mutation flows through `AuditWriter` inside the business transaction — if the audit row fails, the mutation rolls back. Captured: actor, action, entity type/id, old and new values (JSONB, PII redacted to field names + hashes for sensitive columns), changed field list, reason (mandatory for approvals, deletions, geometry changes, and title changes), IP, user agent, request ID.

Specifically tracked (master prompt §28): parcel create/geometry change, technical-description change, tie-point change, every computation run, every workflow transition, deletion and restoration, title changes, permission and role changes, party-record reads, document downloads of classified files, CRS transformations, and import commits.

Partitioned monthly; retained indefinitely; exportable as a signed CSV/JSON bundle for a single entity ("give me everything ever done to parcel X").

---

## 9. Workflow architecture

A generic, table-driven state machine (`workflow_definitions/states/transitions`) so new entity types reuse the engine.

```text
DRAFT → SUBMITTED → UNDER_REVIEW → {RETURNED → DRAFT | VERIFIED} → APPROVED → PUBLISHED
                                                   ↓
                                              (any state) → ARCHIVED (admin, reason required)
```

Each transition names a `required_permission`, an optional guard (e.g. "computation exists and closure is within tolerance", "no blocking validation errors"), and whether a reason is mandatory. Transitions are executed by one service, logged to `workflow_history` and the audit log, and emit notifications. Approval does not mutate survey data — it records that a human accepted a specific `parcel_computations.id` and `technical_descriptions.revision`.

---

## 10. Data flow — parcel from technical description

```text
1. User creates parcel shell            → parcels (DRAFT), audit
2. User selects/creates tie point       → survey_control_points (status UNVERIFIED unless verified)
3. User enters or pastes technical description
       paste → parser → staged courses + confidence  → USER REVIEW → CONFIRM
   → technical_descriptions (revision n) + tie_lines + technical_description_courses
4. POST /parcels/{id}/calculate
       load TD + tie point + CRS + tolerances → snapshot inputs
       Domain/Survey: bearings → azimuths → ΔN/ΔE → vertices → closure → area
       vertices → polygon (compute CRS) → ST_Transform → 4326 → ST_IsValid
   → parcel_computations (+ input_snapshot) + parcel_vertices, audit
5. POST /parcels/{id}/validate
       checklist: TD parsed · tie point found & verified? · CRS identified ·
       bearings valid · distances valid · polygon closed · geometry valid ·
       area computed · area vs source · overlap with neighbouring parcels
   → validation_result jsonb, warnings surfaced, nothing hidden
6. User reviews on-map preview, accepts → parcels.geom, current_computation_id,
   provenance = COMPUTED_FROM_TECHNICAL_DESCRIPTION, version++, parcel_versions
7. Submit → Review → Verify → Approve → Publish (workflow engine, each step audited)
```

At no point does the system alter entered bearings or distances. At no point is a computed parcel described as a legally authoritative boundary.

---

## 11. Integration points (kept loose on purpose)

- **RPT/assessment**: an outbound port `PropertyLinkProvider` with a stable external key (`parcel_code`, `tax_declaration_no`, PSGC). No foreign keys into a third-party schema, no shared tables. Integration is a separate adapter module (PHASE 9+).
- **Base maps**: provider registry in config (`OSM`, `provider-X satellite`), tokens in env, attribution rendered from config. Imagery is context only, never a boundary source.
- **Import/export**: GeoJSON and CSV natively in PHP; Shapefile and KML via `ogr2ogr` behind `OgrAdapter` so the dependency is replaceable (D-12).
- **CRS/transformations**: PROJ via PostGIS; NTv2 grids added later without schema change.
- **Notifications**: internal table now; email/SMS adapters later.

---

## 12. Scalability strategy

1. Indexes and bbox-bounded queries first (cheapest, biggest win).
2. MVT tiles + nginx tile cache for large layers.
3. Zoom-dependent simplification and attribute trimming.
4. Server-side pagination and filtering on every grid; no unbounded list endpoints.
5. `pgbouncer` (transaction pooling) when connection count becomes the limit — note this constrains `SET LOCAL` usage to within transactions, which the design already assumes.
6. Read replica for reporting/exports (`app_ro`).
7. Partition `gis_features` by `layer_id` and `audit_logs` by month.
8. Materialised views for expensive dashboards, refreshed by the worker.

Explicitly not done: loading whole layers into the browser; client-side filtering of large datasets; per-feature round trips during pan/zoom.

---

## 13. Testing architecture (summary; detail in specification.md §Testing)

| Level | Tool | Target |
|---|---|---|
| Unit — survey math | PHPUnit | bearing parse, DMS↔decimal, quadrant↔azimuth, unit conversion, traverse, closure, area, tolerance evaluation. **≥ 95 % coverage of `Domain/Survey`, with known-answer test vectors** |
| Unit — frontend | Vitest | reducers, formatters, permission hooks, coordinate display |
| Integration — API | PHPUnit + throwaway PostGIS container | auth, RBAC, layer permissions, data scopes, validation, concurrency (409), RLS bypass attempts |
| Spatial | PHPUnit + PostGIS | geometry validity, SRID correctness, bbox/intersect/within/nearest, area in projected CRS |
| Contract | OpenAPI schema validation in CI | request/response conformance |
| E2E | Playwright | the full §41 workflow list, login → approve → history |
| Performance | k6 or JMeter | tile endpoint, feature bbox query, search at target volume |
| Security | automated dependency audit + manual checklist | authz bypass, IDOR, upload abuse |

CI gate: lint + PHPStan L8 + unit + integration + build must pass before merge. A task is not done if CI is red (master prompt §49).

---

## 14. Technology decisions register

| ID | Decision | Alternatives rejected | Reason |
|---|---|---|---|
| ADR-01 | PHP 8.3 + Slim 4 + PDO | Laravel, Symfony full-stack, Node/FastAPI | explicit spatial SQL, thin auditable middleware, matches mandated stack |
| ADR-02 | Store geometry canonically in EPSG:4326 | store in PRS92 zone | one SRID, one index, portable; compute CRS handled per parcel |
| ADR-03 | Compute and measure area in PRS92 PTM zone | compute in 4326 | area/length in geographic degrees is invalid |
| ADR-04 | JSONB for dynamic attributes | EAV, table-per-layer | no runtime DDL, indexable, manageable |
| ADR-05 | Access JWT + rotating refresh cookie | PHP sessions, long-lived JWT | stateless API, revocable, CSRF-contained |
| ADR-06 | RLS as backstop, app authz primary | RLS-only, app-only | defence in depth with usable errors |
| ADR-07 | Optimistic concurrency via `version` + `If-Match` | pessimistic locks | web-friendly, no stuck locks, explicit conflicts |
| ADR-08 | Survey math as a pure domain library | logic in controllers/React | testability is a hard requirement |
| ADR-09 | MVT via `ST_AsMVT` for large layers | GeoJSON everywhere, external tile server | no extra service, PostGIS is already authoritative |
| ADR-10 | Immutable computations; adjustments create new rows | mutate in place | auditability, §18 forbids silent alteration |
| ADR-11 | `ogr2ogr` for Shapefile/KML | pure-PHP parsers | correctness over purity; adapter-isolated |
| ADR-12 | TanStack Query + Zustand | Redux Toolkit, Context-only | server/UI state separation, less boilerplate |
| ADR-13 | Axios as the HTTP transport, wrapped by a single `apiClient`, under TanStack Query | bare `fetch` | mandated by the stack; interceptors give one place for auth refresh, `request_id`, and error mapping |
| ADR-14 | Envelope error format `{success,error:{code,message,details}}` | RFC 7807 problem+json (v0.1 draft) | mandated by the master prompt; the v0.1 choice is superseded. `details` carries field errors, rule ids, and `request_id` |
| ADR-15 | Split/consolidation performed in PostGIS (`ST_Split`, `ST_Union`, `ST_Difference`) inside one transaction | client-side geometry algebra | server is authoritative, transactional, and testable |
| ADR-16 | Parents are never deleted on split/consolidation; they become `SUPERSEDED` with `parcel_relationships` edges | delete-and-recreate, soft-delete only | lineage and history are legal-grade requirements |
| ADR-17 | `survey_control_points` is the point registry; `tie_points` records the *use* of a point by a technical description | one merged table (v0.1 draft), duplicated coordinate columns | a point may tie many parcels; usage metadata (role, sequence, as-used coordinates) belongs on the usage, not the monument |
| ADR-18 | `parcel_courses` is a **view** over `technical_description_courses` | a second physical course table | one source of truth for course data; the view satisfies the prescribed table list without duplication |
| ADR-19 | Basemaps are rows in `basemap_providers` with licence metadata; third-party keys are proxied server-side | hard-coded providers, keys in the SPA | licensing is a first-class, auditable concern (§16) |
| ADR-20 | CAD data enters only through the validated import pipeline; no imagery is ever ingested from CAD-Earth or similar desktop tools | treating CAD-Earth as a tile source | licence compliance (§17); this is a hard prohibition, not a preference |

Any change to this table requires a documented reason and a corresponding update to `specification.md` and `frontend.md` (master prompt §48.7).

---

## 15. Professional and legal limitation (architectural, not cosmetic)

The system is a GIS computation and records-management platform. It does not certify surveys, adjudicate boundaries, or establish ownership. Architecturally this is enforced by:

- `parcels.provenance` is mandatory and always rendered wherever geometry is displayed or exported.
- Every export and print carries a machine-generated provenance and disclaimer block.
- `APPROVED` means "accepted in this system by an authorised officer", a status distinct from any external legal or agency certification; the four-level distinction (GIS computation / survey source data / verified internal record / legal certified document) is modelled explicitly and shown in the UI.
- Area comparison outcomes are labelled as validation aids.
- Imagery-derived geometry is permanently stamped `DIGITIZED_FROM_IMAGERY` and cannot be relabelled without a recorded, audited justification.

---

## 16. Basemap provider and licensing architecture

Basemaps are visual context. They are never a source of parcel geometry, and the architecture makes that structural rather than advisory: no code path can promote a basemap into the geometry pipeline, because geometry only ever enters through the survey engine, the import pipeline, or manual drawing — each of which stamps a provenance value.

### 16.1 Provider registry

```sql
app.basemap_providers(id PK, code UQ, name, provider_type, service_url,
    url_template, layer_name, matrix_set, format, attribution_html, attribution_url,
    license_type, license_reference, license_expires_on, license_notes,
    requires_api_key bool, api_key_env_name, proxy_required bool,
    min_zoom, max_zoom, bounds geometry(Polygon,4326) NULL, srid,
    is_enabled, is_default, display_order, allowed_role_ids bigint[],
    created_by, created_at, updated_by, updated_at)
-- provider_type ∈ (XYZ, WMS, WMTS, VECTOR_TILE, LOCAL_ORTHOPHOTO, TMS)
-- license_type  ∈ (OPEN_ODBL, COMMERCIAL_WEB, GOVERNMENT_GRANT, ORGANIZATION_OWNED, UNLICENSED)
```

Rules enforced by the platform:

1. **An API key never reaches the browser.** Providers with `requires_api_key` are fetched through `GET /api/v1/basemaps/{code}/tiles/{z}/{x}/{y}`, a thin authenticated proxy that injects the key from the environment, enforces `allowed_role_ids`, and applies rate limits. Only key-free providers (OSM) are given a direct URL template.
2. **A provider with `license_type = UNLICENSED` cannot be enabled.** The API rejects the update and explains why. `license_expires_on` in the past disables the provider automatically and raises an admin notification.
3. **Attribution is mandatory.** `attribution_html` is required for every enabled provider and is rendered by the map at all times; it cannot be dismissed or hidden by layer settings.
4. **The proxy caches only what the licence permits.** `cache_ttl_seconds` defaults to 0 for commercial providers unless the licence explicitly allows caching; the value and its justification are stored in `license_notes`. No bulk pre-seeding of third-party tiles is implemented anywhere in the codebase.
5. Enabling, disabling, or editing licence fields is audited.

### 16.2 Local orthophoto

LGU-owned imagery (D-15) is the one case where tiles may be hosted by the platform. It is stored outside the database, served by nginx from a read-only volume, registered as `LOCAL_ORTHOPHOTO`, and carries its own capture date, resolution, and source in `license_notes`. It is still imagery — provenance rules for any geometry digitised from it are unchanged.

---

## 17. CAD-Earth / AutoCAD workflow architecture

CAD-Earth and AutoCAD are **desktop survey/CAD tools operated by the user**. They are outside the trust and licence boundary of this platform. The only connection between them and the platform is a file passing through the validated import pipeline.

```text
 ┌─────────────────────────────┐        ┌──────────────────────────────────────┐
 │ DESKTOP (user's machine)    │        │ PLATFORM                             │
 │  AutoCAD + CAD-Earth        │        │                                      │
 │  survey linework, points,   │  file  │  Import pipeline                     │
 │  parcel geometry            ├───────►│   upload → format detect → CRS       │
 │                             │ DXF/   │   declaration → layer/field mapping  │
 │  imagery displayed here     │ CSV/   │   → validation → preview → COMMIT    │
 │  under the DESKTOP licence  │ SHP/   │                                      │
 │  ─────────────────────────  │ GPKG   │  → PostGIS (provenance = CAD_IMPORT) │
 │  NEVER crosses this line ✗  │        │  → React GIS (own web basemaps only) │
 └─────────────────────────────┘        └──────────────────────────────────────┘
```

### 17.1 Hard prohibitions (implemented as absent capability, not as policy text)

The platform contains **no** code to: scrape or bulk-download provider imagery; ingest imagery from CAD-Earth or any desktop tool; convert third-party imagery into an internal tile service; rehost or redistribute third-party imagery; or reuse a desktop licence key for web clients. Any future change that would add such a capability requires an explicit, documented licence grant recorded in `basemap_providers.license_reference` and a new ADR. A desktop CAD-Earth licence is never accepted as authorisation for web use.

Image files that arrive as *documents* (a scanned plan, a site photo) are documents, not basemaps; they are stored in the document store, never registered as a tile source, and never georeferenced into the map stack without an explicit georeferencing workflow (out of scope for v1).

### 17.2 CAD import specifics

- **DXF** via GDAL/`ogr2ogr` (`OGR_DXF` driver): entity layers map to target GIS layers; blocks, text, and dimensions are dropped with a report of what was discarded.
- **DWG**: not read natively (D-16). The user converts to DXF, or an ODA File Converter step is added server-side if licensed.
- **CRS is never inferred.** CAD files usually carry no CRS. The importer *requires* an explicit CRS declaration and, for local/assumed CAD grids, a documented transformation (origin, scale, rotation) written to `coordinate_transformations` before any geometry is accepted.
- Imported CAD geometry is stamped `provenance = CAD_IMPORT`, retains `source_document_id` pointing at the stored original file, and enters as `DRAFT`. It is never auto-approved and never auto-merged into an existing parcel.
- Survey points imported from CAD become **candidate** control points with `status = UNVERIFIED`, never verified control.

---

## 18. Parcel lifecycle, lineage, split and consolidation architecture

### 18.1 Lifecycle states

```text
DRAFT → SUBMITTED → UNDER_REVIEW → { RETURNED → DRAFT | VERIFIED } → APPROVED → PUBLISHED
                                                                         │
   any state ──(admin, reason)──► ARCHIVED                               │
   PUBLISHED/APPROVED ──(split or consolidation commits)──► SUPERSEDED ◄─┘
```

`SUPERSEDED` is terminal and is set **only** by the lineage engine — never by a user edit, never by a delete. A superseded parcel keeps its geometry, its versions, its computations, and its documents forever; it disappears from default map and search results but is always reachable through lineage, history, and an explicit "include historical" filter.

### 18.2 Lineage model

```sql
app.parcel_relationships(id PK, parent_parcel_id FK, child_parcel_id FK,
    relationship_type, operation_id FK, effective_date, reason,
    source_document_id FK NULL, created_by, created_at,
    UQ(parent_parcel_id, child_parcel_id, relationship_type, operation_id))
-- relationship_type ∈ (SUBDIVISION, CONSOLIDATION, MERGER, REPLACEMENT, CORRECTION, ADJUSTMENT)

app.parcel_operations(id PK, operation_type, method, status, parcel_snapshot jsonb,
    inputs jsonb, results jsonb, area_reconciliation jsonb, validation_result jsonb,
    performed_by FK, performed_at, approved_by FK NULL, approved_at, reason)
-- operation_type ∈ (SPLIT, CONSOLIDATION), method ∈ (MAP_SPLIT_LINE, SURVEY_GEOMETRY,
--                   TECHNICAL_DESCRIPTION, IMPORTED_GEOMETRY)
```

A split or consolidation is one `parcel_operations` row plus N `parcel_relationships` edges, so the graph is queryable in both directions and every edge names the operation that created it. Lineage traversal uses a recursive CTE with a depth cap, and cycle prevention is enforced (a parcel can never become its own ancestor).

### 18.3 Split — transactional algorithm

```text
BEGIN
  SELECT parent FOR UPDATE; verify If-Match version; verify status ∈ allowed set
  verify permission parcel.split AND data scope covers the parent
  derive child geometries by method:
    MAP_SPLIT_LINE        : ST_Split(parent.geom, ST_Snap(split_line, parent.geom, tol))
    SURVEY_GEOMETRY /
    TECHNICAL_DESCRIPTION : polygons produced by the survey engine (own computation rows)
    IMPORTED_GEOMETRY     : validated import result
  VALIDATE (all must pass, all reported, none auto-corrected):
    each child ST_IsValid, ST_IsSimple, non-empty, area ≥ minimum
    children pairwise non-overlapping           (ST_Overlaps = false, ST_Area(∩) ≤ ε)
    ST_Union(children) ≈ parent.geom            (ST_Area(ST_SymDifference) ≤ ε)  → no gaps/slivers
    all geometries share the parent's SRID
  AREA RECONCILIATION: Σ child area vs parent area → reported, never forced to balance
  INSERT children (status DRAFT, provenance per method, parent's PSGC/org inherited)
  INSERT parcel_relationships(SUBDIVISION) for each child
  INSERT parcel_versions for children (v1) and for the parent (status change)
  UPDATE parent SET status = SUPERSEDED, version = version + 1
  INSERT parcel_operations + audit_logs for every affected row
COMMIT      -- any failure → ROLLBACK, nothing partially applied
```

### 18.4 Consolidation — transactional algorithm

```text
BEGIN
  SELECT all parents FOR UPDATE (ordered by id to avoid deadlock); verify versions
  verify permission parcel.consolidate AND scope covers EVERY parent
  VALIDATE:
    ≥ 2 parents, all distinct, none already SUPERSEDED or ARCHIVED
    all geometries valid and in the same SRID
    no overlaps between parents (ST_Area(∩) ≤ ε)   → overlaps are a blocking error
    union is contiguous: ST_NumGeometries(ST_Union(...)) = 1 unless multipart is explicitly allowed
    gap/sliver detection between parents (ST_Difference of convex hull vs union, area ≤ ε)
    required documentation present per configuration
  result_geom = ST_Union(parents)   → ST_MakeValid check, never silent repair
  AREA RECONCILIATION: Σ parent areas vs union area → reported
  INSERT new parcel (DRAFT), relationships(CONSOLIDATION) parent→child for each parent
  INSERT versions; UPDATE each parent status = SUPERSEDED
  INSERT parcel_operations + audit_logs
COMMIT
```

Both operations produce a **preview** through an identical read-only code path (`dry_run = true`) that runs every validation and returns geometries, areas, and warnings **without writing anything**. The UI always previews before committing (`frontend.md` §21). The commit endpoint is idempotent by `Idempotency-Key`.

### 18.5 What these operations are not

A split or consolidation in this system is a **GIS and records operation**. It does not subdivide land in law, does not create titles, and does not substitute for an approved subdivision plan or the relevant agency process. Children start in `DRAFT`, carry the provenance of their derivation method, and can only reach `APPROVED` through the normal workflow with the configured documentary requirements (D-17). This distinction is modelled in data (`GIS_OPERATION` / `SURVEY_SOURCE` / `VERIFIED` / `APPROVED` / `PUBLISHED`) and rendered in the UI, per §15.

---

## 19. Transaction architecture

| Operation | Transaction scope | Locking |
|---|---|---|
| Single-entity CRUD | one transaction, version check inside it | optimistic (`WHERE version = ?`) |
| Parcel computation accept | computation + parcel geom + version + audit | row lock on the parcel |
| Split / consolidation | entire operation per §18 | `SELECT … FOR UPDATE` on all parents, ordered by id |
| Workflow transition | state change + history + audit + notifications | row lock on the entity |
| Import commit | batched inserts (chunks of 500) inside one transaction per chunk, job row updated per chunk | staging rows only |
| Field type change | dry-run outside, conversion + metadata update inside one transaction | advisory lock on the layer |

Rules: every multi-row mutation is wrapped by `TransactionManager::transactional()`; audit and version writes happen **inside** the business transaction so they cannot be lost; `SET LOCAL app.*` for RLS is set at transaction start; no HTTP calls, file writes, or `ogr2ogr` subprocesses occur inside an open transaction (files are written first, then referenced); deadlocks are retried once with jitter, then surfaced as a conflict.

---

## 20. Observability architecture

- **Request id**: generated by middleware (or taken from `X-Request-Id`), attached to every log line, every audit row, every error response, and returned as a response header. One id correlates browser, API, and audit.
- **Logs**: structured JSON via Monolog to stdout. Channels: `http`, `auth`, `survey`, `spatial`, `import`, `security`. Levels tuned per environment. **Never logged**: passwords, tokens, API keys, party/PII values, full document contents, raw geometry payloads above a size threshold.
- **Slow queries**: PostgreSQL `log_min_duration_statement`, plus application-side timing on spatial and computation endpoints with a warn threshold aligned to the NFR targets.
- **Health**: `GET /api/v1/health` (liveness), `GET /api/v1/health/ready` (DB connectivity, PostGIS version, migration state, document store writability, disk headroom). Readiness failures are explicit about which check failed.
- **Metrics** (v1, lightweight): counters and histograms exposed at `/api/v1/metrics` behind an admin token — request rate, error rate, p95 per route class, tile cache hit ratio, computation duration, import throughput.
- **Error handling**: one `ErrorHandler` middleware maps every exception to the envelope in ADR-14; stack traces go to logs only, never to the client. Unhandled errors return a generic message plus the request id.

---

## 21. Repository structure

```text
project/
├── PLANNING.md  architecture.md  specification.md  frontend.md
├── database.md  api.md  todo.md  TASK.md
├── accomplish.md  next_task.md          ← maintained during implementation
├── .env.example  .gitignore  docker-compose.yml  Makefile
├── frontend/
│   ├── src/{app,components,features,services,stores,utils,hooks,lib,styles,i18n,types}/
│   │   └── features/{map,layers,parcels,survey,titles,documents,users,audit,
│   │                 control-points,import-export,search,reports,admin}/
│   ├── tests/            (vitest unit + component)
│   └── e2e/              (playwright)
├── backend/
│   ├── public/index.php
│   ├── src/{Core,Auth,RBAC,Users,GIS,Parcels,Survey,Titles,Documents,Audit,
│   │        Reports,ImportExport}/
│   ├── config/  routes/  bin/ (console, workers)
│   └── tests/{Unit,Integration,Api,Spatial}/
├── database/
│   ├── migrations/       (Phinx, numbered, forward-only in production)
│   ├── seeds/            (permissions, roles, CRS, PSGC, workflows, basemaps — idempotent)
│   └── fixtures/         (SYNTHETIC test data, clearly labelled)
├── docs/                 (ADRs, runbooks, restore procedure, API examples)
└── .github/workflows/    (lint, static analysis, tests, build)
```

`.env` is never committed; `.env.example` lists every variable with safe placeholders. `.gitignore` covers `.env`, `node_modules`, `vendor`, `storage/documents`, `storage/tilecache`, build output, and any `*.key`/`*.pem`.

---

## 22. Risk register (architectural)

| ID | Risk | Impact | Mitigation |
|---|---|---|---|
| R-01 | Historical datum transformation parameters are unknown or regionally variable | Wrong parcel positions presented as authoritative | Never transform silently; record every transformation with parameters and accuracy; show original and transformed separately; flag low-confidence transforms |
| R-02 | Control-point coordinates are unverified or wrong | Every dependent parcel is displaced | `UNVERIFIED` by default; warning persists to approval; computations snapshot the point as used; correcting a point flags dependents for review |
| R-03 | Basemap licence misuse (imagery treated as reusable) | Legal exposure for the LGU | Licence metadata required, proxy-only keys, no caching without documented permission, no ingestion path from desktop tools (§17) |
| R-04 | Users treat computed geometry as a legal boundary | Real-world harm, institutional liability | Provenance badge everywhere, disclaimer on every export and print, explicit GIS/survey/verified/approved/legal distinction in data and UI |
| R-05 | Parser mis-extracts bearings from noisy text | Wrong geometry entering the record | Mandatory review/confirm gate, per-field confidence, source-text highlighting, no confirm while unresolved, extraction method stored per value |
| R-06 | Split/consolidation produces slivers or gaps | Corrupted cadastral fabric | Union-equality and overlap checks are blocking; nothing is auto-corrected; preview before commit; full rollback on failure |
| R-07 | Performance collapse at real data volume | Unusable map | Vector tiles, bbox-bounded queries, no unbounded endpoints, indexes before production data, performance suite against NFR targets |
| R-08 | Scope/permission bypass | Unauthorised access to property and PII data | Three independent authorization layers, RLS backstop, 404-not-403, negative E2E tests per boundary |
| R-09 | Scope creep toward "a web QGIS" | Never shipping | Non-scope list in `PLANNING.md`; new capability requires an ADR and a todo entry |
| R-10 | Single-node deployment with no tested restore | Total data loss | Backup + WAL archiving, documented restore procedure, mandatory restore drill task |
