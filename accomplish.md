# Accomplishments

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



