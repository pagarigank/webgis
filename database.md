# database.md

**Project:** Philippine Parcel, Survey & Multi-User GIS Management Platform
**Document status:** DRAFT v0.1 — authoritative for the data model
**Engine:** PostgreSQL 16 + PostGIS 3.4 (+ `pg_trgm`, `pgcrypto`, `btree_gist`, `uuid-ossp` or application-generated UUIDv7)

This document is authoritative for tables, columns, constraints, indexes, and migrations. `architecture.md` §3 is a summary of it; where they differ, this document wins.

---

## 1. Conventions

| Topic | Rule |
|---|---|
| Schemas | `app` (application), `audit` (history/audit), `ref` (reference data), `staging` (import work) |
| Keys | `bigint GENERATED ALWAYS AS IDENTITY` for high-volume; `uuid` for externally referenced entities (`parcels`, `gis_features`, `documents`) |
| Naming | `snake_case`, plural tables, `*_id` FKs, constraints prefixed `pk_ uq_ fk_ ck_ idx_ gix_` |
| Timestamps | `timestamptz`, stored UTC, displayed Asia/Manila |
| Numerics | `numeric` for area/distance/money — never `float`; areas `numeric(18,4)` m²; coordinates `numeric(14,4)`; angles `numeric(12,8)` |
| Soft delete | `deleted_at` on user-facing tables; parcels, titles, control points, versions, and audit rows are **never** hard-deleted |
| Concurrency | `version int NOT NULL DEFAULT 1` on every mutable table |
| Actors | `created_by`, `created_at`, `updated_by`, `updated_at` on every mutable table |
| Enumerations | `CHECK` constraints for small closed sets; `ref.*` lookup tables where the set is data |
| Geometry | `geometry(<Type>, 4326)` canonical; SRID enforced by the type; GIST index mandatory before production data |
| DDL | only via numbered Phinx migrations; no manual DDL in any environment; the application DB role holds no DDL rights |

---

## 2. Entity-relationship overview

```text
                      ┌──────────────┐
  ref.psgc_areas ◄────┤organizations ├───┐
                      └──────┬───────┘   │
                             │           │
                      ┌──────▼───────┐   │   ┌───────┐   ┌────────────┐
                      │    users     ├───┼──►│ roles ├──►│permissions │
                      └──┬────────┬──┘   │   └───┬───┘   └────────────┘
                         │        │      │       │
                 data_scopes   refresh_  │  layer_permissions
                         │      tokens   │       │
                         │               │       ▼
                         │        ┌──────┴──────────────┐
                         │        │     gis_layers      │◄── gis_layer_fields
                         │        │                     │◄── gis_layer_styles
                         │        └──────────┬──────────┘
                         │                   │
                         │            ┌──────▼───────┐
                         └───────────►│ gis_features │──► audit.gis_feature_versions
                                      └──────────────┘

 ref.crs_registry ◄─┬─ survey_control_points ◄─ tie_points ─┐
                    │                                        │
                    ├─ survey_plans ──────────┐              │
                    │                         ▼              ▼
                    │                  ┌─────────────────────────────┐
                    ├─ coordinate_     │   technical_descriptions    │
                    │  transformations └───┬──────────────┬──────────┘
                    │                      │              │
                    │      technical_description_courses  tie_lines
                    │                      │
                    │              ┌───────▼───────────┐
                    └─────────────►│parcel_computations│──► parcel_vertices
                                   └───────┬───────────┘
                                           │
        land_titles ──parcel_titles──► ┌───▼────┐ ◄──parcel_relationships──┐
        title_parties                  │parcels │                           │
        parties                        └───┬────┘ ◄──────────────────────────┘
                                           │              (parent ⇄ child)
                          audit.parcel_versions   parcel_operations

  documents ──document_links──► (parcels | titles | plans | control points | features | operations)

  workflow_definitions → states → transitions → instances → history
  audit.audit_logs (partitioned monthly)
  import_jobs → import_job_rows      export_jobs      basemap_providers      notifications
```

---

## 3. Reference schema (`ref`)

```sql
CREATE TABLE ref.psgc_areas (
  code          varchar(12) PRIMARY KEY,
  level         varchar(20) NOT NULL CHECK (level IN ('REGION','PROVINCE','CITY','MUNICIPALITY','BARANGAY')),
  name          varchar(160) NOT NULL,
  parent_code   varchar(12) REFERENCES ref.psgc_areas(code),
  geom          geometry(MultiPolygon,4326),
  is_active     boolean NOT NULL DEFAULT true
);
CREATE INDEX idx_psgc_parent ON ref.psgc_areas(parent_code);
CREATE INDEX gix_psgc_geom ON ref.psgc_areas USING GIST (geom);

CREATE TABLE ref.crs_registry (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code          varchar(40) NOT NULL UNIQUE,      -- 'EPSG:3123', 'LOCAL:CITY-GRID-1'
  srid          integer,                          -- NULL for unregistered local systems
  name          varchar(160) NOT NULL,
  datum         varchar(80) NOT NULL,             -- 'PRS92', 'Luzon 1911', 'WGS84', 'ASSUMED'
  projection    varchar(80),                      -- 'Transverse Mercator'
  zone          varchar(40),                      -- 'PTM Zone III'
  units         varchar(20) NOT NULL DEFAULT 'm',
  is_projected  boolean NOT NULL,
  is_historical boolean NOT NULL DEFAULT false,
  proj4text     text,
  wkt           text,
  area_of_use   geometry(Polygon,4326),
  authority     varchar(80),                      -- 'EPSG', 'NAMRIA', 'LGU'
  notes         text,
  is_active     boolean NOT NULL DEFAULT true
);
```

Seeded: `EPSG:4326`, `EPSG:3857`, PRS92 PTM zones 1–5 (`EPSG:3121`–`3125`), Luzon 1911 zones I–V (`EPSG:25391`–`25395`, `is_historical = true`). Local/assumed systems are added as data (D-13, FR-090).

Other `ref` tables: `ref.units` (code, kind, to_canonical_factor exact `numeric`, is_active), `ref.survey_plan_types`, `ref.point_types`, `ref.monument_types`, `ref.title_types`, `ref.provenance_classes`, `ref.relationship_types`. Keeping these as data rather than `CHECK` constraints where the LGU may extend them; the rest stay as `CHECK`.

---

## 4. Identity, access, organisation (`app`)

```sql
CREATE TABLE app.organizations (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code varchar(40) NOT NULL UNIQUE,
  name varchar(160) NOT NULL,
  org_type varchar(24) NOT NULL CHECK (org_type IN
     ('REGION','PROVINCE','CITY','MUNICIPALITY','BARANGAY','OFFICE','DEPARTMENT','PROJECT')),
  parent_id bigint REFERENCES app.organizations(id),
  psgc_code varchar(12) REFERENCES ref.psgc_areas(code),
  status varchar(16) NOT NULL DEFAULT 'ACTIVE',
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(),
  version int NOT NULL DEFAULT 1
);

CREATE TABLE app.users (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  username citext NOT NULL UNIQUE,
  email citext NOT NULL UNIQUE,
  password_hash text NOT NULL,
  full_name varchar(160) NOT NULL,
  position varchar(120),
  org_id bigint NOT NULL REFERENCES app.organizations(id),
  status varchar(16) NOT NULL DEFAULT 'ACTIVE'
     CHECK (status IN ('ACTIVE','SUSPENDED','DISABLED')),
  mfa_enabled boolean NOT NULL DEFAULT false,
  mfa_secret_enc bytea,
  must_change_password boolean NOT NULL DEFAULT true,
  failed_login_count int NOT NULL DEFAULT 0,
  locked_until timestamptz,
  last_login_at timestamptz,
  password_changed_at timestamptz,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(),
  deleted_at timestamptz
);

CREATE TABLE app.roles (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code varchar(60) NOT NULL UNIQUE,
  name varchar(120) NOT NULL,
  description text,
  is_system boolean NOT NULL DEFAULT false,
  requires_mfa boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.permissions (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code varchar(80) NOT NULL UNIQUE,      -- 'parcel.split'
  module varchar(40) NOT NULL,
  description text NOT NULL
);

CREATE TABLE app.role_permissions (
  role_id bigint NOT NULL REFERENCES app.roles(id) ON DELETE CASCADE,
  permission_id bigint NOT NULL REFERENCES app.permissions(id),
  PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE app.user_roles (
  user_id bigint NOT NULL REFERENCES app.users(id) ON DELETE CASCADE,
  role_id bigint NOT NULL REFERENCES app.roles(id),
  org_id  bigint REFERENCES app.organizations(id),
  granted_by bigint REFERENCES app.users(id),
  granted_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_user_roles ON app.user_roles (user_id, role_id, COALESCE(org_id, 0));

CREATE TABLE app.data_scopes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id bigint NOT NULL REFERENCES app.users(id) ON DELETE CASCADE,
  scope_type varchar(24) NOT NULL CHECK (scope_type IN
     ('ORGANIZATION','PROVINCE','MUNICIPALITY','BARANGAY','CUSTOM_AREA','PROJECT')),
  scope_ref_code varchar(40),               -- PSGC code or org code
  geom geometry(MultiPolygon,4326),         -- for CUSTOM_AREA
  access_level varchar(12) NOT NULL CHECK (access_level IN ('NONE','VIEW','EDIT','APPROVE')),
  valid_from date, valid_to date,
  granted_by bigint REFERENCES app.users(id),
  granted_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT ck_scope_target CHECK (scope_ref_code IS NOT NULL OR geom IS NOT NULL)
);
CREATE INDEX idx_scopes_user ON app.data_scopes(user_id, scope_type);
CREATE INDEX gix_scopes_geom ON app.data_scopes USING GIST (geom);

CREATE TABLE app.layer_permissions (
  layer_id bigint NOT NULL REFERENCES app.gis_layers(id) ON DELETE CASCADE,
  role_id  bigint NOT NULL REFERENCES app.roles(id) ON DELETE CASCADE,
  can_view boolean NOT NULL DEFAULT false, can_create boolean NOT NULL DEFAULT false,
  can_update boolean NOT NULL DEFAULT false, can_delete boolean NOT NULL DEFAULT false,
  can_approve boolean NOT NULL DEFAULT false,
  PRIMARY KEY (layer_id, role_id)
);

CREATE TABLE app.refresh_tokens (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id bigint NOT NULL REFERENCES app.users(id) ON DELETE CASCADE,
  token_hash bytea NOT NULL UNIQUE,
  family_id uuid NOT NULL,
  issued_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL,
  rotated_from bigint REFERENCES app.refresh_tokens(id),
  revoked_at timestamptz, revoked_reason varchar(40),
  user_agent text, ip inet
);
CREATE INDEX idx_rt_active ON app.refresh_tokens(user_id) WHERE revoked_at IS NULL;
```

**Scope resolution** (implemented in SQL and mirrored in the application): explicit `NONE` denies; otherwise the most specific matching grant wins in the order BARANGAY > MUNICIPALITY > PROVINCE > ORGANIZATION > CUSTOM_AREA; absence of any grant denies.

---

## 5. GIS core

```sql
CREATE TABLE app.gis_layers (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code varchar(60) NOT NULL UNIQUE,
  name varchar(160) NOT NULL,
  description text,
  group_path varchar(200) NOT NULL DEFAULT 'Custom',   -- 'Survey/Control Points'
  geometry_type varchar(20) NOT NULL CHECK (geometry_type IN
     ('POINT','MULTIPOINT','LINESTRING','MULTILINESTRING','POLYGON','MULTIPOLYGON','GEOMETRY')),
  srid integer NOT NULL DEFAULT 4326,
  source varchar(80),
  render_mode varchar(12) NOT NULL DEFAULT 'geojson'
     CHECK (render_mode IN ('geojson','mvt','raster')),
  is_snap_target boolean NOT NULL DEFAULT false,
  is_system boolean NOT NULL DEFAULT false,
  status varchar(16) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','ARCHIVED')),
  visible_default boolean NOT NULL DEFAULT true,
  opacity_default numeric(4,3) NOT NULL DEFAULT 1.0 CHECK (opacity_default BETWEEN 0 AND 1),
  display_order int NOT NULL DEFAULT 100,
  label_field varchar(60), min_zoom int, max_zoom int,
  feature_count_cache bigint NOT NULL DEFAULT 0,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(),
  deleted_at timestamptz
);

CREATE TABLE app.gis_layer_fields (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  layer_id bigint NOT NULL REFERENCES app.gis_layers(id) ON DELETE CASCADE,
  field_name varchar(60) NOT NULL CHECK (field_name ~ '^[a-z][a-z0-9_]{0,59}$'),
  field_label varchar(120) NOT NULL,
  field_type varchar(20) NOT NULL CHECK (field_type IN
     ('text','long_text','integer','decimal','boolean','date','datetime','dropdown',
      'multi_select','email','phone','url','currency','reference','user','document')),
  required boolean NOT NULL DEFAULT false,
  default_value jsonb,
  options jsonb,               -- dropdown/multi_select choices; reference target config
  validation_rules jsonb,      -- {min,max,regex,minLength,maxLength,precision,currency}
  searchable boolean NOT NULL DEFAULT false,
  sortable   boolean NOT NULL DEFAULT false,
  displayable boolean NOT NULL DEFAULT true,
  editable boolean NOT NULL DEFAULT true,
  is_pii boolean NOT NULL DEFAULT false,
  sort_order int NOT NULL DEFAULT 100,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  deleted_at timestamptz,
  UNIQUE (layer_id, field_name)
);

CREATE TABLE app.gis_layer_styles (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  layer_id bigint NOT NULL REFERENCES app.gis_layers(id) ON DELETE CASCADE,
  style_type varchar(16) NOT NULL CHECK (style_type IN ('SINGLE','CATEGORIZED','GRADUATED')),
  attribute_field varchar(60),
  rules jsonb NOT NULL DEFAULT '[]',   -- [{value|range, fill, stroke, width, opacity, icon}]
  default_rule jsonb NOT NULL,
  label_config jsonb,
  is_active boolean NOT NULL DEFAULT true,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.gis_features (
  id uuid PRIMARY KEY,
  layer_id bigint NOT NULL REFERENCES app.gis_layers(id),
  geom geometry(Geometry,4326) NOT NULL,
  attributes jsonb NOT NULL DEFAULT '{}',
  status varchar(20) NOT NULL DEFAULT 'ACTIVE',
  org_id bigint REFERENCES app.organizations(id),
  psgc_barangay varchar(12) REFERENCES ref.psgc_areas(code),
  provenance varchar(40) NOT NULL DEFAULT 'MANUAL_DRAWING',
  source_document_id uuid,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(),
  deleted_at timestamptz
);
CREATE INDEX gix_features_geom  ON app.gis_features USING GIST (geom);
CREATE INDEX idx_features_layer ON app.gis_features (layer_id) WHERE deleted_at IS NULL;
CREATE INDEX gin_features_attrs ON app.gis_features USING GIN (attributes jsonb_path_ops);
CREATE INDEX idx_features_psgc  ON app.gis_features (psgc_barangay);
```

Triggers on `gis_features`:

1. `trg_enforce_geometry_type` — rejects geometry incompatible with the layer's declared `geometry_type`.
2. `trg_validate_attributes` — validates `attributes` against `gis_layer_fields` (type, required, options, range). Defence in depth behind application validation.
3. `trg_write_feature_version` — writes `audit.gis_feature_versions` on every insert/update/soft-delete.

`ST_IsValid` is enforced in the write path and reported by a nightly job, **not** as a table constraint (`architecture.md` §3.5 explains why).

Expression indexes are created by migration for fields flagged `searchable`/`sortable`, e.g.
`CREATE INDEX idx_features_l7_lotno ON app.gis_features ((attributes->>'lot_no')) WHERE layer_id = 7;`

```sql
CREATE TABLE audit.gis_feature_versions (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  feature_id uuid NOT NULL, layer_id bigint NOT NULL,
  version int NOT NULL, operation varchar(10) NOT NULL,
  geom geometry(Geometry,4326), attributes jsonb, status varchar(20),
  changed_by bigint, changed_at timestamptz NOT NULL DEFAULT now(),
  change_reason text, request_id varchar(40),
  UNIQUE (feature_id, version)
);
```

---

## 6. Survey subsystem

```sql
CREATE TABLE app.survey_plans (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  plan_number varchar(80) NOT NULL UNIQUE,
  plan_type varchar(20) NOT NULL,          -- Psd, Psu, Pcs, Csd, Ccs, Bsd, Vs, Fls, Rs, As, Swo, Msi, OTHER
  survey_date date, approved_date date,
  approving_agency varchar(120),
  surveyor_name varchar(160), surveyor_license varchar(60),
  control_reference varchar(160),
  crs_id bigint REFERENCES ref.crs_registry(id),
  area_sqm numeric(18,4), lot_count int,
  psgc_barangay varchar(12) REFERENCES ref.psgc_areas(code),
  source_document_id uuid, remarks text,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(), deleted_at timestamptz
);

CREATE TABLE app.survey_control_points (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  point_name varchar(80) NOT NULL,
  point_type varchar(24) NOT NULL CHECK (point_type IN
     ('BLLM','MBM','PBM','GCP','CONTROL_POINT','TIE_POINT','REFERENCE_POINT','OTHER')),
  monument_type varchar(60),
  easting numeric(14,4), northing numeric(14,4), elevation numeric(10,4),
  native_crs_id bigint REFERENCES ref.crs_registry(id),
  latitude numeric(12,9), longitude numeric(12,9),
  coordinate_origin varchar(16) NOT NULL DEFAULT 'PROJECTED'
     CHECK (coordinate_origin IN ('PROJECTED','GEOGRAPHIC')),   -- which was entered
  geom geometry(Point,4326),
  datum varchar(60), zone varchar(40),
  source varchar(160), survey_reference varchar(160),
  accuracy_class varchar(40), accuracy_value_m numeric(8,4),
  description text,
  status varchar(16) NOT NULL DEFAULT 'UNVERIFIED'
     CHECK (status IN ('UNVERIFIED','VERIFIED','DISPUTED','RETIRED')),
  psgc_barangay varchar(12) REFERENCES ref.psgc_areas(code),
  verified_by bigint REFERENCES app.users(id), verified_at timestamptz,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(), deleted_at timestamptz,
  UNIQUE (point_name, native_crs_id)
);
CREATE INDEX gix_cp_geom ON app.survey_control_points USING GIST (geom);
CREATE INDEX idx_cp_type_status ON app.survey_control_points (point_type, status);
CREATE INDEX idx_cp_name_trgm ON app.survey_control_points USING GIN (point_name gin_trgm_ops);
```

### 6.1 `tie_points` — usage, not duplication (ADR-17)

`survey_control_points` is the registry of physical/reference points. `tie_points` records **the use of a point as the tie for a particular technical description**, including the coordinates as used at that moment. A monument ties many parcels; its usage metadata does not belong on the monument.

```sql
CREATE TABLE app.tie_points (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  technical_description_id bigint NOT NULL REFERENCES app.technical_descriptions(id) ON DELETE CASCADE,
  control_point_id bigint REFERENCES app.survey_control_points(id),
  -- ad-hoc tie point not in the registry (recorded, flagged, never promoted silently):
  adhoc_name varchar(80), adhoc_easting numeric(14,4), adhoc_northing numeric(14,4),
  adhoc_crs_id bigint REFERENCES ref.crs_registry(id),
  role varchar(20) NOT NULL DEFAULT 'TIE' CHECK (role IN ('TIE','REFERENCE','CHECK')),
  as_used_easting numeric(14,4) NOT NULL,     -- snapshot at time of use
  as_used_northing numeric(14,4) NOT NULL,
  as_used_crs_id bigint NOT NULL REFERENCES ref.crs_registry(id),
  as_used_status varchar(16) NOT NULL,        -- verification status at time of use
  sequence int NOT NULL DEFAULT 1,
  notes text,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT ck_tie_source CHECK (control_point_id IS NOT NULL OR adhoc_name IS NOT NULL)
);
```

A tie point is **never** automatically a parcel corner; the point of beginning is derived from it through the tie line(s).

```sql
CREATE TABLE app.technical_descriptions (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  parcel_id uuid NOT NULL REFERENCES app.parcels(id) ON DELETE CASCADE,
  revision int NOT NULL,
  survey_plan_id bigint REFERENCES app.survey_plans(id),
  original_text text,                       -- NEVER discarded
  normalized_text text,
  source_type varchar(24) NOT NULL CHECK (source_type IN
     ('MANUALLY_ENTERED','OCR_EXTRACTED','AI_EXTRACTED','IMPORTED','PASTED_TEXT')),
  parser_status varchar(20) NOT NULL DEFAULT 'NOT_PARSED'
     CHECK (parser_status IN ('NOT_PARSED','PARSED','PARTIAL','FAILED','CONFIRMED')),
  confirmed_by bigint REFERENCES app.users(id), confirmed_at timestamptz,
  bearing_reference varchar(12) NOT NULL DEFAULT 'GRID'
     CHECK (bearing_reference IN ('GRID','GEODETIC','MAGNETIC','ASSUMED')),
  distance_unit varchar(12) NOT NULL DEFAULT 'm',
  compute_crs_id bigint REFERENCES ref.crs_registry(id),
  point_of_beginning_label varchar(40),
  survey_reference varchar(160),
  source_document_id uuid,
  status varchar(16) NOT NULL DEFAULT 'DRAFT',
  is_current boolean NOT NULL DEFAULT true,
  calculation_version int NOT NULL DEFAULT 0,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (parcel_id, revision)
);
CREATE UNIQUE INDEX uq_td_current ON app.technical_descriptions (parcel_id) WHERE is_current;

CREATE TABLE app.technical_description_courses (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  technical_description_id bigint NOT NULL
     REFERENCES app.technical_descriptions(id) ON DELETE CASCADE,
  seq int NOT NULL,
  course_type varchar(8) NOT NULL DEFAULT 'LINE' CHECK (course_type IN ('LINE','CURVE')),
  from_point_label varchar(40), to_point_label varchar(40),
  -- bearing: original + normalized + azimuth (never silently altered)
  original_bearing varchar(60),
  bearing_quadrant varchar(2) CHECK (bearing_quadrant IN ('NE','SE','SW','NW')),
  deg smallint CHECK (deg BETWEEN 0 AND 360),
  min smallint CHECK (min BETWEEN 0 AND 59),
  sec numeric(6,3) CHECK (sec >= 0 AND sec < 60),
  bearing_type varchar(12) NOT NULL DEFAULT 'QUADRANT'
     CHECK (bearing_type IN ('QUADRANT','AZIMUTH','CARDINAL')),
  normalized_bearing varchar(40),
  azimuth_dd numeric(12,8) CHECK (azimuth_dd >= 0 AND azimuth_dd < 360),
  -- distance: original + canonical
  original_distance numeric(14,4), original_unit varchar(12),
  distance_m numeric(14,4) CHECK (distance_m > 0),
  -- curve data (PHASE 12+)
  curve_direction varchar(2) CHECK (curve_direction IN ('CW','CCW')),
  radius_m numeric(14,4), arc_length_m numeric(14,4),
  chord_length_m numeric(14,4), chord_azimuth_dd numeric(12,8),
  central_angle_dd numeric(12,8), tangent_in_azimuth_dd numeric(12,8),
  extraction_method varchar(20) NOT NULL DEFAULT 'MANUALLY_ENTERED'
     CHECK (extraction_method IN ('MANUALLY_ENTERED','OCR_EXTRACTED','AI_EXTRACTED','IMPORTED')),
  confidence numeric(4,3),
  source_text_span jsonb,       -- {start,end} into original_text for review highlighting
  is_confirmed boolean NOT NULL DEFAULT false,
  remarks text,
  UNIQUE (technical_description_id, seq)
);

CREATE TABLE app.tie_lines (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  technical_description_id bigint NOT NULL
     REFERENCES app.technical_descriptions(id) ON DELETE CASCADE,
  tie_point_id bigint NOT NULL REFERENCES app.tie_points(id) ON DELETE CASCADE,
  seq int NOT NULL DEFAULT 1,
  to_point_label varchar(40),
  original_bearing varchar(60),
  bearing_quadrant varchar(2), deg smallint, min smallint, sec numeric(6,3),
  azimuth_dd numeric(12,8), normalized_bearing varchar(40),
  original_distance numeric(14,4), original_unit varchar(12), distance_m numeric(14,4),
  extraction_method varchar(20) NOT NULL DEFAULT 'MANUALLY_ENTERED',
  remarks text,
  UNIQUE (technical_description_id, seq)
);
```

`app.parcel_courses` is a **view** (ADR-18) joining `technical_description_courses` to its parcel through the current technical description, so the prescribed name exists without a duplicate source of truth.

### 6.2 Computation

```sql
CREATE TABLE app.parcel_computations (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  parcel_id uuid NOT NULL REFERENCES app.parcels(id) ON DELETE CASCADE,
  technical_description_id bigint NOT NULL REFERENCES app.technical_descriptions(id),
  compute_crs_id bigint NOT NULL REFERENCES ref.crs_registry(id),
  method varchar(40) NOT NULL DEFAULT 'TRAVERSE_PLANE',
  adjustment_method varchar(24) CHECK (adjustment_method IN ('NONE','COMPASS','TRANSIT','CRANDALL')),
  adjustment_params jsonb,
  base_computation_id bigint REFERENCES app.parcel_computations(id),
  start_easting numeric(14,4), start_northing numeric(14,4),
  close_easting numeric(14,4), close_northing numeric(14,4),
  closure_de numeric(12,4), closure_dn numeric(12,4),
  linear_error_m numeric(12,4), error_azimuth_dd numeric(12,8),
  perimeter_m numeric(14,4), relative_precision_denominator numeric(14,2),
  computed_area_sqm numeric(18,4), postgis_area_sqm numeric(18,4),
  source_area_sqm numeric(18,4), area_diff_sqm numeric(18,4), area_diff_pct numeric(10,6),
  closure_status varchar(24) NOT NULL CHECK (closure_status IN
     ('WITHIN_TOLERANCE','EXCEEDS_TOLERANCE','NOT_CLOSED','INDETERMINATE')),
  geom geometry(Polygon,4326),
  validation_result jsonb,
  input_snapshot jsonb NOT NULL,     -- immutable: tie points as used, courses, CRS, tolerances
  tolerances jsonb NOT NULL,
  engine_version varchar(24) NOT NULL,
  is_current boolean NOT NULL DEFAULT false,
  computed_by bigint NOT NULL REFERENCES app.users(id),
  computed_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_comp_parcel_current ON app.parcel_computations (parcel_id) WHERE is_current;
CREATE INDEX idx_comp_td ON app.parcel_computations (technical_description_id);

CREATE TABLE app.parcel_vertices (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  computation_id bigint NOT NULL REFERENCES app.parcel_computations(id) ON DELETE CASCADE,
  seq int NOT NULL, point_label varchar(40),
  easting numeric(14,4) NOT NULL, northing numeric(14,4) NOT NULL,
  compute_crs_id bigint NOT NULL REFERENCES ref.crs_registry(id),
  native_easting numeric(14,4), native_northing numeric(14,4),
  native_crs_id bigint REFERENCES ref.crs_registry(id),
  latitude numeric(12,9), longitude numeric(12,9),
  is_tie_vertex boolean NOT NULL DEFAULT false,
  UNIQUE (computation_id, seq)
);
```

**Computations are immutable.** No `UPDATE` path exists except flipping `is_current`. Recomputation and adjustment insert new rows.

```sql
CREATE TABLE app.coordinate_transformations (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  entity_type varchar(40) NOT NULL, entity_id varchar(64) NOT NULL,
  source_crs_id bigint NOT NULL REFERENCES ref.crs_registry(id),
  target_crs_id bigint NOT NULL REFERENCES ref.crs_registry(id),
  method varchar(28) NOT NULL CHECK (method IN
     ('POSTGIS_PROJ','HELMERT_7PARAM','MOLODENSKY_3PARAM','NTV2_GRID','AFFINE_LOCAL','MANUAL')),
  parameters jsonb, parameter_source varchar(160), accuracy_m numeric(8,4),
  performed_by bigint REFERENCES app.users(id),
  performed_at timestamptz NOT NULL DEFAULT now(),
  notes text
);
CREATE INDEX idx_transform_entity ON app.coordinate_transformations (entity_type, entity_id);
```

---

## 7. Parcels, lineage, titles

```sql
CREATE TABLE app.parcels (
  id uuid PRIMARY KEY,
  parcel_code varchar(80) NOT NULL UNIQUE,
  lot_number varchar(60), block_number varchar(60),
  survey_plan_id bigint REFERENCES app.survey_plans(id),
  survey_type varchar(40),
  title_number_ref varchar(80),
  tax_declaration_no varchar(80),
  source_area_sqm numeric(18,4), source_area_unit varchar(12) DEFAULT 'sqm',
  computed_area_sqm numeric(18,4),
  psgc_barangay varchar(12) REFERENCES ref.psgc_areas(code),
  psgc_municipality varchar(12) REFERENCES ref.psgc_areas(code),
  psgc_province varchar(12) REFERENCES ref.psgc_areas(code),
  location_description text,
  status varchar(16) NOT NULL DEFAULT 'DRAFT' CHECK (status IN
     ('DRAFT','SUBMITTED','UNDER_REVIEW','RETURNED','VERIFIED','APPROVED',
      'PUBLISHED','ARCHIVED','SUPERSEDED')),
  geometry_source varchar(44) NOT NULL DEFAULT 'MANUAL_DRAWING' CHECK (geometry_source IN
     ('SURVEY_COORDINATES','COMPUTED_FROM_TECHNICAL_DESCRIPTION',
      'TRANSFORMED_FROM_HISTORICAL_SURVEY','IMPORTED_GIS','CAD_IMPORT',
      'DIGITIZED_FROM_IMAGERY','MANUAL_DRAWING','APPROXIMATE')),
  verification_status varchar(20) NOT NULL DEFAULT 'UNVERIFIED',
  geom geometry(MultiPolygon,4326),
  current_computation_id bigint REFERENCES app.parcel_computations(id),
  org_id bigint REFERENCES app.organizations(id),
  source_document_id uuid, remarks text,
  superseded_by_operation_id bigint,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(), deleted_at timestamptz
);
CREATE INDEX gix_parcels_geom ON app.parcels USING GIST (geom);
CREATE INDEX idx_parcels_status ON app.parcels (psgc_barangay, status);
CREATE INDEX idx_parcels_lot_trgm ON app.parcels USING GIN (lot_number gin_trgm_ops);
CREATE INDEX idx_parcels_td ON app.parcels USING GIN (tax_declaration_no gin_trgm_ops);
CREATE INDEX idx_parcels_active ON app.parcels (status)
   WHERE deleted_at IS NULL AND status NOT IN ('SUPERSEDED','ARCHIVED');

CREATE TABLE app.parcel_operations (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  operation_type varchar(16) NOT NULL CHECK (operation_type IN ('SPLIT','CONSOLIDATION')),
  method varchar(28) NOT NULL CHECK (method IN
     ('MAP_SPLIT_LINE','SURVEY_GEOMETRY','TECHNICAL_DESCRIPTION','IMPORTED_GEOMETRY')),
  status varchar(16) NOT NULL DEFAULT 'COMMITTED',
  inputs jsonb NOT NULL,              -- parent ids + versions, split line, options
  parcel_snapshot jsonb NOT NULL,     -- parent geometry/attributes as at commit
  results jsonb NOT NULL,             -- child ids + areas
  area_reconciliation jsonb NOT NULL, -- Σ inputs, Σ outputs, difference, pct
  validation_result jsonb NOT NULL,
  reason text NOT NULL,
  source_document_id uuid,
  performed_by bigint NOT NULL REFERENCES app.users(id),
  performed_at timestamptz NOT NULL DEFAULT now(),
  request_id varchar(40)
);

CREATE TABLE app.parcel_relationships (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  parent_parcel_id uuid NOT NULL REFERENCES app.parcels(id),
  child_parcel_id  uuid NOT NULL REFERENCES app.parcels(id),
  relationship_type varchar(16) NOT NULL CHECK (relationship_type IN
     ('SUBDIVISION','CONSOLIDATION','MERGER','REPLACEMENT','CORRECTION','ADJUSTMENT')),
  operation_id bigint REFERENCES app.parcel_operations(id),
  effective_date date NOT NULL,
  reason text, source_document_id uuid,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT ck_no_self CHECK (parent_parcel_id <> child_parcel_id),
  UNIQUE (parent_parcel_id, child_parcel_id, relationship_type, operation_id)
);
CREATE INDEX idx_rel_parent ON app.parcel_relationships (parent_parcel_id);
CREATE INDEX idx_rel_child  ON app.parcel_relationships (child_parcel_id);

CREATE TABLE audit.parcel_versions (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  parcel_id uuid NOT NULL, version int NOT NULL,
  snapshot jsonb NOT NULL,           -- attributes + TD revision + computation id
  geom geometry(MultiPolygon,4326),
  status varchar(16), geometry_source varchar(44),
  change_summary varchar(200), change_reason text,
  changed_by bigint, changed_at timestamptz NOT NULL DEFAULT now(),
  request_id varchar(40),
  UNIQUE (parcel_id, version)
);
```

Lineage traversal (recursive CTE, depth-capped, cycle-guarded) is exposed as `app.fn_parcel_ancestors(uuid, int)` and `app.fn_parcel_descendants(uuid, int)`.

```sql
CREATE TABLE app.parties (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  party_type varchar(16) NOT NULL CHECK (party_type IN ('INDIVIDUAL','ORGANIZATION','GOVERNMENT')),
  full_name_enc bytea NOT NULL,        -- pgcrypto/libsodium, app-managed key
  name_search_hash bytea,              -- deterministic hash for exact-match lookup only
  identifiers_enc bytea, address_enc bytea, contact_enc bytea,
  is_sensitive boolean NOT NULL DEFAULT true,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(), deleted_at timestamptz
);

CREATE TABLE app.land_titles (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  title_number varchar(80) NOT NULL,
  title_type varchar(24) NOT NULL,
  title_date date, registry_office varchar(120),
  survey_plan_id bigint REFERENCES app.survey_plans(id),
  lot_number varchar(60), area_sqm numeric(18,4),
  location_description text, psgc_barangay varchar(12) REFERENCES ref.psgc_areas(code),
  source_document_id uuid, status varchar(16) NOT NULL DEFAULT 'ACTIVE', remarks text,
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(), deleted_at timestamptz,
  UNIQUE (title_number, registry_office)
);

CREATE TABLE app.title_parties (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  title_id bigint NOT NULL REFERENCES app.land_titles(id) ON DELETE CASCADE,
  party_id bigint NOT NULL REFERENCES app.parties(id),
  role varchar(24) NOT NULL DEFAULT 'REGISTERED_OWNER',
  share_numerator int, share_denominator int,
  effective_from date, effective_to date
);

CREATE TABLE app.parcel_titles (
  parcel_id uuid NOT NULL REFERENCES app.parcels(id) ON DELETE CASCADE,
  title_id bigint NOT NULL REFERENCES app.land_titles(id),
  relationship varchar(24) NOT NULL DEFAULT 'COVERS',
  PRIMARY KEY (parcel_id, title_id)
);
```

---

## 8. Documents, workflow, audit, I/O, basemaps

```sql
CREATE TABLE app.documents (
  id uuid PRIMARY KEY,
  storage_key varchar(200) NOT NULL UNIQUE,
  original_filename varchar(255) NOT NULL,
  doc_type varchar(40) NOT NULL,        -- TITLE_SCAN, SURVEY_PLAN, TECH_DESC, PHOTO, CAD, GIS, OTHER
  mime_type varchar(120) NOT NULL, byte_size bigint NOT NULL,
  sha256 bytea NOT NULL UNIQUE,
  access_level varchar(24) NOT NULL DEFAULT 'INTERNAL' CHECK (access_level IN
     ('PUBLIC','INTERNAL','RESTRICTED','SENSITIVE_PERSONAL')),
  description text, page_count int,
  uploaded_by bigint NOT NULL REFERENCES app.users(id),
  uploaded_at timestamptz NOT NULL DEFAULT now(), deleted_at timestamptz
);

CREATE TABLE app.document_links (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  document_id uuid NOT NULL REFERENCES app.documents(id),
  entity_type varchar(40) NOT NULL, entity_id varchar(64) NOT NULL,
  link_role varchar(40) NOT NULL DEFAULT 'SUPPORTING',
  linked_by bigint, linked_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (document_id, entity_type, entity_id, link_role)
);
CREATE INDEX idx_doclinks_entity ON app.document_links (entity_type, entity_id);

CREATE TABLE app.workflow_definitions (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code varchar(40) NOT NULL UNIQUE, entity_type varchar(40) NOT NULL,
  name varchar(120) NOT NULL, is_active boolean NOT NULL DEFAULT true);
CREATE TABLE app.workflow_states (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  definition_id bigint NOT NULL REFERENCES app.workflow_definitions(id),
  code varchar(24) NOT NULL, name varchar(80) NOT NULL,
  is_initial boolean NOT NULL DEFAULT false, is_terminal boolean NOT NULL DEFAULT false,
  display_order int NOT NULL DEFAULT 0, UNIQUE (definition_id, code));
CREATE TABLE app.workflow_transitions (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  definition_id bigint NOT NULL REFERENCES app.workflow_definitions(id),
  from_state_id bigint NOT NULL REFERENCES app.workflow_states(id),
  to_state_id bigint NOT NULL REFERENCES app.workflow_states(id),
  action_code varchar(40) NOT NULL, required_permission varchar(80) NOT NULL,
  requires_reason boolean NOT NULL DEFAULT false,
  requires_comment boolean NOT NULL DEFAULT false,
  guard_expression text, UNIQUE (definition_id, from_state_id, action_code));
CREATE TABLE app.workflow_instances (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  definition_id bigint NOT NULL REFERENCES app.workflow_definitions(id),
  entity_type varchar(40) NOT NULL, entity_id varchar(64) NOT NULL,
  current_state_id bigint NOT NULL REFERENCES app.workflow_states(id),
  assigned_to bigint REFERENCES app.users(id), due_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (entity_type, entity_id));
CREATE TABLE app.approval_actions (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  instance_id bigint NOT NULL REFERENCES app.workflow_instances(id),
  from_state varchar(24), to_state varchar(24) NOT NULL, action_code varchar(40) NOT NULL,
  actor_id bigint NOT NULL REFERENCES app.users(id),
  accepted_computation_id bigint REFERENCES app.parcel_computations(id),
  accepted_td_revision int,
  reason text, comment text, acted_at timestamptz NOT NULL DEFAULT now(),
  request_id varchar(40));

CREATE TABLE audit.audit_logs (
  id bigint GENERATED ALWAYS AS IDENTITY,
  occurred_at timestamptz NOT NULL DEFAULT now(),
  user_id bigint, username_snapshot varchar(120),
  action varchar(60) NOT NULL, entity_type varchar(40) NOT NULL, entity_id varchar(64),
  old_values jsonb, new_values jsonb, changed_fields text[],
  reason text, ip inet, user_agent text, request_id varchar(40),
  PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);
CREATE INDEX brin_audit_time ON audit.audit_logs USING BRIN (occurred_at);
CREATE INDEX idx_audit_entity ON audit.audit_logs (entity_type, entity_id, occurred_at DESC);
CREATE INDEX idx_audit_user ON audit.audit_logs (user_id, occurred_at DESC);

CREATE TABLE app.basemap_providers (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code varchar(40) NOT NULL UNIQUE, name varchar(120) NOT NULL,
  provider_type varchar(20) NOT NULL CHECK (provider_type IN
     ('XYZ','TMS','WMS','WMTS','VECTOR_TILE','LOCAL_ORTHOPHOTO')),
  service_url text, url_template text, layer_name varchar(120),
  matrix_set varchar(80), format varchar(40), srid integer NOT NULL DEFAULT 3857,
  attribution_html text NOT NULL, attribution_url text,
  license_type varchar(28) NOT NULL CHECK (license_type IN
     ('OPEN_ODBL','COMMERCIAL_WEB','GOVERNMENT_GRANT','ORGANIZATION_OWNED','UNLICENSED')),
  license_reference varchar(200), license_expires_on date, license_notes text,
  requires_api_key boolean NOT NULL DEFAULT false, api_key_env_name varchar(80),
  proxy_required boolean NOT NULL DEFAULT false, cache_ttl_seconds int NOT NULL DEFAULT 0,
  min_zoom int DEFAULT 0, max_zoom int DEFAULT 19, bounds geometry(Polygon,4326),
  is_enabled boolean NOT NULL DEFAULT false, is_default boolean NOT NULL DEFAULT false,
  display_order int NOT NULL DEFAULT 100, allowed_role_ids bigint[],
  version int NOT NULL DEFAULT 1,
  created_by bigint, created_at timestamptz NOT NULL DEFAULT now(),
  updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT ck_license_enabled CHECK (NOT (is_enabled AND license_type = 'UNLICENSED')),
  CONSTRAINT ck_attribution CHECK (NOT is_enabled OR length(attribution_html) > 0)
);

CREATE TABLE app.import_jobs (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  source_format varchar(20) NOT NULL CHECK (source_format IN
     ('GEOJSON','CSV','KML','SHAPEFILE','GEOPACKAGE','DXF')),
  source_document_id uuid NOT NULL REFERENCES app.documents(id),
  source_filename varchar(255) NOT NULL,
  target_entity varchar(40) NOT NULL,    -- FEATURE | PARCEL | CONTROL_POINT
  target_layer_id bigint REFERENCES app.gis_layers(id),
  declared_crs_id bigint REFERENCES ref.crs_registry(id),
  transformation_id bigint REFERENCES app.coordinate_transformations(id),
  field_mapping jsonb, options jsonb,
  status varchar(20) NOT NULL DEFAULT 'UPLOADED' CHECK (status IN
     ('UPLOADED','MAPPED','VALIDATED','COMMITTED','FAILED','CANCELLED')),
  total_rows int DEFAULT 0, valid_rows int DEFAULT 0, invalid_rows int DEFAULT 0,
  validation_result jsonb, error_report jsonb,
  created_by bigint NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
  committed_by bigint, committed_at timestamptz
);
CREATE TABLE staging.import_job_rows (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  job_id bigint NOT NULL REFERENCES app.import_jobs(id) ON DELETE CASCADE,
  row_number int NOT NULL, raw jsonb, normalized jsonb,
  geom geometry(Geometry,4326), validation jsonb,
  is_valid boolean NOT NULL DEFAULT false, action varchar(12) NOT NULL DEFAULT 'INSERT');

CREATE TABLE app.export_jobs (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  format varchar(20) NOT NULL, query_spec jsonb NOT NULL,
  target_crs_id bigint REFERENCES ref.crs_registry(id),
  status varchar(16) NOT NULL DEFAULT 'QUEUED', document_id uuid REFERENCES app.documents(id),
  row_count int, requested_by bigint NOT NULL, requested_at timestamptz NOT NULL DEFAULT now(),
  completed_at timestamptz);

CREATE TABLE app.notifications (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id bigint NOT NULL REFERENCES app.users(id) ON DELETE CASCADE,
  type varchar(40) NOT NULL, title varchar(160) NOT NULL, body text,
  entity_type varchar(40), entity_id varchar(64),
  read_at timestamptz, created_at timestamptz NOT NULL DEFAULT now());

CREATE TABLE app.edit_locks (entity_type varchar(40) NOT NULL, entity_id varchar(64) NOT NULL,
  user_id bigint NOT NULL, acquired_at timestamptz NOT NULL DEFAULT now(),
  heartbeat_at timestamptz NOT NULL DEFAULT now(), expires_at timestamptz NOT NULL,
  PRIMARY KEY (entity_type, entity_id));

CREATE TABLE app.system_settings (key varchar(80) PRIMARY KEY, value jsonb NOT NULL,
  description text, updated_by bigint, updated_at timestamptz NOT NULL DEFAULT now());
```

`system_settings` holds the tolerance defaults, upload limits, minimum lot area, sliver epsilon, lineage depth cap, and feature flags — so they are configuration, not constants in code.

---

## 9. Row-level security

```sql
ALTER TABLE app.parcels ENABLE ROW LEVEL SECURITY;
CREATE POLICY parcels_scope_select ON app.parcels FOR SELECT TO app_rw
  USING (app.fn_user_can_see(current_setting('app.user_id', true)::bigint,
                             psgc_barangay, org_id));
CREATE POLICY parcels_scope_write ON app.parcels FOR UPDATE TO app_rw
  USING (app.fn_user_can_edit(current_setting('app.user_id', true)::bigint,
                              psgc_barangay, org_id));
```

Applied to `parcels`, `gis_features`, `land_titles`, `parties`, `documents`, `technical_descriptions`. RLS is a **backstop**; the application still enforces authorization so errors are meaningful (`architecture.md` §6.3). `app.fn_user_can_see/edit` are `STABLE` and index-friendly; scope sets are also cached per request in the application to avoid repeated evaluation.

Database roles: `app_migrator` (DDL, deploy only), `app_rw` (DML on `app`/`staging`, SELECT on `ref`, **INSERT-only on `audit`**), `app_ro` (SELECT only).

---

## 10. Indexes — summary

Beyond those declared inline: GIST on every geometry column; `btree_gist` composite `(layer_id, geom)` on `gis_features` if layer-filtered spatial queries dominate at scale; partial indexes on `status` for active-record queries; BRIN on all time-series/audit columns; `pg_trgm` GIN on `lot_number`, `title_number`, `tax_declaration_no`, `point_name`, `plan_number` for fuzzy search; expression indexes on searchable JSONB attributes, created by migration when a field is flagged.

Partitioning: `audit.audit_logs` monthly from day one (worker creates the next partition ahead of time). `app.gis_features` LIST-partitioned by `layer_id` only if volume demands it (D-03); the migration path is designed but not executed at v1.

---

## 11. Migrations

- Phinx, numbered `YYYYMMDDHHMMSS_description.php`, one logical change per migration, `up`/`down` both implemented where reversal is safe.
- Production migrations are forward-only; a mistake is corrected by a new migration, never by editing an applied one.
- Extension creation, schema creation, and role grants are migrations too.
- Destructive changes (drop column, narrow type) require: a dry-run report task, a deprecation period where the column is unused, and an explicit migration — never bundled with a feature change.
- Every migration runs in CI against a throwaway PostGIS container, then staging, before production.
- Order: extensions → schemas → roles → `ref` → identity/access → GIS → survey → parcels → lineage → documents/workflow/audit → I/O/basemaps → views and functions → indexes on large tables (concurrently where possible).

---

## 12. Seed and fixture data

**Seeds (all environments, idempotent):** permission catalogue; the eight system roles with their permission grants; CRS registry entries; `ref.units` with exact conversion factors; PSGC reference data; workflow definition for parcels with states and transitions; default system settings and tolerances; the OSM basemap provider (the only one enabled by default, `OPEN_ODBL`).

**Fixtures (never production):** clearly labelled synthetic data — `SAMPLE_`/`TEST_` prefixes on every identifier — comprising sample users per role, sample organisations and scopes, sample layers with each field type, sample points/lines/polygons, synthetic control points, synthetic survey plans, synthetic technical descriptions with **known expected coordinates, closure, and area**, and known split/consolidation cases with expected results.

No real title numbers, real owner names, or real parcel boundaries appear in any non-production dataset, and none are invented to look real (FR-070). The known-answer survey fixtures are the benchmark the computation engine is tested against.
