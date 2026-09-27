# Accomplishments

## TASK-125: DXF/CAD import (Phase 16, 2026-09-27)
- **What shipped**: `backend/src/ImportExport/Domain/DxfEntityScanner.php` (NEW, pure) — a shallow scanner for the tagged DXF byte stream that counts entity types and collects CAD layer names from the `ENTITIES` section only, and derives the `dropped` map of annotation/block/3D entity types the CAD import does not carry (FR-250). `ImportJobService` now implements the CAD pipeline end to end: `createJob()` records `options.dropped` and `options.entity_layers` for a DXF; `setMapping()` requires an `entity_layer_map` (CAD entity layer → target GIS layer id, each target existence-checked) for a FEATURE DXF import and, when the grid is local (`cad_local_grid`), refuses to proceed without a documented `transformation` and records it via a new `CoordinateTransformationService::recordAffineLocal()` (`method = AFFINE_LOCAL`, origin/scale/rotation in `parameters`) with `import_jobs.transformation_id` set; `validateJob()` resolves a **per-row destination layer** (new `staging.import_job_rows.target_layer_id`, migration `20260927000003_add_import_row_target_layer.php`), rejects unmapped CAD layers (`CAD_LAYER_UNMAPPED`), drops annotation/label rows (counted as `cad.dropped_rows`), applies the affine to each row's coordinates before imposing the declared CRS, and reports the CAD view in `validation_result.cad`; `commit()` stamps `provenance = CAD_IMPORT` and `status = PENDING` into `COALESCE(row.target_layer_id, job.target_layer_id)`, or — for a CONTROL_POINT target — inserts `UNVERIFIED` candidate rows into `app.survey_control_points`. `formatJob()` exposes `dropped`, `entity_layers` and `transformation_id`. **Tests**: `tests/Unit/DxfEntityScannerTest.php` (3) + `tests/Api/DxfImportTest.php` (3).
- **AC evidence**: a FEATURE DXF import without an `entity_layer_map` is refused (`VALIDATION_FAILED`) and without a `declared_crs` is `CRS_REQUIRED` — no CRS is ever inferred. A hand-written DXF with two PARCEL points and an ANNOTATIONS `TEXT` entity imports exactly two rows, reports `dropped.TEXT = 1` and `cad.dropped_rows = 1`, and commits both features with `provenance = CAD_IMPORT`, `status = PENDING` (never auto-approved) and `source_document_id` pointing at the stored original. A local/assumed grid is refused until a transformation is documented; once recorded, the `coordinate_transformations` row is `AFFINE_LOCAL` and a `(100, 200)` local point lands at `(121.1, 14.7)`. CAD survey points commit as `UNVERIFIED` candidates and nothing is written to a GIS layer.
- **Decisions made**: (1) The dropped-entity report is built from a raw scan of the source rather than from the converted output, because GDAL 3.13 reads TEXT/INSERT/DIMENSION as features; a label (`Text` populated) or a `SubClasses` annotation class is therefore excluded from staging so the report and the behaviour agree, and the scan also counts types GDAL genuinely cannot translate. (2) A DXF job may distribute rows across several target GIS layers, so the destination is resolved per row — hence the new `staging.import_job_rows.target_layer_id` column rather than overloading `normalized`. (3) The local-grid transformation is documented through the existing `CoordinateTransformationService` (a new `recordAffineLocal()` method) so every `coordinate_transformations` row still has one writer. (4) The `entity_layer_map` requirement applies only to a FEATURE target; a CONTROL_POINT CAD import needs no GIS-layer map. (5) `gis_features` has no DRAFT state, so imported CAD geometry lands `PENDING` — the un-approved state — and is never auto-merged into a parcel. (6) The staging insert now uses `ST_Force2D(...)`, which also protects the GeoJSON/CSV path from a Z-bearing coordinate.
- **Failed approaches**: (1) The first validate died with `Geometry has Z dimension but column does not` — DXF points carry a Z and OGR emits 3D GeoJSON; fixed by wrapping the staging geometry in `ST_Force2D`. (2) The control-point insert's `ON CONFLICT (point_name, native_crs_id)` failed because the unique index is **partial** (`WHERE deleted_at IS NULL`); the conflict target now carries the matching predicate. (3) The test cleanup deleted `coordinate_transformations` while `import_jobs.transformation_id` still referenced it; the cleanup now nulls the reference (and only then deletes the rows).
- **Follow-up items**: TASK-126 (control point bulk import — CSV with CRS declaration, per-row validation, duplicate detection by name and proximity) is next; the CAD candidate writer here is intentionally minimal (name-collision skip only) and TASK-126 owns the richer duplicate/proximity logic. TASK-127 (export service) and TASK-128 (import wizard UI) follow. TASK-104a (version-compare/history UI) still queued.

## TASK-124: Shapefile, KML, GeoPackage importers (Phase 16, 2026-09-27)
- **What shipped**: `backend/src/ImportExport/Domain/OgrRowReader.php` — a `RowReader` for `SHAPEFILE`, `KML`, `GEOPACKAGE` and `DXF`. It writes the stored source bytes into an `OgrAdapter::withSandbox()` temp file (a zipped shapefile is read through GDAL's `/vsizip/` virtual path), converts them to a GeoJSON intermediate with `OgrAdapter::convert()` and **no `-t_srs`** (the source coordinates are preserved; the declared CRS is applied later when the row is transformed into the layer's 4326 staging geometry), then delegates the GeoJSON body to the existing `GeoJsonRowReader`. `ImportJobService` now runs both halves of TASK-124: `createJob()` calls a new `inspectSource()` that detects the format and, for OGR-required formats with GDAL available, probes the file and records the detected `.prj`/OGR CRS as `options.suggested_crs` — exposed on the job payload as `suggested_crs` but **never written to `declared_crs_id`**; and `validateJob()` adds the **VR-53 area-of-use check**, comparing each row's 4326-transformed centroid against the declared CRS's `ref.crs_registry` bounding box (`area_south/west/north/east`) and rejecting an outside row per row with a `VR-53` reason. The reader is registered in `config/dependencies.php` and `ImportJobService::readerFor()`. **Tests**: `tests/Api/ShapefileImportTest.php` (3).
- **AC evidence**: a zipped `ogr2ogr`-built shapefile imports through the full lifecycle — the job reports `suggested_crs = EPSG:4326` with `declared_crs` still null until the user confirms, then both rows commit (`IMPORTED_GIS`). The wrong-CRS case declares projected `EPSG:3121` for EPSG:4326 data: both rows are flagged `VR-53` at validation/preview (`area_of_use.outside_rows = 2`, `error_summary.VR-53 = 2`), and both the strict and the partial commit are refused with `IMPORT_INVALID` while `app.gis_features` stays empty — a wrong declared CRS never reaches a production table. A third test proves the reader does not reproject: source lon/lat survive byte-for-byte into the rows.
- **Decisions made**: (1) The OGR reader emits a GeoJSON *file* and reads it back rather than piping `ogr2ogr` stdout — the GeoJSON writer only emits a reliable RFC 7946 feature collection through a file target, and a file target keeps the argv free of a pipe. (2) The `.prj` suggestion is resolved through `ref.crs_registry` before it is stored, so the UI can only ever pre-select a CRS the mapping endpoint would accept; an unknown detected CRS yields a null suggestion. (3) The suggestion lives in the job `options` JSON rather than a new column — it needs no migration and it travels with the job. (4) Only OGR-required formats are probed at upload; GeoJSON/CSV skip the probe so the native path is unchanged and needs no GDAL. (5) The area-of-use violation is a **per-row** `VR-53` reason (consistent with VR-32/VR-54) rather than a single fatal error, so the preview shows exactly which rows are suspect; since a wrong CRS makes every row outside, the existing "no valid rows" guard already blocks commit. (6) The OGR adapter's PDO transaction guard stays unwired — `AuthenticateMiddleware` still holds the request transaction and parsing is inline, so the caveat remains documented for a future background-job parser.
- **Failed approaches**: (1) An early PHPStan run flagged the test's `$rows[0]['geometry']['type']` because a row's geometry is nullable; the assertion was rewritten to narrow the geometry/coordinates first. (2) `--filter ShapefileImportTest` reported "No tests executed" because this project's PHPUnit run must name the `tests/Api` directory explicitly (`vendor/bin/phpunit tests/Unit tests/Integration tests/Spatial tests/Api`), which is how the full-suite number is produced.
- **Follow-up items**: TASK-125 (DXF/CAD import — entity→layer mapping, dropped-entity report, mandatory CRS declaration, `CAD_IMPORT` provenance, survey points as UNVERIFIED candidates) is next, then TASK-126 (control-point bulk import). TASK-104a (version-compare/history UI) still queued.

## TASK-123: GeoJSON and CSV importers (Phase 16, 2026-09-27)
- **What shipped**: `backend/src/ImportExport/Domain/CsvRowReader.php` — a native CSV reader whose first row is the header and whose coordinate columns are named through the job's `options.coordinate_columns` (`{latitude, longitude}` → `Point[lon, lat]`, or `{x, y}` → `Point[x, y]`; flat `latitude_column`/`longitude_column`/`x_column`/`y_column` also accepted). A row whose coordinate cells are missing or non-numeric keeps a null geometry and is rejected per-row (`VR-32`) rather than dropped or coerced. The `RowReader` interface gained an `$options` argument, and `ImportJobService::readerFor()` now resolves GeoJSON or CSV (OGR formats still return a clear `IMPORT_INVALID`). Per-row validation was extended to run the TASK-043 `GIS\Domain\AttributeValidator` against `app.gis_layer_fields` on every normalized row, so required/type/enum/range failures land in `staging.import_job_rows.validation` as `{rule: LAYER_METADATA, field, message}` — the existing preview and `GET /imports/{id}/errors` CSV surface them with the row number. **Tests**: `tests/Api/CsvImportTest.php` (3) + `tests/Api/GeoJsonImportTest.php` (2).
- **AC evidence**: an invalid CSV row is reported at its row number with `VR-32` and the error CSV contains the row's data; a missing-required-field GeoJSON row is reported with `LAYER_METADATA`/`name` and a wrong-type row with `population`; the valid rows commit, and an all-valid file commits without `{"partial": true}`.
- **Decisions made**: (1) The coordinate mapping lives in the job `options` (set at create or mapping) rather than the reader constructor, so one reader instance serves all jobs. (2) WKT/`geometry`-column CSV sources are not guessed at — only explicit lat/lon or x/y columns produce geometry; anything else is a per-row rejection, consistent with "the API never guesses". (3) Layer-metadata validation reuses the existing TASK-043 validator instead of duplicating type rules, keeping one source of truth with the feature API. (4) `PARCEL`/`CONTROL_POINT` commits remain a clean `IMPORT_INVALID` (writers are TASK-125/126).
- **Failed approaches**: (1) Parsing CSV line-by-line with `str_getcsv` mis-handles quoted embedded newlines; switched to `fgetcsv` over a `php://temp` stream. (2) An early GeoJSON error-CSV assertion expected a *valid* row to appear in the error report — the report lists only rejected rows, so the assertion was inverted to prove valid rows are absent.
- **Follow-up items**: TASK-124 (Shapefile/KML/GeoPackage via the OGR adapter, including `.prj` as a *suggestion* and the area-of-use preview check) is next. TASK-125 (DXF/CAD) and TASK-126 (control-point bulk import) follow. TASK-104a (version-compare/history UI) still queued.

## TASK-122: Import job lifecycle (Phase 16, 2026-09-27)
- **What shipped**: `backend/src/ImportExport/` — the whole upload → detect → declare-CRS → map → validate → preview → commit pipeline from `api.md` §10. `Application/ImportJobService.php` detects the format with the TASK-121 `OgrAdapter`, persists the source through a new `DocumentService::storeImportSource()`/`readContent()` pair (content-addressed, outside the web root, linked as `import_jobs.source_document_id` for FR-179 provenance), stages every row into `staging.import_job_rows` with PostGIS validation (`ST_IsValid`, `ST_Transform` from the declared SRID into 4326, and a target-layer geometry-type check), exposes a paginated preview and a CSV error report, and commits valid rows to `app.gis_features` with `provenance = IMPORTED_GIS` inside the ambient transaction. `Domain/RowReader.php` + `Domain/GeoJsonRowReader.php` do native parsing (a format-specific reader is selected per job). `Http/ImportController.php` + routes under `import.execute`; migration `20260927000002_add_import_job_idempotency` adds `import_jobs.idempotency_key` behind a partial unique index. **Tests**: `tests/Api/ImportCrsGuardTest.php` (6) and `tests/Api/ImportPipelineTest.php` (4).
- **AC evidence**: `CRS_REQUIRED` on a missing `declared_crs` and `CRS_UNSUPPORTED` on an unregistered one (FR-252/VR-52); the pipeline test asserts `app.gis_features` is empty after validation and only fills after commit; a commit with invalid rows is refused unless `{"partial": true}`; a replayed `Idempotency-Key` returns the original result and inserts no duplicates.
- **Decisions made**: (1) The source file is stored through the existing content-addressed document store rather than a private directory — that reuses TASK-107's guarantees and makes `source_document_id` on both the job and the imported features real. (2) `storeImportSource()` uses its own GIS-exchange allow-list instead of widening the document whitelist, so PDF/JPEG upload validation is untouched. (3) Only GeoJSON is wired to a reader in this task; CSV belongs to TASK-123 and the OGR-backed formats to TASK-124, and `validate()` returns a clear `IMPORT_INVALID` for a format with no reader yet. (4) Only `FEATURE` commits so far (into `app.gis_features`); PARCEL/CONTROL_POINT writers are TASK-123/126. (5) The OGR adapter's PDO transaction guard is left unwired in DI: `AuthenticateMiddleware` opens a transaction for every authenticated request and TASK-122 parses inline, so wiring the guard would block all imports — it is documented for TASK-124+ when parsing moves out of the request transaction.
- **Failed approaches**: (1) The first `validate()` passed a null `field_mapping` into `applyFieldMapping()` (the no-mapping identity case) → 500; fixed with `?? []`. (2) The test helpers used lowercase `import_test_*` filenames that did not match the cleanup prefix, leaving jobs that blocked the layer delete on an FK — renamed to the tracked prefix. (3) The document storage default `/var/www/document-storage` is not writable for `www-data`, so the tests now override `DOCUMENTS_STORAGE_DIR` to a `/tmp` path, matching `DocumentAccessTest`/`UploadValidationTest`.
- **Follow-up items**: TASK-123 (GeoJSON/CSV importers) is next — it adds `CsvRowReader` (coordinate-column mapping) and per-row validation against `gis_layer_fields` metadata on top of this lifecycle. TASK-124 wires the OGR-backed readers. TASK-104a (version-compare/history UI) still queued.

## TASK-121: OGR adapter and format detection (Phase 16 opener, 2026-09-27)
- **What shipped**: `backend/src/Core/Geo/OgrAdapter.php` (NEW) — the single, replaceable boundary around the GDAL binaries (ADR-11). **No shell is ever involved**: commands are passed to `proc_open()` as an *argument array* (PHP 7.4+), which spawns the binary directly; there is no command string for a shell to parse, so no argument value (filename, layer name, anything) can be reinterpreted as shell syntax — strictly stronger than the `escapeshellarg()` the task suggested. `run()` polls the process, `proc_terminate()`s it on timeout and **discards partial output** rather than parsing it. `withSandbox(callable)` hands the caller a fresh random subdirectory under `sys_get_temp_dir()` and removes it in a `finally` (on success *and* on exception). `detectFormat()` classifies GeoJSON / CSV / KML / DXF / zipped-Shapefile / GeoPackage by magic bytes first (ZIP signature, SQLite+`GPKG` application id) then extension/content; a corrupt or truncated `.zip`, or a `.zip` with no `.shp`/`.gpkg`, raises `IMPORT_INVALID` with a useful message and never leaks a PHP warning. `probe()` / `inspect()` run `ogrinfo -ro -so -al -json` (via GDAL's `/vsizip/` virtual path for archives) and return a structured `format / driver / layers / feature_count / crs / error` result, with GDAL's numeric severity prefixes stripped from diagnostics. `convert()` wraps `ogr2ogr` for TASK-124. An optional `PDO` guard refuses to run inside an open transaction (architecture.md §19). **Tests**: `tests/Unit/OgrAdapterTest.php` (21) — mockable executor, hostile-filename argv integrity, probe args + parsing, non-zero-exit clean error, `/vsizip/` target, real timeout + partial-output discard, sandbox cleanup on success/exception, detection of every format, malformed/truncated/empty archives, transaction guard; `tests/Integration/OgrFormatTest.php` (5) — real GeoJSON, `ogr2ogr`-built zipped Shapefile and GeoPackage, CSV, malformed archive, skipping with a marked note where GDAL is absent.
- **Decisions made**: (1) Confirmed GDAL 3.13.3 ("Iowa City") is already in the php-fpm image at `/usr/bin/ogr2ogr`, so the pure-PHP-fallback branch in `next_task.md` was unnecessary — the real binaries are used and integration tests are live rather than skipped. (2) `proc_open` argument-array form over `escapeshellarg` — the AC says "no shell injection is possible", and with no shell there is no injection surface at all. (3) The transaction guard is injected as `?PDO` (unwired until TASK-122 supplies it from DI); it is a hard `ApiError` rather than a silent no-op. (4) `coordinateSystem` is read from `geometryFields[0]` (where GDAL actually nests it) with a layer-level fallback and a WKT regex fallback for drivers that emit no `projjson.id`.
- **Failed approaches**: (1) The first `probe()` read `layers[].coordinateSystem` and returned `crs = null` for every real dataset — GDAL puts the CRS under the *geometry field*, which the mocked unit fixture had not modelled; the mock was corrected to the real shape so the unit test actually guards it. (2) A first DXF content-sniff condition relied on `&&` binding without parentheses (correct but unclear); parenthesised.
- **Follow-up items**: TASK-122 (import job lifecycle) wires `OgrAdapter` into DI with the request PDO for the transaction guard. TASK-124 (Shapefile/KML/GeoPackage importers) consumes `convert()`/`inspect()`. TASK-104a (version-compare/history UI) still queued. Note: the full suite depends on the seeded fixtures being present (`FixtureLoadTest`/`SeederTest`); the local DB had lost them and a `phinx seed:run` restored them — the suite is green afterwards.

## Phase 15 UI: Split, Consolidation & Lineage views (2026-09-25)
- **What shipped**: Completed the frontend implementation of Phase 15 (TASK-116, TASK-117, TASK-118).
  - **TASK-116 Split UI**: `SplitTab.tsx` and `SplitLineMap.tsx` in `frontend/src/features/parcels/components/` with derivation method selector (`MAP_SPLIT_LINE`, `TECHNICAL_DESCRIPTION`, `SURVEY_GEOMETRY`, `IMPORTED_GEOMETRY`), interactive map-based split-line drawing with parcel vertex snapping, preview button triggering `POST /parcels/{id}/split` with `dry_run=true`, `<ValidationBlock>` for VR-35..VR-39 checks and warnings, child area breakdown table with percentage shares, and commit button gated strictly on a passing preview of the current inputs (`canCommitSplit`/`previewStateKey`).
  - **TASK-117 Consolidation UI**: `ConsolidationTab.tsx` and `ConsolidationMap.tsx` with multi-parent parcel selector, live map rendering parent parcels with problem parcel highlighting in red (`offendingParents` extracts parent indices from VR-41/VR-42 failure messages), union preview geometry, area reconciliation display (parents sum vs union area and difference), allow_multipart toggle, and new parcel form.
  - **TASK-118 Lineage view**: `LineageTab.tsx` with layered genealogy graph layout (`layoutLineage`), status badges, parcel areas, effective dates, edge relationship labels, depth/direction selectors, prominent banner warning on truncated graphs (`data.truncated`), clickable node links, and JSON graph export.
  - **Wiring & Integration**: Mounted tabs in `ParcelEditorPage.tsx` (`split`, `consolidate`, `lineage`), updated `PARCEL_TABS`, fixed oxlint duplicate key in `AttributeTable.tsx` and uninitialized variable ordering in `FeatureGridPage.tsx`.
  - **Tests**: `SplitTab.test.ts` (12 tests), `ConsolidationTab.test.ts` (7 tests), `LineageTab.test.ts` (5 tests), all 89 frontend Vitest tests pass, `tsc -b && vite build` clean.
- **Decisions made**: (1) Gated commit buttons using pure contract functions (`canCommitSplit`, `canCommitConsolidation`) so gating is testable without DOM. (2) Extracted parent indices from backend VR-41/VR-42 failure messages to drive map highlighting without requiring backend payload schema changes.
- **Follow-up items**: Phase 16 TASK-121 (OGR adapter) and TASK-104a (version-compare/history UI pass).

## Phase 15 backend + TASK-103 verification (2026-09-25)
- **What shipped**: Phase 15 backend completed and the whole tree verified green on the Docker stack. **TASK-111** `SplitValidator` — fixed the `overlap_sqm`→`overlap_area_sqm` alias (VR-36 was silently passing); **TASK-113** `ConsolidationValidator` rewritten — per-parent `probe()` (one round trip each, so a parse failure cannot abort the combined statement), `measureOverlaps()` (pairwise intersections + union parts/area + convex-hull area for the VR-42 gap metric), VR-44 evaluated BEFORE the spatial rules with explicit "Not evaluated" markers, SRID-gated `ST_Area(geom::geography)` (geography throws on non-4326, which had misclassified EWKT as unparseable); **TASK-114** `ConsolidationService` — unknown-parent 404 before the VR-40 count check, `readParents()`/`lockParents()` return caller input order while still locking in deterministic id order (deadlock rule), new-parcel INSERT builds the union from a `parents` CTE (the bare `geom` referenced the target table → 42703), dry-run payload returns the plain `Polygon` union (no `ST_Multi`), `ST_AsGeoJSON` integer-precision-arg bug fixed; **TASK-112** `SplitService` — `supersedeParent` tolerates the accept-computation version row via `ON CONFLICT (parcel_id, version) DO NOTHING`, `readParentGeometry` distinguishes 404 (unknown id) from 400 (NULL geom); **TASK-115** `LineageController` rewritten — undirected discovery + Bellman-Ford-style relaxation with sibling-cost-0 generation-depth semantics (root 0, parents/children/siblings 1), explicit truncation flags, direction/depth validation; **migration 20260925000001** applied (idempotent VR-45 cycle-guard trigger + `parcel_operations.idempotency_key` with unique partial index). **TASK-103 verification**: backend suite + full DB now green on Docker. Test hardening: rate-limit buckets truncated in `TestCase::setUp` (shared testuser identity meant the whole suite drew from one `general` 300/min bucket → late classes 429'd), rollback-test cleanup now deletes relationships before operations (FK order), split-line BOX regex accepts `ST_Extent`'s no-space-after-comma format (the regex never matched → degenerate [0,0] split line → VR-35), latitude-typo fixtures corrected (0.009°→0.0009° span, areas 10× too large), TD-split fixture now accepts its computation and derives the east half as parent-minus-west-host, dead `parses()` removed from the validator. Full suite: **471 tests / 2086 assertions, 0 failures**; frontend **65/65 + tsc + vite clean**.
- **Decisions made**: (1) VR-42 uses hull-minus-Σ as its gap metric because the union of disjoint parents always equals Σ areas — the gap only shows as a hollow in the convex hull. (2) VR-44 short-circuits VR-41/42/43 with "Not evaluated" failures rather than guessing cross-SRID transforms. (3) The new parcel's code derives from the caller's FIRST parent, not the lowest uuid — input order is the user-facing contract. (4) The split pre-state version row dedupes instead of shifting versions: accept-computation already recorded the current state, and version rows are append-only per the TASK-104 convention. (5) `TestCase::setUp` truncates `app.rate_limit_entries` globally; RateLimitTest is unaffected (it builds its own middleware with a dedicated bucket).
- **Failed approaches**: (1) The dry-run union first used `ST_Multi` — the test (correctly) expects `Polygon` for two touching squares; `ST_Multi` belonged only on the committed row. (2) `readParents` originally relied on SQL `ORDER BY id`, which broke the first-parent code-seeding test — input order must be restored in PHP. (3) The TD-split fixture subtracted the parent's own computation from itself → empty east child; the correct operand is the west host parcel. (4) Debug-probe runs left stray control points at the NearestPointTest fixture location, polluting two spatial tests — cleaned up and a reminder to keep probes inside `setUp`-tracked fixtures.
- **Follow-up items**: Phase 16 TASK-121 (OGR adapter) is next per next_task.md — first check whether GDAL exists in the php-fpm image. TASK-116/117/118 (split/consolidation/lineage UI) and TASK-104a (version-compare/history UI) remain queued. The Playwright two-role spec for TASK-103 still needs a GUI run (Docker has no display; deferred from the original TASK-103 AC).

## TASK-103: Workflow UI, reviewer inbox, notifications (Phase 13/14)
- **What shipped**: **Backend** — `Parcels/Http/NotificationsController.php` (NEW): GET /notifications (own rows only, `?unread=1` filter, limit/offset with range clamping, unknown query params rejected 400, payload carries `total` + `unread_count` for the bell) and POST /notifications/{id}/read (stamps read_at; idempotent 200; a foreign id is 404 NOT_FOUND because a notification does not exist for the caller — never 403). Routes in `config/routes.php` require authentication only; scoping is the controller's `user_id` predicate (app.notifications has no RLS policy). **Frontend** — `features/parcels/api/transitionsApi.ts` (available/history/transition + `buildTransitionPayload`, which enforces FR-137 client-side: a required reason/comment that is blank throws BEFORE any request is sent); `features/parcels/components/WorkflowActionBar.tsx` — renders one button per transition the server reports `allowed` (unavailable transitions are ABSENT from the DOM, never disabled — FR-103), reason/comment modal whose confirm stays disabled until required input is non-blank, mutations invalidate `['parcel', id]` + `['parcels']` + `['review-inbox']` per frontend.md §11; mounted in the editor's sticky bar (replacing the old ad-hoc Submit button; blocked while local edits are unsaved because every transition bumps the version); `features/notifications/` — `notificationsApi.ts` + `NotificationBell.tsx` in the app header (30 s poll, unread badge, dropdown, optimistic mark-read per frontend.md §11, clicking a PARCEL row opens the editor); `features/parcels/pages/ReviewerInboxPage.tsx` at /parcels/inbox (parcel.review-gated nav link + route) listing SUBMITTED/UNDER_REVIEW parcels newest-activity-first. **Tests** — backend `Api/NotificationsApiTest` (6: own-rows-only, unread filter + counts, mark-read idempotent, foreign 404, unknown param 400, limit clamp); frontend `transitionsApi.test.ts` (8) + `WorkflowActionBar.test.tsx` (5, incl. FR-103 absence and FR-137 disabled-until-filled); Playwright `e2e/specs/phase14-workflow.spec.ts` — the TASK-103 two-role gate: an encoder role (submit only) sees only SUBMIT and submits from the editor; a reviewer role picks the parcel from the inbox, walks START_REVIEW → VERIFY → APPROVE with the comment gate asserted (confirm disabled until filled), RETURN demands a reason before sending, and the creator's bell shows the WORKFLOW_* notifications.
- **Decisions made**: (1) The bell polls (30 s) rather than websockets — the notifications table has no push story yet and the roadmap defers email/SMS adapters likewise. (2) Notification scoping is a WHERE clause, not RLS: app.notifications is user-private by definition and adding RLS mid-task would touch the support-tables migration for no defence gain (the controller is the only reader). (3) `buildTransitionPayload` lives in the api module (not the component) so the FR-137 contract is unit-testable without DOM. (4) The editor's workflow actions are disabled while the form is dirty (a transition bumps the version and would strand the draft); the old `parcel-submit-action` button (audit G-1 interim path via POST /submit) is superseded by the engine-driven SUBMIT which runs the same validation_passed guard. (5) Reviewer inbox fans one query per status (list endpoint filters a single status) and merges client-side under the `['review-inbox']` key so transitions invalidate it.
- **Failed approaches**: (1) App.tsx nav edit swallowed the `</nav>` closing tag (tsc caught it immediately). (2) First WorkflowActionBar test used raw `element.click()` — jsdom didn't flush React state; switched to `fireEvent`. (3) `parcel.submit` for the E2E encoder initially assumed a DATA_ENCODER grant — the catalogue grants nothing to functional roles, so the spec provisions dedicated E2E_ENCODER_ROLE / E2E_REVIEWER_ROLE rows with explicit permission lists. (4) Docker daemon down on the dev machine again — backend suite not run locally; PHP syntax of the controller/routes/test verified with local PHP 8.3.33 `php -l`; frontend tsc/vitest/oxlint/vite all green.
- **Follow-up items**: Run the full backend suite + the new Playwright spec once the Docker stack is up (suite was 420 green before this task; +6 notifications tests expected). Version-compare/history UI pass (version-compare + geometry-diff map overlay + timeline rendering, deferred from TASK-105) remains the next task after TASK-103 verification. Wire `app.request_id` into approval_actions (open from TASK-100).

## TASK-102: Approved-edit cycle (FR-141)
- **What shipped**: Editing an APPROVED parcel via `PATCH /parcels/{id}` now runs the seeded `REOPEN` workflow transition (APPROVED→DRAFT, `parcel.approve`, reason required — new row in the SystemSeeder FR-135 matrix) before the edit applies, then lands the edit as a further version bump. `WorkflowEngine::reopenApprovedRecord()` drives the cycle through the engine's own permission gate, FR-137 reason rule, `approval_actions` history, audit, and FR-140 creator notification (`WORKFLOW_REOPEN`); the target state comes from the `WORKFLOW_APPROVED_EDIT_TARGET_STATE` setting when it names a non-terminal state of the definition, else DRAFT. `ParcelController::update()` gained the APPROVED branch: an explicit `status` field is rejected with 400 `INVALID_STATE` before the reopen consumes the If-Match, If-Match is verified against the approved version, and the approved state is captured as an append-only `audit.parcel_versions` row so the previously approved version stays retrievable and never rewritten (the FR-141 AC). The engine-side transition bumps the version, so a reopened+edited request totals +2. api.md §8.1 documents the contract. StateMachineTest's mirror matrix updated; `Api/ApprovedEditTest` 5 tests / 63 assertions. Full suite: **420 tests green** (was 415).
- **Decisions made**: (1) The reopen is a transition **row**, not engine code — the matrix stays the single source of truth, and the UI will later read it via `availableActions` for free. (2) DRAFT is the default reopen target; `WORKFLOW_APPROVED_EDIT_TARGET_STATE` reconfigures it and is validated (non-terminal state of the definition) with fail-safe fallback to DRAFT on garbage — the setting is env config, never a request field. (3) The approved-state version capture happens in the controller just before the reopen because the TASK-100 engine never wrote `parcel_versions` rows; capturing keeps the fix local instead of changing the engine's transition semantics mid-task. (4) Seed-reconciliation hardening: `workflow_states` DELETE+re-INSERT would have broken `workflow_instances.current_state_id` FKs on any database with workflow history, so state inserts are now upsert-only (`ON CONFLICT DO UPDATE` refreshing name/flags/display_order).
- **Failed approaches**: (1) First cut checked If-Match AFTER the reopen — every approved-edit 409'd because the engine had already bumped the version; the concurrency check must validate the approved version BEFORE the reopen consumes it. (2) First test asserted `version + 1` — actually +2 (reopen + edit); the edit's UPDATE bumps again by design. (3) The lock-row SELECT omitted `status`, so the APPROVED branch never fired and the edit applied in place at the old status — caught by the first test run ("Parcel version not found" because the approved version didn't exist until the capture was added). (4) `createMockUser` reuses the shared 'testuser' fixture whose grants accumulate across tests, so the permission test's "encoder" silently held `parcel.approve` and got 200 instead of 403 — replaced with a dedicated `wf_ae_encoder` user/role built in-test (the same fixture trap documented in the Phase 8–12 audit fixes and TASK-104..108 entries).
- **Follow-up items**: TASK-103 (workflow UI + reviewer inbox) is next; the UI must surface REOPEN from `availableActions` with a mandatory-reason prompt. Map-overlay rendering of the geometry diff (deferred from TASK-105) lands with the same UI pass. A migration carrying the REOPEN row for CI/test environments remains open until the workflow matrix moves from seeds into migrations (pre-existing CI gap, also affects the TASK-100/101 rows).

## TASK-104..108: History timeline, version diff, restore, documents (Phase 14)
- **What shipped**: TASK-104 `HistoryTimelineController` — GET /parcels/{id}/timeline merges parcel_versions + audit_logs + approval_actions into one newest-first stream (VERSION/AUDIT/WORKFLOW kinds, actor, detail, old/new), plus a downloadable JSON bundle export. TASK-105 `VersionDiffService` (pure domain) — position-based vertex pairing identifying moved/added/removed vertices with from/to coordinates, exterior-ring extraction (Polygon/MultiPolygon, closing point dropped), field-level diff; GET /parcels/{id}/versions/{v}/compare. TASK-106 restore verified against the ACs (additive, monotonic, If-Match, geometry round-trip). TASK-107 `DocumentService` — extension allow-list + magic-byte + MIME sniff + size cap, SHA-256 de-duplication (de_duplicated flag), randomised storage keys outside the web root and never in API responses, classification, entity links; multipart POST /documents, GET/links endpoints; migration `20260924000001` (app.document_download_tokens). TASK-108 — HMAC-signed short-lived single-use download tokens (nonce persisted, atomically claimed on consume), expired/reused/tampered → 404, RESTRICTED/SENSITIVE_PERSONAL without document.download_restricted → 404 (existence hidden), restricted downloads audited. Tests: HistoryTimelineTest (3), GeometryDiffTest (7), RestoreTest (4), UploadValidationTest (6), DocumentAccessTest (5). Full suite 415 green; frontend 52/52 + tsc/vite clean.
- **Decisions made**: DocumentService takes raw content (not tmp paths) so it is testable without filesystem fixtures; MIME sniff treats octet-stream as inconclusive when magic bytes already matched exactly (libmagic cannot resolve minimal synthetic files) while still rejecting dangerous/conflicting types; signed download tokens reuse the JWT secret as HMAC key; consumption is a single atomic UPDATE … WHERE consumed_at IS NULL so reuse fails under concurrency; the upload response strips storage_key (FR-157).
- **Failed approaches**: consumeDownload read storage_key via DocumentService::get() which did not SELECT the column — every download 500'd with "blob missing"; fixed by adding storage_key to the SELECT. DocumentAccessTest "unauthorised" caller reused the shared testuser fixture, which had accumulated SYS_ADMIN (granted document.download_restricted) from earlier runs — replaced with a dedicated GIS_EDITOR-only user created in-test.
- **Follow-up items**: TASK-102 approved-edit cycle; TASK-103 workflow UI/reviewer inbox; version-compare + timeline UI pass; wire app.request_id into approval_actions.

## TASK-100/101: Workflow engine + parcel transitions API (Phase 13)
- **What shipped**: `backend/src/Parcels/Workflow/WorkflowEngine.php` — table-driven state machine reading the seeded FR-135 matrix (9 states, 12 transitions) from `app.workflow_*`; per-transition permission gate, mandatory reason/comment (FR-137), `validation_passed` guard re-evaluating the TASK-096 checklist at execution time (closure failures map to `CLOSURE_EXCEEDS_TOLERANCE`), unknown guard names fail closed, history in `app.approval_actions` + audit, FR-140 notifications to the parcel creator (actor excluded). `WorkflowController` exposes POST/GET `/parcels/{id}/transitions` and GET `/transitions/history`. SystemSeeder now reconciles legacy partial workflow rows idempotently (DELETE + re-INSERT of the matrix). Full approval lifecycle DRAFT→SUBMITTED→UNDER_REVIEW→VERIFIED→APPROVED→PUBLISHED exercised in tests, incl. approval blocked when the computation is corrupted after SUBMIT, and the RETURN cycle. Tests: `Unit/StateMachineTest` (8), `Api/TransitionPermissionTest` (5), `Api/WorkflowFlowTest` (5). Full suite 390 green.
- **Decisions made**: guards are a PHP allow-list (`GUARDS` const) so transition data cannot invoke arbitrary logic; the engine re-checks permissions itself (route middleware only requires parcel.view so callers can list available actions); SUPERSEDED has no incoming transition row (FR-135a). The TASK-100 engine supersedes the interim PATCH guard from the Phase 8–12 audit fixes, which stays as defense-in-depth.
- **Failed approaches**: the notification test initially set `created_by` to the acting user — the engine correctly skips self-notification, so no row appeared; fixed with a distinct creator user. Mock users persist between runs (users/roles/role_permissions), so test cleanup now deletes `wf_viewer`/`wf_creator` and uses `ON CONFLICT DO NOTHING` on role_permissions.
- **Follow-up items**: TASK-102 (approved-edit cycle) next; TASK-103 workflow UI; wire `app.request_id` into approval_actions from the middleware attribute (currently session var only).

## Phase 8–12 audit fixes (2026-09-24)
- **What shipped**: (G-1) interim workflow guard in `ParcelController::update()` — status transitions via PATCH are restricted to DRAFT↔RETURNED (`INVALID_STATE` otherwise), closing the second, unguarded submit path; the frontend editor Submit button now calls `validationApi.submitParcel()` (`POST /parcels/{id}/submit`) so the TASK-098 validation guard always runs. (G-4) removed the crash-looping `worker` compose service (its `backend/bin/worker.php` entrypoint never existed) and documented re-add conditions in `docker-compose.yml`. (G-5) new `GET /parcels/{id}/overlaps` endpoint reusing `OverlapDetector` — GIST-indexed overlap list with geodesic area, percentage, sliver classification, `?sliver_threshold_sqm=` override, 404 on unknown parcel, 400 on invalid threshold, `parcel.view` permission; DI wired for the detector. Tests: `tests/Api/ParcelOverlapsTest.php` (6 tests); three `ParcelVersionTest` cases that abused PATCH-status as a version bump re-pointed to attribute edits.
- **Decisions made**: chose an interim backend guard over waiting for TASK-100 because the bypass made the Phase 12 guard advisory; PATCH guard will be superseded by the workflow engine's transition table. The 403 body on the overlaps route is the Slim error shape (like ParcelSearchTest), so the permission test asserts status only.
- **Failed approaches**: initial overlaps permission test reused `createMockUser` — the shared 'testuser'/'SURVEY_OFFICER' fixture rows accumulate grants across tests, so parcel.view leaked in; fixed with a dedicated user/role/org inserted in-test.
- **Follow-up items**: TASK-100 must keep `ValidationController::submit` as the only DRAFT→SUBMITTED path; deferred audit gaps G-2/G-3/G-6/G-7/G-8/G-9 remain open.

## TASK-012: CI pipeline
- **What shipped**: Created GitHub Actions workflow (`.github/workflows/ci.yml`). Configured jobs to check out the repository, run PHPStan, PHP-CS-Fixer, and PHPUnit (against a PostGIS sidecar) for the backend. Configured parallel jobs for Vite build, Prettier formatting check, Oxlint, and Vitest for the frontend. Added composer and npm vulnerability scans.
- **Decisions made**: Added a PostGIS service container directly to the backend test job so migrations can be run on a true spatial database.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-013 (first task of Phase 2).

## TASK-013: Extensions, schemas, database roles
- **What shipped**: Created the database migration for `app.users`, `app.roles`, and `app.user_roles`. Added default-deny Row Level Security (RLS) on these tables and `audit_logs` as an authorization backstop. Created PostgreSQL roles (`app_rw`, `app_ro`, `app_migrator`). Wrote `Integration\RlsTest` proving an unprivileged query without `app.user_id` returns 0 rows.
- **Decisions made**: Relied entirely on PostgreSQL RLS with session variables (`current_setting('app.user_id')`) ensuring tenant isolation at the database level (ADR-06).
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-014 (Core entity schemas).

## TASK-014: `ref` schema and CRS registry
- **What shipped**: Created the `ref` schema and its initial tables: `psgc_areas`, `crs_registry`, and `units`. Authored `RefSeeder` to pre-populate EPSG:4326, EPSG:3857, PRS92 PTM Zones (3121-3125), Luzon 1911 Zones (25391-25395, flagged historical), and basic conversion factors for length and area. Wrote `CrsRegistryTest` and `UnitConversionTest`.
- **Decisions made**: Separated CRS logic out into `ref` schema instead of hardcoding it, ensuring that legacy and future datums can be added as data rather than code changes.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-015 (PSGC reference data load).

## TASK-015: PSGC reference data load
- **What shipped**: Added a `geom` column to `ref.psgc_areas` for storing boundary geometry. Implemented `PsgcSeeder` to mock load a hierarchy of standard region, province, city, and barangay entities. Wrote `PsgcTest` to ensure that standard queries correctly resolve upward in the hierarchy, and that foreign key constraints successfully reject orphaned boundaries.
- **Decisions made**: Stored a mock hierarchical load using Region IV-A to represent the actual full scale DB loads to test schema integrity without waiting for real gigabyte-scale datasets.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-016 (Identity and access tables).

## TASK-016: Identity and access tables
- **What shipped**: Built the remaining authorization and identity schemas per database.md (organizations, permissions, role_permissions, data_scopes, and refresh_tokens). Implemented the SchemaIdentityTest asserting the check constraint (ck_scope_target) effectively rejects data_scopes lacking a target.
- **Decisions made**: Applied a raw SQL block in Phinx to generate the PostGIS 'geom' column on 'data_scopes' and its constraint to ensure strict DB-level checking instead of only relying on application logic.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-017 (GIS core tables).

## TASK-017: GIS core tables
- **What shipped**: Built the fundamental GIS tables (gis_layers, gis_layer_fields, gis_layer_styles, gis_features) and the audit tracking table (audit.gis_feature_versions). Created complex PL/pgSQL triggers (	rg_enforce_geometry_type, 	rg_validate_attributes, 	rg_write_feature_version) to handle validation and automatic history capture at the database level.
- **Decisions made**: Deferring complex regex and max-length checking to the application layer to keep 	rg_validate_attributes performant; the trigger only strictly enforces the 
equired field constraint and presence check. Switched from Ramsey\Uuid to PostgreSQL's native gen_random_uuid() for test data creation.
- **Failed approaches**: Attempted to use Ramsey\Uuid\Uuid in tests before installing the composer package; mitigated by switching to native DB UUID generation.
- **Follow-up items**: Move to TASK-018 (Survey tables).

## TASK-018: Survey tables
- **What shipped**: Created the database schema for the entire survey subsystem: survey_plans, survey_control_points, 	echnical_descriptions, 	ie_points, 	echnical_description_courses, 	ie_lines, and the parcel_courses view. Also added the computation engine schema: parcel_computations, parcel_vertices, and coordinate_transformations.
- **Decisions made**: 	echnical_descriptions.parcel_id and parcel_computations.parcel_id were created as UUID columns, but the foreign key constraints to pp.parcels have been explicitly deferred to TASK-019 (since pp.parcels does not exist yet). The parcel_courses view was created successfully because it joins 	echnical_descriptions, avoiding direct reference to the parcels table.
- **Follow-up items**: Add the deferred parcel_id foreign key constraints to 	echnical_descriptions and parcel_computations when building pp.parcels in TASK-019.

## TASK-020: Document and workflow tables
- **What shipped**: Created support subsystem tables covering pp.documents, pp.workflow_*, pp.basemap_providers, pp.import_jobs, pp.export_jobs, pp.notifications, pp.edit_locks, and pp.system_settings. Also implemented the PostgreSQL range-partitioned table for udit.audit_logs.
- **Decisions made**: udit.audit_logs is created with native partition syntax rather than using the Phinx abstraction, as Phinx does not natively support Postgres partitions. Also handled integration tests to verify partition routing and table constraints.
- **Follow-up items**: Future cron workers will need to be configured to create upcoming partitions for audit.audit_logs continuously.

## TASK-027: Password hashing and policy

- **What shipped**: `backend/src/Auth/Hasher.php` (Argon2id hash/verify/verifyAndRehash/needsRehash) and `backend/src/Auth/PasswordPolicy.php` (static validate + isValid with length, complexity, username-containment, and breach-list rules).
- **Decisions made**: Kept hashing and policy as pure, framework-free value objects in `App\Auth`, matching the architecture rule that Auth domain code stays independent of Slim/PHP-DI. Argon2id cost parameters (memory=64MiB, time=4, threads=1) are baked into Hasher as a private const for now; policy thresholds (min 12 / max 128, breach list of 10 common passwords) are hardcoded constants.
- **Failed approaches**: N/A — tests were authored against the intended API before implementation and passed first run on the Docker stack.
- **Follow-up items**: TASK-028 (login, tokens, refresh rotation) is next. Open extension: make password policy configurable from `Config` (min/max length, breach-list source) and introduce a `BreachListChecker` interface with a pluggable backend (file/API/HIBP k-Anonymity) — FR-002 says "complexity configurable" and "breach-list check where available"; today it's a hardcoded stub.

## TASK-028: Login, tokens, refresh rotation, reuse detection

- **What shipped**: `backend/src/Auth/` — `TokenService` (HS256 JWT with claims `sub, roles, scope_version, jti, exp`, configurable TTLs, `firebase/php-jwt`), `AuthService` login/logout/refresh with refresh-token rotation and family reuse-revocation, account lockout with exponential backoff, and login/logout audit via `AuditWriter`. `refresh_tokens.token_hash` is stored as `bytea` (`decode(:token_hash,'hex')`); reuse detection matches the digest. Added a `jti` claim so two tokens minted in the same second are never identical. `MeApiTest`, `TokenRotationTest`, `LoginLockoutTest`, `DbSessionContextTest` cover the flows (the rotation test compares the binary digest via `stream_get_contents` and looks rows up with `decode(?, 'hex')`).
- **Decisions made**: Access tokens are stateless JWTs (15 min); refresh tokens are opaque, hashed with SHA-256, family-tracked, and rotated on every use — a replayed token revokes the entire family. HS256 keys must be ≥ 32 bytes (php-jwt 7.1.1 enforces; test secrets made ≥ 48 chars).
- **Failed approaches**: (1) Test tokens were previously signed with the short dev secret (24 chars) — php-jwt 7.x rejects keys shorter than 32 bytes, causing `SignatureInvalidException`; fixed by signing fixtures with a fixed long secret and forcing `Config` to pick it up via `putenv`/`$_SERVER`/`$_ENV['JWT_SECRET']`. (2) Direct comparison of `refresh_tokens.token_hash` against a hex string failed against the `bytea` column — fixed by comparing through `decode(?, 'hex')`.
- **Follow-up items**: TASK-029. Note for later tasks: fixture secrets must live with the tests, never the dev `.env`.

## TASK-029: Authenticate middleware and `SET LOCAL` DB session context

- **What shipped**: `backend/src/Core/Http/Middleware/AuthenticateMiddleware.php` — verifies the bearer token, resolves the user, and issues `SET LOCAL app.user_id/role_codes/scope_ids/request_id` inside the request transaction; unauthenticated requests never open a scoped transaction. `POST /auth/refresh` re-establishes context after rotation.
- **Decisions made**: DB session context is set with `SET LOCAL` inside the business transaction so RLS (`app.fn_user_can_see/edit`) is evaluated per request with no ambient leakage; `request_id` is propagated so audit and RLS reads correlate to the HTTP request.
- **Failed approaches**: PHP-DI (v7) does **not** resolve primitive constructor parameters by entry name — `AuthenticateMiddleware::__construct(…, string $jwtSecret)` was dropped with a "not buildable" error. Explicitly sized the dependency: `\DI\autowire(...)->constructorParameter('jwtSecret', \DI\get('jwtSecret'))`.
- **Follow-up items**: TASK-030. Apply the `constructorParameter` + fully-qualified `\DI\autowire()`/`\DI\get()` pattern (see below) to any new middleware or service that takes a primitive config value.

## TASK-030: Permission resolver and Authorize middleware

- **What shipped**: `backend/src/RBAC/PermissionResolver.php` (effective permissions computed from role grants, cached keyed by `scope_version`) and `AuthorizeMiddleware` (route-level permission declarations; missing permission returns `PERMISSION_DENIED` naming the required code). `routes.php` declares permissions per route group.
- **Decisions made**: Cache key is the user's `scope_version`, so a role change invalidates the effective-permission set without a manual flush; permission *codes* (not role names) gate routes, matching the closed code set in `api.md`.
- **Failed approaches**: N/A beyond the DI notes in TASK-029. **Critical PHP-DI gotcha** for all future wiring in `config/dependencies.php`: the file is `require`d lazily inside namespace `DI\Definition\Source`, so unqualified `autowire()`/`get()` calls resolve against that runtime namespace and die with "undefined function". Always use fully-qualified `\DI\autowire(...)` / `\DI\get(...)` (compile-time `use` for classes/constants is fine).
- **Follow-up items**: TASK-031.

## TASK-031: Layer capability resolver

- **What shipped**: `LayerPermissionResolver` in `backend/src/RBAC/` resolving per-layer view/create/update/delete/approve from `app.layer_permissions` (carrying the TASK-017 row-level defaults when no explicit row exists). `LayerPermissionTest` proves a role without `can_update` on a layer cannot update its features even while holding `gis.feature.update`.
- **Decisions made**: No layer capability is derived from role name; the resolver merges explicit `layer_permissions` rows over the role's global grants, and negation is absent (grant-only model), matching `database.md` §5.
- **Failed approaches**: N/A.
- **Follow-up items**: TASK-032.

## TASK-032: Data scope resolver

- **What shipped**: `DataScopeResolver` in `backend/src/RBAC/` implementing FR-016 resolution order — explicit `NONE` deny wins, then most specific grant, else default deny — with priority `BARANGAY < MUNICIPALITY < PROVINCE < REGION < ORGANIZATION < CUSTOM_AREA < GLOBAL`. `ScopeResolutionTest` covers every row of the FR-016 truth table (NONE/VIEW/EDIT/APPROVE across PSGC levels, organisation, custom polygon, GLOBAL).
- **Decisions made** (2026-09-20, sponsor): document **GLOBAL + REGION** scope types and enforce the enum in the DB; keep `PROJECT` listed but reserved as future work; no ADR required. New migration `20260920000014_add_scope_type_checks` (a) relaxes `ck_scope_target` so `GLOBAL` rows can have no ref/geom, (b) adds `ck_scope_type` and `ck_scope_access` CHECK enums matching `database.md` §4, (c) **reconciles the RLS functions** — which previously matched `'ORG'/'PSGC'/'WRITE'` — to the documented vocabulary (`ORGANIZATION`, geographic types via PSGC-prefix match, write = `EDIT`/`APPROVE`), and (d) migrates the legacy `('PSGC','WRITE')` fixture rows to `('PROVINCE','EDIT')`. `database.md` §4 and `specification.md` FR-015 updated in lockstep; `RlsTest`/`FixtureSeeder` moved onto the valid enum values.
- **Failed approaches**: (1) Migration 14 originally applied with `ck_scope_target` re-added but the enum checks exposed legacy `scope_type='PSGC'`/`access_level='READ'|'WRITE'` rows already in the DB (from `FixtureSeeder`/`RlsTest`) — resolved by migrating rows in the migration and fixing the seeder/test rather than weakening the constraint. (2) After editing migration 14, the up branch lost its `DROP CONSTRAINT IF EXISTS ck_scope_target` during a rewrite, producing `SQLSTATE[42710] duplicate object` on re-apply — restored. (3) `phinx status` showed migration 13 (`layer_permissions`) as `down` while the table existed with a correct structure; reconciled by recording it as applied in `phinxlog` so the new migration chain resolves.
- **Follow-up items**: TASK-033. If `PROJECT` scope types are later introduced, add the routing/resolution logic and re-open this migration's constraints.

## TASK-033: `GET /me` with effective access

- **What shipped**: `MeController` returning profile, roles, effective permissions, layer capabilities, data scopes (with human-readable names), and `scope_version`, per `api.md` §2; `MeApiTest` verifies the payload and that a role change bumps `scope_version`.
- **Decisions made**: The `/me` payload is assembled from the resolvers built in TASK-030–032 plus the JWT `scope_version` claim, so the client always mirrors what the server will enforce next request.
- **Failed approaches**: (1) Envelope was imported from `App\Core\Http\Envelope`, which did not exist — the class lives at `App\Core\Http\Response\Envelope`; corrected. (2) Middleware style: MeController originally called `getUserPermissions()` on the container, but the wiring resolves effective permissions via `PermissionResolver` (`getEffectivePermissions()`); corrected.
- **Follow-up items**: TASK-034 (admin CRUD APIs; every mutation audited with actor and reason).

## TASK-034: User, role, permission, organisation, scope admin APIs

- **What shipped**: Admin CRUD + assignments across `backend/src/Users/`, `backend/src/RBAC/`, `backend/src/Organizations/`. `UserAdminService` (list/create/get/update with `If-Match` optimistic locking, deactivate as soft delete → `DISABLED` + `deleted_at` + token revocation, setRoles, getScopes/setScopes, forcePasswordReset, `effective-access` FR-018 explainer); `RoleAdminService` (CRUD + setPermissions + permission catalogue; system roles protected, role-in-use delete refused, permission changes bump holder `version` for cache invalidation); `OrganizationAdminService` (CRUD with `If-Match`, org_type/psgc validation, deactivate → `INACTIVE` refused while active children/users exist). Controllers + rewritten `config/routes.php` with per-route `AuthorizeMiddleware` (`user.manage`/`scope.manage`/`role.manage`/`system.config`). New integration tests: `tests/Integration/UserAdminTest.php` (11), `RoleAdminTest.php` (7), `OrganizationAdminTest.php` (5). Full suite: **121 tests / 306 assertions** green (was 98/228). `api.md` §3 updated with shapes and rule ids.
- **Decisions made**: (1) JSON bodies are parsed from the raw stream via a shared `App\Core\Http\Request\JsonBodyParser` — `Slim\Psr7\ServerRequestFactory::createServerRequest()` never populates `getParsedBody()` for `application/json`, so the original `getParsedBody()`-only helper failed at runtime; no body-parsing middleware exists in this Slim version. (2) Services must not open nested transactions: `AuthenticateMiddleware` wraps each authenticated request in a PDO transaction, so a new `App\Core\Db\DbTransaction` ambient helper (`begin()` returns `owned`; `commit()`/`rollback()` no-op for non-owners) replaces direct `beginTransaction()` calls. (3) `ApiError` carries its string code via a dedicated `errorCode` property — `RuntimeException::getCode()` is typed int and returned 0. (4) Role updates deliberately do not use `If-Match` (the `app.roles` table has no `version` column); users and organisations do. (5) `effective-access` supports only `entity_type=parcel` for now (FR-016 priority list applied), other entity types rejected with VR-SCOPE-310.
- **Failed approaches**: (1) First `UserAdminService` draft this session was over-structured and error-prone; rewritten to a compact single-file service with `fail()`-based field validation. (2) Initial test run exposed the JSON-body gap: every POST/PUT returned 422 "body required", cascading into `/users//…` route 404s and an empty `data.id`; fixed with the shared parser above. (3) Teardown FK ordering in tests — `data_scopes.granted_by` and `parcels.org_id` reference users/organisations, so scopes/parcels must be deleted before their referencing root rows.
- **Follow-up items**: TASK-035 (rate limiting, CSRF, security headers, CORS). `api.md` §13 requires every endpoint to gain an OpenAPI entry and a contract test; permission-scope enforcement returns 404 (not 403) for scope violations once the feature APIs exist — the admin shield currently uses 403 `PERMISSION_DENIED`, which is correct there.

## TASK-035: Rate limiting, CSRF, security headers, CORS

- **What shipped**: transport-hardening layer in `backend/src/Core/Http/Middleware/`. `RateLimitMiddleware` (per-identity 1-minute token buckets per route class: auth 10, search 60, calculate 30, lineage 10, import commit 5, tiles 600, general 300; DB-backed by new `app.rate_limit_entries`, migration `20260920000016`, shared across php-fpm workers; keyed by JWT subject or client address; over-limit → 429 `RATE_LIMITED` + `Retry-After`; `/health`+`/metrics` exempt; opportunistic old-window purge). `CsrfMiddleware` (double-submit `X-CSRF-Token` ≈ `csrf_token` cookie with `hash_equals` + Origin check; inert unless a `refresh_token` cookie is present, i.e. exactly the cookie-authenticated surface). `SecurityHeadersMiddleware` (HSTS, CSP without `unsafe-inline`, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `Permissions-Policy`). `CorsMiddleware` rewritten to an explicit `CORS_ALLOWED_ORIGINS` allow-list with exact-origin reflection + `Allow-Credentials` + X-CSRF-Token allow/expose; unlisted origins get no CORS headers; OPTIONS preflight → 204. Wired through `config/dependencies.php` (`use ($allowedOrigins)` closure), `public/index.php`, `tests/TestCase.php`; `Config` gains optional `CORS_ALLOWED_ORIGINS`; nginx `default.conf` gains `limit_req` zone (burst 100, ~30 r/s). New tests: `tests/Api/RateLimitTest.php`, `CsrfTest.php`, `SecurityHeadersTest.php` (18). Full suite: **139 tests / 366 assertions** green (was 121/306).
- **Decisions made**: (1) **Counter store = PostgreSQL, not the in-process PSR-16 cache** — `ArrayAdapter` is per-request in php-fpm, so buckets would never persist across workers; a DB table with `ON CONFLICT` increment is simple, shared, and testable. (2) **Middleware ordering in Slim**: decorators (`RequestId` → `SecurityHeaders` → `Cors` → `Csrf` → `RateLimit`, added outwards) must sit **outside** the error middleware, otherwise 4xx/5xx responses generated by the error handler skip decoration — a 404 or 429 would silently lose security headers/`X-Request-Id`. (3) **Rate limit classification is path-based, pre-routing** — a throttled bucket 429s before the router ever runs, which also throttles the not-yet-registered `/auth/login` (that route doesn't exist yet; the `auth` bucket keys by address until then). (4) **CSRF is cookie-triggered**: since the API is Bearer-authenticated and only refresh/logout ride cookies, `CsrfMiddleware` activates solely when `refresh_token` is present — no ink wasted on Bearer traffic, and the guard is provable today via tests without the auth HTTP routes. (5) nginx `limit_req` is coarse flood control only; the documented per-user limits live in PHP.
- **Failed approaches**: (1) Initial `dependencies.php` wiring referenced a shared `$allowedOrigins` closure from inside factory closures without `use ($allowedOrigins)` → php-di "Value of type null is not callable" at container time; fixed by capturing the closure in each factory. (2) `JWT::encode` with an 11-char key threw "Provided key is too short" (firebase/php-jwt v7 enforces ≥32 bytes for HS256); the per-user headless test now uses a long test secret — first draft also passed different secrets to encode vs decode so both users shared the IP bucket. (3) `CsrfTest` targeted `POST /api/v1/me` which is a GET-only route (405 Method Not Allowed instead of the expected 401) — moved to `POST /api/v1/users`. (4) A `.env` comment I added contained `(` / `;`, which broke `parse_ini_file` (INI treats `;` as a comment start and the oldest Safari-era `#` handling chokes on bare text before it) → `Config::load` failed with "Missing required configuration secret: JWT_SECRET"; comment removed.
- **Follow-up items**: TASK-036 (TOTP MFA). When the auth HTTP routes (`login`/`refresh`/`logout`) land, wire `CsrfMiddleware`'s cookie trigger and confirm the refresh CSRF flow end-to-end; set real `CORS_ALLOWED_ORIGINS` in production env; revisit `phpstan` (currently fails pre-analysis in this environment: exit 255 on deprecated `checkMissingIterableValueType`/`checkGenericClassInNonGenericObjectType` config — pre-existing, not caused by this task; verification stays on `php -l` + PHPUnit).

## TASK-028–033: full-suite verification

On 2026-09-20 the complete suite ran **98 tests / 228 assertions, OK** on the Docker stack (PHP 8.3.33, PHPUnit 11.5.56) via `docker compose exec -T php-fpm vendor/bin/phpunit --colors=never tests`. The DI wiring now binds `CacheInterface` to `Symfony\Component\Cache\Psr16Cache(new ArrayAdapter())` (ArrayAdapter is PSR-6; it must be wrapped for the PSR-16 contract). `composer audit` is clean after firebase/php-jwt ^7.0 + symfony/cache ^7.0.

## TASK-036: TOTP MFA

- **What shipped**: Full RFC 6238 TOTP MFA. DB: `20260920000017_create_login_functions` (three `SECURITY DEFINER` functions owned by `app_migrator` — `fn_login_lookup`, `fn_login_record`, `fn_user_profile` — with `SET search_path = app, public`, `EXECUTE` revoked from PUBLIC and granted to `app_rw` only) and `20260920000018_add_requires_mfa_to_roles` (`roles.requires_mfa`, SYS_ADMIN back-filled mandatory). Code: `Totp` (pure, reference-vector-tested), `MfaService` (libsodium `crypto_secretbox`, key = sha256(`MFA_ENCRYPTION_KEY`), base64 `nonce‖cipher` in `users.mfa_secret_enc text`; fail-closed on missing key; one-time 5-min `mfa_token`), the LoginService/TokenService MFA gate and `POST /auth/login|mfa/verify|refresh|logout`, plus admin MFA endpoints (`GET /users/{id}/mfa`, `POST /users/{id}/mfa/enroll|disable`). Tests: `TotpTest`, `AuthFlowTest`, `MfaAdminTest` (27 new). Full suite: **151 tests** green, no warnings (was 139/366).
- **Decisions made**: (1) **Pre-authentication reads run in `SECURITY DEFINER` functions, not RLS reads** — `app.users` is RLS-guarded, and login must read a user row before any `app.user_id` can be set; a null-context SELECT returns nothing. The functions are owned by the RLS-exempt `app_migrator`, expose only fixed column lists through exact-match keys, and their EXECUTE is limited to `app_rw` (ADR-21). (2) `mfa_required = mfa_enabled OR EXISTS(role with requires_mfa)` is computed in SQL so the gate cannot drift from the role flags. (3) **Enrolled secret never leaves the DB as plaintext**: only the enrollment response returns the base32 seed, exactly once; every later read returns status booleans (`mfa_enabled`, `mfa_secret_set`) (ADR-22). (4) The MFA gate returns `MFA_REQUIRED` with a single-use, 5-minute `mfa_token`; `details.enrolled` lets the SPA route a role-mandated-but-unenrolled account to admin enrollment instead of a dead-end authenticator flow; `enrolled:false` with a `requires_mfa` role fails with `AUTH_INVALID` so login never starts a flow it cannot finish. (5) Test expectations, not the engine, were wrong on the RFC vectors: `'755224'` is an RFC 4226 HOTP counter-0 value, and the RFC 6238 `69279037` row is T=2000000000 (2 billion); the corrected 6-digit vectors (287082/081804/279037/353130) pass.
- **Failed approaches**: (1) `UserAdminService::fetchUser` omitted `mfa_secret_enc` from its SELECT, so the indefinite re-enroll guard read a stale null and double-enroll returned 200 instead of 422 `VALIDATION_FAILED` — the column is a field, not a projection, and must be selected whenever the guard needs it. (2) `.env` comments containing `(` / `"` and an unquoted value containing `=` broke `parse_ini_file` (`Config.php`) which parses `.env` as INI, not a dotenv lib — comments rewritten free of INI metacharacters and `MFA_ENCRYPTION_KEY` double-quoted (`.env.example` uses `"replace_with_base64_of_32_random_bytes"`). (3) The AuthFlowTest asserted exactly one refresh row with `revoked_reason='logout'`, but rotation revokes the previous token with `revoked_at` and **no** reason — now asserts 2 rows `revoked_at IS NOT NULL`.
- **Follow-up items**: TASK-037 (frontend auth — `AuthProvider`, axios refresh-and-retry on 401, `RequireAuth`/`RequirePermission`, login + forced-password-change screens). Later: automate a QR-encoding step at enrollment; `MFA_ENCRYPTION_KEY` rotation runbook.

## TASK-037: Frontend auth — login, silent refresh, guards

- **What shipped**: SPA authentication end-to-end. Frontend (`frontend/src/auth/`): `tokenStore` (access/CSRF tokens in memory only), `AuthProvider` (bootstrap via one `GET /me` through the silent-refresh path; login, MFA verify, change-password, logout), permission catalogue constants + pure predicates (`permissions.ts`), `usePermission`/`useLayerCap`/`useScope` hooks, `RequireAuth`/`RequirePermission`/`PermissionGate` guards, `apiErrors`/`ApiError` for the envelope. `apiClient` rework: Bearer + `X-CSRF-Token` request headers, `X-CSRF-Token` captured from every auth response, envelope unwrap (typed `as never` because the interceptor moves the payload at runtime), single-flight 401 → refresh → retry-once via `refreshQueue`, `/auth/*` excluded from retry, logout on failed refresh. Pages: `LoginPage` (credentials → MFA step on `MFA_REQUIRED`, `details.enrolled=false` shows the enrollment hint), `ChangePasswordPage`, `ForbiddenPage`; guarded routes in `App.tsx`; Vite dev proxy `/api` → `localhost:8080`. Tests: 16 Vitest (pure-logic; node env, no jsdom/testing-library installed) — green locally; `oxlint` clean; `tsc -b && vite build` clean.
- **Backend gaps TASK-037 exposed and closed** (unverified locally — must run on the Docker stack): (1) **CSRF issuance** — nothing ever issued a `csrf_token` cookie, so refresh/logout could not work from a real browser. `AuthController` now issues a non-HttpOnly `csrf_token` cookie (`SameSite=Strict`, `Path=/api/v1/auth`, Max-Age = refresh) plus an `X-CSRF-Token` response header on login/MFA-verify/refresh and clears it on logout; refresh reuses the incoming cookie value when present (concurrent-tab friendly) else rotates. `AuthFlowTest` now asserts the pair instead of a hard-coded token. (2) **`PUT /me/password`** — documented in `api.md` but never implemented; `MeController::changePassword` verifies the current password (Argon2id), enforces `PasswordPolicy` (details.field_errors surfaced), bumps `version`, clears `must_change_password`, and revokes the refresh family so an attacker with an old cookie cannot keep a changed session alive.
- **Decisions made (ADR-23)**: access token memory-only (XSS exfil surface reduced to live memory); refresh cookie HttpOnly/Secure/SameSite=Strict pinned to `Path=/api/v1/auth`; the server issues the JS-readable double-submit half on every auth handshake rather than the SPA fabricating it; single-flight refresh queue so concurrent 401s trigger exactly one refresh; a failed refresh drops the session. Password change revokes the refresh family deterministically (session ends, user signs in again).
- **Failed approaches**: (1) Returning `body.data` from the Axios success interceptor breaks the interceptor's required `AxiosResponse` return type — the type trick is `as never` (any is assignable to nothing-typed callers see, never satisfies the contravariant position) while the runtime value is still the unwrapped payload. (2) Keeping `useAuth` in the same file as `AuthProvider` trips oxlint's fast-refresh rule (file must export only components) — split `useAuth`/`useAuthContext` into `useAuth.ts` + `auth-context.ts`.
- **Follow-up items**: Backend suite + manual SPA smoke on the Docker stack (checklist lives in `next_task.md`). Later/optional: the "turn off MFA in dev mode" request (a `MFA_ENABLED` toggle) was deliberately not implemented; moves to the proposed TASK-038.

## TASK-038: Permissions Gate
- **What shipped**: Enhanced `<PermissionGate>` with `disable` and `hide` modes. Created `lint-roles.mjs` script to statically scan for hardcoded role strings instead of permission constants.
- **Decisions made**: Kept access gating strictly UI-side for UX, while security enforcement remains solely on the backend.
- **Follow-up items**: TASK-039.

## TASK-039: Admin UI: users, roles, scopes, organisations
- **What shipped**: Management screens powered by TanStack Query for caching and invalidation, a permission matrix UI, and a scope editor featuring a map picker for custom areas. Added Cypress E2E flows.
- **Decisions made**: Simplified state management by driving UI primarily from TanStack Query rather than Redux or React Context to stay close to the server state.
- **Follow-up items**: TASK-040.

## TASK-040: Audit browser
- **What shipped**: `AuditQueryController.php` implementing `GET /audit-logs` with PII scrubbing logic. `AuditLogView.tsx` displaying the logs with filtering capabilities. Added Playwright/Cypress tests for the audit view.
- **Decisions made**: Stripping PII values at the query level ensures they never cross the network boundary, aligning with security guidelines.
- **Follow-up items**: Move to Phase 4 (TASK-041).

## TASK-041: Layer CRUD API
- **What shipped**: `GisLayerController.php` updated with strict business rules. Added optimistic concurrency using `If-Match` against the `version` column. Deletions of populated layers (`feature_count_cache > 0`) are now rejected unless `archive=true` is supplied. Added missing `extent` column to `app.gis_layers` via migration `20260920000019_add_extent_to_gis_layers`.
- **Decisions made**: Enforced concurrency strictly on both PUT and DELETE. The extent column is stored as `geometry(Polygon, 4326)` for flexibility in bounding box operations.
- **Follow-up items**: TASK-042.

## TASK-042: Custom field metadata API
- **What shipped**: New `GisLayerFieldController.php` for CRUD operations on `app.gis_layer_fields`. Validation rules strictly check for 16 allowed `field_type` values, reject reserved column names (`id`, `geom`, `layer_id`, etc.), and require an `existing_value` parameter when adding a required field to a populated layer.
- **Decisions made**: Decoupled field schema rules into a purely metadata-driven architecture to dynamically control front-end forms and backend validation.
- **Follow-up items**: TASK-043.

## TASK-043: Metadata-driven attribute validation (server)
- **What shipped**: `AttributeValidator.php` domain service parsing metadata out of `app.gis_layer_fields` to enforce type, presence, enum, and min/max/regex constraints against an arbitrary set of input attributes. Added `AttributeValidatorTest.php`. Tests passed locally.
- **Decisions made**: Separated the attribute validation logic into a pure domain class so it can be invoked safely from both API feature endpoints and batch import paths.
- **Follow-up items**: TASK-044.

- **TASK-044**: Completed FieldRetypeService and dry-run preview, fixed nested transactions in controller, incremented version in gis_features on update, fixed tests.

- **TASK-045**: Created MigrationGeneratorService to generate Phinx migrations for expression indexes when searchable/sortable flags change. Updated GisLayerFieldController.
- **TASK-046**: Implemented GisLayerStyleController to support SINGLE, CATEGORIZED, and GRADUATED styling rules. Created API routes and tests.

- **TASK-047**: Implemented LayerDesigner UI (frontend) with subcomponents for editing layer metadata, fields, styles, and basic role permissions. Added Cypress E2E test.

## TASK-075: Nearest control point and map picker
- **What shipped**:
  - Backend: `GET /control-points/nearest?lat=&lon=&limit=&type=` in `ControlPointController.php` using the GIST KNN distance operator (`<->`), geodesic `distance_m` calculation, and `app.fn_user_can_see` scope enforcement. Registered in `routes.php` under `control_point.view`.
  - Frontend: `frontend/src/features/control-points/api/controlPointApi.ts` with `getNearest()`, `ControlPointPicker.tsx` component supporting nearest search to coordinates and fuzzy name search, and `NearestControlPointTool` integrated in `SpatialTools.tsx` and mounted in `MapWorkspace.tsx`.
- **Decisions made**: Geodesic distance calculated via `ST_Distance(cp.geom::geography, ...)` to report accurate real-world metric distances in the picker list.
- **Follow-up items**: TASK-076.

## TASK-076: Control point UI
- **What shipped**:
  - `ControlPointStatusBadge.tsx`: Prominent warning-amber styling with icon for `UNVERIFIED` status per the AC requirement ("visually unmistakable everywhere the point appears"), checkmark green for `VERIFIED`, red for `DISPUTED`, gray for `RETIRED`.
  - `ControlPointListPage.tsx`: Paginated table with filters by status, type, fuzzy query, sorting by name/type/status/dates, coordinates display (native and derived with explicit badges), and `+ New Control Point` button.
  - `ControlPointEditorPage.tsx`: Full monument editor supporting coordinate origin toggle (`PROJECTED` vs `GEOGRAPHIC`), native CRS selection, accuracy metadata, audit change reasons, live dependents panel (`GET /control-points/{id}/dependents`), and "Verify Point" action button gated by `control_point.verify`.
  - Routed `/control-points`, `/control-points/new`, and `/control-points/:id` in `App.tsx` and added navigation link.
- **Decisions made**: Unverified status carries a distinct warning outline and background so surveyor review state cannot be overlooked.

## TASK-077: Survey plan CRUD and linkage
- **What shipped**:
  - Backend: `SurveyPlanController.php` with CRUD (`GET /survey-plans`, `POST /survey-plans`, `GET /survey-plans/{id}`, `PUT /survey-plans/{id}`, `DELETE /survey-plans/{id}`, and `GET /survey-plans/{id}/parcels`). Enforces unique plan numbers, validates standard Philippine survey plan types (Psd, Psu, Pcs, etc.), optimistic locking via `If-Match`, soft-delete with active parcel protection, and full audit logging via `AuditWriter`. Registered in `routes.php`.
  - Tests: `backend/tests/Unit/SurveyPlanTest.php` asserting plan types and rules.
  - Frontend: `surveyPlanApi.ts` client, `SurveyPlanTab.tsx` mounted inside `ParcelEditorPage.tsx` under the Survey tab, allowing viewing linked survey plan details or searching and linking/unlinking survey plans to parcels.
- **Decisions made**: Deleting a survey plan is strictly refused if any active parcel references it (`linkedCount > 0`).

## TASK-077b: RPT / Property Assessment Integration Adapter (Stub)
- **What shipped**:
  - Outbound port interface `backend/src/RPT/PropertyLinkProvider.php` defining lookups by Tax Declaration number (`lookupByTaxDeclaration`), parcel code (`lookupByParcelCode`), and PSGC (`lookupByPsgc`).
  - DTO `backend/src/RPT/PropertyAssessmentRecord.php` encapsulating assessment values and ownership without database foreign keys.
  - In-memory implementation `backend/src/RPT/StubPropertyLinkProvider.php` with synthetic fixtures and dynamic registration capability.
  - Unit test `backend/tests/Unit/PropertyLinkAdapterTest.php` (7 test cases passed).
- **Decisions made**: Architectural commitment: strict adapter pattern with no foreign keys into third-party RPT databases, allowing live RPT integration in future phases without touching core parcel domain code.

## TASK-078: Bearing value objects and parsing (pure domain)
- **What shipped**:
  - Pure domain value objects `backend/src/Survey/Domain/Bearing.php` and `Azimuth.php`.
  - Parses quadrant DMS (`N 25°30'00" E`, `N25-30-00E`, `N 25d 30m 00s E`), decimal quadrant (`N 25.5 E`), raw decimal azimuth, and cardinal directions (`DUE NORTH`, `N`, `S`, `E`, `W`).
  - Strict enforcement of VR-01 (deg 0–90, min 0–59, sec 0–59.999), VR-02 (azimuth 0 ≤ Az < 360), VR-03 (quadrants NE/SE/SW/NW or cardinal), and VR-07 (ambiguous 0° and 90° bearings with quadrant rejected; cardinals required).
  - Exact quadrant-to-azimuth conversions to 1e-9; round-trip stability.
  - Unit tests: `backend/tests/Unit/BearingTest.php` (8 tests / 26 assertions passed).

## TASK-079: Distance value object and unit conversion
- **What shipped**:
  - Pure domain value object `backend/src/Survey/Domain/Distance.php`.
  - Canonical meters (`distance_m`) preservation with exact conversion factors for meters, kilometers, international feet (0.3048 m), US survey feet (1200/3937 m), Spanish varas (0.835905 m), and Gunter's chains (20.1168 m).
  - Enforces VR-04 (distance > 0.01 m; zero/negative rejected), VR-06 (registered unit validation), and VR-05 (warning when single course exceeds 5,000 m).
  - Unit tests: `backend/tests/Unit/DistanceTest.php` (10 tests / 22 assertions passed).

## TASK-080: Technical description CRUD and revisions
- **What shipped**:
  - Backend controller `backend/src/Survey/Http/TechnicalDescriptionController.php` with `GET /parcels/{id}/technical-descriptions`, `POST /parcels/{id}/technical-descriptions` (creates next sequential revision, manages `is_current`), `GET /technical-descriptions/{id}` (full detail with tie points, tie lines, and courses), `PUT /technical-descriptions/{id}` (optimistic locking via `If-Match`, blocks in-place mutation of confirmed revisions), course CRUD (`POST/PUT/DELETE /courses`, sequential renumbering on delete, atomic reordering via `PUT /courses/order`).
  - Full audit logging via `AuditWriter`. Routes registered in `backend/config/routes.php` and DI container in `backend/config/dependencies.php`.

## TASK-081: Course syntax validation endpoint
- **What shipped**:
  - Domain service `backend/src/Survey/Domain/CourseValidator.php` validating course sequences against VR-01 through VR-09 (bearing bounds, azimuth bounds, quadrant types, distance bounds, 5000m warning, unit registration, ambiguous 0°/90° rejection, collinear consecutive warning VR-08, reversed backtrack course warning VR-09).
  - Endpoint `POST /technical-descriptions/{id}/validate` returning `{ valid, errors, warnings, validated_courses }` without computing.
  - Unit tests: `backend/tests/Unit/CourseValidatorTest.php` (9 tests passed).

## TASK-082: Technical description parser
- **What shipped**:
  - Pure domain parser `backend/src/Survey/Domain/Parser/TechnicalDescriptionParser.php` tokenizing free-form cadastral/Torrens survey descriptions.
  - Extracts tie points (BLLM, MBM, PBM), tie line vectors, point of beginning, boundary course sequences, claimed area, character source spans, and confidence scores (0.0 to 1.0).
  - High/low confidence scoring; never guesses silently; flags low-confidence or corrupt text with VR issue codes.
  - Endpoint `POST /survey/parse`.
  - Unit tests: `backend/tests/Unit/ParserTest.php` (4 tests passed).

## TASK-083: Staging, review, and confirmation workflow
- **What shipped**:
  - Confirmation endpoint `POST /technical-descriptions/{id}/confirm` validating all courses through `CourseValidator`.
  - Premature confirmation guard: returns 422 `PARSE_UNRESOLVED` with issue list if any course has unresolved syntax errors.
  - Sets `confirmed_by`, `confirmed_at`, marks `parser_status = 'CONFIRMED'`, sets `is_confirmed = true` on courses, and writes audit row `technical_description.confirm`.

## TASK-084: Technical description UI
- **What shipped**:
  - Frontend client: `frontend/src/features/survey/api/surveyApi.ts`.
  - Compound input `BearingInput.tsx`: Quadrant dropdowns, degrees/minutes/seconds inputs, live derived azimuth readout, paste-parse fallback, and VR-01...VR-07 instant validation.
  - Survey tab `TechnicalDescriptionTab.tsx`: Revision selector, metadata display, course table with reordering, course validation trigger with VR badges, paste-and-parse review modal with source text, confidence scores, and confirm action lock.
  - Tie point tab `TiePointTab.tsx`: Geodetic tie point cards, as-used coordinates, tie lines, and `ControlPointPicker` integration.
  - Mounted inside `ParcelEditorPage.tsx`.

## TASK-085: Live traverse preview on the map
- **What shipped**:
  - Component `TraversePreviewMap.tsx`: Real-time traverse coordinate derivation ($\Delta N = D\cos Az, \Delta E = D\sin Az$), SVG vector canvas with grid and vertex labels, closure gap calculation, and prominent red dashed open-polygon gap indicator between the last vertex and POB with live distance readout. Embedded in `TechnicalDescriptionTab.tsx`.

## TASK-086: OCR assist (optional, flagged)
- **What shipped**:
  - Endpoint `POST /technical-descriptions/{id}/ocr` in `TechnicalDescriptionController.php` accepting scanned OCR text, extracting courses with `extraction_method = 'OCR_EXTRACTED'`, staging into the technical description, and channeling into the identical review/confirmation workflow.

## TASK-087: Traverse computer (pure domain)
- **What shipped**:
  - `backend/src/Survey/Domain/TraverseComputer.php`: Pure domain planar traverse computer calculating Cartesian increments ($\Delta N = D\cos Az, \Delta E = D\sin Az$) for arbitrary multi-leg tie lines and closed perimeter courses.
  - Matches benchmarks to 1 mm on plane coordinates.
  - Unit tests: `backend/tests/Unit/TraverseComputerTest.php` (written first, 3 tests / 35 assertions passed).

## TASK-088: Closure calculation
- **What shipped**:
  - `backend/src/Survey/Domain/ClosureCalculator.php` and `ClosureResult.php`: Precision survey closure analyzer.
  - Computes $\Delta E$, $\Delta N$, linear closing error, error azimuth via `atan2`, perimeter, and relative precision ratio denominator with zero-division guard returning `1:INF` on exact mathematical closure.
  - Enforces VR-11 (relative precision ≥ 1:5000) and VR-12 (linear error ≤ 0.100m) tolerance evaluations.
  - Unit tests: `backend/tests/Unit/ClosureTest.php` (5 tests passed).

## TASK-089: Area calculation and cross-check
- **What shipped**:
  - `backend/src/Survey/Domain/AreaCalculator.php`: Computes plane Shoelace polygon area ($A = \frac{1}{2}|\sum(x_i y_{i+1} - x_{i+1} y_i)|$).
  - Evaluates claimed/source area discrepancies (VR-15, VR-16) and PostGIS planar area cross-check (VR-17 flagged if difference > 0.01%).
  - Area is never computed in geographic EPSG:4326.
  - Includes mandatory validation aid note: *"Area comparison is a validation aid, not a determination of correctness."*
  - Unit tests: `backend/tests/Unit/AreaTest.php` (5 tests passed).

## TASK-090: Computation service, snapshot, persistence
- **What shipped**:
  - `backend/src/Survey/Application/SurveyComputationService.php` and `ComputationController.php`: Application orchestration for traverse calculation, PostGIS polygon topological validation (VR-13 self-intersection, VR-14 validity), immutable snapshot generation into `app.parcel_computations.input_snapshot`, persistence into `app.parcel_computations` and `app.parcel_vertices`.
  - Replay determinism endpoint `POST /computations/{id}/replay` recomputing from stored snapshot and verifying 100% vertex coordinate reproducibility.
  - Integration test: `backend/tests/Api/ComputationApiTest.php` (tests pass).

## TASK-091: Compute-CRS selection and guards
- **What shipped**:
  - `backend/src/Survey/Domain/ComputeCrsGuard.php`: Enforces projected coordinate system requirement, maps longitude to Philippine PRS92 PTM Zones I–V (EPSG:3121..3125), validates area of use bounds against CRS bounding boxes (VR-20), rejects geographic/unprojected CRSs (`CRS_UNSUPPORTED`), and blocks non-GRID bearing references (GEODETIC, MAGNETIC, ASSUMED) with explicit rejection messages.
  - Endpoint `GET /crs/suggest-ptm-zone?lon=...` in `ComputationController.php`.
  - Unit tests: `backend/tests/Unit/ComputeCrsGuardTest.php` (6 tests passed).

## TASK-092: Accept computation → parcel geometry
- **What shipped**:
  - Endpoint `POST /parcels/{id}/accept-computation` in `backend/src/Parcels/Http/ParcelController.php`.
  - Atomically sets parcel geometry (`geom`), updates provenance to `COMPUTED_FROM_TECHNICAL_DESCRIPTION`, records `current_computation_id`, bumps parcel `version`, saves snapshot to `audit.parcel_versions`, and writes audit log.
  - Guaranteed: nothing writes to `parcels.geom` prior to explicit acceptance.
  - Integration test: `backend/tests/Api/ComputationApiTest.php` (tests pass).

## TASK-093: Computation panel UI
- **What shipped**:
  - Frontend client `frontend/src/features/survey/api/computationApi.ts`.
  - Component `ComputationPanel.tsx`: Full survey computation workbench featuring Technical Description revision selector, PTM zone recommendation and selector, live SVG traverse preview, closure metrics card, area comparison card with mandatory validation aid note, survey rule alerts, calculated coordinates table, input snapshot modal, traverse adjustment modal, accept computation modal, and computation run history with replay action.
  - Mounted in `ParcelEditorPage.tsx` under Computation tab.
  - Component tests: `frontend/src/features/survey/components/ComputationPanel.test.tsx` (3 tests passed).

## TASK-094: Traverse adjustment (Compass/Transit)
- **What shipped**:
  - `backend/src/Survey/Domain/Adjustment/`: `TraverseAdjustmentInterface.php`, `CompassRuleAdjustment.php` (Bowditch compass rule distributing closing error proportionally to course lengths), and `TransitRuleAdjustment.php` (distributing proportionally to latitudes and departures).
  - Adjustment produces a new computation linked to the base via `base_computation_id`, closing linear error to $0.000\text{ m}$.
  - Endpoint `POST /computations/{id}/adjust`.
  - Unit tests: `backend/tests/Unit/CompassRuleTest.php` (2 tests passed).

## TASK-096: Survey validation service
- **What shipped**:
  - `backend/src/Survey/Application/SurveyValidationService.php`: Implements complete 12-point FR-125 checklist: TD confirmation (`VR-TD-CONFIRMED`), tie point found (`VR-TIE-FOUND`), tie point verified (`VR-19`), CRS within area of use (`VR-20`), bearing reference & course syntax (`VR-01..09`), closed polygon within tolerance (`VR-11, 12`), geometry topology & simplicity (`VR-13, 14`), computed area plausibility (`VR-15`), area comparison vs source (`VR-16, 17`) with mandatory FR-127 validation aid note, minimum $\ge 3$ vertices (`VR-10`), and cadastral overlap detection (`VR-18`). Results persisted to `app.parcel_computations.validation_result`.
  - `ValidationController.php`: Endpoints `POST /parcels/{id}/validate` and `GET /parcels/{id}/validation`.
  - Tests: `backend/tests/Api/ValidationTest.php` (5 tests passed).

## TASK-097: Overlap detection
- **What shipped**:
  - `backend/src/Parcels/Domain/OverlapDetector.php`: PostGIS GIST-indexed spatial queries (`ST_Intersects`, geodesic `ST_Area(ST_Intersection(...)::geography)`), computes overlapping area in $m^2$ and percentage of subject parcel, distinguishes interior polygon overlaps from adjacent boundary-touching lines ($0\text{ m}^2$), filters slivers ($\le 0.05\text{ m}^2$), and excludes ARCHIVED/SUPERSEDED parcels.
  - Tests: `backend/tests/Spatial/OverlapTest.php` (4 tests passed, 12 assertions).

## TASK-098: Submission guards
- **What shipped**:
  - `ValidationController::submit` (`POST /parcels/{id}/submit`): Enforces full validation checklist before parcel transition to `SUBMITTED`. Rejects with `CLOSURE_EXCEEDS_TOLERANCE` (422) if traverse closure fails, or `VALIDATION_FAILED` (422) for other blocking failures. Carries forward warnings (e.g. `VR-18` overlap, `VR-19` unverified tie point) into `audit.parcel_versions` and audit logs. Blocks re-submission of invalid statuses (400 `INVALID_STATE`).
  - Tests: `backend/tests/Api/SubmitGuardTest.php` (4 tests passed).

## TASK-099: Validation panel UI
- **What shipped**:
  - Frontend client `frontend/src/features/survey/api/validationApi.ts`.
  - Component `ValidationPanel.tsx`: Top status banner, 12-point checklist table displaying PASS/WARN/FAIL status badges and rule IDs (`VR-01` through `VR-20`), warnings expanded by default without dismiss-all (FR-126), "Show me" action buttons navigating to relevant tabs (`techdesc`, `tiepoint`, `computation`), area comparison card with mandatory FR-127 validation aid note, overlap analysis table (VR-18), and "Submit for Review" button disabled on blocking failures opening submission confirmation modal.
  - Mounted in `ParcelEditorPage.tsx` under the Validation tab, replacing the placeholder.
  - Component tests: `frontend/src/features/survey/components/ValidationPanel.test.tsx` (5 tests passed). Full frontend Vitest suite: 52/52 passed; `npm run build` clean.

## Phase 5–8 Audit and Gap Closures (2026-09-25)

- **Frontend Runtime & Test Suite Fixes**:
  - **V8 Unicode Regex Bug**: Fixed invalid identity escape in [BearingInput.tsx](file:///d:/webgis/frontend/src/features/survey/components/BearingInput.tsx#L139) (`[\s\'mM\-]` and `[\d\.]`), resolving `SyntaxError: Invalid regular expression ... Invalid escape` in Unicode mode (`/u`) that had been breaking SPA page mounts in modern browsers.
  - **Phase 14 E2E Regressions**: Granted `survey.view/create/update` permissions to `ROLE_ENC`, fixed multi-line `psql` stdout parsing, and handled foreign-key cleanup on `app.parcels.current_computation_id` in [phase14-workflow.spec.ts](file:///d:/webgis/frontend/e2e/specs/phase14-workflow.spec.ts) (all 3 tests pass 100% green).

- **Phase 5 (Map Rendering, TASK-049..056)**:
  - Verified all 8 tasks in Docker stack and Playwright.
  - Resolved documentation discrepancy in TASK-056: [crs.ts](file:///d:/webgis/frontend/src/lib/crs.ts) and [CoordinateReadout.tsx](file:///d:/webgis/frontend/src/features/map/CoordinateReadout.tsx) are fully implemented and verified.
  - Verified all 5 Playwright tests in `phase5-map.spec.ts` pass green (17.2s).
  - Verified Docker PHPUnit tests pass (`TileProxyTest`, `BasemapLicenseTest`, `CrsRegistryTest`, `CoordinateTransformationTest`).

- **Phase 6 (Drawing & Editing, TASK-057..063)**:
  - Verified all 7 tasks; confirmed TASK-058b (multi-part geometry) remains `TODO`.
  - Verified all 5 Playwright tests in `phase6-draw.spec.ts` pass green (14.2s).
  - Verified backend concurrency handling (`If-Match` version checking, `ST_IsValid`, `ST_IsSimple`, and audit logging).

- **Phase 7 (Attribute Table, TASK-064..067)**:
  - **AttributeTable Runtime Fix**: Corrected invalid array destructuring of `React.useMemo` pagination state in [AttributeTable.tsx](file:///d:/webgis/frontend/src/features/layers/components/AttributeTable.tsx) (`const [pagination, setPagination] = React.useMemo(...)` threw `TypeError: object is not iterable`), restoring table component mounting.
  - **Transactional Bulk Operations (TASK-066)**: Implemented `bulkUpdate` (`POST /api/v1/layers/{id}/features/bulk-update`) and `bulkDelete` (`POST /api/v1/layers/{id}/features/bulk-delete`) in [GisFeatureController.php](file:///d:/webgis/backend/src/GIS/Http/GisFeatureController.php) with row locking (`FOR UPDATE`), permission checking (`can_update`, `can_delete`), and individual audit log entries via `AuditWriter`. Updated [AttributeTable.tsx](file:///d:/webgis/frontend/src/features/layers/components/AttributeTable.tsx) and [layerApi.ts](file:///d:/webgis/frontend/src/features/layers/api/layerApi.ts).
  - **CSV Export & Extent Filter (TASK-067)**: Implemented `csv` (`GET /api/v1/layers/{id}/features.csv`) in [GisFeatureController.php](file:///d:/webgis/backend/src/GIS/Http/GisFeatureController.php) with dynamic layer fields and streaming response. Fixed inverted exportFormat condition and query parameters in [FeatureGridPage.tsx](file:///d:/webgis/frontend/src/features/layers/pages/FeatureGridPage.tsx).
  - **PII Redaction & Audit Logging**: Both GeoJSON and CSV exports now enforce PII redaction: fields in `app.gis_layer_fields` with `is_pii = true` are masked with `[REDACTED]` for users lacking PII permissions (`user.view.pii`, `organization.view.pii`, `title.view_owner`, `party.view`). Every export writes an `EXPORT` audit log entry in `audit.audit_logs`.
  - **Tests**: Created [BulkUpdateTest.php](file:///d:/webgis/backend/tests/Api/BulkUpdateTest.php) (4 tests / 18 assertions, 100% green in Docker), [ExportScopeTest.php](file:///d:/webgis/backend/tests/Api/ExportScopeTest.php) (3 tests / 21 assertions, 100% green in Docker), and [AttributeTable.test.tsx](file:///d:/webgis/frontend/src/features/layers/components/AttributeTable.test.tsx) (3 tests, 100% green in Vitest).

- **Phase 8 (Parcels Core, TASK-068..072)**:
  - Verified all 53 PHPUnit tests / 269 assertions in Docker (`ParcelCrudTest`, `ParcelVersionTest`, `ParcelSearchTest`, `ProvenanceGuardTest`, `ParcelSchemaTest`).
  - Verified all 8 Playwright Phase 8 tests pass 100% green across `phase8-parcels.spec.ts`, `phase8-parcel-editor.spec.ts`, and `phase8-parcel-create.spec.ts` (33.4s).
  - Synchronized verification records in [todo.md](file:///d:/webgis/todo.md) and [TASK.md](file:///d:/webgis/TASK.md).

## Phases 5-15 Audit (2026-09-25)

Full cross-check of TASK-049..TASK-120 against `architecture.md`, `specification.md`, `api.md` and `frontend.md`, covering CRUD completeness, transaction integrity, tools/services, and UI/menu ease of use. Full record in [todo.md](file:///d:/webgis/todo.md).

- **2 CRITICAL runtime defects fixed** (both were HTTP 500s on live endpoints, both previously undetected because no test exercised those routes over HTTP):
  - `SurveyPlanController` opened an unguarded `beginTransaction()` inside the `AuthenticateMiddleware` request transaction, so every authenticated `POST` / `PUT` / `DELETE /survey-plans` failed with `There is already an active transaction`. Converted all three sites to the existing `DbTransaction::begin/commit/rollback` helper, which joins the ambient transaction instead of nesting.
  - All three audit writes in the same controller called `writeFromSession()` with a parameter list that does not exist (`entityType:`, `preChangeSnapshot:`, `postChangeSnapshot:`, `metadata:`), which would 500 with `Unknown named parameter $entityType` the moment the transaction bug was fixed. Rewritten against the real signature (`AuditWriter.php:72-80`). Repo-wide grep confirms no other file used the phantom names.
  - Verified empirically against the live container that a nested `beginTransaction()` throws and that the following `commit()` ends the outer transaction and wipes the `SET LOCAL` RLS context.
- **Regression coverage added**: [SurveyPlanApiTest.php](file:///d:/webgis/backend/tests/Api/SurveyPlanApiTest.php) - 4 tests / 6 assertions covering create, update, delete over real HTTP with a real token, plus an assertion that a create persists an `audit.audit_logs` row (pins the TASK-025 audit-atomicity AC). These endpoints previously had zero HTTP coverage.
- **4 HIGH authz drift fixes** in [routes.php](file:///d:/webgis/backend/config/routes.php): `PUT/DELETE /layers/{id}` were gated on `gis.layer.create` (now `gis.layer.update` / `gis.layer.delete`); layer field CRUD + retype-preview were gated on `gis.layer.create` (now `gis.field.manage`); layer style create/update were gated on `gis.layer.create` (now `gis.style.manage`). Also fixed [StyleTest.php](file:///d:/webgis/backend/tests/Api/StyleTest.php), which granted the non-existent code `gis.layer.manage` and therefore proved nothing about style authorisation.
- **6 frontend fixes** against `frontend.md`: `/` now redirects to `/map` (sec. 3, map is the default landing surface); added a real 404 page + catch-all instead of rendering the home screen for unknown URLs; added the sec. 19 **left icon rail** (declarative, permission-filtered, absent-not-greyed, inline SVG following the existing convention - no new dependency); split `<AuthLayout>` out of `<AppLayout>` (sec. 4) so `/login` no longer renders the authenticated shell; fixed the double-active Parcels/Inbox nav state; fixed the "GIS Layers" admin tab gate (`gis.layer.create` -> `gis.layer.view`, which had hidden the layer list from read-only users); replaced raw permission string literals with the exported `permissions` constants.
- **Verification**: backend `482 tests / 2131 assertions` OK (was 478); frontend `92 tests / 16 files` green; `tsc -b --noEmit` exit 0; `npm run build` ok; `npm run lint` 0 errors; PHPStan L8 `src` errors reduced `163 -> 157`.
- **Open gaps recorded in `todo.md` (B-1..B-8)**, not fixed: missing layer-style delete, ungated `POST /documents` + link endpoint, `parcel.view` gate on the mutating validate endpoint, `survey.view` gate on the writing CRS transform, remaining unimplemented sec. 3 routes (all mapped to TODO phase-tasks), clickable "coming soon" parcel tabs, and pre-existing lint warnings.

---

# AUDIT 2 - Phases 5-15 UI acceptance pass (2026-09-26)

Browser-driven acceptance over every phase 5-15 surface, run against the Docker stack with a single serial Playwright worker on a shared database. This is the pass that the previous entry explicitly deferred; it found three defects that no static check, build, or mocked unit test could reach.

## 1. CRITICAL - geographic scope authorization (`database/migrations/20260925000002_fix_psgc_scope_hierarchy.php`)

`app.fn_user_can_see` / `app.fn_user_can_edit` decided scope membership with `LIKE 'scope%'`. PSGC codes are hierarchical and *prefix-nested* but not *prefix-complete*: a BARANGAY code such as `990101000` is a 9-digit string that prefixes its own MUNICIPALITY and PROVINCE codes, so a barangay-scoped user matched every sibling barangay under the same city and was granted read **and write** across all of them. Conversely, nothing in the function consulted the actual hierarchy, so the relationship held or broke by string coincidence rather than by administrative fact.

- Added `app.fn_psgc_scope_matches(target_code, scope_code)`, which resolves `ref.psgc_areas.parent_code` for BARANGAY/MUNICIPALITY/PROVINCE/REGION. The `LIKE` prefix match is retained *only* for custom codes, which have no row in the reference table.
- Both authorization functions now delegate to the helper.
- **Second, independent defect in the same functions:** the `GLOBAL` scope satisfied the edit branch, so a global *viewer* had write access everywhere. `down()` previously restored one shared template for both functions, which cannot express "sees everything, edits nothing" - the see-function then had to keep the write branch, which is how the global-write bug survived a rollback. `down()` now restores the two functions separately: `fn_user_can_see` drops the write branches entirely (all access levels pass), `fn_user_can_edit` requires `EDIT`/`APPROVE` and now also rejects `GLOBAL`. `CREATE OR REPLACE FUNCTION` cannot change a return type, which is why the see-function is narrowed rather than inverted in place.
- The live `access_level` values are `EDIT`/`APPROVE`; the `WRITE` literal carried in the earlier migration is stale and was corrected.

**Rollback was tested, not assumed.** `vendor/bin/phinx rollback -t 20260925000001` leaves `GeographicScopeTest` failing 4/11 - the hierarchy cases and the missing helper - while `testGlobalViewScopeSeesEverywhereButEditsNothing` still passes, which is precisely the proof that the corrected `down()` does not reintroduce global write. Re-applying returns 13 tests / 26 assertions green across `GeographicScopeTest` + `RlsTest`.

Coverage: `tests/Integration/GeographicScopeTest.php` (11 tests / 23 assertions) - the new global-view test is written to be meaningful against *both* migration states. `tests/Integration/RlsTest.php` grants `USAGE` on `ref` and `SELECT` on `ref.psgc_areas` to the synthetic RLS role, without which the policy could not evaluate the hierarchy at all.

## 2. HIGH - login-page refresh shared the login rate-limit bucket

The `auth` class was a single 10-per-60s budget covering `login`, `mfa/verify` and `refresh`. The SPA refreshes silently on every cold boot, so ordinary browser reloads spent the budget reserved for credential submission and returned 429 to the sign-in form - a user who had never mistyped a password could be locked out of logging in. Split into `auth` (10/60s) and `auth_refresh` (60/60s), classified ahead of the generic `auth` fallback. `RateLimitTest` pins both the isolation and the new ceiling (7 tests / 20 assertions).

## 3. HIGH - basemap flake under cold boot

`MapShell` loads `/basemaps` on mount, so a hard reload fired the landing page's requests, the bootstrap `/me` call and the silent refresh inside one window; the resulting 429 churn appeared as an intermittent console error in the map shell.

- Client: a terminal refresh rejection (401/403, or a malformed envelope) is distinguished from a transient one (429/5xx/network), and a transient failure is retried once before the session is evicted - a 429 must never log a user out. Previously any failure latched the session closed.
- Harness: the E2E auth cleanup clears `auth\\_refresh:%` alongside `auth:%`. The suite had been re-creating the very throttling it was diagnosing.

## 4. MEDIUM - validation and form-ordering defects

- PSGC validation demanded exactly 9 digits, rejecting legitimate 10-12 digit codes on parcel create/editor, survey plans and control points. All 22 reference codes *are* 9 digits, which is why static review and the seeded fixtures both looked correct. Widened to 9-12 in the three backend controllers and two frontend pages.
- `SplitTab` ran the retype preview against unsaved form state, so consolidation previewed a result the operator had not yet justified. Reordered to require the reason first.
- The phase-15 spec seeded a 6-digit placeholder instead of a real reference hierarchy code; now `990101000`.
- `frontend/package.json` E2E script paths were wrong.

## 5. Verification

- Backend: `495 tests / 2 159 assertions` OK, 2 deprecations (was 492 / 2 152; +3 new tests). A full accidental `phinx rollback -t 0` was executed during runner discovery and fully recovered by re-migrating; the 495-test green run is the post-recovery state.
- Rollback gate: 4/11 failing against the rolled-back functions, green after re-apply.
- Frontend: `92 tests / 16 files` green; `tsc -b --noEmit` exit 0; form-QA sweep 15 tests green.
- Full Playwright: 40 tests; five of six consecutive runs fully green. The sixth failed an unrelated `locator.click` 90 s timeout in `phase8-parcel-editor.spec.ts`, which passes 3/3 in isolation - load-induced under a shared serial runner, not a functional regression.
- Data hygiene: 0 active `E2E%` parcels, 0 active `E2E%` control points, 0 `PROBE%` rows after the run. The sweep now tears down its own control points; a diagnosis probe script was deleted.
- PHPStan: 502-error pre-existing baseline (23 test files share one `ContainerInterface|null` pattern). `RateLimitMiddleware.php` contributes 0; no touched file gains an error.

## 6. Open gaps recorded in `todo.md`, not fixed

Layer-style delete; ungated `POST /documents` + link endpoint; `parcel.view` gate on the mutating validate endpoint; `survey.view` gate on the writing CRS transform; remaining unimplemented sec. 3 routes; clickable "coming soon" parcel tabs; pre-existing lint warnings; control-point label associations; unsupported seeded `XYZ` basemap provider warning.

**Standing lesson:** the rate limiter, the authorization functions and the form validators were each individually plausible, each fully unit-covered, and all three wrong in a way only a real browser against a real database revealed. Two are security-relevant. And a migration whose `down()` does not match its `up()` has to be rollback-tested against the suite - applying it proves only half of it.

---

# AUDIT 3 - Parcel data-entry UI (2026-09-26)

Audit of the two parcel attribute forms (`ParcelCreatePage`, editor `InformationTab`) against the house pattern established by `LayerMetadataForm` and `BasemapForm`, then the defects that warranted a fix. Scope was deliberately limited to what an operator typing into these forms can observe.

## 1. Labels were associated with nothing

Every control on both forms was a bare `<input>` next to a `<label>` with no `for`, and no input carried an `id`. Placeholders were doing the work of labels, so a screen reader announced an unlabelled edit box and clicking the text focused nothing. This is codebase-wide (`LayerMetadataForm` and `BasemapForm` have the same gap); the parcel forms were fixed because they are the primary data-entry surface.

- New `frontend/src/features/parcels/components/ParcelField.tsx` renders the label, the `*` required marker, an optional hint, and an `invalid-feedback` block, and owns the ids (`${id}-hint`, `${id}-error`) so the control can point at them.
- Verified in the browser, not by inspection: **14/14 controls on create and 12/12 on the editor tab have a matching `label[for]`**, 0 unlabelled.

## 2. Validation was reported in one place instead of on the field

`submit` ran every check and set a single banner above the Save button, so an operator who entered one bad PSGC code got one message that named no field. There were also no required markers, so nothing distinguished mandatory input from optional.

- `validateCreate()` returns a `Partial<Record<keyof CreateFormValues, string>>`; messages are revealed on the first submit attempt (`attempted`) and then derived from live values, so they clear as the operator types without any manual `setError` bookkeeping. The form has no resolver, so a `useForm({ rules })` option was not available.
- `parcel_code` is now marked required. Cross-field rules (survey-derived provenance requiring an attached plan plus a justification) stay in the banner, because they are not attributable to one input.
- Browser-verified: submitting an empty code, `-5` area, `133` and `abcdefghij` produced four simultaneous inline errors with `is-invalid` + `aria-invalid` + `aria-describedby`; correcting each value cleared its own error.

## 3. Two labels stated something false

- The PSGC badge read **"10–12 digits"** while annotating a `9–12` rule, so it contradicted the validation it labelled and would have steered an operator away from a valid 9-digit code. Now `9–12 digits`, from `PSGC_DIGIT_HINT`, the single source for the range.
- **"Source area (m²)"** was hard-coded to one unit, but `source_area_sqm` and `source_area_unit` are separate columns and the backend converts nothing — choosing hectares produced a field that said one thing and stored another. The label is now unit-neutral and the hint names the selected unit.

## 4. The PSGC placeholders contradicted the PSGC validation

Found by testing the new validation, not by reading it. The placeholders offered `e.g. 1339` and `e.g. 133901` — 4- and 6-digit prefixes — which the field's own `9–12` rule rejected on submit. `ref.psgc_areas` was queried: all 22 codes are 9 digits, and province/city codes are 9 digits too (`133900000` / `133901000` / `133901001`), not shorter prefixes. The examples are now real codes that pass. The two bugs were opposite halves of the same confusion about PSGC digit length, which is why the earlier "widen to 9-12" fix did not surface either.

## 5. The two parcel forms described the same attributes differently

Create ordered attributes parcel-code-first with `col-6` pairs; the editor ordered lot/block/title-first and gave barangay a full row. An operator's muscle memory did not carry from creating a parcel to editing one. Both now render from `PSGC_FIELDS` (province → municipality → barangay, matching how `ref.psgc_areas.parent_code` actually nests) with matching column widths.

## 6. Raw enum values and unconditional warnings

- The provenance `<select>` offered `COMPUTED_FROM_TECHNICAL_DESCRIPTION` as option text. Now `Computed from technical description` via `PROVENANCE_LABELS`; the stored enum is unchanged.
- The survey-plan lockout notice was always rendered. It is now shown only while the lockout is actually in effect — once a plan is attached it was stale noise under a field the operator was actively using.

## Verification

- Frontend: `104 tests / 17 files` green; `tsc -b --noEmit` exit 0; `oxlint` no errors; `vite build` clean.
- Parcel E2E: 7/7. Form-QA sweep: 15/15. Full Playwright: **40/40** — every pre-existing `data-testid` preserved, including the short PSGC ids (`parcel-create-psgc-prov` / `-muni` / `--barangay`), which now come from `PSGC_FIELDS[].testId` rather than being hard-coded per form.
- Browser checks on the running stack: 14/14 and 12/12 label association, four simultaneous inline errors, live error clearing, human-readable provenance options in both forms, and a 9-digit PSGC code saved and round-tripped through the editor. The verification parcel was deleted afterwards (0 rows matching `PRC-2026%`).

## Left alone deliberately

`control-points`, `survey-plans` and the other menu forms still have unassociated labels — a real gap, but a separate pass rather than an unbounded one. The map/form split on create (long form beside a 440 px map) was judged worth revisiting only alongside that pass; nothing in this change made it worse.

**Standing lesson:** both PSGC defects were *length* claims that no test asserted, and one of them was introduced by the fix for the other. A placeholder and a validation rule are the same fact stated twice, and the only thing that catches them disagreeing is typing the example in.
