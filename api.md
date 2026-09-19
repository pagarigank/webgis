# api.md

**Project:** Philippine Parcel, Survey & Multi-User GIS Management Platform
**Document status:** DRAFT v0.1 — authoritative for the API contract
**Base path:** `/api/v1` · **Transport:** HTTPS only · **Content:** `application/json`, `application/geo+json`, `application/vnd.mapbox-vector-tile`

`specification.md` §6 is a summary of this document; where they differ, this document wins.

---

## 1. Conventions

### 1.1 Envelope

Success:

```json
{ "success": true,
  "data": { },
  "meta": { "request_id": "01J…", "page": 1, "per_page": 50, "total": 1240, "total_pages": 25 } }
```

Failure:

```json
{ "success": false,
  "error": { "code": "VALIDATION_FAILED",
             "message": "Human-readable, safe to display.",
             "details": { "request_id": "01J…", "fields": [ ] } } }
```

Collections always return an array in `data` and pagination in `meta`. GeoJSON responses put a `FeatureCollection` in `data`.

### 1.2 Error codes (closed set)

| Code | HTTP | Meaning |
|---|---|---|
| `AUTH_REQUIRED` | 401 | no or expired access token |
| `AUTH_INVALID` | 401 | bad credentials, revoked or reused refresh token |
| `MFA_REQUIRED` | 401 | password accepted, TOTP needed |
| `PERMISSION_DENIED` | 403 | authenticated but lacks the permission or layer capability |
| `NOT_FOUND` | 404 | does not exist **or** is outside the caller's data scope (deliberately indistinguishable) |
| `VALIDATION_FAILED` | 422 | input failed a documented validation rule |
| `GEOMETRY_INVALID` | 422 | PostGIS rejected the geometry; `details.reason` and `details.location` included |
| `CRS_REQUIRED` / `CRS_UNSUPPORTED` | 422 | CRS not declared, or not in the registry |
| `CLOSURE_EXCEEDS_TOLERANCE` | 422 | blocking closure failure on a submit attempt |
| `PARSE_UNRESOLVED` | 422 | confirmation attempted with unresolved parsed courses |
| `SPLIT_INVALID` / `CONSOLIDATION_INVALID` | 422 | lineage operation failed validation; `details.failures[]` lists every rule |
| `IMPORT_INVALID` | 422 | import cannot proceed; row errors in `details` |
| `VERSION_CONFLICT` | 409 | optimistic concurrency check failed |
| `LICENSE_RESTRICTED` | 409 | basemap action blocked by licence state |
| `RATE_LIMITED` | 429 | throttled; `Retry-After` header set |
| `INTERNAL_ERROR` | 500 | unexpected; message is generic, `request_id` included |

Validation failures list every failure, not the first: `details.fields[] = { field, rule, message }` where `rule` is a `VR-*` id from `specification.md` §5.

### 1.3 Headers

| Header | Use |
|---|---|
| `Authorization: Bearer <access token>` | all endpoints except login, refresh, health |
| `X-Request-Id` | accepted from the client, otherwise generated; always returned |
| `ETag: "<version>"` | on single-resource GET of versioned entities |
| `If-Match: "<version>"` | **required** on PUT/PATCH/DELETE of versioned entities; absence → 428 `PRECONDITION_REQUIRED` |
| `Idempotency-Key` | required on `calculate`, `split`, `consolidate`, `imports/{id}/commit`, document upload |
| `X-CSRF-Token` | required on the cookie-authenticated `refresh` and `logout` endpoints only |

### 1.4 Query grammar

```text
?page=1&per_page=50                 pagination (per_page max 200)
?sort=-updated_at,lot_number        sort; fields validated against metadata allow-list
?q=free+text                        full-text/fuzzy search
?filter[status]=DRAFT               exact match
?filter[area_sqm][gte]=1000         operators: eq gte gt lte lt like in between isnull
?bbox=minx,miny,maxx,maxy&bbox_srid=4326
?fields=id,lot_number,geom          sparse fieldsets
?include=computation,titles         related resources
?include_historical=true            include SUPERSEDED/ARCHIVED records (default false)
```

An unbounded feature query (no `bbox`, no bounded `per_page`) returns `VALIDATION_FAILED` — the API refuses to let a client pull an entire layer.

### 1.5 Rate limits

Defaults, per user: auth 10/min, search 60/min, calculate 30/min, split/consolidate 10/min, import commit 5/min, tiles 600/min, general 300/min. Exceeding returns 429 with `Retry-After`.

---

## 2. Authentication

```http
POST /api/v1/auth/login
{ "username": "jdelacruz", "password": "…" }
→ 200 { "success": true, "data": {
    "access_token": "eyJ…", "expires_in": 900, "token_type": "Bearer",
    "user": { "id": 12, "username": "jdelacruz", "full_name": "…", "org_id": 3,
              "must_change_password": false } } }
   Set-Cookie: refresh_token=…; HttpOnly; Secure; SameSite=Strict; Path=/api/v1/auth
→ 401 AUTH_INVALID | 401 MFA_REQUIRED (details.mfa_token) | 429 RATE_LIMITED
```

| Endpoint | Purpose |
|---|---|
| `POST /auth/mfa/verify` | `{mfa_token, code}` → same payload as login |
| `POST /auth/refresh` | cookie + `X-CSRF-Token`; rotates the refresh token; reuse revokes the family |
| `POST /auth/logout` | revokes the family → 204 |
| `PUT /me/password` | `{current_password, new_password}` |
| `GET /me` | profile + effective access (below) |

```json
GET /me → data: {
  "user": { … },
  "roles": ["GIS_EDITOR"],
  "permissions": ["gis.feature.create", "parcel.view", …],
  "layer_capabilities": { "7": {"view":true,"create":true,"update":true,"delete":false,"approve":false} },
  "scopes": [ { "type":"BARANGAY", "code":"037105001", "name":"Barangay A", "access":"EDIT" },
              { "type":"MUNICIPALITY", "code":"037105", "name":"…", "access":"VIEW" } ],
  "scope_version": 14
}
```

`scope_version` changes when roles or scopes change; the SPA refetches `/me` and re-renders permission-dependent UI.

---

## 3. Administration

```text
GET|POST         /users                 GET|PUT|DELETE /users/{id}     (DELETE = deactivate)
PUT              /users/{id}/roles      GET|PUT        /users/{id}/scopes
POST             /users/{id}/force-password-reset
GET              /users/{id}/effective-access?entity_type=parcel&entity_id=…
GET|POST         /roles                 GET|PUT|DELETE /roles/{id}
PUT              /roles/{id}/permissions
GET              /permissions
GET|POST         /organizations         GET|PUT|DELETE /organizations/{id}
GET              /psgc?level=BARANGAY&parent=037105
GET|PUT          /settings              (system_settings; tolerances, limits, flags)
GET              /crs                   GET /crs/{id}
POST             /crs/transform         explicit, logged coordinate transformation
```

`GET /users/{id}/effective-access` answers "what can this user do to this record, and why", returning the decision plus the rule that produced it (FR-018) — the debugging tool that prevents permission guesswork.

---

## 4. Layers, fields, styles

```text
GET    /layers?tree=1                      layer tree with group_path nesting
POST   /layers                             gis.layer.create
GET    /layers/{id}                        ETag
PUT    /layers/{id}                        If-Match
DELETE /layers/{id}                        archives; refuses if non-empty without ?archive_features=1
PUT    /layers/order                       [{id, display_order}]
GET    /layers/{id}/extent                 bbox in 4326

GET    /layers/{id}/fields                 POST /layers/{id}/fields
PUT    /layers/{id}/fields/{fid}           DELETE /layers/{id}/fields/{fid}   (soft)
POST   /layers/{id}/fields/{fid}/retype-preview   dry run: convertible/failing value counts
PUT    /layers/{id}/fields/order

GET    /layers/{id}/styles                 PUT /layers/{id}/styles
GET    /layers/{id}/permissions            PUT /layers/{id}/permissions
```

```json
POST /layers
{ "code":"streetlights", "name":"Streetlights", "group_path":"Infrastructure",
  "geometry_type":"POINT", "srid":4326, "label_field":"pole_no",
  "render_mode":"geojson", "is_snap_target": false }
```

```json
POST /layers/7/fields
{ "field_name":"pole_no", "field_label":"Pole Number", "field_type":"text",
  "required":true, "searchable":true, "sortable":true,
  "validation_rules": { "maxLength": 20, "regex": "^SL-[0-9]{4}$" }, "sort_order": 10 }
```

Adding a required field to a populated layer requires `?existing=default|exempt` and records the choice (FR-028).

---

## 5. Features and spatial operations

```text
GET    /layers/{id}/features?bbox=…&filter[…]=…&page=&per_page=   → GeoJSON FeatureCollection
POST   /layers/{id}/features
GET    /features/{id}                      ETag
PUT    /features/{id}                      If-Match
DELETE /features/{id}                      If-Match, soft delete
GET    /features/{id}/history              versions + audit entries
POST   /features/{id}/restore/{version}    creates a new version from an old one
POST   /features/bulk-update               permission-gated, transactional, audited
GET    /tiles/{layerId}/{z}/{x}/{y}.mvt    scope-aware, cached with a scope-hash key
```

```json
POST /layers/7/features
{ "geometry": { "type":"Point", "coordinates":[120.5678,15.1234] },
  "attributes": { "pole_no":"SL-0142", "wattage":150, "installed_on":"2025-03-11" } }
```

Spatial operations — one endpoint, explicit operation, always server-side:

```json
POST /spatial/query
{ "op":"nearest", "layer_ids":[7,9], "geometry":{"type":"Point","coordinates":[120.5,15.1]},
  "limit":5, "max_distance_m":500, "srid":4326 }
```

`op` ∈ `bbox | intersects | within | contains | nearest | within_distance | buffer`. `buffer` returns a computed geometry and never persists it; saving a buffer result is an ordinary feature create.

```json
POST /spatial/measure
{ "type":"area" | "distance", "geometry": { … }, "srid":4326, "compute_crs":"EPSG:3123", "unit":"m" }
```

Measurement and area always happen in a projected CRS; the response names the CRS used.

---

## 6. Survey: control points, tie points, plans

```text
GET|POST   /control-points                 GET|PUT|DELETE /control-points/{id}
POST       /control-points/{id}/verify     control_point.verify; records verifier and time
GET        /control-points/nearest?lat=&lon=&limit=&type=
POST       /control-points/import          → creates an import job (§10)
GET        /control-points/{id}/dependents parcels whose computations used this point

GET|POST   /survey-plans                   GET|PUT /survey-plans/{id}
GET        /survey-plans/{id}/parcels
```

```json
POST /control-points
{ "point_name":"BLLM-3", "point_type":"BLLM", "monument_type":"Concrete",
  "easting":512225.120, "northing":1678780.450, "native_crs":"EPSG:3123",
  "coordinate_origin":"PROJECTED", "datum":"PRS92", "zone":"PTM Zone III",
  "source":"LGU survey records 2019", "accuracy_class":"2nd order", "accuracy_value_m":0.05 }
→ 201, status "UNVERIFIED"; latitude/longitude derived and returned, labelled as derived
```

Editing a control point's coordinates returns, in `data.impact`, the list of parcels flagged for review (FR-081). Past computations are untouched because they hold snapshots.

---

## 7. Technical descriptions and parsing

```text
GET    /parcels/{id}/technical-descriptions
POST   /parcels/{id}/technical-descriptions        creates the next revision
GET    /technical-descriptions/{tdId}
PUT    /technical-descriptions/{tdId}              If-Match; DRAFT/unconfirmed only
POST   /technical-descriptions/{tdId}/courses      add
PUT    /technical-descriptions/{tdId}/courses/{cid}
DELETE /technical-descriptions/{tdId}/courses/{cid}
PUT    /technical-descriptions/{tdId}/courses/order
POST   /technical-descriptions/{tdId}/validate     syntax/rule check without computing
POST   /survey/parse                               free text → staged courses
POST   /technical-descriptions/{tdId}/confirm      staged → confirmed (audited, distinct action)
```

```json
POST /survey/parse
{ "text":"…thence N 25°30' E, 45.20 meters to point 2; thence S 64°30' E, 30.00 meters…",
  "source_type":"PASTED_TEXT", "distance_unit_hint":"m" }

→ data: {
  "parser_status":"PARTIAL",
  "tie_line_candidates":[ … ],
  "courses":[
    { "seq":1, "bearing":{ "quadrant":"NE","deg":25,"min":30,"sec":0,
                           "azimuth_dd":25.5, "original":"N 25°30' E" },
      "distance":{ "value":45.20, "unit":"m", "meters":45.20, "original":"45.20 meters" },
      "extraction_method":"AI_EXTRACTED", "confidence":0.97,
      "source_span":{ "start":8, "end":44 }, "resolved":true },
    { "seq":3, "bearing":null, "distance":{ "value":22.1, "unit":"m", "meters":22.1 },
      "extraction_method":"AI_EXTRACTED", "confidence":0.41,
      "source_span":{ "start":112, "end":149 }, "resolved":false,
      "issues":[{ "field":"bearing", "rule":"VR-01", "message":"Bearing could not be read." }] } ]
}
```

Parsed output is **staged**. `POST /confirm` returns `PARSE_UNRESOLVED` while any course has `resolved: false`. Confirmation writes `confirmed_by`/`confirmed_at` and an audit row; only a confirmed description may be computed (FR-100, FR-101).

---

## 8. Parcels

```text
GET|POST   /parcels                        GET|PUT|DELETE /parcels/{id}
GET        /parcels/{id}/overlaps
GET        /parcels/{id}/history           versions + audit + workflow, merged timeline
GET        /parcels/{id}/versions/{v}      full snapshot incl. geometry
POST       /parcels/{id}/versions/{v}/restore
POST       /parcels/{id}/transitions       { action, reason, comment }
GET        /parcels/{id}/lineage?direction=both&depth=5
GET        /parcels/{id}/documents         POST /parcels/{id}/documents
```

### 8.1 Computation

```json
POST /parcels/{id}/calculate        Idempotency-Key required
{ "technical_description_id":412, "compute_crs":"EPSG:3123",
  "tolerances": { "linear_closure_m":0.10, "relative_precision_min":5000 } }

→ 201 data: {
  "computation_id":908, "engine_version":"survey-1.0.0", "compute_crs":"EPSG:3123",
  "tie_points":[{ "name":"BLLM-3","as_used_easting":512225.120,"as_used_northing":1678780.450,
                  "as_used_status":"UNVERIFIED" }],
  "vertices":[{ "seq":1,"label":"1","easting":512345.6789,"northing":1678901.2345,
                "latitude":15.1234567,"longitude":120.5678901 }],
  "closure":{ "delta_e":0.014,"delta_n":-0.009,"linear_error_m":0.0166,
              "error_azimuth_dd":122.74,"perimeter_m":412.88,
              "relative_precision":"1:24872","status":"WITHIN_TOLERANCE" },
  "area":{ "computed_sqm":10432.7412,"postgis_sqm":10432.7408,"source_sqm":10400.0000,
           "difference_sqm":32.7412,"difference_pct":0.3148,
           "note":"Area comparison is a validation aid, not a determination of correctness." },
  "warnings":[{ "rule":"VR-19","severity":"warning","message":"Tie point BLLM-3 is unverified." }],
  "geometry_preview":{ "type":"Polygon","coordinates":[[ … ]] },
  "persisted_to_parcel": false }
```

Nothing touches `parcels.geom` until:

```text
POST /parcels/{id}/accept-computation   { "computation_id":908, "reason":"…" }
```

which sets the geometry, `geometry_source = COMPUTED_FROM_TECHNICAL_DESCRIPTION`, bumps the version, writes a parcel version row, and audits.

```text
GET    /parcels/{id}/computations         list, newest first, never deleted
GET    /computations/{cid}                includes vertices and input_snapshot
GET    /computations/{cid}/snapshot       exactly what the engine was given
POST   /computations/{cid}/replay         recomputes from the snapshot; asserts identical output
POST   /computations/{cid}/adjust         { method:"COMPASS", params:{} } → NEW computation
POST   /parcels/{id}/validate             full validation checklist → validation_result
```

`POST /survey/calculate` and `POST /survey/validate` exist as stateless variants for previewing a computation that is not yet attached to a parcel; they persist nothing.

### 8.2 Split

```json
POST /parcels/{id}/split          Idempotency-Key required; ?dry_run=true for preview
{ "method":"MAP_SPLIT_LINE",
  "split_line":{ "type":"LineString","coordinates":[[…],[…]] }, "srid":4326,
  "children":[ { "lot_number":"100-A" }, { "lot_number":"100-B" } ],
  "effective_date":"2026-09-20", "reason":"Subdivision per plan Psd-000000",
  "source_document_id":"…" }

→ 200 (dry_run) / 201 (committed) data: {
  "operation_id":55, "dry_run":true,
  "children":[{ "temp_id":"A","lot_number":"100-A","area_sqm":4210.11,
                "geometry":{ … },"share_pct":40.36 }, … ],
  "area_reconciliation":{ "parent_sqm":10432.74,"children_sum_sqm":10432.74,
                          "difference_sqm":0.0,"difference_pct":0.0 },
  "validation":{ "passed":true,
     "checks":[{ "rule":"VR-36","status":"pass","message":"No overlap between children." },
               { "rule":"VR-37","status":"pass","message":"Union matches parent (Δ 0.004 m²)." }],
     "warnings":[{ "rule":"VR-38","message":"Child B is below the configured minimum lot area." }] },
  "parent_after":{ "status":"SUPERSEDED" } }
```

`If-Match` on the parent version is required for commit. Failure returns `SPLIT_INVALID` with every failed check in `details.failures[]`. The commit is one transaction; on error nothing is applied.

### 8.3 Consolidation

```json
POST /parcels/consolidate         Idempotency-Key required; ?dry_run=true
{ "parent_parcel_ids":["uuid-a","uuid-b","uuid-c"],
  "parent_versions":{ "uuid-a":4,"uuid-b":2,"uuid-c":7 },
  "new_parcel":{ "lot_number":"200","survey_plan_id":88 },
  "allow_multipart":false, "effective_date":"2026-09-20", "reason":"Consolidation per Ccs-000000" }

→ data: { "operation_id":56, "result":{ "area_sqm":3120.39, "geometry":{ … } },
  "area_reconciliation":{ "parents_sum_sqm":3120.40,"union_sqm":3120.39,
                          "difference_sqm":-0.01,"difference_pct":-0.0003 },
  "validation":{ "passed":true, "checks":[ … ],
                 "warnings":[{ "rule":"VR-42","message":"Sliver gap 0.006 m² between LOT-11 and LOT-12." }] },
  "parents_after":[{ "id":"uuid-a","status":"SUPERSEDED" }, … ] }
```

`GET /operations/{operationId}` returns the full record of either operation: inputs, snapshot, results, reconciliation, validation, actor, reason.

### 8.4 Lineage

```json
GET /parcels/{id}/lineage?direction=both&depth=5
→ data: {
  "nodes":[ { "id":"uuid-p","lot_number":"100","status":"SUPERSEDED","area_sqm":10432.74 },
            { "id":"uuid-a","lot_number":"100-A","status":"PUBLISHED","area_sqm":4210.11 } ],
  "edges":[ { "parent":"uuid-p","child":"uuid-a","type":"SUBDIVISION",
              "operation_id":55,"effective_date":"2026-09-20" } ],
  "truncated": false }
```

---

## 9. Titles, parties, documents

```text
GET|POST /titles                      GET|PUT /titles/{id}
GET      /titles/{id}/parties         requires title.view_owner; the read is audited
POST     /titles/{id}/parties         party.manage
GET      /parties/{id}                audited read
GET|POST /parcels/{id}/titles

POST     /documents                   multipart; Idempotency-Key; returns metadata only
GET      /documents/{id}
GET      /documents/{id}/download     302 to a signed, short-lived, single-use URL
POST     /documents/{id}/links        DELETE /documents/{id}/links/{lid}
DELETE   /documents/{id}              soft delete
```

Parcel and title responses are assembled in three tiers by permission (FR-068). A caller without `title.view_owner` receives no `parties` key at all — not an empty array, not nulls — so absence is unambiguous and nothing leaks through shape.

---

## 10. Import and export

```text
POST   /imports                        multipart upload + { source_format, target_entity,
                                         target_layer_id? } → job (status UPLOADED)
GET    /imports/{id}                   status, counts, detected fields, detected geometry types
PUT    /imports/{id}/mapping           { declared_crs, field_mapping, transformation?, options }
POST   /imports/{id}/validate          → status VALIDATED, per-row validation written to staging
GET    /imports/{id}/preview?page=     rows with normalized values, geometry, and errors
GET    /imports/{id}/errors            CSV error report
POST   /imports/{id}/commit            Idempotency-Key; { partial: false } → production rows
DELETE /imports/{id}                   cancels and purges staging

POST   /exports                        { format, query_spec, target_crs, include_pii? }
GET    /exports/{id}                   status + document id when complete
```

`PUT /imports/{id}/mapping` returns `CRS_REQUIRED` if `declared_crs` is absent — the API never guesses a CRS (FR-252, VR-52). DXF imports additionally require an entity-layer → GIS-layer mapping and report dropped entity types in `data.dropped`.

---

## 11. Basemaps

```text
GET    /basemaps                       enabled providers visible to the caller, with attribution
GET    /basemaps/admin                 basemap.manage; full records incl. licence fields
POST   /basemaps                       PUT /basemaps/{id}    DELETE /basemaps/{id}
POST   /basemaps/{id}/test             server-side reachability check
GET    /basemaps/{code}/tiles/{z}/{x}/{y}   authenticated proxy for key-bearing providers
```

`GET /basemaps` never returns an API key or a URL containing one; key-bearing providers are advertised with the proxy URL only. Enabling a provider whose licence is `UNLICENSED` or expired returns `LICENSE_RESTRICTED` naming the reason.

---

## 12. Search, reports, audit, system

```text
GET  /search?q=&types=parcel,title,control_point,layer,feature,document&limit=
GET  /search/coordinates?value=&crs=           → resolves and returns a zoom target
POST /search/spatial                            same body grammar as /spatial/query

GET  /reports                                   available reports for the caller
POST /reports/{code}/render                     { params } → inline payload or a job id
GET  /reports/jobs/{id}                         status + document id

GET  /audit-logs?entity_type=&entity_id=&user_id=&action=&from=&to=
GET  /audit-logs/export                         audit.export
GET  /notifications                             POST /notifications/{id}/read

GET  /config                                    basemaps, units, tolerances, CRS list, flags
GET  /health          GET /health/ready         GET /version        GET /metrics (admin)
```

Search results are typed and grouped, respect scopes (out-of-scope records are absent), and owner search requires `title.view_owner` and is audited.

---

## 13. OpenAPI and contract testing

The full schema lives at `backend/openapi.yaml`, is the source for generated TypeScript client types, and is validated in CI. Contract tests assert that every endpoint's responses validate against the schema, that every error path returns a code from §1.2, that versioned mutations reject a missing `If-Match` with 428, and that scope violations return 404 rather than 403. An endpoint without an OpenAPI entry and a contract test does not count as implemented (`specification.md` §10, `todo.md` definition of done).
