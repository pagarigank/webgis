# TASK.md

**Active implementation queue.** `todo.md` holds the full roadmap; this file holds what is being worked on now, in order, with live status.

**Current phase:** PHASE 12 — Validation
**Implementation status:** IN PROGRESS — Phases 1 through 11 (tasks 001–095) shipped; next is TASK-096 (survey validation service)
**Last updated:** 2026-09-24

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
| 66 | TASK-066 | Row create, edit, delete from the grid | 064, 057 | DONE | FeatureEditor modal with FieldRenderer, permissions gating, bulk delete |
| 67 | TASK-067 | Grid export and filter-by-extent | 064 | DONE | CSV & GeoJSON export with bbox extent filtering and permission gating |
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
| 96 | TASK-095 | Explicit coordinate transformation service | 014, 073 | DONE | POST /crs/transform, coordinate_transformations logging, no implicit transform on read |
| 97 | TASK-096 | Survey validation service | 090, 073 | TODO | Full checklist: TD, tie point, CRS, bearings, distances, closure, geometry, area, overlap |

Phase 11 (Computation engine, tasks 087–095) has been audited and completed on 2026-09-24. Pure domain survey geometry (TraverseComputer, ClosureCalculator, AreaCalculator, CompassRuleAdjustment, TransitRuleAdjustment, ComputeCrsGuard), application orchestration (`SurveyComputationService`, `ComputationController`, `ParcelController::acceptComputation`, `CoordinateTransformationService`), immutable snapshotting, replay determinism, and full frontend survey computation panel UI with live SVG geometry preview, closure status badges, area comparison with mandatory validation aid note, and parcel geometry acceptance have been implemented and verified. All 353 backend tests pass and frontend builds with 0 errors. Next active task is TASK-096 (survey validation service).

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
