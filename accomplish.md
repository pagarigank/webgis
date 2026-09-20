# Accomplishments

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

## TASK-028–033: full-suite verification

On 2026-09-20 the complete suite ran **98 tests / 228 assertions, OK** on the Docker stack (PHP 8.3.33, PHPUnit 11.5.56) via `docker compose exec -T php-fpm vendor/bin/phpunit --colors=never tests`. The DI wiring now binds `CacheInterface` to `Symfony\Component\Cache\Psr16Cache(new ArrayAdapter())` (ArrayAdapter is PSR-6; it must be wrapped for the PSR-16 contract). `composer audit` is clean after firebase/php-jwt ^7.0 + symfony/cache ^7.0.
