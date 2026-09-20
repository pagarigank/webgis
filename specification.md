# specification.md

**Project:** Philippine Parcel & Multi-User GIS Web Application
**Document status:** DRAFT v0.2 — for review and approval
**Companion documents:** `PLANNING.md` (scope/risks), `architecture.md` (authoritative for structure and ADRs), `database.md` (authoritative for the ERD), `api.md` (authoritative for the API contract), `frontend.md` (UI), `todo.md` / `TASK.md` (execution).
Section 6 below is an API **summary**; where it and `api.md` differ, `api.md` wins. Section 7 is a DB summary; `database.md` wins.

Requirement IDs: `FR-*` functional, `NFR-*` non-functional, `VR-*` validation rule, `SR-*` security, `AC-*` acceptance criterion. Priority: **M** must (go-live), **S** should, **C** could (post-go-live).

---

## 1. Scope

In scope: interactive web GIS; user-defined layers and fields; point/line/polygon editing; Philippine parcel records; land-title information; technical-description encoding and parsing; tie points and tie lines; bearing/distance computation; parcel geometry generation; closure and area validation; PostGIS storage; multi-user RBAC with layer permissions and record scopes; draft→approval workflow; audit and versioning; import/export; spatial search and analysis.

Out of scope for v1: full QGIS feature parity; raster analysis; network/routing analysis; topology editing across layers; offline field collection; automated title adjudication; direct writes into any external RPT system; production of legally certified survey documents.

---

## 2. Actors and roles

| Role | Purpose |
|---|---|
| System Administrator | users, roles, permissions, system config, backups, audit access |
| GIS Administrator | layers, fields, styles, CRS registry, import/export, layer permissions |
| GIS Manager | approves parcels/surveys, manages scopes within their organisation |
| GIS Editor | creates/edits features and parcel geometry within scope |
| Survey/Technical User | technical descriptions, control points, computations, validation |
| Assessor | property/RPT attributes, read parcel geometry, limited title access |
| Field Encoder | data entry into assigned barangays; cannot submit for approval |
| Viewer | read-only within scope; no owner/PII access |

Roles are data, not code (FR-030). The set above is seeded, editable, and extensible.

---

## 3. Functional requirements

### 3.1 Authentication and account management

| ID | Pri | Requirement |
|---|---|---|
| FR-001 | M | Users authenticate with username/email + password; access token (15 min) + rotating refresh token (14 d, httpOnly cookie) |
| FR-002 | M | Password policy: ≥ 12 chars, complexity configurable, Argon2id hashing, breach-list check where available |
| FR-003 | M | Account lockout after N failed attempts (default 5) with exponential backoff; all attempts logged |
| FR-004 | M | `GET /me` returns profile, roles, effective permission codes, per-layer capabilities, data scopes, `scope_version` |
| FR-005 | M | Users change their own password; admins force a reset (`must_change_password`) |
| FR-006 | S | TOTP MFA, mandatory for roles flagged `requires_mfa` |
| FR-007 | M | Logout revokes the refresh-token family; refresh reuse revokes all sessions for the user |
| FR-008 | S | Admin can view and terminate active sessions |

### 3.2 Users, roles, permissions, scopes

| ID | Pri | Requirement |
|---|---|---|
| FR-010 | M | CRUD users; assign organisation; activate/deactivate (never hard-delete) |
| FR-011 | M | CRUD roles; assign permission codes; system roles are protected from deletion |
| FR-012 | M | Permission codes are a seeded, immutable catalogue (§3.3) |
| FR-013 | M | Assign roles to users, optionally scoped to an organisation |
| FR-014 | M | Per-layer permissions: view/create/update/delete/approve by role |
| FR-015 | M | Record-level data scopes by organisation, region, province, municipality, barangay, custom polygon, or global, with access level NONE/VIEW/EDIT/APPROVE |
| FR-016 | M | Scope resolution: explicit NONE denies; otherwise the most specific grant wins; default deny |
| FR-017 | M | All permission and scope changes are audited with actor and reason |
| FR-018 | S | Effective-permission preview: "what can this user do to this record, and why" |

### 3.3 Permission catalogue (seeded)

```text
gis.layer.view / create / update / delete
gis.field.manage
gis.style.manage
gis.feature.view / create / update / delete
parcel.view / create / update / delete / submit / review / verify / approve / publish / archive
parcel.split / parcel.consolidate / parcel.lineage.view / parcel.version.restore
survey.view / create / update / approve
techdesc.view / create / update / parse / confirm
control_point.view / create / update / verify
title.view / title.update / title.view_owner
party.view / party.manage
document.view / upload / download_restricted / delete
rpt.view / rpt.update
import.execute / export.execute
user.manage / role.manage / scope.manage
audit.view / audit.export
basemap.view / basemap.manage
report.view / report.export
cad.import
system.config
```

### 3.4 GIS layers

| ID | Pri | Requirement |
|---|---|---|
| FR-020 | M | Authorised users create layers at runtime with no code change or deployment |
| FR-021 | M | Layer metadata: id, name, code, description, group path, geometry_type, srid, status, visibility, opacity, display_order, style, label_field, min/max zoom, timestamps, actors |
| FR-022 | M | Geometry types: POINT, LINESTRING, POLYGON, MULTIPOINT, MULTILINESTRING, MULTIPOLYGON, GEOMETRY |
| FR-023 | M | Layers are organised into a reorderable tree (Base / Administrative / Survey / Property / Infrastructure / Custom) |
| FR-024 | M | Deleting a layer requires the layer to be empty or an explicit archive action; features are never orphaned |
| FR-025 | S | Duplicate a layer definition (structure only, or structure + style) |

### 3.5 Dynamic custom fields

| ID | Pri | Requirement |
|---|---|---|
| FR-026 | M | Authorised users add fields to a layer: text, long_text, integer, decimal, boolean, date, datetime, dropdown, multi_select, email, phone, url, currency, reference (to another layer's feature or a parcel/title/control point), user (system user picker), document (link to the document store) |
| FR-026a | M | `currency` stores a `numeric(18,4)` amount plus an ISO currency code (default PHP) and never uses a float |
| FR-026b | M | `reference`, `user`, and `document` fields store the target id and entity type; deleting a target is blocked or soft-handled, never leaving a dangling reference; resolution respects the reader's permissions, so an unreadable target renders as "restricted", not as raw data |
| FR-026c | S | `sortable` and `displayable` metadata flags in addition to `searchable`/`visible`, controlling grid behaviour |
| FR-027 | M | Field metadata: field_name, label, data_type, required, default, options, validation_rules, display_order, searchable, visible, editable, is_pii |
| FR-028 | M | Adding a required field to a layer with existing data requires a default or an explicit "existing records exempt" choice; the choice is recorded |
| FR-029 | M | Changing a field's data type is only permitted when every existing value converts cleanly; a dry-run report is shown first |
| FR-030 | M | No GIS attribute, layer, or role is hard-coded in the frontend or backend; all are read from metadata |
| FR-031 | M | Deleting a field soft-deletes the definition and preserves values in feature versions |

### 3.6 Features (map data)

| ID | Pri | Requirement |
|---|---|---|
| FR-035 | M | Create/edit/delete features in permitted layers within scope |
| FR-036 | M | Geometry operations: draw, select, move, reshape (vertex edit), delete, snap; split where practical (S) |
| FR-037 | M | Measure distance and area on the map with unit selection |
| FR-038 | M | Zoom to feature; zoom to layer extent; identify feature |
| FR-039 | M | Spatial queries: bbox, within distance, within polygon, intersects, contains, nearest N |
| FR-040 | S | Buffer analysis returning a result set (not persisted unless saved as a feature) |
| FR-041 | M | All geometry validated client-side for feedback and server-side for truth |
| FR-042 | M | Feature edits are versioned; version history is viewable and restorable by permitted roles |
| FR-043 | M | Optimistic concurrency: stale writes return 409 with both versions |

### 3.7 Attribute table

| ID | Pri | Requirement |
|---|---|---|
| FR-045 | M | Per-layer attribute grid with server-side pagination, sorting, search, and column filters |
| FR-046 | M | Add/edit/delete rows subject to permissions; inline or modal edit |
| FR-047 | M | Row selection highlights the map feature; map selection highlights and scrolls to the row |
| FR-048 | M | Zoom to record; zoom to selection |
| FR-049 | M | Configurable visible columns and column order, persisted per user per layer |
| FR-050 | M | Export current view (respecting filters and permissions) to CSV/GeoJSON |
| FR-051 | S | Filter by map extent ("only show what's visible") |

### 3.8 Parcels

| ID | Pri | Requirement |
|---|---|---|
| FR-055 | M | Parcel record with: parcel_code, lot_number, block_number, survey_plan, survey_type, title reference, tax declaration no., source area + unit, barangay/municipality/province/region (PSGC), location description, status, provenance, source document, remarks, geometry, actors, timestamps |
| FR-056 | M | A parcel may exist without geometry (records-first workflow) |
| FR-057 | M | Provenance is mandatory and displayed wherever geometry is shown or exported |
| FR-058 | M | Parcel versioning: every change to geometry, technical description, status, or key attributes creates a version with a change summary |
| FR-059 | M | Parcels support document attachments (title scans, plans, technical descriptions) |
| FR-060 | M | Parcel search by lot, block, plan, title, tax declaration, barangay, owner (if authorised), coordinates |
| FR-061 | S | Neighbour/overlap detection: report parcels whose geometry overlaps or has slivers against the candidate |
| FR-062 | S | Stable external key exposed for future RPT integration; no coupling to a specific RPT schema |

### 3.9 Land titles and parties

| ID | Pri | Requirement |
|---|---|---|
| FR-065 | M | Title record: title_number, title_type (OCT/TCT/CCT/Free Patent/Homestead/CLOA/EP/other), title_date, registry office, lot number, survey plan, area, location, source document, remarks, status |
| FR-066 | M | Titles link to parcels many-to-many with a relationship type |
| FR-067 | M | Registered owners are stored as `parties`, separated from title attributes, encrypted at rest, and require `title.view_owner` |
| FR-068 | M | API responses are assembled in three tiers — public GIS attributes / property information / sensitive personal information — by permission, with omission performed server-side |
| FR-069 | M | Every read of a party record is audited |
| FR-070 | M | No invented or sample real title data ships in any environment; seed data is clearly synthetic |

### 3.10 Survey control points and tie points

| ID | Pri | Requirement |
|---|---|---|
| FR-075 | M | Control point record per `architecture.md` §3.6, including native coordinates, datum, zone, CRS, source, accuracy, status, verifier |
| FR-076 | M | Point types: BLLM, MBM, PBM, GCP, Control Point, Tie Point, Reference Point, Other |
| FR-077 | M | Coordinates may be entered as E/N in a projected CRS **or** lat/long; the system derives the other and records which was original |
| FR-078 | M | Points are `UNVERIFIED` until a user with `control_point.verify` verifies them; using an unverified point in a computation raises a warning that persists into validation |
| FR-079 | M | Nearest-control-point search from a map click or coordinate |
| FR-080 | M | Bulk import of control points with CRS declaration, preview, and per-row validation |
| FR-081 | M | Editing a control point's coordinates never retroactively changes past computations (snapshots protect them) but flags affected parcels for review |

### 3.11 CRS and transformations

| ID | Pri | Requirement |
|---|---|---|
| FR-085 | M | CRS registry seeded with EPSG:4326, 3857, PRS92 PTM zones 1–5 (3121–3125), Luzon 1911 zones I–V (25391–25395); extensible without code change |
| FR-086 | M | Each parcel/computation records its compute CRS; the zone is suggested from location and confirmed by the user |
| FR-087 | M | No implicit transformation of historical coordinates on read or display of original values |
| FR-088 | M | Every persisted transformation writes a `coordinate_transformations` row: source CRS, target CRS, method, parameters, parameter source, accuracy, operator, timestamp |
| FR-089 | M | UI distinguishes original survey coordinates, transformed coordinates, and display coordinates |
| FR-090 | S | Support for custom local plane systems defined by an origin, scale, and rotation |

### 3.12 Technical descriptions

| ID | Pri | Requirement |
|---|---|---|
| FR-095 | M | Manual course entry: course #, from point, to point, bearing (quadrant + D/M/S), distance + unit, remarks |
| FR-096 | M | Tie line(s) from a control point to the point of beginning, with the same bearing/distance model |
| FR-097 | M | Add, edit, delete, reorder, and validate courses; reordering renumbers and is audited |
| FR-098 | M | Technical descriptions are revisioned; the current revision is flagged and prior revisions are retained |
| FR-099 | M | Paste-and-parse: free text → candidate courses with per-field confidence and the source substring highlighted |
| FR-100 | M | Parser output is staged only. The flow `PARSED → USER REVIEW → CONFIRM → COMPUTE` is mandatory; confirmation is a distinct, audited action |
| FR-101 | M | Parsed values that fail validation are shown as unresolved; a description cannot be confirmed with unresolved courses |
| FR-102 | S | OCR assist from an uploaded scan; identical staging and confirmation rules apply |
| FR-103 | S | Curve courses (radius, arc length, chord, central angle, direction) |
| FR-104 | M | Bearing reference (GRID/GEODETIC/MAGNETIC/ASSUMED) is recorded; non-GRID blocks computation with an explicit message until convergence handling exists |

### 3.13 Computation engine

| ID | Pri | Requirement |
|---|---|---|
| FR-110 | M | Compute vertices from tie point + tie line(s) + courses using ΔN = D·cos(Az), ΔE = D·sin(Az) in the compute CRS |
| FR-111 | M | Bearing parsing supports quadrant DMS, quadrant decimal, azimuth DMS, azimuth decimal, and cardinal forms |
| FR-112 | M | Canonical internal units: metres, decimal-degree azimuth; feet and other supported units convert on input with the original preserved |
| FR-113 | M | Closure: ΔE, ΔN, linear error, perimeter, relative precision (1:N), error bearing |
| FR-114 | M | Area: shoelace on plane coordinates, plus a PostGIS cross-check; both stored; disagreement beyond tolerance is a warning |
| FR-115 | M | Every computation stores an immutable `input_snapshot` (tie point coordinates as used, all courses, CRS, tolerances, engine version) |
| FR-116 | M | Computations are immutable; recomputation creates a new row; `is_current` marks the active one |
| FR-117 | M | Closure is never forced; coordinates are never silently altered |
| FR-118 | S | Adjustments (Compass/Bowditch, Transit) produce a new computation linked to the original, recording method, parameters, operator, and date |
| FR-119 | M | The resulting polygon is generated in the compute CRS, validated, transformed to 4326, and stored; the vertices remain authoritative |
| FR-120 | M | A computation can be replayed from its snapshot and must reproduce identical vertices |

### 3.14 Validation

| ID | Pri | Requirement |
|---|---|---|
| FR-125 | M | Validation screen with explicit pass/warn/fail per check: technical description parsed; tie point found; tie point verified; CRS identified; bearings valid; distances valid; polygon closed within tolerance; geometry valid (ST_IsValid/ST_IsSimple); area computed; area vs source area; overlap with existing parcels; minimum vertex count |
| FR-126 | M | Warnings are never hidden, collapsed by default, or suppressed by a "looks fine" heuristic |
| FR-127 | M | Area comparison shows source area, computed area, difference, and percentage difference, labelled as a validation aid, not a determination of correctness |
| FR-128 | M | Validation results are persisted with the computation and shown in history |
| FR-129 | M | Records failing a blocking check cannot be submitted for approval; the blocking reason is displayed |

### 3.15 Workflow and approval

| ID | Pri | Requirement |
|---|---|---|
| FR-135 | M | States: DRAFT, SUBMITTED, UNDER_REVIEW, RETURNED, VERIFIED, APPROVED, PUBLISHED, ARCHIVED, SUPERSEDED |
| FR-135a | M | SUPERSEDED is set **only** by a committed split or consolidation, never by a user edit or deletion; it is terminal, and the record retains all geometry, versions, computations, and documents |
| FR-136 | M | Each transition requires a specific permission; illegal transitions are rejected server-side |
| FR-137 | M | RETURNED and ARCHIVED require a reason; APPROVED requires a comment and records the exact computation and TD revision accepted |
| FR-138 | M | Full transition history with actor, timestamp, reason, and state pair |
| FR-139 | S | Assignment and due dates; a reviewer inbox |
| FR-140 | S | In-app notifications on submit, return, approve |
| FR-141 | M | Editing an APPROVED parcel requires a new version and returns it to DRAFT/UNDER_REVIEW per configuration; the approved version remains intact |

### 3.16 Audit and history

| ID | Pri | Requirement |
|---|---|---|
| FR-145 | M | All mutations audited with actor, action, entity, old/new values, changed fields, reason, IP, user agent, request id |
| FR-146 | M | Audit rows are append-only and cannot be updated or deleted by the application database role |
| FR-147 | M | Per-record history timeline (versions + audit + workflow) on parcels, features, control points, titles |
| FR-148 | M | Side-by-side version comparison including geometry diff (added/removed/moved vertices) |
| FR-149 | M | Audit search by actor, entity, action, date range; export requires `audit.export` |
| FR-150 | M | Reads of party/PII records and downloads of restricted documents are audited |

### 3.17 Documents

| ID | Pri | Requirement |
|---|---|---|
| FR-155 | M | Upload with type, classification, and description; linked to parcels, titles, plans, control points, or features |
| FR-156 | M | Allowed types: PDF, JPEG, PNG, TIFF, plus GIS exchange files during import; size cap configurable (default 25 MB) |
| FR-157 | M | Content-addressed storage with de-duplication by SHA-256 |
| FR-158 | M | Download via short-lived signed URL; classification enforced server-side |
| FR-159 | M | Soft delete only; blobs retained per retention policy |
| FR-160 | S | Inline PDF/image preview |

### 3.18 Search

| ID | Pri | Requirement |
|---|---|---|
| FR-165 | M | Global search across lot number, title number, survey plan, tax declaration, barangay, control point, layer name, and coordinates, returning typed, grouped results |
| FR-166 | M | Owner search only for holders of `title.view_owner`; the search itself is audited |
| FR-167 | M | Coordinate search accepts lat/long and E/N with a CRS selector, and zooms to the point |
| FR-168 | M | Spatial search: near me, within distance, within drawn polygon, intersects, nearest N |
| FR-169 | M | Results respect layer permissions and data scopes; out-of-scope records are absent, not greyed out |
| FR-170 | S | Fuzzy matching on lot/title numbers (`pg_trgm`) |

### 3.19 Import and export

| ID | Pri | Requirement |
|---|---|---|
| FR-175 | M | Import GeoJSON, CSV with coordinates; Shapefile (.zip) and KML via the OGR adapter |
| FR-176 | M | Import pipeline: upload → format detect → CRS declaration/selection → field mapping → validation → preview with per-row errors → explicit commit |
| FR-177 | M | Nothing reaches production tables before commit; staging rows are isolated |
| FR-178 | M | Downloadable error report for failed rows; partial commit is allowed only when explicitly chosen |
| FR-179 | M | Imported features are stamped `IMPORTED_GIS` provenance and record the source file id |
| FR-180 | M | Export GeoJSON, CSV, KML, Shapefile with CRS selection, filter/selection scope, and a provenance + disclaimer block |
| FR-181 | M | Exports respect permissions and scopes; PII is excluded unless the requester holds the PII permission, and the export is audited |

### 3.20 Map and styling

| ID | Pri | Requirement |
|---|---|---|
| FR-185 | M | Base maps: OSM plus configurable providers; imagery is labelled visual context, never a boundary source |
| FR-186 | M | Layer panel: toggle, reorder, opacity, zoom to layer, legend, style editing for permitted users |
| FR-187 | M | Styling stored as metadata: fill, stroke, width, opacity, icon, label, label field; SINGLE, CATEGORIZED, and GRADUATED (S) style types |
| FR-188 | M | No layer styles hard-coded in components; defaults come from the API |
| FR-189 | M | Large layers render as vector tiles; the client never requests an unbounded layer |
| FR-190 | S | Point clustering at low zoom for dense point layers |

### 3.21 Manual parcel creation

| ID | Pri | Requirement |
|---|---|---|
| FR-195 | M | Authorised users create a parcel by drawing a polygon: create parcel → draw → edit vertices → enter information → save draft |
| FR-196 | M | Drawing supports vertex editing, snapping to configured snap-target layers, undo/redo, and live area readout |
| FR-197 | M | Client-side geometry validation gives immediate feedback; the server repeats every check and its verdict is authoritative |
| FR-198 | M | A manually drawn parcel is stamped `MANUAL_DRAWING` (or `DIGITIZED_FROM_IMAGERY` when traced over imagery) and is visibly marked as such everywhere it appears |
| FR-199 | M | A manually drawn parcel cannot be relabelled to a survey-derived provenance without attaching survey data and recording an audited justification |

### 3.22 Parcel splitting / subdivision

| ID | Pri | Requirement |
|---|---|---|
| FR-205 | M | Authorised users (`parcel.split`) split a parcel into two or more children |
| FR-206 | M | Methods: map split line, survey geometry, technical description (children computed by the survey engine), imported geometry |
| FR-207 | M | The system previews the result — child geometries, areas, warnings — **before** anything is written; the preview runs every validation of the commit path |
| FR-208 | M | Blocking validations: invalid child geometry, empty child, children overlapping each other, union of children not matching the parent within tolerance (gaps/slivers), mixed SRIDs, parent already SUPERSEDED or ARCHIVED, insufficient permission or scope |
| FR-209 | M | Area reconciliation (sum of child areas vs parent area) is computed and displayed; a discrepancy is reported, never silently balanced |
| FR-210 | M | Children are created as DRAFT with new identifiers, inherit location/organisation, and may each receive their own survey plan, technical description, and documents |
| FR-211 | M | The parent is never deleted: on commit it becomes SUPERSEDED with lineage edges to every child |
| FR-212 | M | The entire operation is one database transaction; any failure rolls back completely |
| FR-213 | M | The operation records method, inputs, results, reconciliation, validation outcome, operator, timestamp, reason, and source documents |

### 3.23 Parcel consolidation

| ID | Pri | Requirement |
|---|---|---|
| FR-220 | M | Authorised users (`parcel.consolidate`) merge two or more parcels into one |
| FR-221 | M | Pre-validation: geometry validity, overlaps between parents (blocking), gaps/slivers, contiguity of the union, CRS compatibility, parcel statuses, presence of required documentation |
| FR-222 | M | Union preview with area comparison (sum of parents vs union) shown before commit |
| FR-223 | M | A non-contiguous union is rejected unless multipart parcels are explicitly permitted by configuration for that operation |
| FR-224 | M | The resulting parcel is created as DRAFT with lineage edges from every parent; all parents become SUPERSEDED and are preserved |
| FR-225 | M | One transaction, full rollback on any failure, full audit |

### 3.24 Parcel lineage

| ID | Pri | Requirement |
|---|---|---|
| FR-230 | M | Relationships stored with parent, child, relationship type (SUBDIVISION, CONSOLIDATION, MERGER, REPLACEMENT, CORRECTION, ADJUSTMENT), operation reference, effective date, reason, source document, actor, timestamp |
| FR-231 | M | Ancestors and descendants are traversable in both directions to a configurable depth, with cycle prevention |
| FR-232 | M | A lineage view shows the genealogy as a graph with each node's status, area, and effective date, and links to the operation that created each edge |
| FR-233 | M | Superseded parcels are excluded from default map/search results and included via an explicit "historical" filter |
| FR-234 | S | Lineage export as a report (`report.export`) |

### 3.25 Basemap provider management

| ID | Pri | Requirement |
|---|---|---|
| FR-240 | M | Basemap providers are managed records, not code: name, type (XYZ/WMS/WMTS/VECTOR_TILE/LOCAL_ORTHOPHOTO/TMS), service URL or template, layer/matrix set, format, attribution, licence type and reference, expiry, key requirement, proxy requirement, min/max zoom, bounds, enabled, default, order, role restrictions |
| FR-241 | M | Third-party API keys are held server-side only and never appear in the SPA bundle, in a URL visible to the browser, or in logs |
| FR-242 | M | Providers requiring a key are served through an authenticated server-side tile proxy that enforces role restrictions and rate limits |
| FR-243 | M | A provider with licence type UNLICENSED, or with a past expiry date, cannot be enabled; attempting to do so returns `LICENSE_RESTRICTED` with an explanation |
| FR-244 | M | Attribution is mandatory for every enabled provider and is always rendered; it cannot be hidden |
| FR-245 | M | Tile caching for a commercial provider is off unless the licence explicitly permits it, with the permission recorded in the provider's licence notes |
| FR-246 | M | The platform provides no capability to scrape, bulk-download, rehost, or redistribute third-party imagery, and no path by which desktop-tool imagery can become a platform tile source |
| FR-247 | M | Changes to provider records and licence fields are audited |

### 3.26 CAD / survey data import (CAD-Earth, AutoCAD)

| ID | Pri | Requirement |
|---|---|---|
| FR-250 | M | DXF import via the OGR adapter, mapping CAD entity layers to target GIS layers, with a report of dropped entity types |
| FR-251 | M | DWG is not read natively; the user converts to DXF unless a server-side converter is licensed (D-16) |
| FR-252 | M | CRS is never inferred for CAD data: an explicit CRS declaration is required, and a local/assumed CAD grid requires a documented transformation (origin, scale, rotation) recorded in `coordinate_transformations` before geometry is accepted |
| FR-253 | M | Imported CAD geometry is stamped `CAD_IMPORT`, retains a link to the stored original file, and enters as DRAFT |
| FR-254 | M | Survey points imported from CAD become UNVERIFIED candidate control points and are never auto-verified |
| FR-255 | M | CAD import follows the standard pipeline: upload → detect → CRS → mapping → validate → preview → commit; nothing bypasses it |

### 3.27 Reports

| ID | Pri | Requirement |
|---|---|---|
| FR-260 | M | Reports: Parcel Profile, Parcel History, Parcel Lineage, Survey Computation, Closure, Area Comparison, Technical Description, Title Information, Survey Plan, Control Point, Audit Trail, Approval History, Data Quality |
| FR-261 | M | Every report renders on screen and exports; PDF for Survey Computation, Closure, and Parcel Profile at v1 (D-18), CSV for tabular reports |
| FR-262 | M | Every report carries: generation timestamp, generating user, data-as-of, provenance, CRS/datum, and the non-certification disclaimer |
| FR-263 | M | Reports respect permissions and data scopes; PII appears only for holders of the relevant permission, and generation of a report containing PII is audited |
| FR-264 | M | A report of a superseded or draft record is watermarked with that status |
| FR-265 | S | Report generation for large result sets runs as a background job with a download link on completion |

### 3.28 Data quality display

| ID | Pri | Requirement |
|---|---|---|
| FR-270 | M | Every important GIS record can display: source, CRS, transformation history, geometry source/provenance, accuracy or quality class, verification status, approval status, last updated, updated by |
| FR-271 | M | Incomplete or uncertain data carries a visible warning indicator, not a silent blank |
| FR-272 | M | The UI and API consistently distinguish GIS_OPERATION, SURVEY_SOURCE, VERIFIED, APPROVED, PUBLISHED |

### 3.29 Observability and operations

| ID | Pri | Requirement |
|---|---|---|
| FR-280 | M | Every request carries a request id, returned to the client and recorded in logs and audit rows |
| FR-281 | M | Structured JSON application, API, and database-error logging with configurable levels, excluding credentials, tokens, and PII |
| FR-282 | M | Liveness and readiness health endpoints; readiness reports which dependency failed |
| FR-283 | S | Basic metrics endpoint behind an admin credential |
| FR-284 | M | Login and logout are audited alongside data mutations |


---

## 4. Non-functional requirements

| ID | Requirement | Target |
|---|---|---|
| NFR-01 | Map pan/zoom responsiveness at target volume (D-03) | tile response p95 < 300 ms cached, < 800 ms uncached |
| NFR-02 | Feature bbox query | p95 < 500 ms for ≤ 2,000 features |
| NFR-03 | Parcel computation (≤ 50 courses) | < 200 ms server time |
| NFR-04 | Attribute grid page (50 rows, filtered, sorted) | p95 < 600 ms |
| NFR-05 | Global search | p95 < 800 ms |
| NFR-06 | Concurrent users | 50 sustained, 100 peak without degradation beyond 2× targets |
| NFR-07 | Availability | 99 % during LGU office hours; planned maintenance windows announced |
| NFR-08 | Backup/restore | RPO per D-11; restore drill passes on a full dataset |
| NFR-09 | Browser support | current Chrome, Edge, Firefox; Safari 16+ |
| NFR-10 | Responsive | full function ≥ 1280 px; usable review/query on tablet; read-only lookup on phone |
| NFR-11 | Accessibility | keyboard navigation for all non-map controls; visible focus; WCAG 2.1 AA contrast; map tools have keyboard/text alternatives |
| NFR-12 | Localisation | English UI at v1, with all strings externalised for Filipino later; dates Asia/Manila; PSGC naming |
| NFR-13 | Maintainability | PHPStan level 8, ESLint clean, no function > 50 lines in domain code, ≥ 95 % coverage on `Domain/Survey` |
| NFR-14 | Observability | structured JSON logs with request id; slow-query log; health endpoint |
| NFR-15 | Data integrity | no destructive migration without a reversible path; no hard deletes of parcels, titles, control points, versions, or audit rows |
| NFR-16 | Upgradability | migrations versioned and forward-only in production; seed data idempotent |

---

## 5. Validation rules

### 5.1 Bearings and distances

| ID | Rule | Severity |
|---|---|---|
| VR-01 | Quadrant bearing degrees 0–90; minutes 0–59; seconds 0–59.999 | error |
| VR-02 | Azimuth 0 ≤ Az < 360 | error |
| VR-03 | Quadrant must be one of NE, SE, SW, NW (cardinals handled explicitly) | error |
| VR-04 | Distance > 0; > 0.01 m | error |
| VR-05 | Distance > 5,000 m in a single course | warning (plausible but unusual) |
| VR-06 | Distance unit must be a registered unit; conversion factor is exact and versioned | error |
| VR-07 | Bearing of exactly 0° or 90° with an ambiguous quadrant must be re-entered as a cardinal | error |
| VR-08 | Two consecutive courses with identical bearing and touching endpoints | warning (collinear, possibly a transcription split) |
| VR-09 | Reversed course (azimuth differs by 180° ± 0.001° from the previous, same distance) | warning |

### 5.2 Traverse and polygon

| ID | Rule | Severity |
|---|---|---|
| VR-10 | ≥ 3 courses to form a polygon | error |
| VR-11 | Linear closure ≤ 0.10 m (configurable) | warning above |
| VR-12 | Relative precision ≥ 1:5,000 | warning below; error below 1:1,000 (configurable per survey type) |
| VR-13 | Resulting ring must not self-intersect (`ST_IsSimple`) | error |
| VR-14 | `ST_IsValid` on the final polygon | error |
| VR-15 | Computed area > 0 and within plausible bounds (> 1 m², < 10,000 ha) | error outside, warning near bounds |
| VR-16 | Computed vs source area difference ≤ 0.5 % | warning above; flag above 2 % |
| VR-17 | Shoelace area vs PostGIS area difference ≤ 0.01 % | warning above (indicates a projection or precision problem) |
| VR-18 | Overlap with an existing non-archived parcel | warning with the list of overlapping parcels |
| VR-19 | Tie point unverified | warning, persisted and shown at approval |
| VR-20 | Compute CRS not appropriate for the parcel's location (outside the zone's area of use) | error |

### 5.3 Data entry

| ID | Rule | Severity |
|---|---|---|
| VR-25 | Field values validated against `gis_layer_fields` metadata (type, required, options, regex, min/max) both in the app and by DB trigger | error |
| VR-26 | Geometry type must match the layer's declared type | error |
| VR-27 | Feature geometry must be valid and within the declared CRS bounds | error |
| VR-28 | Title number unique per registry office | error |
| VR-29 | Parcel code unique | error |
| VR-30 | PSGC codes must resolve in `ref.psgc_areas` | error |
| VR-31 | Uploaded file MIME must match its extension and magic bytes | error |
| VR-32 | Import row geometry must be valid and within the declared CRS | error, row rejected |

### 5.4 Split, consolidation and lineage

| ID | Rule | Severity |
|---|---|---|
| VR-35 | Split produces ≥ 2 children, each with valid, simple, non-empty geometry | error |
| VR-36 | Child polygons do not overlap one another (intersection area ≤ ε, default 0.01 m²) | error |
| VR-37 | Union of children matches the parent within tolerance (symmetric-difference area ≤ ε, default 0.05 m²) — detects gaps and slivers | error |
| VR-38 | Each child area ≥ configurable minimum (default 1 m²) | error |
| VR-39 | Σ child areas vs parent area difference > 0.1 % | warning, always reported |
| VR-40 | Consolidation requires ≥ 2 distinct parents, none SUPERSEDED or ARCHIVED | error |
| VR-41 | Parents do not overlap one another | error |
| VR-42 | Gaps between parents above ε | error (blocks consolidation) |
| VR-43 | Union is a single contiguous polygon unless multipart is explicitly permitted | error |
| VR-44 | All inputs share one SRID | error |
| VR-45 | Lineage edge would create a cycle (a parcel as its own ancestor) | error |
| VR-46 | Required documentation for the operation is missing | error if configured as mandatory, otherwise warning |
| VR-47 | Split/consolidation of a parcel with an unresolved validation warning | warning, carried forward to every child |

### 5.5 Basemap and import

| ID | Rule | Severity |
|---|---|---|
| VR-50 | Enabling a provider with licence type UNLICENSED or a past expiry | error |
| VR-51 | Enabled provider without attribution | error |
| VR-52 | Import without an explicit CRS declaration | error |
| VR-53 | CAD import whose coordinates fall outside the declared CRS area of use | error |
| VR-54 | Import geometry type incompatible with the target layer | error, row rejected |
| VR-55 | Imported coordinates that look like a local/assumed grid (small or offset values) with no transformation supplied | warning, requires explicit acknowledgement |


---

## 6. API specification

### 6.1 Conventions

- Base path `/api/v1`. JSON in and out (`application/json`); geometry as GeoJSON (`application/geo+json`) or MVT for tiles.
- Auth: `Authorization: Bearer <access token>` on every endpoint except `/auth/login`, `/auth/refresh`, `/health`.
- Errors use the mandated envelope (ADR-14; this supersedes the RFC 7807 form used in v0.1 of this document):
  ```json
  { "success": false,
    "error": {
      "code": "VALIDATION_FAILED",
      "message": "The technical description contains invalid courses.",
      "details": {
        "request_id": "01J…",
        "fields": [{ "field": "courses[2].bearing", "rule": "VR-01",
                     "message": "Minutes must be between 0 and 59." }]
      } } }
  ```
  Success responses use `{ "success": true, "data": …, "meta": … }`. Error codes are a closed, documented set (`api.md` §Errors): `VALIDATION_FAILED`, `GEOMETRY_INVALID`, `CLOSURE_EXCEEDS_TOLERANCE`, `VERSION_CONFLICT`, `PERMISSION_DENIED`, `NOT_FOUND`, `AUTH_REQUIRED`, `AUTH_INVALID`, `RATE_LIMITED`, `CRS_REQUIRED`, `CRS_UNSUPPORTED`, `PARSE_UNRESOLVED`, `SPLIT_INVALID`, `CONSOLIDATION_INVALID`, `LICENSE_RESTRICTED`, `IMPORT_INVALID`, `INTERNAL_ERROR`.
- Status codes: 200, 201, 204, 400 (malformed), 401 (unauthenticated), 403 (unauthorised), 404 (not found **or** out of scope — scope misses return 404, never 403, to avoid leaking existence), 409 (version conflict), 422 (validation), 429 (rate limited), 500.
- Pagination: `?page=1&per_page=50` → `{ "data": [...], "meta": { "page", "per_page", "total", "total_pages" } }`; `per_page` max 200.
- Filtering: `?filter[field]=value`, `?q=` full text, `?sort=-updated_at`, `?bbox=minx,miny,maxx,maxy&bbox_srid=4326`.
- Concurrency: `ETag: "<version>"` on single-resource GET; `If-Match` required on PUT/PATCH/DELETE of versioned resources.
- Idempotency: `Idempotency-Key` header honoured on POST `/calculate`, `/import/{id}/commit`, and document upload.

### 6.2 Endpoints

```text
AUTH
POST   /api/v1/auth/login                    → tokens, user summary
POST   /api/v1/auth/refresh                  → rotated tokens        (cookie + CSRF)
POST   /api/v1/auth/logout                   → 204                   (cookie + CSRF)
POST   /api/v1/auth/mfa/verify
GET    /api/v1/me                            → profile, permissions, layer caps, scopes
PUT    /api/v1/me/password

ADMIN — USERS / ROLES / SCOPES
GET    /api/v1/users            POST /api/v1/users
GET    /api/v1/users/{id}       PUT  /api/v1/users/{id}     DELETE (deactivate)
PUT    /api/v1/users/{id}/roles
GET    /api/v1/users/{id}/scopes            PUT /api/v1/users/{id}/scopes
GET    /api/v1/roles            POST /api/v1/roles          PUT/DELETE /api/v1/roles/{id}
PUT    /api/v1/roles/{id}/permissions
GET    /api/v1/permissions
GET    /api/v1/organizations    POST/PUT/DELETE
GET    /api/v1/psgc?level=&parent=

LAYERS / FIELDS / STYLES
GET    /api/v1/layers                         (tree=1 for grouped tree)
POST   /api/v1/layers
GET    /api/v1/layers/{id}     PUT   /api/v1/layers/{id}    DELETE /api/v1/layers/{id}
PUT    /api/v1/layers/order
GET    /api/v1/layers/{id}/fields             POST /api/v1/layers/{id}/fields
PUT    /api/v1/layers/{id}/fields/{fid}       DELETE /api/v1/layers/{id}/fields/{fid}
POST   /api/v1/layers/{id}/fields/{fid}/retype-preview
GET    /api/v1/layers/{id}/styles             PUT  /api/v1/layers/{id}/styles
GET    /api/v1/layers/{id}/extent
GET    /api/v1/layers/{id}/permissions        PUT  /api/v1/layers/{id}/permissions

FEATURES
GET    /api/v1/layers/{id}/features           ?bbox=&q=&filter[]=&page=&per_page=
POST   /api/v1/layers/{id}/features
GET    /api/v1/features/{id}                  (ETag)
PUT    /api/v1/features/{id}                  (If-Match)
DELETE /api/v1/features/{id}                  (If-Match)
GET    /api/v1/features/{id}/history
POST   /api/v1/features/{id}/restore/{version}
GET    /api/v1/tiles/{layerId}/{z}/{x}/{y}.mvt
POST   /api/v1/spatial/query                  {op: intersects|within|nearest|buffer|distance, …}
POST   /api/v1/spatial/measure

PARCELS
GET    /api/v1/parcels                        POST /api/v1/parcels
GET    /api/v1/parcels/{id}                   PUT  /api/v1/parcels/{id}   (If-Match)
DELETE /api/v1/parcels/{id}                   (soft, reason required)
GET    /api/v1/parcels/{id}/technical-descriptions
POST   /api/v1/parcels/{id}/technical-descriptions            (new revision)
GET    /api/v1/technical-descriptions/{tdId}
PUT    /api/v1/technical-descriptions/{tdId}                  (If-Match, draft only)
POST   /api/v1/technical-descriptions/parse                   (text → staged courses)
POST   /api/v1/technical-descriptions/{tdId}/confirm          (staged → confirmed)
POST   /api/v1/parcels/{id}/calculate                         (body: tdId, computeSrid, tolerances?)
POST   /api/v1/parcels/{id}/validate
POST   /api/v1/parcels/{id}/accept-computation                {computationId}
GET    /api/v1/parcels/{id}/computations
GET    /api/v1/computations/{cid}                             (+ vertices, snapshot)
POST   /api/v1/computations/{cid}/adjust                      {method, params}
GET    /api/v1/parcels/{id}/history
GET    /api/v1/parcels/{id}/versions/{v}
POST   /api/v1/parcels/{id}/transitions                       {action, reason, comment}
GET    /api/v1/parcels/{id}/overlaps

SURVEY
GET    /api/v1/control-points        POST /api/v1/control-points
GET    /api/v1/control-points/{id}   PUT  (If-Match)  DELETE (soft)
POST   /api/v1/control-points/{id}/verify
GET    /api/v1/control-points/nearest?lat=&lon=&limit=
GET    /api/v1/survey-plans          POST/PUT/GET{id}
GET    /api/v1/crs                   GET /api/v1/crs/{id}
POST   /api/v1/crs/transform                                  (explicit, logged)

TITLES / PARTIES
GET    /api/v1/titles                POST/PUT/GET{id}
GET    /api/v1/titles/{id}/parties                            (requires title.view_owner)
POST   /api/v1/titles/{id}/parties
GET    /api/v1/parties/{id}                                   (audited read)

DOCUMENTS
POST   /api/v1/documents                                      (multipart)
GET    /api/v1/documents/{id}                                 (metadata)
GET    /api/v1/documents/{id}/download                        (signed, short-lived)
POST   /api/v1/documents/{id}/links                           DELETE /api/v1/documents/{id}/links/{lid}

IMPORT / EXPORT
POST   /api/v1/imports                        (upload + declare target/format)
GET    /api/v1/imports/{id}                   (status, counts)
PUT    /api/v1/imports/{id}/mapping
POST   /api/v1/imports/{id}/validate
GET    /api/v1/imports/{id}/preview           ?page=
GET    /api/v1/imports/{id}/errors            (CSV)
POST   /api/v1/imports/{id}/commit            (Idempotency-Key)
POST   /api/v1/exports                        GET /api/v1/exports/{id}

SEARCH / AUDIT / SYSTEM
GET    /api/v1/search?q=&types=
GET    /api/v1/audit-logs?entity_type=&entity_id=&user_id=&from=&to=&action=
GET    /api/v1/audit-logs/export
GET    /api/v1/notifications                  POST /api/v1/notifications/{id}/read
GET    /api/v1/health                         GET /api/v1/version
GET    /api/v1/config                         (base maps, units, tolerances, feature flags)
```

The list is a specification target, not a copy exercise — endpoints are added only when a task needs them, and every one ships with an OpenAPI entry and contract tests.

### 6.3 Representative payloads

`POST /api/v1/parcels/{id}/calculate`

```json
{
  "technical_description_id": 412,
  "compute_srid": 3123,
  "tolerances": { "linear_closure_m": 0.10, "relative_precision_min": 5000 }
}
```

Response `201`:

```json
{
  "computation_id": 908,
  "compute_srid": 3123,
  "engine_version": "survey-1.0.0",
  "start": { "label": "POB", "easting": 512345.6789, "northing": 1678901.2345 },
  "vertices": [
    { "seq": 1, "label": "1", "easting": 512345.6789, "northing": 1678901.2345,
      "latitude": 15.1234567, "longitude": 120.5678901 }
  ],
  "closure": {
    "delta_e": 0.014, "delta_n": -0.009, "linear_error_m": 0.0166,
    "perimeter_m": 412.88, "relative_precision": "1:24872",
    "error_azimuth_dd": 122.74, "status": "WITHIN_TOLERANCE"
  },
  "area": {
    "computed_sqm": 10432.7412, "postgis_sqm": 10432.7408,
    "source_sqm": 10400.0000, "difference_sqm": 32.7412, "difference_pct": 0.3148,
    "note": "Area comparison is a validation aid, not a determination of correctness."
  },
  "warnings": [
    { "code": "VR-19", "severity": "warning", "message": "Tie point BLLM-3 is unverified." }
  ],
  "geometry_preview": { "type": "Polygon", "coordinates": [[ /* … */ ]] }
}
```

Nothing in this response is persisted to `parcels.geom` until `POST /accept-computation`.

`409 Conflict` on a stale write:

```json
{ "success": false,
  "error": {
    "code": "VERSION_CONFLICT",
    "message": "This record was modified by another user.",
    "details": { "your_version": 7, "current_version": 9,
                 "modified_by": "jdelacruz", "modified_at": "2026-09-19T03:12:44Z",
                 "request_id": "01J…" } } }
```

---

## 7. Database specification

Authoritative DDL outline lives in `architecture.md` §3. Additional rules:

- Migrations are numbered, reversible where safe, and never edited after being applied to staging.
- Seed data (permissions, roles, CRS registry, PSGC, workflow definitions, tolerance defaults, base-map config) is idempotent and version-controlled; sample GIS/parcel data is separate, clearly labelled synthetic, and never loaded in production.
- Naming: `snake_case`, plural tables, `*_id` foreign keys, `idx_`/`uq_`/`fk_`/`ck_` prefixes on constraints.
- Enumerations are Postgres `CHECK` constraints or lookup tables in `ref` — not application-only.
- No table in `audit` grants UPDATE or DELETE to the application role.
- Every spatial table has a GIST index before it receives production data.

---

## 8. Error handling

| Condition | Response | User-visible behaviour |
|---|---|---|
| Validation failure | 422 + per-field codes | inline field errors, form not submitted |
| Permission denied | 403 | explicit message naming the missing permission |
| Out of scope | 404 | record simply not found |
| Version conflict | 409 | conflict dialog: your version / current version / reload or overwrite-with-review |
| Geometry invalid | 422 with the PostGIS reason and the offending location | map highlights the problem vertex/ring |
| Computation failure | 422 with the failing course index | the course row is highlighted in the editor |
| Upstream/tile failure | 502/504 | layer shows a retry affordance, map stays usable |
| Unexpected error | 500 + request_id, no stack trace | "Something went wrong — reference `<request_id>`" |

All errors are logged with the request id; the same id is shown to the user for support correlation.

---

## 9. Security requirements

| ID | Requirement |
|---|---|
| SR-01 | Argon2id password hashing; no reversible storage of credentials |
| SR-02 | All authorization decisions enforced server-side; the frontend is advisory only |
| SR-03 | Layer permissions and data scopes enforced in every query, including tiles, exports, and search |
| SR-04 | PDO prepared statements; dynamic identifiers validated against metadata allow-lists |
| SR-05 | CSP without `unsafe-inline`; no `dangerouslySetInnerHTML`; API returns data, not markup |
| SR-06 | CSRF protection on all cookie-authenticated endpoints; Origin verification |
| SR-07 | Upload validation by extension, MIME sniff, and magic bytes; size caps; storage outside the web root; randomised keys |
| SR-08 | Rate limiting on auth, search, compute, import, and export |
| SR-09 | Secrets only in environment variables; startup fails if absent; no secrets in the SPA bundle or repo |
| SR-10 | PII encrypted at rest at column level; PII reads audited; PII excluded from logs and non-privileged exports |
| SR-11 | Audit log append-only at the database privilege level |
| SR-12 | Least-privilege database roles; the app role holds no DDL rights |
| SR-13 | RLS policies on parcels, features, titles, parties, documents, technical descriptions as a backstop |
| SR-14 | Signed, short-lived, single-use document download URLs |
| SR-15 | Dependency vulnerability scanning in CI; no known-critical dependencies shipped |
| SR-16 | Backups encrypted and access-restricted; restore procedure documented and tested |

---

## 10. Testing specification

| Suite | Scope | Gate |
|---|---|---|
| Survey unit tests | bearing parsing (all formats + malformed), DMS↔decimal round-trip, quadrant↔azimuth for all four quadrants and boundaries, unit conversion, traverse against known-answer vectors, closure, relative precision, shoelace area vs hand-computed values, tolerance evaluation | ≥ 95 % coverage of `Domain/Survey`; must pass |
| Parser tests | a corpus of real-form Philippine technical-description strings (synthetic), including OCR-like noise; asserts correct extraction **and** correct low-confidence flagging | must pass |
| API integration | auth flows, token rotation and reuse detection, RBAC matrix per endpoint, layer permissions, data scope enforcement (including the "404 not 403" rule), CRUD, validation, 409 concurrency | must pass |
| Spatial | geometry validity, SRID preservation, projected-area correctness, bbox/intersects/within/nearest, MVT output decodes and contains expected features | must pass |
| RLS | direct SQL as the app role attempting cross-scope reads returns nothing | must pass |
| E2E (Playwright) | login → create layer → create field → draw polygon → save → edit → search → create parcel → enter technical description → calculate → review closure → save → submit → approve → view history; plus a negative pass for each permission boundary | must pass before release |
| Performance | tile, bbox, grid, search at D-03 volumes against NFR targets | reported per release |
| Accessibility | keyboard traversal of forms, grid, and panels; contrast audit | reported per release |

Test data: synthetic only, clearly labelled, generated by a seeder. No real title numbers, real owner names, or real parcel boundaries in any non-production dataset, and none invented to look real.

---

## 11. Acceptance criteria (release-level)

| ID | Criterion |
|---|---|
| AC-01 | A GIS Administrator creates a layer with five custom field types and records are created against it without any code deployment |
| AC-02 | A Survey user enters a tie point, tie line, and eight courses, computes, and sees vertices, closure, relative precision, and area comparison with explicit warnings |
| AC-03 | The same computation replayed from its stored snapshot reproduces identical vertices to 1 mm |
| AC-04 | Editing the tie point afterwards does not change the stored computation, and flags the parcel for review |
| AC-05 | An Editor scoped to Barangay A cannot read, search, tile, or export a parcel in Barangay C — verified at API and direct-SQL level |
| AC-06 | Two users editing the same parcel produce a 409 with both versions; no silent overwrite occurs |
| AC-07 | An approval records the exact computation and technical-description revision accepted, with actor, timestamp, and comment, and appears in the history timeline |
| AC-08 | Owner information is invisible in API responses, search, and export for a user lacking `title.view_owner`, and every authorised read is in the audit log |
| AC-09 | A Shapefile import with a wrong declared CRS is caught at preview and never reaches production tables |
| AC-10 | A 250k-parcel layer pans and zooms within NFR-01 using vector tiles, with no unbounded layer request in the network log |
| AC-11 | Every exported file and printed view carries provenance and the non-certification disclaimer |
| AC-12 | A restore from backup onto a clean host reproduces the system within the RTO target |
| AC-13 | The full E2E workflow suite passes on a clean environment from migrations + seed |
| AC-14 | No secret appears in the repository, the SPA bundle, or any log |
| AC-15 | A published parcel is split into three children: the preview shows areas and warnings, the commit is transactional, the parent becomes SUPERSEDED, three lineage edges exist, and the parent remains fully viewable through history |
| AC-16 | Two adjacent parcels are consolidated; overlapping or non-adjacent inputs are refused with a specific reason, and both parents survive as SUPERSEDED with lineage edges |
| AC-17 | A lineage view of a twice-transformed parcel renders the full genealogy in both directions with the operation behind each edge |
| AC-18 | A simulated failure midway through a split leaves the database exactly as it was — no orphan children, no status change, no lineage edges |
| AC-19 | A basemap provider with an expired licence cannot be enabled, and no provider API key appears in the SPA bundle, a browser network URL, or any log |
| AC-20 | A DXF export from CAD imports only through the pipeline, requires a CRS declaration, and lands as `CAD_IMPORT` DRAFT geometry with its source file attached |
| AC-21 | A Survey Computation report reproduces the stored computation exactly, including closure, area comparison, warnings, provenance, and the non-certification disclaimer |
| AC-22 | The full core acceptance workflow (login → control point → parcel → title → plan → technical description → parse → review → confirm → CRS → calculate → closure → area → polygon → map → version → submit → review → approve → publish → split → lineage → consolidate → lineage) passes end to end on a clean environment |
