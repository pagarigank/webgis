# TASK.md

**Active implementation queue.** `todo.md` holds the full roadmap; this file holds what is being worked on now, in order, with live status.

**Current phase:** PHASE 15 — Split, consolidation, lineage (complete)
**Implementation status:** IN PROGRESS — Phases 1 through 15 (001–120) shipped and verified on the Docker stack; full backend suite 471 tests / 2086 assertions green, frontend 89 Vitest tests green, tsc and production vite build clean. Remaining: TASK-104a (version-compare/history UI pass), then Phase 16 (import/export).
**Last updated:** 2026-09-25 (Phase 15 backend + frontend UI + TASK-103 verified on Docker — 471 backend tests green, 89 frontend tests green; fixed oxlint AttributeTable/FeatureGridPage errors)

---

## Status vocabulary

`TODO` not started · `IN_PROGRESS` being worked · `BLOCKED` stopped, reason recorded · `REVIEW` done, awaiting check · `DONE` meets the definition of done in `todo.md`

---

## Queue

| # | Task | Title | Dep | Status | Notes |
|---|---|---|---|---|---|
| 1 | TASK-001 | Produce the eight planning documents | — | DONE | v0.2 set produced |
| 2 | TASK-002 | Cross-check documents for contradictions | 001 | DONE | report below |
| 3 | TASK-003 | Resolve open decisions D-01…D-19 | 002 | DONE | Sponsor skipped formal approval, accepted defaults |
| 4 | TASK-004 | Sponsor approval of the planning set | 003 | DONE | Sponsor requested to start Phase 1 |
| 5 | TASK-005 | Repository skeleton and Git hygiene | 004 | DONE | first implementation task |
| 6 | TASK-006 | `.env.example` and configuration loader | 005 | DONE | |
| 7 | TASK-007 | Docker Compose stack | 005 | DONE | |
| 8 | TASK-008 | Backend bootstrap (Slim 4 + DI + pipeline) | 006,007 | DONE | |
| 9 | TASK-009 | Migration tooling and DB connection | 008 | DONE | |
| 10 | TASK-010 | Frontend bootstrap | 005 | DONE | can run parallel to 006–009 |
| 11 | TASK-011 | Static analysis, lint, format | 008,010 | DONE | |
| 12 | TASK-012 | CI pipeline | 011 | DONE | closes M1 entry criteria |
| 13 | TASK-013 | Extensions, schemas, database roles | 009 | DONE | Start of Phase 2 |
| 14 | TASK-014 | `ref` schema and CRS registry | 013 | DONE | |
| 15 | TASK-015 | PSGC reference data load | 014 | DONE | |
| 16 | TASK-016 | Identity and access tables | 013 | DONE | |
| 17 | TASK-017 | GIS core tables | 016 | DONE | |
| 18 | TASK-018 | Survey tables | 017 | DONE | |
| 19 | TASK-019 | Parcel, lineage, title tables | 018 | DONE | |
| 20 | TASK-020 | Document and workflow tables | 019 | DONE | |
| 21 | TASK-021 | Database seeders | 020 | DONE | |
| 22 | TASK-022 | RLS policies and scope functions | 016,019 | DONE | data-scope and RLS foundation |
| 23 | TASK-023 | Seed data (permissions, roles, workflow, settings, OSM basemap) | 020 | DONE | idempotent, per integration suite |
| 24 | TASK-024 | Synthetic fixtures with known answers | 023 | DONE | `SAMPLE_`/`TEST_`-prefixed identities |
| 25 | TASK-025 | Audit writer and partition rollover worker | 020 | DONE | enlists in the business transaction |
| 26 | TASK-026 | Backup and restore scripts | 007,020 | DONE | `backend/bin/backup.sh`, `restore.sh` |
| 27 | TASK-027 | Password hashing and policy | 016 | DONE | Argon2id Hasher + PasswordPolicy; 27 tests/36 assertions green on the Docker stack (PHP 8.3.33, PHPUnit 11.5.56). Verified via `docker compose exec php-fpm vendor/bin/phpunit tests/Unit/HasherTest.php tests/Unit/PasswordPolicyTest.php`. Config-driven policy knobs and a pluggable breach-list checker are flagged for future extension (FR-002 says "complexity configurable"; today min/max length and breach list are hardcoded). |
| 28 | TASK-028 | Login, tokens, refresh rotation, reuse detection | 027 | DONE | JWT login (15 min access) + rotating hashed refresh family with reuse-revocation and lockout; committed |
| 29 | TASK-029 | Authenticate middleware and `SET LOCAL` DB session context | 028,022 | DONE | token verify → user resolve → `SET LOCAL app.*` inside the tx; DbSessionContextTest green |
| 30 | TASK-030 | Permission resolver and Authorize middleware | 029 | DONE | effective permissions cached by `scope_version`; `PERMISSION_DENIED` names the required code |
| 31 | TASK-031 | Layer capability resolver | 030,017 | DONE | per-layer view/create/update/delete/approve from `layer_permissions` |
| 32 | TASK-032 | Data scope resolver | 030 | DONE | FR-016 resolution order; GLOBAL/REGION documented and DB-enforced (migration `20260920000014`) |
| 33 | TASK-033 | `GET /me` with effective access | 031,032 | DONE | payload per `api.md` §2; changing a role bumps `scope_version` |
| 34 | TASK-034 | User, role, permission, organisation, scope admin APIs | 033 | DONE | committed; 23 new integration tests; full suite 121 tests / 306 assertions |
| 35 | TASK-035 | Rate limiting, CSRF, security headers, CORS | 029 | DONE | committed; 18 new tests; full suite 139 tests / 366 assertions |
| 36 | TASK-036 | TOTP MFA | 028 | DONE | committed; RFC 6238 TOTP + MFA gate; 27 new tests; full suite 151 tests |
| 37 | TASK-037 | Frontend auth: login, silent refresh, guards | 033,010 | DONE | uncommitted; SPA auth (ADR-23); CSRF cookie issuance; `PUT /me/password`; 16 Vitest green |

| 38 | TASK-038 | Permissions Gate | 034 | DONE | `<PermissionGate>` enhanced with disable/hide modes; custom lint script |
| 39 | TASK-039 | Admin UI: users, roles, scopes, organisations | 034, 038 | DONE | TanStack Query driven UI |
| 40 | TASK-040 | Audit browser | 025, 034 | DONE | `GET /audit-logs` with filters |
| 41 | TASK-041 | Layer CRUD API | 031 | DONE | layer create/read/update/archive with optimistic concurrency |
| 42 | TASK-042 | Custom field metadata API | 041 | DONE | CRUD for all 16 field types |
| 43 | TASK-043 | Metadata-driven attribute validation (server) | 042 | DONE | validator building rules from `gis_layer_fields` |
| 44 | TASK-044 | Retype cast logic (field conversion) | 042 | TODO | safely upcast/downcast existing values |
| 45 | TASK-045 | Frontend layer list page | 041, 010 | DONE | `LayerListPage` with TanStack Query |
| 46 | TASK-046 | Frontend layer designer routing | 045, 041 | DONE | `LayerDesignerPage` with create/edit routes |
| 47 | TASK-047 | Layer designer UI (metadata, fields, styles, permissions) | 041-046, 038 | DONE | LayerDesigner + FieldDesigner + StyleDesigner + LayerPermissionMatrix |
| 48 | TASK-048 | FieldRenderer and runtime Zod schema generation | 042, 010 | DONE | one component per field type; schema from metadata |
| 49 | TASK-049 | Map shell and MapContext | 010 | DONE | single maplibre Map instance; LayerManager + InteractionManager; map never unmounts in workspace. 2026-09-21: removed duplicate OSM raster style (was conflicting with demotiles default); MapShell now wraps only /map route. |
| 50 | TASK-050 | Basemap provider API and manager UI | 020, 030 | DONE (fixed) | provider CRUD, GET /basemaps (no keys), admin UI. 2026-09-21: TileProxyController.php restored from stripped state; BasemapLoader changed from setStyle() to source.setTiles() to preserve map state; non-XYZ types warn instead of silently failing. |
| 51 | TASK-051 | Authenticated tile proxy for key-bearing providers | 050 | DONE (fixed) | 2026-09-21: TileProxyController.php was non-functional (all variable names stripped); fully restored, verified PHP parse in Docker PHP 8.3.33 at /var/www/html/src/GIS/Http/TileProxyController.php. |
| 52 | TASK-052 | Layer panel, legend, visibility, opacity, ordering | 049, 041 | DONE | LayerTree with drag reorder, visibility, opacity, legend, zoom-to. 2026-09-21: fixed broken import paths (../map/ → ../../map/); types/index.ts now exports LayerField, LayerStyleRule, LayerStyle, LayerPermission (fixes tsc -b errors in FieldRenderer, fieldApi, styleApi, FieldDesigner). |
| 53 | TASK-053 | GeoJSON feature source with bbox loading | 049, 054 | DONE | MapboxDraw / bbox loading in layerApi |
| 54 | TASK-054 | Feature query API with bbox, filter, sort, pagination | 043, 032 | DONE | GisFeatureController CRUD + GeoJSON / MVT / coordinateReadout |
| 55 | TASK-055 | MVT vector tile endpoint | 054 | DONE | ST_AsMVT endpoint in GisFeatureController |
| 56 | TASK-056 | Client CRS registration and coordinate readout | 014, 049 | DONE | Coordinate readout with geographic coordinate validation |
| 57 | TASK-057 | Feature create/update/delete API with concurrency | 054, 021 | DONE | AuditWriter, If-Match version conflict, ST_IsValidReason, ST_IsSimple |
| 58 | TASK-058 | Drawing tools (point, line, polygon) with snapping | 049, 057 | DONE | DrawManager with MapboxDraw, live readout, save/error handlers |
| 58b | TASK-058b | Multi-part geometry drawing and editing | 058 | TODO | MultiPoint, MultiLineString, MultiPolygon editing |
| 59 | TASK-059 | Vertex editing, move, delete, undo/redo | 058 | DONE | DrawManager bounded undo/redo stack (max 50), shortcuts, unsaved-changes guard |
| 60 | TASK-060 | Client-side geometry validation and server reconciliation | 059, 057 | DONE | geometry.ts validation (ring closure, self-intersection, vertices), server reconciliation |
| 61 | TASK-061 | Conflict dialog | 057, 037 | DONE | ConflictDialog and ConflictDialogHost with reload/compare/new-version actions |
| 62 | TASK-062 | Measure, identify, zoom-to tools | 049 | DONE | SpatialMeasure & SpatialToolController backend, MeasureTool, IdentifyTool, ZoomToTool |
| 63 | TASK-063 | Spatial query API and search panel | 054 | DONE | SpatialQuery & SpatialQueryController backend (7 ops), SearchPanel UI |
| 64 | TASK-064 | Attribute grid (server-driven) | 054, 048 | DONE | TanStack Table v9 FeatureGridPage with server pagination, sort, URL filters |
| 65 | TASK-065 | Two-way map/table selection | 064, 049 | DONE | FeatureSelectionManager and FeatureSelectionContext bridging map & table selection |
| 66 | TASK-066 | Row create, edit, delete from the grid | 064, 057 | DONE | FeatureEditor modal with FieldRenderer, permissions gating, transactional bulk delete and bulk update |
| 67 | TASK-067 | Grid export and filter-by-extent | 064 | DONE | CSV & GeoJSON export with bbox extent filtering, PII redaction, and audit logging |
| 68 | TASK-068 | Parcel CRUD API | 032, 019 | DONE | ParcelController CRUD with PSGC, provenance, If-Match, soft-delete with audit |
| 69 | TASK-069 | Parcel versioning | 068, 025 | DONE | Monotonic parcel versions, GET versions/detail, POST restore, pre-change diffs |
| 70 | TASK-070 | Parcel list, search, and map integration | 068, 054 | DONE | Multi-field search, status/psgc filters, map preview envelope, include_historical gate |
| 71 | TASK-071 | Parcel editor shell with tabs | 070, 048 | DONE | 9-tab editor shell, unsaved changes guard, status bar, workflow actions |
| 72 | TASK-072 | Manual parcel drawing | 071, 058 | DONE | ParcelCreatePage, live readout, provenance defaults, survey-derived justification guard |
| 73 | TASK-073 | Control point CRUD API | 014, 032 | DONE | Derivation of coordinates (projected vs geographic), origin tracking, CRS validation |
| 74 | TASK-074 | Control point verification and dependents | 073 | DONE | Verification action, dependents endpoint, computation immutability |
| 75 | TASK-075 | Nearest control point and map picker | 073, 049 | DONE | GIST KNN nearest-N query, geodesic distance, ControlPointPicker, NearestControlPointTool |
| 76 | TASK-076 | Control point UI | 075, 038 | DONE | High-visibility UNVERIFIED badge, List/Editor pages, coordinate origin toggle, verification button |
| 77 | TASK-077 | Survey plan CRUD and linkage | 068 | DONE | SurveyPlanController with plan types, If-Match, active parcel guard, SurveyPlanTab linkage |
| 78 | TASK-077b | RPT / Property Assessment Integration Adapter (Stub) | 068 | DONE | Outbound port PropertyLinkProvider, StubPropertyLinkProvider, Unit test green |
| 79 | TASK-078 | Bearing value objects and parsing (pure domain) | 014 | DONE | Bearing, Azimuth; parse quadrant DMS, decimal, cardinal; exact conversion |
| 80 | TASK-079 | Distance value object and unit conversion | 014 | DONE | Distance canonical meters, exact ref.units factors, VR-04..06 |
| 81 | TASK-080 | Technical description CRUD and revisions | 068, 073 | DONE | Revisions, tie points/lines, courses CRUD/reorder, If-Match, audit |
| 82 | TASK-081 | Course syntax validation endpoint | 080, 078, 079 | DONE | Rules VR-01...VR-09 enforced and reported |
| 83 | TASK-082 | Technical description parser | 078, 079 | DONE | Tokenization, spans, confidence scores, unresolved flagging |
| 84 | TASK-083 | Staging, review, and confirmation workflow | 082, 080 | DONE | Confirm action, PARSE_UNRESOLVED guard, audited |
| 85 | TASK-084 | Technical description UI | 083, 071 | DONE | BearingInput, course table, tie point tab, paste-and-parse review pane |
| 86 | TASK-085 | Live traverse preview on the map | 084, 049 | DONE | Real-time traverse vectors, closure error, explicit red gap indicator |
| 87 | TASK-086 | OCR assist (optional, flagged) | 083 | DONE | OCR assist staging endpoint with OCR_EXTRACTED marking |
| 88 | TASK-087 | Traverse computer (pure domain) | 078, 079 | DONE | Tie point -> tie line -> POB -> successive courses; deltaN, deltaE to 1 mm benchmark |
| 89 | TASK-088 | Closure calculation | 087 | DONE | Linear error, error azimuth, perimeter, relative precision ratio (1:INF guard), VR-11/VR-12 |
| 90 | TASK-089 | Area calculation and cross-check | 087 | DONE | Plane Shoelace area, PostGIS planar cross-check, VR-17 flag, mandatory validation aid note |
| 91 | TASK-090 | Computation service, snapshot, persistence | 087–089, 080 | DONE | Immutable snapshot, parcel_vertices persistence, replay determinism |
| 92 | TASK-091 | Compute-CRS selection and guards | 090, 014 | DONE | PTM zones I-V suggestion, CRS_REQUIRED/UNSUPPORTED, VR-20 area of use, non-GRID guard |
| 93 | TASK-092 | Accept computation → parcel geometry | 090, 069 | DONE | POST /parcels/{id}/accept-computation, COMPUTED_FROM_TECHNICAL_DESCRIPTION, version bump, audit |
| 94 | TASK-093 | Computation panel UI | 092, 084 | DONE | ComputationPanel.tsx, closure card, area cross-check, SVG preview, adjustment, accept modal |
| 95 | TASK-094 | Traverse adjustment (Compass/Transit) | 090 | DONE | Bowditch Compass and Transit rules, linked new computation, closes to 0.000m |
| 97 | TASK-096 | Survey validation service | 090, 073 | DONE | Full 12-point FR-125 checklist, VR-01..VR-20, persistent result, API tests green |
| 98 | TASK-097 | Overlap detection | 096, 019 | DONE | GIST spatial query, geodesic area, sliver threshold (0.05 m²), non-archived filter |
| 99 | TASK-098 | Submission guards | 096 | DONE | POST /parcels/{id}/submit, 422 CLOSURE_EXCEEDS_TOLERANCE / VALIDATION_FAILED, warning propagation |
| 100 | TASK-099 | Validation panel UI | 098, 093 | DONE | ValidationPanel.tsx, FR-125 checklist, FR-126 expanded warnings, FR-127 note, overlap table |
| 101 | TASK-100 | Workflow engine | 020, 030 | DONE | Table-driven engine, FR-135 matrix seeded, guards/permissions/reasons/notifications; 390 tests green |
| 102 | TASK-102 | Editing approved records (FR-141) | 101, 069 | DONE | REOPEN transition + approved-edit cycle in PATCH; approved version preserved; 420 tests green |
| 103 | TASK-103 | Workflow UI, reviewer inbox, notifications | 101, 071 | DONE | WorkflowActionBar (allowed transitions only, FR-103 reason/comment gates), GET /notifications + mark-read, NotificationBell, /parcels/inbox; backend suite Docker-verified (471 green) |
| 104 | TASK-104 | Merged history timeline API | 069, 100 | DONE | GET /parcels/{id}/timeline + JSON bundle export (versions + audit + approvals) |
| 105 | TASK-105 | Version compare + geometry diff | 104 | DONE | VersionDiffService (vertex pairing: moved/added/removed) + GET /parcels/{id}/versions/{v}/compare |
| 105a | TASK-104a | Version compare + history timeline UI | 104, 105 | TODO | Field & geometry diff on map preview, merged timeline stream with export |
| 106 | TASK-106 | Version restore | 105 | DONE | Additive restore, If-Match, monotonic versions, geometry round-trip verified |
| 107 | TASK-107 | Document upload + validation | 020 | DONE | Magic-byte/MIME/size guards, SHA-256 de-dup, randomised storage keys; migration 20260924000001 |
| 108 | TASK-108 | Signed document downloads | 107 | DONE | HMAC single-use tokens, classification enforcement, restricted downloads audited |
| 111 | TASK-111 | Split validation rules (VR-35…VR-39) | 019, 089 | DONE | SplitValidator: per-rule fixtures; alias fix overlap_sqm→overlap_area_sqm; hull-based VR-42 metric |
| 112 | TASK-112 | Split service (dry run + commit) | 111, 069 | DONE | Four methods, idempotency, failAfterChildren seam; BOX-regex fix in tests; ON CONFLICT version guard |
| 113 | TASK-113 | Consolidation validation rules (VR-40…VR-44) | 111 | DONE | ConsolidationValidator: probe/measureOverlaps, SRID-gated geography cast, VR-44 before spatial rules |
| 114 | TASK-114 | Consolidation service (dry run + commit) | 113 | DONE | Input-order parents, CTE-based union insert, dry-run Polygon payload, failAfterNewParcel seam |
| 115 | TASK-115 | Lineage API | 112, 114 | DONE | Generation-depth BFS (siblings at depth 1), truncation flags, VR-45 cycle-guard trigger in migration 20260925000001 |
| 116 | TASK-116 | Split UI | 112, 071 | DONE | SplitTab.tsx, SplitLineMap.tsx, ValidationBlock.tsx, SplitTab.test.ts (12 tests pass), commit gate, dry-run preview |
| 117 | TASK-117 | Consolidation UI | 114, 116 | DONE | ConsolidationTab.tsx, ConsolidationMap.tsx, ConsolidationTab.test.ts (7 tests pass), offending-parent map highlighting, union preview |
| 118 | TASK-118 | Lineage view | 115 | DONE | LineageTab.tsx, LineageTab.test.ts (5 tests pass), genealogy graph layout, truncation notice, JSON export |
| 119 | TASK-119 | Historical record handling | 115 | DONE | Parcel list excludes SUPERSEDED/ARCHIVED by default; Api/HistoricalFilterTest |
| 120 | TASK-120 | Split/consolidation from survey data | 112, 090 | DONE | TD-derived children carry COMPUTED_FROM_TECHNICAL_DESCRIPTION + own closure; uncomputed TD rejected 400 |

Phase 12 (Validation, tasks 096–099) has been audited and completed on 2026-09-24. Survey validation service (`SurveyValidationService.php`), spatial overlap detector (`OverlapDetector.php`), submission guards (`ValidationController::submit`), and full frontend validation panel UI (`ValidationPanel.tsx`) with FR-125 12-point checklist, FR-126 expanded warnings, FR-127 mandatory validation aid note, and overlap analysis table have been fully implemented, tested, and verified. All 366 backend PHPUnit tests pass and all 52 frontend Vitest tests pass with clean production bundle build. Next active task is TASK-100 (workflow engine).

Phase 8–12 audit fixes (2026-09-24): (G-1) closed the PATCH submit bypass — `ParcelController::update` now rejects status transitions outside DRAFT↔RETURNED with `INVALID_STATE`, and the editor's Submit button calls the validated `POST /parcels/{id}/submit` via `validationApi.submitParcel` instead of PATCH; (G-4) removed the crash-looping `worker` compose service (`backend/bin/worker.php` was never created) with a comment documenting when to re-add it; (G-5) added `GET /parcels/{id}/overlaps` reusing `OverlapDetector` (parcel.view, optional `sliver_threshold_sqm`, 404/400 guards) with `Api/ParcelOverlapsTest` (6 tests). ParcelVersionTest cases that used PATCH-status as a version-bump helper were re-pointed at attribute edits. Remaining audit gaps (G-2 session-var drift, G-3 superuser container, G-6 idempotency, G-7 error-code drift, G-8 missing Playwright specs for 9–12, G-9 EXPLAIN evidence) are deferred: G-2 lands naturally with the TASK-100 workflow engine; the rest are backlog. Full suite after fixes: 372 backend tests green, 52/52 frontend, tsc/vite clean.

TASK-034 shipped the admin APIs: `UserAdminService` (CRUD, deactivate as soft delete, role/scope assignment, force-password-reset, `effective-access` explainer), `RoleAdminService`/`OrganizationAdminService` (system-role protection, If-Match versioning, deactivation guards), controllers, and rewritten `config/routes.php` with per-route `AuthorizeMiddleware` declarations. Full suite: 121 tests / 306 assertions green on the Docker stack (PHP 8.3.33, PHPUnit 11.5.56).

TASK-035 shipped the transport-hardening layer: `RateLimitMiddleware` (DB-backed 1-minute token buckets per route class, 429 `RATE_LIMITED` + `Retry-After`; `app.rate_limit_entries` migration `20260920000016`), `CsrfMiddleware` (double-submit + Origin check, active only when the `refresh_token` cookie is presented), `SecurityHeadersMiddleware` (HSTS, CSP without `unsafe-inline`, nosniff, X-Frame-Options DENY, Referrer-Policy, Permissions-Policy), and an explicit `CORS_ALLOWED_ORIGINS` allow-list with exact-origin reflection. Decorators sit outside the error middleware so 4xx/5xx responses stay decorated. nginx gains a coarse per-address `limit_req`. Full suite: 139 tests / 366 assertions green on the Docker stack (PHP 8.3.33, PHPUnit 11.5.56).

TASK-036 shipped TOTP MFA end-to-end. **DB:** migration `20260920000017_create_login_functions` — because `app.users` sits behind RLS and login must read a user row *before any actor id exists*, three `SECURITY DEFINER` functions owned by `app_migrator` (`app.fn_login_lookup`, `app.fn_login_record`, `app.fn_user_profile`; `SET search_path = app, public`; `EXECUTE` revoked from PUBLIC, granted to `app_rw` only) bridge the gap with exact-match keys and fixed column lists (ADR-21); migration `20260920000018_add_requires_mfa_to_roles` back-fills `roles.requires_mfa` (SYS_ADMIN mandatory). **Code:** `backend/src/Auth/Totp.php` (pure RFC 6238 — SHA-1, 30 s, 6 digits, ±1-step window, counter-derived; verified against the RFC 6238 appendix vectors), `MfaService.php` (libsodium `crypto_secretbox` secret-at-rest, fail-closed on missing key, one-time 5-min `mfa_token`), LoginService/TokenService rewired with an MFA gate (`ApiError MFA_REQUIRED`, `details.mfa_token` + `details.enrolled`), `Http/AuthController.php` routes (`POST /auth/login|mfa/verify|refresh|logout`), and admin MFA endpoints (`GET /users/{id}/mfa`, `POST /users/{id}/mfa/enroll|disable`, `user.manage`). **Fixes:** `UserAdminService::fetchUser` now selects `mfa_secret_enc` (the double-enroll guard never fired before); `.env`/`.env.example` moved to INI-valid syntax for `parse_ini_file` and the base64 `MFA_ENCRYPTION_KEY` is quoted. Tests: `tests/Unit/TotpTest.php` (corrected RFC vectors), `tests/Api/AuthFlowTest.php` (login/refresh/logout/MFA), `tests/Api/MfaAdminTest.php` (enroll/disable), 27 new; full suite **151 tests** green, no warnings. `MFA_REQUIRED` was narrowed/added to the closed code set and `api.md` §2/§3.2 rewritten (incl. the previously-unimplemented `details.enrolled`).

TASK-037 shipped SPA authentication end-to-end (ADR-23). **Backend gaps closed** (TASK-037 exposed them): the server now issues the readable half of the CSRF double-submit pair — a non-HttpOnly `csrf_token` cookie (`SameSite=Strict`, `Path=/api/v1/auth`, Max-Age = refresh max age) plus an `X-CSRF-Token` response header echoed on login/MFA-verify/refresh and cleared on logout, so a real browser can call the cookie-authenticated refresh/logout endpoints (previously nothing issued `csrf_token`); and `PUT /api/v1/me/password` (`MeController::changePassword`) was implemented — verifies `current_password` via Argon2id, validates the new password with `PasswordPolicy`, bumps `version`, clears `must_change_password`, and revokes the refresh family. **Frontend:** `frontend/src/auth/` — `tokenStore` (access/CSRF tokens in memory only — never storage), `apiClient` rework (Bearer + `X-CSRF-Token` request headers, envelope unwrap, `X-CSRF-Token` capture from every auth response, single-flight 401 refresh via `refreshQueue` with one retry per request, skip `/auth/*`, logout on failed refresh), `AuthProvider` (bootstrap via `GET /me` through the same silent-refresh path, login, MFA verify, change-password, logout), permission catalogue constants + pure predicates + `usePermission`/`useLayerCap`/`useScope` hooks, `RequireAuth`/`RequirePermission`/`PermissionGate` guards, and pages: `LoginPage` (creds → MFA step on `MFA_REQUIRED`), `ChangePasswordPage`, `ForbiddenPage`. Vite dev proxy `/api → :8080` added. Tests: 16 Vitest (pure logic — no DOM dependency) green locally; the backend changes must be verified with the full suite on the Docker stack (no `php`/`docker` on the dev machine). **Note:** the earlier "turn off MFA in dev" request (a `MFA_ENABLED` env toggle) is **not** implemented — revisit if dev friction demands it.

---

## Blocked items

| Task | Blocked since | Error / reason | Attempts | Suspected cause | Recommended next action |
|---|---|---|---|---|---|
| — | — | None currently blocked. TASK-003 was resolved on 2026-09-20: the sponsor skipped formal approval and accepted the stated defaults (see TASK-003 / TASK-004 in the queue). | — | — | — |

---

## Cross-check report (TASK-002)

Contradictions found between the v0.1 documents and the revised master prompt, and how each was resolved:

| # | Contradiction | Resolution | Documents changed |
|---|---|---|---|
| 1 | Error format: RFC 7807 vs the prescribed `{success, error:{code,message,details}}` envelope | Adopted the prescribed envelope; added a closed error-code set | ADR-14, `specification.md` §6.1, `api.md` §1 |
| 2 | HTTP client: bare `fetch` wrapper vs "Axios or equivalent" | Adopted Axios under TanStack Query | ADR-13, `frontend.md` §1, §12 |
| 3 | Backend layout: layer-first `src/Http|Application|Domain` vs prescribed module folders | Module-first with uniform internal layering; survey library canonical path `backend/src/Survey/Domain` | `architecture.md` §2, §5.1, §21 |
| 4 | `tie_points` listed as a table while v0.1 merged tie points into control points | `survey_control_points` is the registry; `tie_points` records per-description usage with as-used coordinates | ADR-17, `database.md` §6.1 |
| 5 | `parcel_courses` and `technical_description_courses` both listed | `parcel_courses` is a view over the course table | ADR-18, `database.md` §6 |
| 6 | Parcel status set lacked `SUPERSEDED` | Added; set only by the lineage engine, terminal | FR-135/135a, `database.md` §7, `architecture.md` §18 |
| 7 | Provenance set lacked `CAD_IMPORT` and `MANUAL_DRAWING` | Added to the enumeration and to the UI badge set | `database.md` §7, `specification.md` §3.21 |
| 8 | Field types lacked currency, reference, user, document | Added with storage and resolution rules | FR-026/026a/026b, `database.md` §5 |
| 9 | Split, consolidation, and lineage absent from v0.1 | New architecture §18, requirements §3.22–3.24, rules VR-35…VR-47, API §8.2–8.4, tasks 111–120, UI §21 | all |
| 10 | Basemaps were configuration; the prompt requires a provider manager with licensing | New `basemap_providers` table, licence constraints, tile proxy, admin UI | `architecture.md` §16, FR-240–247, `database.md` §8 |
| 11 | CAD-Earth workflow and imagery-licensing prohibitions absent | New architecture §17 stating the prohibitions as absent capability; DXF import requirements | `architecture.md` §17, FR-250–255 |
| 12 | Reports absent | New requirements §3.27, tasks 132–134 | `specification.md`, `todo.md` |
| 13 | Observability, transactions, and retry protocol under-specified | New architecture §19–20, FR-280–284, retry protocol in `PLANNING.md` §6 | multiple |

**Consistency checks passed:** status vocabularies match across `specification.md`, `database.md`, `api.md`, and `frontend.md`; permission codes match between `specification.md` §3.3, `api.md`, and the seed list in `todo.md` TASK-023; every `VR-*` referenced in `api.md` exists in `specification.md` §5; every table referenced in `api.md` and `architecture.md` exists in `database.md`; every phase in `todo.md` has at least one acceptance criterion in `specification.md` §11; the CRS policy (store 4326, compute in PRS92 zone, never transform silently) is stated identically in all four technical documents.

**Known gaps accepted for now:** curve courses, traverse adjustment, geodetic-to-grid convergence, and NTv2 grids are designed for but deferred (`PLANNING.md` §2.3); multi-tenancy is explicitly not designed in.

---

## Accomplishment log

Maintained in `accomplish.md` once implementation begins. Each completed task records: what shipped, decisions made, approaches that failed and why, and follow-up items. `next_task.md` always names the single next task and its entry criteria.

**2026-09-25 — Phases 5-15 audit (not a queue task; cross-cutting).** Reviewed TASK-049..TASK-120 for CRUD completeness, transaction integrity, tools/services, and UI ease of use against `architecture.md` / `specification.md` / `api.md` / `frontend.md`. Fixed 2 CRITICAL defects (`SurveyPlanController` nested `beginTransaction()` and a non-existent `writeFromSession()` signature, each a live HTTP 500 on all three write endpoints, both previously untested), 4 HIGH authz gate drifts, and 6 frontend deviations including the missing §19 left icon rail. Added `tests/Api/SurveyPlanApiTest.php` (4 tests) to close the coverage gap that hid the two criticals. Full detail and the 8 recorded open gaps in `todo.md` → "AUDIT — Phases 5-15".

**Standing lesson from this audit:** an endpoint can be fully implemented and still 500 on every call if it opens its own transaction or calls a method with the wrong parameters, and a green suite will not reveal it if no test drives the route over HTTP. Any new write endpoint should ship with at least one HTTP-level test, and controllers must use `DbTransaction` rather than raw `beginTransaction()`.

**2026-09-26 — Phases 5-15 UI acceptance pass (not a queue task; cross-cutting).** Ran the previously deferred browser pass over every phase 5-15 surface and fixed what it exposed.

**CRITICAL — geographic scope authorization was wrong for every nested scope** (`database/migrations/20260925000002_fix_psgc_scope_hierarchy.php`). The authorization functions tested a user's assigned PSGC code with a `LIKE 'prefix%'` match, so a BARANGAY-scoped user was silently granted read *and write* to every sibling barangay sharing the 9-digit city prefix, and a MUNICIPALITY scope only matched a second barangay by accident of string overlap. **DB:** added `app.fn_psgc_scope_matches(target_code, scope_code)` which resolves `ref.psgc_areas.parent_code` for BARANGAY/MUNICIPALITY/PROVINCE/REGION and keeps the prefix fallback only for custom codes; `app.fn_user_can_see` / `app.fn_user_can_edit` now delegate to it. The same pass found a second defect in the same functions — `GLOBAL` scope satisfied the *edit* branch, giving a global viewer write access. `down()` is no longer a shared template: it restores `fn_user_can_see` (any access level) and `fn_user_can_edit` (`EDIT`/`APPROVE` only, including for `GLOBAL`) as separate functions, because a single template could not express "sees everything, edits nothing". `CREATE OR REPLACE FUNCTION` cannot change a return type, so the write branches were dropped from `fn_user_can_see` entirely rather than inverted in place. The live `access_level` values are `EDIT`/`APPROVE`; the older migration's `WRITE` literal was stale. **Tests:** `tests/Integration/GeographicScopeTest.php` (11 tests / 23 assertions) including `testGlobalViewScopeSeesEverywhereButEditsNothing` as the rollback regression guard, and `RlsTest.php` grants `USAGE` on `ref` / `SELECT` on `ref.psgc_areas` to the synthetic RLS role.

**Rollback verified, not assumed.** `vendor/bin/phinx rollback -t 20260925000001` then `GeographicScopeTest` fails 4/11 (the hierarchy bugs, plus the missing helper) and the new GLOBAL VIEW test still passes — proving the corrected `down()` preserves read-only global access instead of granting write. Re-applying returns 13 tests / 26 assertions green across `GeographicScopeTest` + `RlsTest`.

**HIGH — login-page refresh shared the login rate-limit bucket** (`RateLimitMiddleware.php`). A 10/60s `auth` bucket covered `login`, `mfa/verify` *and* `refresh`, and the SPA silently refreshes on every cold boot, so a handful of browser reloads exhausted the login budget and locked a legitimate user out of the sign-in form. Split into `auth` (10/60s, credential endpoints) and a new `auth_refresh` (60/60s) class, classified before the generic `auth` catch-all. `tests/Api/RateLimitTest.php` (7 tests / 20 assertions) pins both the isolation and the new ceiling.

**HIGH — basemap loading was flaky only under cold boot.** `MapShell` calls `/basemaps` on mount, so a hard reload issued the landing page's requests, the bootstrap `/me` call, and the silent refresh inside the same window; the 429 churn surfaced as an intermittent `/admin/basemaps`-shaped console error in the map shell. Fixed on both sides: the client distinguishes a terminal refresh rejection from a transient one and retries a transient failure once before evicting (a 429/5xx/network error must not log the user out), and the E2E auth cleanup now clears `auth\\_refresh:%` alongside `auth:%` so the suite is not the thing re-creating the condition it is testing. `tests/Integration/.../NearestPointTest.php` 7 tests / 41 assertions.

**MEDIUM — PSGC validation rejected valid codes.** Par/field validation required exactly 9 digits, so any legitimate 10-12 digit code was refused on the parcel create/editor forms, survey plans, and control points; the reference data itself is all 9 digits, which is what made the mistake invisible. Widened to 9-12 in the three backend controllers and the two frontend parcel pages.

**MEDIUM — consolidation offered a preview before it had a reason.** `SplitTab` ran the retype preview against whatever was in the form, so the operator saw a computed result for an unsaved, unvalidated consolidation. Reordered to require the reason first.

**Fixture/test-hygiene fixes:** the phase-15 split-consolidation spec now seeds the reference hierarchy code `990101000` instead of a 6-digit placeholder; `frontend/package.json` E2E script paths corrected; the form-QA sweep now tears down its control points (which had been leaking rows into the shared DB) and a probe script used for diagnosis was deleted.

**Verification:** backend `495 tests / 2 159 assertions` OK, 2 deprecations (was 492/2 152 — the 3 new tests); frontend `92 tests / 16 files` green; `tsc -b --noEmit` exit 0; form-QA sweep 15 tests green; full Playwright suite 40 tests, five of six consecutive runs fully green, the sixth failing an unrelated `locator.click` 90 s timeout in `phase8-parcel-editor.spec.ts` that passes 3/3 in isolation (load-induced, not a functional regression). Post-run DB audit: 0 active `E2E%` parcels, 0 active `E2E%` control points, 0 `PROBE%` rows. PHPStan holds a 502-error pre-existing baseline (23 test files share the same `ContainerInterface|null` pattern); the `RateLimitMiddleware.php` change adds 0 and no new error appears in any file touched here.

**Open gaps recorded in `todo.md`, not fixed:** layer-style delete, ungated `POST /documents` + link endpoint, `parcel.view` gate on the mutating validate endpoint, `survey.view` gate on the writing CRS transform, remaining unimplemented sec. 3 routes, clickable "coming soon" parcel tabs, pre-existing lint warnings, control-point label associations, and the unsupported seeded `XYZ` basemap provider warning.

**Standing lesson from this pass:** the rate limiter, the authorization functions, and the form validators were each individually plausible and each fully unit-covered, yet all three were wrong in a way only a real browser session against a real database could show. Two of the three defects (scope prefix matching, `GLOBAL` write access) are security-relevant, and the migration that fixes them has a `down()` whose behaviour differs from its `up()` — so it must be rollback-tested against the suite, not just applied.

---

## 2026-09-26 - Parcel data-entry UI audit (not a queue task; cross-cutting)

Audited the two parcel attribute forms — `ParcelCreatePage` and the editor's `InformationTab` — against the pattern `LayerMetadataForm` and `BasemapForm` already follow, and fixed what was observably wrong. No backend behaviour changed.

**HIGH — every control on both forms was unlabelled.** Neither form put a `for` on its labels or an `id` on its inputs, so placeholders served as accessible names: a screen reader announced an unlabelled edit box and clicking the label text focused nothing. New `frontend/src/features/parcels/components/ParcelField.tsx` owns the label, the required `*`, the hint and the `invalid-feedback` block, and the ids they are announced under. Verified in the browser rather than by reading the markup: 14/14 controls on create and 12/12 on the editor tab now resolve a matching `label[for]`.

**HIGH — validation errors named no field.** Every check ran inside `submit` and set one banner above the Save button, and nothing marked a field as required. `validateCreate()` now returns a per-field message map, revealed on the first submit attempt and then derived from live values so each error clears as the operator corrects that field; `parcel_code` is marked required. The two cross-field rules (survey-derived provenance needs an attached plan *and* a justification) deliberately stay in the banner, since neither belongs to a single input. Browser-verified: an empty code, a `-5` area, `133` and `abcdefghij` all reported inline at once, each with `is-invalid`, `aria-invalid` and `aria-describedby`.

**MEDIUM — the PSGC placeholders contradicted the PSGC validation.** Found by typing the new examples in, not by reading them: the placeholders offered `1339` and `133901` — 4- and 6-digit prefixes — which the field's own `9–12` rule rejected on submit. Querying `ref.psgc_areas` showed all 22 codes are 9 digits and that province/city codes are 9 digits as well (`133900000` / `133901000` / `133901001`), not shorter prefixes. The examples are now real codes that pass. This is the mirror image of the "10–12 digits" badge fixed in the previous pass: both defects were claims about PSGC digit length, neither was asserted by a test, and this one was introduced *by* that earlier fix.

**MEDIUM — two labels stated something false.** The PSGC badge read "10–12 digits" while annotating a `9–12` rule. "Source area (m²)" hard-coded one unit although `source_area_sqm` and `source_area_unit` are separate columns and the backend converts nothing, so choosing hectares produced a field that said one thing and stored another. The range is now `PSGC_DIGIT_HINT`, the single source shared with the validator; the area label is unit-neutral and its hint names the selected unit.

**MEDIUM — the two forms described the same attributes differently.** Create led with `parcel_code` and used `col-6` pairs; the editor led with lot/block/title and gave barangay a full row, so muscle memory did not carry between the two. Both now render from `PSGC_FIELDS` in province → municipality → barangay order, which matches how `ref.psgc_areas.parent_code` actually nests, with matching column widths.

**LOW — raw enum values and an unconditional warning.** The provenance selector offered `COMPUTED_FROM_TECHNICAL_DESCRIPTION` as its option text; it now reads "Computed from technical description" with the stored enum unchanged. The survey-plan lockout notice is now shown only while the lockout is in effect, instead of sitting permanently under a field the operator may be actively using.

**Verification:** frontend `104 tests / 17 files` green, `tsc -b --noEmit` exit 0, `oxlint` no errors, `vite build` clean; parcel E2E 7/7, form-QA sweep 15/15, full Playwright **40/40**. Every pre-existing `data-testid` is preserved, including the short PSGC ids, which now come from `PSGC_FIELDS[].testId` rather than being hard-coded twice. The verification parcel created during browser testing was deleted (0 rows matching `PRC-2026%`).

**Open, not fixed here:** `control-points` and `survey-plans` forms still have unassociated labels — the same defect, a separate pass rather than an unbounded one; and the create-page map/form split (long form beside a 440 px map) is worth revisiting only alongside that pass.

**Standing lesson from this pass:** a placeholder and a validation rule are the same fact stated twice, and nothing caught them disagreeing because no test typed the example in. The defect was introduced by the very fix for its own twin.

---

## Working rules

1. One task at a time, taken in queue order. No task starts before its dependencies are `DONE`.
2. Read `architecture.md` before structural work, `database.md` before migrations, `api.md` before endpoints, `frontend.md` before UI.
3. Do not skip dependencies. Do not redesign without an ADR. Do not duplicate an existing component or service.
4. Survey calculations never enter UI components and stay independently unit-testable.
5. Validate geometry and permissions server-side, always.
6. Tests ship with the feature; run them after every meaningful change; fix failures before moving on.
7. Maximum 3 retries on a failing test, then `BLOCKED` with the full record.
8. Update `TASK.md`, `accomplish.md`, and `next_task.md` before committing.
9. Never commit `.env`, secrets, keys, credentials, or build artefacts.
