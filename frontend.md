# frontend.md

**Project:** Philippine Parcel & Multi-User GIS Web Application
**Document status:** DRAFT v0.2 — for review and approval
**Companion documents:** `PLANNING.md`, `architecture.md`, `specification.md`, `database.md`, `api.md`, `todo.md`, `TASK.md`

---

## 1. Stack and rationale

| Concern | Choice | Why |
|---|---|---|
| Framework | React 18 + TypeScript (strict) | mandated; strict mode because survey data deserves type safety |
| Build | Vite 5 | fast HMR, simple env handling, code splitting |
| UI kit | Bootstrap 5.3 + custom SCSS theme | mandated; dense desktop-GIS theme layered on top |
| Map | OpenLayers 9 | real GIS engine: projections, vector tiles, editing interactions, snapping |
| Projections | proj4 + `ol/proj/proj4`, registered from `/api/v1/crs` | PRS92 and Luzon 1911 zones are not built into OL |
| HTTP client | **Axios**, wrapped by a single `apiClient` with interceptors | mandated by the stack; one place for auth refresh, `request_id`, envelope unwrapping, and error mapping |
| Server state | TanStack Query v5 over the Axios client | caching, invalidation, optimistic updates, retry — replaces most hand-written async state |
| Client state | Zustand | map/UI state (active tool, selection, panel layout) without Redux boilerplate |
| Forms | React Hook Form + Zod | schema-driven validation that mirrors server rules; needed for metadata-driven forms |
| Tables | TanStack Table v8 (headless) + Bootstrap markup | server-side pagination/sort/filter, no imposed styling |
| Routing | React Router v6 (data routers) | nested layouts, route-level permission guards |
| Charts/diagrams | lightweight SVG components (no heavy chart lib at v1) | closure diagrams and traverse plots are custom anyway |
| i18n | `react-i18next`, all strings externalised from day one | Filipino localisation later without a rewrite |
| Testing | Vitest + Testing Library; Playwright for E2E | matches `specification.md` §10 |

Explicitly rejected: any React wrapper library around OpenLayers (they lag OL releases and obscure the interaction lifecycle); a component library that fights Bootstrap; storing tokens in `localStorage` (access token lives in memory, refresh token is an httpOnly cookie).

---

## 2. Application shell and layout

```text
┌────────────────────────────────────────────────────────────────────────────┐
│ AppHeader:  logo · global search · CRS/coords readout · notifications ·    │
│             user menu · environment badge (non-prod)                        │
├──────────┬──────────────────────────────────────────────┬──────────────────┤
│ Left     │  MapToolbar (pan/select/draw/edit/measure/   │ Right dock       │
│ dock     │             identify/snap/undo)              │                  │
│          │                                              │  Properties      │
│ Layers   │                                              │  Feature info    │
│ Legend   │              MAP CANVAS                      │  Parcel summary  │
│ Search   │                                              │  Validation      │
│ Results  │                                              │  History         │
│          │  MapStatusBar: scale · coords · CRS · zoom   │                  │
├──────────┴──────────────────────────────────────────────┴──────────────────┤
│ Bottom dock (resizable, tabbed, collapsible)                                │
│  Attribute Table │ Technical Description │ Computation │ Validation │ History│
└────────────────────────────────────────────────────────────────────────────┘
```

Behaviour rules:

- All three docks are resizable and collapsible; sizes and open tabs persist per user in `localStorage` (UI preference only — never data).
- The map never unmounts while inside the workspace route. Panels change around it. This is the single biggest perceived-performance decision in the app: remounting an OL map loses tile cache, view state, and interaction state.
- The bottom dock is where survey work happens, so it can be expanded to ~70 % height without disturbing the layer panel.
- A distinct **Parcel Editor** route exists for focused, form-heavy work, with a live map preview pane rather than the full workspace.

---

## 3. Routing

```text
/login
/forgot-password
/                                   → redirect to /map
/map                                MapWorkspace (map + docks)
  /map/layers/:layerId              layer focused, attribute table open
  /map/features/:featureId          feature selected, properties open
/parcels                            ParcelListPage (grid + filters + map preview)
/parcels/new                        ParcelEditor (create)
/parcels/:id                        ParcelEditor
  /parcels/:id/information
  /parcels/:id/survey
  /parcels/:id/title
  /parcels/:id/technical-description
  /parcels/:id/computation
  /parcels/:id/validation
  /parcels/:id/documents
  /parcels/:id/history
/control-points                     ControlPointListPage
/control-points/:id                 ControlPointEditor
/survey-plans  /survey-plans/:id
/titles        /titles/:id
/search                             SearchResultsPage (deep-linkable)
/parcels/:id/split                  SplitWorkspace (map + preview + child forms)
/parcels/consolidate                ConsolidationWorkspace (selection → preview → form)
/parcels/:id/lineage                LineageView (genealogy graph)
/reports       /reports/:code       ReportsSection
/import        /import/:jobId       ImportWizard (GeoJSON/CSV/KML/SHP/GPKG/DXF)
/export
/admin/basemaps                     BasemapManager
/admin/layers  /admin/layers/:id    LayerDesigner (fields, styles, permissions)
/admin/users   /admin/users/:id
/admin/roles   /admin/roles/:id
/admin/organizations
/admin/crs
/admin/audit
/admin/settings
/403  /404  /error
```

Route guards: `<RequireAuth>` then `<RequirePermission codes={[...]} mode="any|all">`. A guard failure renders an explanatory page naming the missing permission — not a blank screen. Guards are a UX convenience; the server is the control (`specification.md` SR-02).

---

## 4. Component hierarchy

```text
<App>
 ├─ <QueryClientProvider> <AuthProvider> <ConfigProvider> <I18nProvider> <ToastProvider>
 └─ <RouterProvider>
     ├─ <AuthLayout>            → <LoginPage> <ForgotPasswordPage>
     └─ <AppLayout>
         ├─ <AppHeader>
         │   ├─ <GlobalSearchBox>        (typeahead, grouped by entity type)
         │   ├─ <CoordinateReadout>      (CRS selector, live cursor coords)
         │   ├─ <NotificationBell>
         │   └─ <UserMenu>
         ├─ <MapWorkspace>
         │   ├─ <LeftDock>
         │   │   ├─ <LayerPanel>
         │   │   │   ├─ <LayerTree>            (drag-reorder, group nodes)
         │   │   │   │   └─ <LayerTreeNode>    (toggle, opacity, ⋮ menu)
         │   │   │   ├─ <LayerLegend>
         │   │   │   └─ <AddLayerButton>       (permission-gated)
         │   │   ├─ <SearchPanel>              (spatial + attribute search)
         │   │   └─ <SelectionPanel>           (current selection set, actions)
         │   ├─ <MapContainer>
         │   │   ├─ <MapToolbar>
         │   │   │   ├─ <NavTools> <SelectTools> <DrawTools> <EditTools>
         │   │   │   ├─ <MeasureTools> <IdentifyTool> <SnapToggle>
         │   │   │   └─ <UndoRedo>
         │   │   ├─ <MapCanvas>                (owns the ol/Map instance)
         │   │   ├─ <MapOverlays>              (popups, tooltips, vertex badges)
         │   │   ├─ <BaseMapSwitcher>
         │   │   ├─ <ScaleBar> <NorthArrow> <ZoomControls>
         │   │   └─ <MapStatusBar>
         │   ├─ <RightDock>
         │   │   ├─ <FeaturePropertiesPanel>   (metadata-driven form)
         │   │   ├─ <ParcelSummaryPanel>
         │   │   ├─ <ValidationPanel>
         │   │   └─ <HistoryPanel>
         │   └─ <BottomDock>
         │       ├─ <AttributeTable>
         │       ├─ <TechnicalDescriptionPanel>
         │       ├─ <ComputationPanel>
         │       └─ <RecordHistoryPanel>
         ├─ <ParcelEditor>                     (tabbed, see §7)
         ├─ <ControlPointEditor>
         ├─ <ImportWizard>
         ├─ <AdminSection>
         │   ├─ <LayerDesigner>
         │   │   ├─ <LayerMetadataForm> <FieldDesigner> <StyleDesigner>
         │   │   └─ <LayerPermissionMatrix>
         │   ├─ <UserManager> <RoleManager> <ScopeEditor>
         │   ├─ <CrsRegistryPage> <AuditBrowser> <SystemSettings>
         │   └─ …
         └─ <GlobalDialogs>                    (conflict, confirm, reason prompt)
```

### 4.1 Shared primitives (`components/common`)

`DataGrid`, `Pagination`, `FilterBar`, `PermissionGate`, `ScopeBadge`, `ProvenanceBadge`, `StatusBadge`, `ConfirmDialog`, `ReasonDialog`, `ConflictDialog`, `EmptyState`, `ErrorState`, `LoadingSkeleton`, `AsyncBoundary`, `FieldRenderer`, `CoordinateInput`, `BearingInput`, `DistanceInput`, `CrsSelect`, `PsgcSelect`, `FileDropzone`, `DiffViewer`, `Toast`.

---

## 5. Map architecture

### 5.1 Ownership model

`<MapCanvas>` creates exactly one `ol/Map` in a `useRef` and never recreates it. Everything else talks to it through services held in a `MapContext`:

```text
MapContext
 ├─ map: ol.Map
 ├─ layerManager   : reconciles API layer definitions → OL layers (add/remove/reorder/opacity/style)
 ├─ interactionMgr : arms exactly one edit interaction at a time; owns undo/redo stack
 ├─ selectionMgr   : selection set, sync with attribute table and right dock
 ├─ styleFactory   : StyleRule (JSON) → ol/style/Style, memoised by rule hash
 ├─ measureService : distance/area with unit conversion
 └─ previewLayer   : ephemeral vector layer for computation previews (never persisted)
```

React components are declarative shells; OL mutations go through the managers. No component reaches into `map.getLayers()` directly.

### 5.2 Layer source selection

Driven by layer metadata (`feature_count_cache`, `render_mode`), matching `architecture.md` §4.1:

```text
render_mode = "geojson"  → ol/source/Vector with a bbox loading strategy
                           (ol.loadingstrategy.bbox), refetch on moveend
render_mode = "mvt"      → ol/source/VectorTile → /api/v1/tiles/{id}/{z}/{x}/{y}.mvt
render_mode = "raster"   → ol/source/XYZ (base maps only)
```

The client never issues an unbounded feature request. Editing an MVT layer works by clicking a tile feature, fetching the authoritative GeoJSON for that single id, and editing it in an overlay vector layer.

### 5.3 Editing

- One active tool at a time; switching tools with unsaved geometry prompts.
- `Draw` → geometry sketch → metadata-driven attribute form → save.
- `Modify` + `Snap` (snap sources = the edited layer plus any layer flagged as a snap target) + `Translate`.
- Vertex count, self-intersection, and ring closure are checked client-side for instant feedback; the server's `ST_IsValid` verdict is authoritative and overrides an optimistic client pass.
- Undo/redo is a bounded stack of geometry snapshots in the interaction manager.
- Saving sends `If-Match`; a 409 opens `<ConflictDialog>` showing your version, the current version, who changed it, and when — with "reload theirs" or "review differences", never a blind overwrite.

### 5.4 Coordinate and CRS display

`<CoordinateReadout>` lets the user pick a display CRS (WGS84 lat/long, PRS92 zone E/N, or a historical zone). Transformation for display uses proj4 client-side and is clearly labelled as a display conversion. Original survey coordinates are always rendered in their native CRS with the CRS name adjacent — the UI never shows a number without its reference system.

### 5.5 Performance rules for the map

- Style objects memoised by rule hash; never construct `ol/style/Style` inside a render loop.
- Vector layers use `declutter` for labels; labels disabled below the layer's `min_zoom`.
- `updateWhileAnimating`/`updateWhileInteracting` disabled for heavy layers.
- Feature queries debounced on `moveend` (250 ms) and cancelled via `AbortController` on rapid panning.
- Preview geometry lives in a separate, non-interactive layer so it never enters the edit or selection paths.

---

## 6. Metadata-driven forms and tables

### 6.1 `<FieldRenderer>`

The single component that turns a `gis_layer_fields` record into an input:

```text
text        → <input type=text>            long_text   → <textarea>
integer     → numeric input, step 1        decimal     → numeric input, configurable precision
boolean     → switch                        date        → date picker
datetime    → datetime picker               dropdown    → <select> from options
multi_select→ multi-select chips            email/phone/url → typed input + format validation
```

A Zod schema is generated at runtime from the field metadata, so client validation always mirrors the server rules in `specification.md` §5.3. When metadata changes, the form changes — no component edits. `visible`, `editable`, `required`, and `is_pii` from metadata combine with the user's permissions to decide render/disable/omit; PII fields the user cannot see are absent from the payload, not merely hidden.

### 6.2 `<AttributeTable>`

Server-driven `TanStack Table`: pagination, sort, and filters are URL/query-state, so a filtered view is shareable and survives reload. Column visibility and order persist per user per layer. Row → map highlight and map → row highlight are two-way through `selectionMgr`. Bulk actions (delete, export, zoom to selection) are permission-gated and confirmed. Filter-by-map-extent is a toggle that adds the current bbox to the query.

---

## 7. Parcel editor

Tabs, each independently loadable, with a persistent right-hand map preview and a sticky status/action bar (current status, provenance badge, version, workflow actions).

| Tab | Contents |
|---|---|
| **Information** | lot/block, PSGC location cascade, tax declaration, source area + unit, location description, remarks, provenance selector with inline explanation of each value |
| **Survey** | survey plan link/create, survey type, surveyor, approval details |
| **Title** | linked titles; owner/party section rendered only with `title.view_owner`, with a visible "sensitive personal information" marker and an audit notice |
| **Tie point** | control-point picker (search, nearest-to-map-click, or create), showing native coordinates, CRS, datum, accuracy, verification status; an unverified point shows a persistent warning |
| **Technical description** | §8 below |
| **Computation** | §9 below |
| **Validation** | §10 below |
| **Documents** | dropzone, classification, type, preview, link management |
| **History** | version timeline, diffs, workflow transitions, audit entries, restore action |

Draft autosave is explicit and visible ("Saved 14:32" / "Unsaved changes"), never silent. Navigating away with unsaved changes prompts.

---

## 8. Technical description UI

```text
Source: ( ) Manual entry   ( ) Paste text   ( ) From document (OCR)      Revision 3 · DRAFT
Bearing reference: [GRID ▾]    Distance unit: [meters ▾]    Compute CRS: [PRS92 Zone III ▾]

TIE LINE
From control point [BLLM-3  🔍]  ⚠ Unverified
  Bearing [N] [25]°[30]'[00]" [E]      Distance [120.450] [m]        → to POB

COURSES                                              [+ Add] [Validate] [Reorder ⇅]
┌───┬──────┬──────┬────────────────────┬──────────┬──────┬─────────┬──────────┐
│ # │ From │ To   │ Bearing            │ Distance │ Unit │ Type    │ Remarks  │
├───┼──────┼──────┼────────────────────┼──────────┼──────┼─────────┼──────────┤
│ 1 │ 1    │ 2    │ [N][25][30][00][E] │ [45.200] │ m    │ Line    │          │
│ 2 │ 2    │ 3    │ [S][64][30][00][E] │ [30.000] │ m    │ Line    │          │
└───┴──────┴──────┴────────────────────┴──────────┴──────┴─────────┴──────────┘
Computed azimuth is shown read-only beside each bearing (e.g. 25.5000°).
```

- `<BearingInput>` is a compound control: quadrant letter, degrees, minutes, seconds, quadrant letter — with a paste-parse fallback that accepts `N 25°30' E`, `N25-30-00E`, `25-30-00`, `DUE NORTH`. It shows the derived azimuth live and validates per VR-01…VR-07 as you type.
- Reorder by drag, with renumbering shown before it is applied.
- The map preview redraws the open traverse after every valid edit (debounced), including an explicit **open-polygon indicator** when the traverse does not close — the user sees the gap rather than a tidied-up shape.
- Delete/reorder on a confirmed revision creates a new revision rather than mutating the confirmed one.

### 8.1 Parse-and-review

```text
┌─ Paste technical description ───────────────────────────────────────┐
│ [textarea]                                            [Parse]        │
├─ Parsed result — REVIEW REQUIRED ───────────────────────────────────┤
│ Source text (highlighted) │ Extracted courses (editable)             │
│ "…thence N 25°30' E,      │ #1  N 25°30'00" E   45.20 m   ● high     │
│  45.20 meters to point 2" │ #2  S 64°30'00" E   30.00 m   ● high     │
│                           │ #3  S ??°??' W      22.1? m   ▲ low      │
├──────────────────────────────────────────────────────────────────────┤
│ ▲ 1 course needs attention. Confirmation is disabled until resolved. │
│                                    [Discard]  [Confirm and continue] │
└──────────────────────────────────────────────────────────────────────┘
```

The confirm button is the only path from parsed to usable data, it is disabled while any course is unresolved, and confirming is recorded in the audit log as a distinct user action (FR-100). Parsed data is visually distinct from confirmed data until that click.

---

## 9. Computation panel

```text
[ Compute ]   Compute CRS: PRS92 Zone III (EPSG:3123)   Engine v1.0.0   Run 2026-09-19 11:04

INPUT SUMMARY            CALCULATED COORDINATES              CLOSURE
Tie point  BLLM-3 ⚠      ┌────┬────────────┬─────────────┐   ΔE            +0.014 m
Tie line   1             │ Pt │ Easting    │ Northing    │   ΔN            −0.009 m
Courses    8             ├────┼────────────┼─────────────┤   Linear error   0.017 m
Perimeter  412.88 m      │POB │ 512345.679 │ 1678901.235 │   Relative      1:24,872
                         │ 1  │ 512365.121 │ 1678942.010 │   Status        ✓ Within tolerance
                         └────┴────────────┴─────────────┘

AREA                                             GEOMETRY PREVIEW
Source (title/plan)   10,400.0000 m²             ┌──────────────────────┐
Computed (shoelace)   10,432.7412 m²             │   [traverse + map]   │
PostGIS cross-check   10,432.7408 m²             │  closing gap shown   │
Difference            +32.7412 m² (+0.3148 %)    └──────────────────────┘
ⓘ Area comparison is a validation aid, not a determination of correctness.

WARNINGS
▲ VR-19 Tie point BLLM-3 is unverified.

[ View input snapshot ]  [ Discard ]  [ Accept computation → parcel geometry ]
```

- Nothing is written to the parcel until **Accept computation**. Until then this is a preview of a stored, immutable computation run.
- "View input snapshot" opens exactly what the engine was given, including tie-point coordinates as they were at run time.
- Previous runs are listed with timestamp, operator, closure, and area so runs can be compared; none are ever deleted.
- Adjustment (when implemented) is a separate action producing a new run shown alongside the original — never replacing it.
- Closure that exceeds tolerance is displayed in a failure style with the numbers still fully visible. The UI never rounds a bad closure into looking acceptable.

---

## 10. Validation panel

```text
SURVEY VALIDATION                                 Last run 2026-09-19 11:05
───────────────────────────────────────────────────────────────────────────
✓ Technical description parsed and confirmed
✓ Tie point found                          BLLM-3
▲ Tie point verification                   UNVERIFIED — verify before approval
✓ CRS identified                           PRS92 Zone III (EPSG:3123)
✓ Bearings valid                           8 of 8
✓ Distances valid                          8 of 8
✓ Polygon closed                           0.017 m · 1:24,872
✓ Geometry valid                           ST_IsValid · ST_IsSimple
✓ Area calculated                          10,432.7412 m²
▲ Area differs from source                 +0.3148 %  (aid only, not a determination)
▲ Overlaps existing parcel                 LOT-0042 · 12.4 m²  [ View on map ]
───────────────────────────────────────────────────────────────────────────
2 warnings · 0 blocking errors      [ Re-run validation ]  [ Submit for review ]
```

Warnings are expanded by default, carry their rule id, and remain attached to the record through submission and approval so a reviewer sees exactly what the encoder saw. Blocking errors disable submission and say why. There is no "dismiss all" control.

---

## 11. State management

| State | Owner | Notes |
|---|---|---|
| Server data (layers, features, parcels, lookups) | TanStack Query | keys `['layers']`, `['layer', id, 'features', bboxKey]`, `['parcel', id]`, `['parcel', id, 'computations']`; staleness tuned per resource (config 1 h, features 30 s) |
| Auth/session | `AuthProvider` + Zustand | access token in memory only; silent refresh on 401 then one retry; global logout on refresh failure |
| Permissions | `AuthProvider`, from `/me` | `usePermission('parcel.approve')`, `useLayerCap(layerId, 'update')`, `useScope(entity)` |
| Map/UI | Zustand slices | `activeTool`, `selection`, `visibleLayers`, `dockLayout`, `baseMap`, `displayCrs` |
| Editing drafts | React Hook Form per editor | dirty tracking, navigation guard, explicit save |
| Ephemeral computation preview | Zustand `previewSlice` | cleared on route change; never persisted |

Mutations invalidate narrowly (`['parcel', id]`, not `['parcels']` wholesale) except after workflow transitions, which invalidate the list too. Optimistic updates are used for cheap toggles (layer visibility, notification read) and deliberately **not** for geometry, computations, or workflow transitions — those must reflect server truth.

---

## 12. API integration

- Single `apiClient` (Axios instance + interceptors): base URL from env, `Authorization` injection, `X-Request-Id` generation and capture, unwrapping of the `{success, data, meta}` envelope, mapping of `{success:false, error:{code,message,details}}` into a typed `ApiError` keyed by the documented error-code set, `AbortController`/cancel-token support, 401 → silent refresh → retry-once, 429 → backoff with a user-visible notice.
- Generated TypeScript types from the OpenAPI schema (`openapi-typescript`) so request/response shapes cannot drift from the backend; drift breaks the build, which is the point.
- Every versioned mutation attaches `If-Match`; `ApiError` of kind `conflict` routes to `<ConflictDialog>`.
- GeoJSON parsed with `ol/format/GeoJSON` configured with `dataProjection: 'EPSG:4326'`, `featureProjection: 'EPSG:3857'` — never with ad-hoc coordinate maths.

---

## 13. Permission-aware UI

```tsx
<PermissionGate codes={['parcel.approve']} fallback={null}>
  <ApproveButton />
</PermissionGate>

<PermissionGate layer={layerId} capability="update" mode="disable"
                reason="You do not have edit rights on this layer.">
  <EditButton />
</PermissionGate>
```

Rules:

1. No component contains a hard-coded role name. Only permission codes and layer capabilities, both fetched from `/me`. (Master prompt §23, §48.13.)
2. `mode="hide"` for actions the user should never see; `mode="disable"` with a reason where absence would be confusing. Destructive and approval actions are hidden, not greyed.
3. A 403 from the server is always treated as the truth, even when the UI thought the action was allowed — it surfaces as an explicit message naming the missing permission.
4. Out-of-scope records simply do not appear (the API returns 404), so the UI needs no special rendering for them.
5. `scope_version` change in a `/me` refetch invalidates permission-derived UI immediately.

---

## 14. Loading, empty, and error states

| State | Treatment |
|---|---|
| Initial load | skeletons matching final layout (grid rows, panel blocks); never a full-page spinner after login |
| Map tile load | subtle progress bar in the status bar; the map stays interactive |
| Background refetch | thin top progress bar; existing data stays visible, never blanked |
| Empty layer | `<EmptyState>` with the reason (no data / filtered out / outside your scope) and the relevant next action |
| No search results | suggestions: check spelling, widen area, try a different type |
| Network error | inline retry on the failing panel; the rest of the app keeps working |
| 403 | explicit message naming the missing permission and who to ask |
| 409 | `<ConflictDialog>` with both versions and a diff |
| 422 | inline field errors keyed by the server's field path, plus a summary at the top of long forms |
| 500 | error boundary with the `request_id` and a copy button |

Error boundaries wrap the map, each dock, and each route so a failure in one panel cannot take down an in-progress survey entry.

---

## 15. Responsive behaviour

| Breakpoint | Behaviour |
|---|---|
| ≥ 1280 px | full three-dock workspace; the design target |
| 992–1279 px | docks collapse to icon rails, opening as overlays |
| 768–991 px (tablet) | map-first; layer panel and attribute table as full-height drawers; viewing, identify, search, and validation review supported; drawing supported with touch-friendly vertex handles; complex survey entry discouraged with a notice |
| < 768 px (phone) | read-only: search, view parcel summary, map identify, validation results. Editing and computation routes show "use a larger screen" rather than a broken form |

Touch: larger hit targets on map controls, long-press for context menus, pinch zoom, two-finger pan.

---

## 16. Styling and theme

- Bootstrap 5 with a custom SCSS layer: compact spacing scale for a dense GIS UI, a neutral map-friendly palette so map colours dominate, monospace for coordinates, bearings, and areas (alignment matters when scanning a course table).
- Semantic status colours used consistently: `draft` neutral, `submitted/under-review` info, `returned` warning, `verified` accent, `approved` success, `archived` muted, `error` danger. Colour is never the only signal — every status also carries a label and icon.
- `<ProvenanceBadge>` appears wherever parcel geometry is shown, printed, or exported; it is not optional and not dismissible.
- Dark mode: out of scope for v1; colour tokens are defined as CSS variables so it remains possible.

---

## 17. Frontend testing

| Layer | Tool | Scope |
|---|---|---|
| Unit | Vitest | bearing/coordinate formatters, Zod schema generation from field metadata, permission hooks, scope logic, unit conversion display |
| Component | Testing Library | `<FieldRenderer>` per data type, `<BearingInput>` parsing and validation, `<AttributeTable>` interactions, `<PermissionGate>` modes, `<ConflictDialog>` |
| Map | Vitest + jsdom with an OL test harness | layer reconciliation, style factory output, interaction arming/disarming, preview layer isolation |
| E2E | Playwright | the `specification.md` §10 workflow list, plus a negative pass per permission boundary and a 409 conflict scenario using two browser contexts |
| Visual | Playwright screenshots on key panels | catches layout regressions in the dense workspace |

No test mocks away authorization: E2E runs as real seeded users with real roles and scopes.

---

## 18. Frontend conventions

```text
src/
  api/          apiClient, generated types, per-resource hooks (useParcels, useLayers…)
  auth/         AuthProvider, guards, permission hooks
  components/   common/ · layout/ · map/ · forms/ · tables/ · dialogs/
  features/
    map/        MapWorkspace + map services (layerManager, interactionMgr, styleFactory)
    layers/     LayerPanel, LayerDesigner, FieldDesigner, StyleDesigner
    parcels/    ParcelEditor + tabs
    survey/     BearingInput, CourseTable, ComputationPanel, ValidationPanel
    control-points/  titles/  documents/  import-export/  search/  admin/  audit/
  hooks/        useDebounce, useAbortable, usePersistentState, useMapContext
  lib/          formatters (bearing, coordinate, area, distance), zodFromFieldMeta, crs
  store/        zustand slices
  styles/       _variables.scss, theme.scss
  i18n/         en/*.json
  types/        domain types (generated + hand-written)
```

Rules that follow from the master prompt and are enforced by lint/review:

1. **No survey mathematics in any component.** Formatting and display only; every computation comes from the API. (§48.14)
2. **No hard-coded layers, fields, styles, roles, or permission strings beyond the permission-code constants file.** (§48.12, §48.13)
3. **No `dangerouslySetInnerHTML`.**
4. **No secrets or API keys in the bundle**; base-map tokens are proxied or supplied by `/api/v1/config`. (§39)
5. **No unbounded feature requests** — every feature query carries a bbox or a bounded page.
6. One component per file, named exports, props typed explicitly, no `any` in `src/features` or `src/lib`.
7. All user-facing strings go through i18n from the first commit.

---

## 19. Frontend module map

The application is organised into the modules named in the master prompt; each is a folder under `src/features/` with its own routes, hooks, and components.

```text
Dashboard · Map · Layers · Features · Parcels · Titles · Survey Plans ·
Technical Descriptions · Control Points · Survey Computation ·
Split/Consolidation · Documents · Search · Reports · Users · Roles ·
Audit · Basemaps · Import/Export · Settings
```

Navigation: a left icon rail switches modules; the map workspace is the default landing surface, and modules that benefit from a map (parcels, control points, split/consolidation, search) reuse the same map instance rather than mounting their own. Modules the user lacks permission for are absent from the rail, not greyed.

---

## 20. Manual parcel drawing

```text
New parcel → [Draw polygon] → vertices placed (snap on, undo/redo active)
           → live readout: vertex count · perimeter · area (in the selected CRS)
           → finish → attribute form → Save draft
```

The provenance selector defaults to `MANUAL_DRAWING`, or to `DIGITIZED_FROM_IMAGERY` when an imagery basemap is the active backdrop — and in the latter case a persistent inline notice states that imagery is visual context and the resulting boundary is approximate. Switching provenance to a survey-derived value is disabled until survey data is attached, and when it becomes possible it requires a typed justification.

---

## 21. Split and consolidation UI

### 21.1 Split

```text
Parcel LOT-100 · PUBLISHED · 10,432.74 m²                    [Cancel] [Preview]
Method: (•) Map split line  ( ) Survey geometry  ( ) Technical description  ( ) Imported

 ┌─ MAP ─────────────────────────────────────┐  ┌─ RESULT PREVIEW ──────────────┐
 │  parent highlighted                        │  │ Child A   4,210.11 m²  40.4 % │
 │  draw split line (snapping on)             │  │ Child B   6,222.63 m²  59.6 % │
 │  candidate children shaded distinctly      │  ├───────────────────────────────┤
 └────────────────────────────────────────────┘  │ Σ children  10,432.74 m²      │
                                                  │ Parent      10,432.74 m²      │
 VALIDATION                                       │ Difference   0.00 m² (0.00 %) │
 ✓ Child geometries valid   ✓ No overlaps         └───────────────────────────────┘
 ✓ Union matches parent (Δ 0.004 m²)
 ▲ Child B area below the barangay minimum lot size — review required

 [Enter child parcel details]   [ Commit split ]  ← disabled while blocking errors exist
```

Rules: **Preview is mandatory** and runs the full server-side validation path with `dry_run`; the commit button is only enabled from a successful preview of the current inputs, and editing anything invalidates the preview. Committing opens a reason prompt, then shows the resulting lineage. Nothing about the parent is destroyed, and the UI says so explicitly ("LOT-100 will be kept as a superseded historical record").

### 21.2 Consolidation

```text
Select parcels (map click, list multi-select, or search) → 3 selected
 ┌─ VALIDATION ─────────────────────────────────────────────────────────────┐
 │ ✓ All geometries valid     ✓ Same CRS (PRS92 Zone III)                   │
 │ ✓ No overlaps              ✓ Union is contiguous                          │
 │ ▲ Sliver gap 0.006 m² between LOT-11 and LOT-12 — below tolerance        │
 └───────────────────────────────────────────────────────────────────────────┘
 Union preview on map · Σ parents 3,120.40 m² · Union 3,120.39 m² · Δ −0.01 m²
 [New parcel information]  [ Commit consolidation ]
```

Blocking failures (overlaps, non-contiguous union, mixed CRS, ineligible status) are shown with the offending parcels highlighted on the map and a "zoom to problem" action, never as a generic failure toast.

### 21.3 Lineage view

An interactive genealogy graph: parents above, children below, each node showing lot number, status badge, area, and effective date; each edge labelled with its relationship type and linking to the operation record. Superseded nodes are visually distinct but fully navigable. Controls: expand ancestors/descendants, depth limit, "show on map", and export to the Lineage report.

---

## 22. Basemap manager UI

An admin table of providers with type, status, licence type, expiry, and attribution presence. The editor exposes service configuration, zoom range, bounds, and role restrictions. Behaviour the UI enforces visibly:

- The API-key field shows only the **environment variable name**, never a value — there is no control anywhere in the SPA that displays or accepts a secret.
- Licence type, reference, and expiry are required to enable a provider; an expired or unlicensed provider shows a red state and the enable toggle is disabled with the reason inline.
- A preview pane renders the provider in a small map before saving.
- The end-user basemap switcher lists only providers the user's role may see, always with attribution rendered on the map.

---

## 23. Reports UI

A reports index grouped by subject (Parcel, Survey, Title, Administration), each opening a parameter form (record, date range, scope) then an on-screen render with an export action. Every rendered report shows its header block — generated at, generated by, data as of, CRS/datum, provenance, and the non-certification disclaimer — on screen and in the exported file. Draft and superseded records produce a visible watermark. Long-running reports switch to a job with a progress indicator and a notification on completion.

---

## 24. Conflict, retry and failure behaviour

- **409 conflict** opens `<ConflictDialog>` offering: reload theirs, compare (field and geometry diff), or create a new version from my edits — never a blind overwrite (`specification.md` FR-043).
- **Operation failure mid-transaction** (split, consolidation, import commit) always reports that nothing was applied, because the server guarantees it; the UI never shows a partially applied state.
- **Blocking validation** disables the primary action and lists each failed rule with its id and a "show me" action that highlights the cause on the map or in the course table.
- Retries are bounded: an automatic retry happens once for idempotent GETs and refresh-after-401; everything else requires an explicit user action, so no operation is silently repeated.
