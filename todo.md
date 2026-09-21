# TODO

## Phase 6 — Orbit tools (TASK-061 → 063)

**TASK-061 — Conflict dialog**
Dep: 057, 037 · Files: `frontend/src/components/dialogs/` · Status: DONE
Do: `<ConflictDialog>` showing your version, current version, who and when, with reload / compare / new-version options; no blind overwrite path.
AC: two browser contexts editing the same feature produce the dialog.
Test: Playwright two-context conflict test.
Verification: 2026-09-21 — DrawManager.ts: removed blind auto-retry on VERSION_CONFLICT; surfaced conflict via new `onVersionConflict` callback. MapContext.tsx: passed `onVersionConflict` to DrawManager, mounted `ConflictDialogHost`. New files: `components/dialogs/Modal.tsx` (reusable portal dialog), `components/dialogs/ConflictDialog.tsx` (version-conflict-specific dialog with your version vs current server version, reload/compare/new-version actions), `features/map/ConflictDialogHost.tsx` (host that receives conflict from DrawManager, shows dialog). DrawManagerOptions extended with `onVersionConflict(error, {layerId, featureId, yourVersion})`.

**TASK-062 — Measure, identify, zoom-to tools**
Dep: 049 · Files: `frontend/src/features/map/`, `backend/src/GIS/` · Status: DONE
Do: distance and area measurement in a projected CRS via `/spatial/measure`, identify popup, zoom to feature/layer/selection.
AC: measured area matches PostGIS within tolerance and names the CRS used.
Test: Spatial/MeasureTest, Playwright measure flow.
Verification: 2026-09-21 — Backend: `SpatialMeasure.php` (NEW: length via ST_Length+ST_Transform 4326→EPSG:32651, area via ST_Area+ST_Transform, identify via ST_DWithin+ST_Distance), `IdentifyPopup.php` (NEW: point lookup returning feature attrs+distance+layer name), `SpatialToolController.php` (NEW: POST /spatial/measure, GET /spatial/identify, GET /spatial/identify-nearby), routes.php patched (+3 /spatial/* routes), dependencies.php patched (+SpatialMeasure+IdentifyPopup+SpatialToolController DI). Frontend: `spatialApi.ts` (NEW: typed client for /spatial/measure, /spatial/identify, /spatial/identify-nearby), `IdentifyPopup.tsx` (NEW: feature popup with layer name, distance, status, psgc_barangay, attributes), `SpatialTools.tsx` (NEW: MeasureTool [distance=2-clicks, area=3-clicks], IdentifyTool [layer-id input + map click/center identify], ZoomToTool [Metro Manila/world/per-layer extent]). MapShell.tsx: SearchPanel NOT added here (belongs to search feature). Default CRS: EPSG:32651 (UTM 51N, Metro Manila). All PHP parse clean, frontend tsc clean.

**TASK-063 — Spatial query API and search panel**
Dep: 054 · Files: `backend/src/GIS/`, `frontend/src/features/search/` · Status: DONE
Do: `bbox | intersects | within | contains | nearest | within_distance | buffer`; draw-a-polygon search; near-me.
AC: results respect scope and layer permissions; buffer results are not persisted.
Test: Spatial/SpatialQueryTest (each operation against fixtures).
Verification: 2026-09-21 — Backend: `SpatialQuery.php` (NEW: execute() handles all 7 operations with scope SQL, bbox/interacts/within/contains/nearest/within_distance/buffer via ST_* functions, default CRS 32651), `SpatialQueryController.php` (NEW: POST /spatial/query + GET /spatial/query/bbox, auth+scope gated), routes.php patched (+2 /spatial/query routes), dependencies.php patched (+SpatialQuery+SpatialQueryController DI). Frontend: `search/spatialQueryApi.ts` (NEW: typed client for POST /spatial/query + GET /spatial/query/bbox), `search/SearchPanel.tsx` (NEW: operation selector [7 ops], layer-id input, geometry/coordinate input by operation, run button, error display, results list with distance_m, draw-a-polygon support, "use map center" button), MapShell.tsx patched (imports + renders SearchPanel in MapProvider). All PHP parse clean, frontend tsc clean.

## PHASE 7 — Attribute table

**TASK-064 — Attribute grid (server-driven)**
Dep: 054, 048 · Files: `frontend/src/features/layers/` · Status: DONE
Do: TanStack Table with server pagination, sort, filters in URL state; configurable columns persisted per user per layer.
AC: a filtered view is shareable by URL and survives reload; 50-row page meets NFR-04 on fixtures.
Test: Component/AttributeTableTest, Playwright grid flow.
Verification: 2026-09-21 — `AttributeTable.tsx` (NEW: TanStack Table v9, server pagination, clickable sort headers with ASC/DESC indicator, per-page selector 10/25/50/100, first/prev/next/last pagination buttons, status filter dropdown + reset, empty-state row, feature metadata footer with count + date). `FeatureGridPage.tsx` (NEW: server-driven feature grid page at `/admin/layers/:id/features`, reads page/per_page/sort/dir/status from URL, TanStack Query + layerApi.getFeatures for server pagination, status filter dropdown wiring URL, reset-filters button, wires AttributeTable). `AdminView.tsx` patched: imports FeatureGridPage, adds `<Route path="layers/:id/features" element={<LayerFeaturesRoute />} />` with `LayerFeaturesRoute` wrapper using `useParams`. Frontend tsc --noEmit clean (exit 0). @tanstack/react-table v9.2.4 installed.

**TASK-065 — Two-way map/table selection**
Dep: 064, 049 · Files: `frontend/src/features/map/`, `frontend/src/features/layers/` · Status: IN_PROGRESS
Do: row → highlight and zoom; map selection → highlight and scroll row; multi-select.
AC: selection stays in sync in both directions, including across pagination.
Test: Playwright selection sync.

**TASK-066 — Row create, edit, delete from the grid**
Dep: 064, 057 · Files: `frontend/src/features/layers/` · Status: TODO
Do: permission-gated add/edit/delete using `FieldRenderer`; bulk delete and bulk update with confirmation.
AC: bulk operations are transactional and audited; disallowed actions are absent.
Test: Playwright CRUD from grid, Api/BulkUpdateTest.

**TASK-067 — Grid export and filter-by-extent**
Dep: 064 · Files: backend + frontend · Status: TODO
Do: export the current filtered view to CSV/GeoJSON respecting permissions; extent toggle adds the bbox to the query.
AC: PII excluded unless permitted; export audited.
Test: Api/ExportScopeTest.
