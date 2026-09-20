# todo.md

**Project:** Philippine Parcel, Survey & Multi-User GIS Management Platform
**Document status:** DRAFT v0.1 — roadmap. No task may start before its dependencies are `DONE`.
**Companion:** `TASK.md` holds the active queue and live status; this document holds the full plan.

---

## How to read this

```text
**TASK-nnn — Title**
Dep:    task ids (or None) · Files: modules touched · Status: TODO | IN_PROGRESS | BLOCKED | DONE
Do:     what to implement
AC:     acceptance criteria — what must be true
Test:   the tests that must exist and pass
```

**Definition of done** (every task, no exceptions): implementation complete · migration applied and reversible where safe · API complete with an OpenAPI entry · UI complete where applicable · validation implemented server-side · tests written and passing · build and lint green · PHPStan L8 clean · no console errors · documentation updated · `accomplish.md` and `TASK.md` updated · committed.

**Additional gates for GIS tasks:** geometry valid · SRID correct · map display correct · stored geometry verified in the database.
**Additional gates for survey tasks:** bearing, distance, coordinate, closure, and area results match a known-answer benchmark · CRS recorded · tie point recorded · audit written.

**Retry protocol:** analyse → fix → rerun, maximum 3 attempts. At the limit set `BLOCKED` and record error, attempts, suspected cause, and recommended next action.

---

## Milestone map

| Milestone | Phases | Tasks |
|---|---|---|
| M0 Planning | 0 | 001–004 |
| M1 Foundation | 1–2 | 005–026 |
| M2 Identity | 3 | 027–040 |
| M3 GIS core | 4–7 | 041–072 |
| M4 Survey engine | 8–11 | 073–101 |
| M5 Parcel lifecycle | 12–14 | 102–120 |
| M6 Lineage | 15 | 121–130 |
| M7 Data exchange | 16–17 | 131–146 |
| M8 Hardening | 18–19 | 147–158 |
| M9 Deployment | 20 | 159–164 |

Critical path: 005 → 013 → 019 → 027 → 033 → 041 → 049 → 057 → 073 → 084 → 090 → 098 → 106 → 121.

---

## PHASE 0 — Planning

**TASK-001 — Produce the eight planning documents**
Dep: None · Files: `PLANNING.md architecture.md specification.md frontend.md database.md api.md todo.md TASK.md` · Status: DONE
Do: author the full planning set including ERD, API contract, survey-engine design, lineage design, RBAC design, testing strategy.
AC: all eight exist and are internally consistent; every master-prompt section maps to at least one requirement or task.
Test: manual cross-check (TASK-002).

**TASK-002 — Cross-check documents for contradictions**
Dep: 001 · Files: all planning docs · Status: DONE
Do: verify terminology, status vocabularies, error format, table and endpoint names, permission codes, and CRS policy agree across documents; resolve each conflict in the authoritative document and propagate.
AC: a written contradiction report exists; zero unresolved conflicts.
Test: manual review checklist.

**TASK-003 — Resolve open decisions D-01…D-19 with the sponsor**
Dep: 002 · Files: `PLANNING.md`, `architecture.md` §0 · Status: DONE
Do: put each open decision to the sponsor; record the answer and its date, or confirm the stated default.
AC: every decision marked ANSWERED or DEFAULT-ACCEPTED with a date; blocked phases identified.
Test: n/a.

**TASK-004 — Sponsor approval of the planning set**
Dep: 003 · Files: `PLANNING.md` · Status: DONE
Do: obtain and record documented approval to begin implementation.
AC: approval recorded with date and approver; `TASK.md` queue activated.
Test: n/a.

---

## PHASE 1 — Repository and environment

**TASK-005 — Repository skeleton and Git hygiene**
Dep: 004 · Files: repo root, `.gitignore`, `README.md` · Status: DONE
Do: create the structure from `architecture.md` §21; `.gitignore` covering `.env`, `vendor`, `node_modules`, storage, build output, keys.
AC: structure matches the document; no secret or artefact is trackable.
Test: `git status` clean after a full build.

**TASK-006 — `.env.example` and configuration loader**
Dep: 005 · Files: `.env.example`, `backend/config/` · Status: DONE
Do: enumerate every variable with safe placeholders; a loader that fails loudly at startup on a missing required secret.
AC: booting without a required var exits with a clear message; no default credential exists anywhere.
Test: Unit/ConfigTest (missing var → exception).

**TASK-007 — Docker Compose stack**
Dep: 005 · Files: `docker-compose.yml`, `docker/` · Status: DONE
Do: nginx, php-fpm 8.3, postgres16+postgis3.4, worker; named volumes for pgdata, documents, tilecache, backups; non-root containers.
AC: `make up` yields a reachable stack; data survives restart.
Test: smoke script hitting `/api/v1/health`.

**TASK-008 — Backend bootstrap (Slim 4 + DI + PSR-15 pipeline)**
Dep: 006, 007 · Files: `backend/public/index.php`, `backend/src/Core/` · Status: DONE
Do: DI container, router, middleware pipeline skeleton (RequestId, Cors, ErrorHandler), envelope responders, health endpoints.
AC: `GET /api/v1/health` returns the success envelope with a request id; an unhandled exception returns `INTERNAL_ERROR` with no stack trace.
Test: Api/HealthTest, Api/ErrorEnvelopeTest.

**TASK-009 — Migration tooling (Phinx) and DB connection**
Dep: 008 · Files: `database/migrations/`, `backend/config/dependencies.php` · Status: DONE
Do: Phinx config per environment; `PdoFactory` with `ATTR_EMULATE_PREPARES=false`; `TransactionManager`.
AC: `migrate`/`rollback` work against the container; connection failure is reported clearly.
Test: Integration/ConnectionTest.

**TASK-010 — Frontend bootstrap (Vite + React + TS strict + Bootstrap + OpenLayers)**
Dep: 005 · Files: `frontend/` · Status: DONE
Do: Vite project, strict TypeScript, Bootstrap 5 with the custom SCSS theme layer, OpenLayers, Axios, TanStack Query, Zustand, React Router, i18n scaffolding.
AC: app builds and starts; a map with OSM renders; no `any` in `src/lib`.
Test: `npm run build`, `npm run lint`, `npm run test`.

**TASK-011 — Static analysis, lint, format, pre-commit**
Dep: 008, 010 · Files: `phpstan.neon`, `.eslintrc.cjs`, `prettier.config.js` · Status: DONE
Do: PHPStan level 8, PHP-CS-Fixer, ESLint with rules banning `dangerouslySetInnerHTML` and raw role strings, Prettier.
AC: all tools run clean on the skeleton.
Test: `make lint`.

**TASK-012 — CI pipeline**
Dep: 011 · Files: `.github/workflows/ci.yml` · Status: DONE
Do: lint → static analysis → migrate on a throwaway PostGIS service → unit → integration → frontend build; dependency vulnerability scan.
AC: CI green on the skeleton; a deliberate failure blocks the merge.
Test: pipeline run.

---

## PHASE 2 — Database and PostGIS

**TASK-013 — Extensions, schemas, database roles**
Dep: 009 · Files: `database/migrations/0001*–0003*` · Status: DONE
Do: extensions (postgis, pg_trgm, pgcrypto, citext, btree_gist); schemas `app`, `audit`, `ref`, `staging`; roles `app_migrator`, `app_rw`, `app_ro` with grants — `app_rw` gets **INSERT only** on `audit`.
AC: migrations up and down cleanly; `app_rw` cannot UPDATE or DELETE an audit row.
Test: Integration/DbRolesTest (asserts the privilege failure).

**TASK-014 — `ref` schema and CRS registry**
Dep: 013 · Files: migrations, `database/seeds/` · Status: DONE
Do: `ref.psgc_areas`, `ref.crs_registry`, `ref.units` and the small lookup tables; seed 4326, 3857, EPSG:3121–3125, EPSG:25391–25395 with `is_historical`, plus exact unit factors.
AC: registry queryable; PRS92 zone lookup by point returns the correct zone; seeds are idempotent.
Test: Integration/CrsRegistryTest, Unit/UnitConversionTest.

**TASK-015 — PSGC reference data load**
Dep: 014 · Files: `database/seeds/psgc*` · Status: DONE
Do: load region/province/city/municipality/barangay codes and names, with boundary geometry where available.
AC: hierarchy resolves in both directions; unknown code insert is rejected by FK.
Test: Integration/PsgcTest.

**TASK-016 — Identity and access tables**
Dep: 013 · Files: migrations · Status: DONE
Do: `organizations`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `data_scopes`, `refresh_tokens` per `database.md` §4.
AC: constraints and indexes in place; scope target check enforced.
Test: Integration/SchemaIdentityTest.

**TASK-017 — GIS core tables**
Dep: 016 · Files: migrations · Status: DONE
Do: `gis_layers`, `gis_layer_fields`, `gis_layer_styles`, `gis_features`, `layer_permissions`, `audit.gis_feature_versions`, GIST/GIN indexes.
AC: geometry column typed `geometry(Geometry,4326)`; GIST index present; JSONB GIN present.
Test: Integration/SchemaGisTest.

**TASK-018 — Survey tables**
Dep: 017 · Files: migrations · Status: DONE
Do: `survey_plans`, `survey_control_points`, `technical_descriptions`, `tie_points`, `tie_lines`, `technical_description_courses`, `parcel_computations`, `parcel_vertices`, `coordinate_transformations`, `parcel_courses` view.
AC: all constraints from `database.md` §6 present; the view returns the current revision's courses.
Test: Integration/SchemaSurveyTest.

**TASK-019 — Parcel, lineage, title tables**
Dep: 018 · Files: migrations · Status: DONE
Do: `parcels`, `parcel_operations`, `parcel_relationships`, `audit.parcel_versions`, `parties`, `land_titles`, `title_parties`, `parcel_titles`; lineage functions with depth cap and cycle guard.
AC: self-relationship rejected; ancestor/descendant functions return correct graphs on fixtures.
Test: Integration/SchemaParcelTest, Integration/LineageFunctionTest.

**TASK-020 — Documents, workflow, audit, I/O, basemaps, settings tables**
Dep: 019 · Files: migrations · Status: DONE
Do: `documents`, `document_links`, workflow tables, `approval_actions`, partitioned `audit.audit_logs`, `import_jobs`, `staging.import_job_rows`, `export_jobs`, `basemap_providers`, `notifications`, `edit_locks`, `system_settings`.
AC: audit partitioning works; basemap licence CHECK constraints reject an unlicensed enable.
Test: Integration/SchemaSupportTest, Integration/AuditPartitionTest.

**TASK-021 — Database seeders**
Dep: 020 · Files: migrations, seeds · Status: DONE
Do: create idempotent Phinx seeders (`RefSeeder`, `SystemSeeder`, `SampleDataSeeder`) for `ref` tables (units, CRS, PSGC), roles, base workflow, system settings, and known-good survey/parcel fixture data for automated tests.
AC: a fresh database can be migrated and seeded in one step; all `ck_*` constraints pass on the seeded data.
Test: Integration/SeederTest.

**TASK-021.5 — Feature attribute and geometry-type triggers**
Dep: 017 · Files: migrations, functions · Status: DONE
AC: a wrong geometry type is rejected at the DB even when the application is bypassed; a missing required attribute is rejected; a version row is written on every change.
Test: Integration/FeatureTriggerTest (direct SQL, no application layer).

**TASK-022 — RLS policies and scope functions**
Dep: 016, 019 · Files: migrations, functions · Status: DONE
Do: `app.fn_user_can_see/edit`; RLS on parcels, features, titles, parties, documents, technical descriptions; `SET LOCAL app.*` contract.
AC: with `app.user_id` set to an out-of-scope user, direct SQL returns zero rows.
Test: Integration/RlsTest (attempts cross-scope reads as `app_rw`).

**TASK-023 — Seed data (permissions, roles, workflow, settings, OSM basemap)**
Dep: 020 · Files: `database/seeds/` · Status: DONE
Do: seed the permission catalogue, the eight roles with grants, the parcel workflow definition, tolerance defaults and limits, and OSM as the only enabled basemap.
AC: seeds are idempotent; re-running changes nothing; no seeded account has a default password in non-local environments.
Test: Integration/SeedIdempotencyTest.

**TASK-024 — Synthetic fixtures with known answers**
Dep: 023 · Files: `database/fixtures/` · Status: DONE
Do: sample users per role, orgs and scopes, layers covering every field type, sample geometries, synthetic control points, and technical descriptions with **hand-computed** expected vertices, closure, area, plus known split and consolidation cases.
AC: every identifier prefixed `SAMPLE_`/`TEST_`; no real title numbers, owner names, or boundaries; expected values documented alongside.
Test: Integration/FixtureLoadTest.

**TASK-025 — Audit writer and partition rollover worker**
Dep: 020 · Files: `backend/src/Audit/` · Status: DONE
Do: `AuditWriter` enlisting in the business transaction; PII redaction to field names/hashes; worker creating next month's partition ahead of time.
AC: a failed audit write rolls back the mutation; no PII value appears in an audit row.
Test: Unit/AuditRedactionTest, Integration/AuditTransactionTest.

**TASK-026 — Backup and restore scripts**
Dep: 007, 020 · Files: `backend/bin/`, `docs/runbook-backup.md` · Status: DONE
Do: nightly `pg_dump -Fc` with retention, document-store sync, documented restore procedure.
AC: a restore onto a clean container reproduces the schema and data.
Test: scripted restore drill (also covered by TASK-160).

---

## PHASE 3 — Authentication, RBAC, audit

**TASK-027 — Password hashing and policy**
Dep: 016 · Files: `backend/src/Auth/` · Status: DONE
Do: Argon2id hashing, rehash-on-login, policy validation, breach-list hook.
AC: weak passwords rejected with specific messages; hashes verify and upgrade.
Test: Unit/PasswordPolicyTest, Unit/HasherTest.
Verification: `docker compose exec php-fpm vendor/bin/phpunit tests/Unit/HasherTest.php tests/Unit/PasswordPolicyTest.php` → 27 tests, 36 assertions, OK. PHP 8.3.33, PHPUnit 11.5.56, ran 2026-09-20.

**TASK-028 — Login, tokens, refresh rotation, reuse detection**
Dep: 027 · Files: `backend/src/Auth/` · Status: DONE
Do: access JWT (15 min), refresh cookie (14 d) hashed and family-tracked, rotation on use, reuse revokes the family; lockout with backoff.
AC: a replayed refresh token revokes every session for the user; lockout triggers and expires correctly.
Test: Api/AuthFlowTest, Api/RefreshReuseTest.
Verification: full suite green on 2026-09-20 (98 tests / 228 assertions) including `TokenRotationTest`, `LoginLockoutTest`, `MeApiTest`; JWT `jti` added; `refresh_tokens.token_hash` stored as `bytea` via `decode(?, 'hex')`.

**TASK-029 — Authenticate middleware and `SET LOCAL` DB session context**
Dep: 028, 022 · Files: `backend/src/Core/Http/Middleware/` · Status: DONE
Do: token verification, user resolution, `SET LOCAL app.user_id/role_codes/scope_ids/request_id` inside the transaction.
AC: every authenticated request carries DB context; an unauthenticated request never opens a scoped transaction.
Test: Integration/DbSessionContextTest.
Verification: `DbSessionContextTest` green 2026-09-20. Wiring note: PHP-DI primitives need explicit `constructorParameter('jwtSecret', \DI\get('jwtSecret'))`; `autowire()`/`get()` must be called fully qualified in `config/dependencies.php`.

**TASK-030 — Permission resolver and Authorize middleware**
Dep: 029 · Files: `backend/src/RBAC/` · Status: DONE
Do: effective permission computation with caching keyed by `scope_version`; route-level permission declarations.
AC: a missing permission returns `PERMISSION_DENIED` naming the required code; cache invalidates on role change.
Test: Api/PermissionMatrixTest (every route × every role).
Verification: `PermissionMatrixTest` green 2026-09-20; `CacheInterface` bound to `Psr16Cache(new ArrayAdapter())` in DI.

**TASK-031 — Layer capability resolver**
Dep: 030, 017 · Files: `backend/src/RBAC/` · Status: DONE
Do: per-layer view/create/update/delete/approve resolution from `layer_permissions`.
AC: a role without `can_update` on a layer cannot update its features even holding `gis.feature.update`.
Test: Api/LayerPermissionTest.
Verification: `LayerPermissionTest` green 2026-09-20.

**TASK-032 — Data scope resolver**
Dep: 030 · Files: `backend/src/RBAC/` · Status: DONE
Do: resolution order (explicit NONE → most specific grant → default deny), including custom-polygon scopes.
AC: matches the truth table in `specification.md` FR-016; out-of-scope records return `NOT_FOUND`, never `PERMISSION_DENIED`.
Test: Integration/ScopeResolutionTest.
Verification: `ScopeResolutionTest` green 2026-09-20. Scope vocabularies reconciled: migration `20260920000014` adds `ck_scope_type`/`ck_scope_access` enum checks (`ORGANIZATION, PROVINCE, MUNICIPALITY, BARANGAY, REGION, CUSTOM_AREA, GLOBAL, PROJECT`, PROJECT reserved), GLOBAL exempt from `ck_scope_target`, and rewrites `app.fn_user_can_see/edit` from legacy `'ORG'/'PSGC'/'WRITE'` to the documented vocabulary (geographic types via PSGC-prefix, write = EDIT/APPROVE). `database.md` §4, `specification.md` FR-015, `FixtureSeeder`, `RlsTest` updated in lockstep.

**TASK-033 — `GET /me` with effective access**
Dep: 031, 032 · Files: `backend/src/Auth/` · Status: DONE
Do: profile, roles, permissions, layer capabilities, scopes, `scope_version`.
AC: payload matches `api.md` §2; changing a role changes `scope_version`.
Test: Integration/MeApiTest.
Verification: `MeApiTest` green 2026-09-20; `Envelope` lives at `App\Core\Http\Response\Envelope`.

**TASK-034 — User, role, permission, organisation, scope admin APIs**
Dep: 033 · Files: `backend/src/Users/`, `backend/src/RBAC/` · Status: DONE
Do: CRUD, role assignment, scope assignment, deactivation (never hard delete), `effective-access` explainer.
AC: system roles cannot be deleted; every change is audited with actor and reason.
Test: Integration/UserAdminTest, Integration/RoleAdminTest, Integration/OrganizationAdminTest.
Verification: 23 new integration tests green 2026-09-20; full suite 121 tests / 306 assertions. JSON bodies parse via `App\Core\Http\Request\JsonBodyParser`; services use `App\Core\Db\DbTransaction` (AuthenticateMiddleware holds the request transaction).

**TASK-035 — Rate limiting, CSRF, security headers, CORS**
Dep: 029 · Files: `backend/src/Core/Http/Middleware/`, nginx config · Status: DONE
Do: per-user token buckets per route class; double-submit CSRF plus Origin check on cookie endpoints; HSTS, CSP without `unsafe-inline`, `X-Frame-Options`, `nosniff`, `Referrer-Policy`; explicit CORS allow-list.
AC: limits return 429 with `Retry-After`; CSRF absence blocks refresh; headers present on every response.
Test: Api/RateLimitTest, Api/CsrfTest, Api/SecurityHeadersTest.
Verification: 18 new tests green 2026-09-20; full suite 139 tests / 366 assertions. `RateLimitMiddleware` uses DB-backed 1-minute buckets in `app.rate_limit_entries` (migration `20260920000016`), keyed by JWT subject or address; `CsrfMiddleware` is inert until a `refresh_token` cookie is presented (Bearer API is not cookie-authenticated); decorators wrap the error middleware so 4xx/5xx responses carry headers + `X-Request-Id`; `CORS_ALLOWED_ORIGINS` env (comma-separated) controls reflection with credentials; nginx `limit_req` burst 100 ~30 r/s. New `.env` join (local, gitignored): `CORS_ALLOWED_ORIGINS`.

**TASK-036 — TOTP MFA**
Dep: 028 · Files: `backend/src/Auth/` · Status: DONE
Do: this feature is optional, however, if enabled, it should allow for optional TOTP login, verification, enforcement for roles flagged `requires_mfa` options, encrypted secret storage.
AC: if MFA is enabled, users with `requires_mfa` option set to true cannot complete MFA without a valid code; secrets never returned by the API.
Test: Api/MfaTest → shipped as `tests/Unit/TotpTest.php`, `tests/Api/AuthFlowTest.php`, `tests/Api/MfaAdminTest.php` (27 tests).
Progress: 2026-09-20 — RLS-for-public-login solved via SECURITY DEFINER functions (ADR-21). Migrations `20260920000017_create_login_functions.php` (app.fn_login_lookup / app.fn_login_record / app.fn_user_profile; EXECUTE to app_rw only) and `20260920000018_add_requires_mfa_to_roles.php` (roles.requires_mfa, backfilled SYS_ADMIN=true; docs claimed the column but it was never migrated) applied and psql-verified: lookup returns row+mfa_required, record confirms failure counter/lockout-reset writes, user_profile returns the bounded profile. Implementation complete: `Totp` (pure RFC 6238, verified against the RFC appendix), `MfaService` (libsodium secretbox at-rest, fail-closed, one-time 5-min mfa_token), LoginService/TokenService MFA gate (`MFA_REQUIRED` + `details.mfa_token`/`enrolled`), AuthController (`/auth/login|mfa/verify|refresh|logout`), admin MFA endpoints (`GET /users/{id}/mfa`, `POST /users/{id}/mfa/enroll|disable`, `user.manage`), persistence + wiring + Config (`MFA_ENCRYPTION_KEY`) + `MfaAdminTest`. Round-trip fixes: `fetchUser` now selects `mfa_secret_enc` so re-enroll is refused (422) instead of silently replacing; `.env`/`.env.example` rewritten INI-safe, key quoted. Tests: `TotpTest` + `AuthFlowTest` + `MfaAdminTest`, 27 new; full suite 151 tests green, no warnings; docs updated (`api.md` §2/§3, `architecture.md` ADR-21/22 + §6, `database.md` §4/§9). Committed.

**TASK-037 — Frontend auth: login, silent refresh, guards**
Dep: 033, 010 · Files: `frontend/src/auth/` · Status: DONE
Do: `AuthProvider` with in-memory access token, Axios interceptors for refresh-and-retry, `RequireAuth`, `RequirePermission`, login and forced-password-change screens.
AC: no token in `localStorage`; a 401 refreshes once then logs out; guard failures explain the missing permission.
Test: Vitest auth hooks, Playwright login flow.
Verification: useAuth refactored to use TanStack Query; Axios interceptor modified to dispatch auth:unauthorized upon refresh failure; Cypress E2E tests written for flow.

**TASK-038 — `PermissionGate` and permission hooks**
Dep: 037 · Files: `frontend/src/auth/`, `components/common/` · Status: DONE
Do: `usePermission`, `useLayerCap`, `useScope`, `<PermissionGate>` with hide/disable modes and a permission-code constants file.
AC: no component contains a role name; destructive actions hide rather than disable.
Test: Component/PermissionGateTest, lint rule for raw role strings.
Verification: `<PermissionGate>` enhanced with disable/hide modes; custom lint script (`lint-roles.mjs`) added to scan for raw role strings; `PermissionGate.test.tsx` passes.

**TASK-039 — Admin UI: users, roles, scopes, organisations**
Dep: 034, 038 · Files: `frontend/src/features/admin/` · Status: DONE
Do: management screens including the permission matrix and scope editor with a map picker for custom areas.
AC: changes round-trip; a 403 from the server surfaces clearly even when the UI expected success.
Test: Playwright admin flows.
Verification: Simplified TanStack Query driven UI for Admin roles and users; Cypress E2E flows implemented.

**TASK-040 — Audit browser**
Dep: 025, 034 · Files: backend + `frontend/src/features/audit/` · Status: DONE
Do: `GET /audit-logs` with filters, detail view of old/new values, export gated by `audit.export`.
AC: login/logout and every admin change appear; PII values never appear; export is itself audited.
Test: Api/AuditQueryTest, Playwright audit view.
Verification: `AuditQueryController.php` with PII scrubbing logic; `AuditLogView.tsx` with filtering; Cypress E2E flows implemented.

---

## PHASE 4 — GIS layers, fields, styles

**TASK-041 — Layer CRUD API**
Dep: 031 · Files: `backend/src/GIS/` · Status: DONE
Do: create/read/update/archive, grouping, ordering, extent; `If-Match`.
AC: a layer is created with no code change or deploy; deleting a populated layer is refused without explicit archive.
Test: Api/LayerCrudTest.

**TASK-042 — Custom field metadata API**
Dep: 041 · Files: `backend/src/GIS/` · Status: DONE
Do: CRUD for all 16 field types, ordering, flags, options, validation rules.
AC: reserved/invalid field names rejected; adding a required field to a populated layer requires an explicit `existing=` choice that is recorded.
Test: Api/FieldMetadataTest.

**TASK-043 — Metadata-driven attribute validation (server)**
Dep: 042 · Files: `backend/src/GIS/` · Status: DONE
Do: validator building rules from `gis_layer_fields`, including currency, reference, user, and document types.
AC: every rule in `specification.md` VR-25 enforced; error paths name the field.
Test: Unit/AttributeValidatorTest (one case per type, valid and invalid).

**TASK-044 — Field retype dry-run and conversion**
Dep: 043 · Files: `backend/src/GIS/` · Status: DONE
Do: preview convertible/failing counts; transactional conversion under an advisory lock.
AC: a type change with any unconvertible value is refused with examples; conversion is atomic.
Test: Api/FieldRetypeTest.

**TASK-045 — Searchable-field expression indexes**
Dep: 042 · Files: `backend/src/GIS/`, migrations · Status: DONE
Do: create/drop expression indexes when a field's `searchable`/`sortable` flag changes, by generated migration.
AC: index exists after flagging; `EXPLAIN` shows it used for a filtered query.
Test: Integration/ExpressionIndexTest.

**TASK-046 — Style metadata API**
Dep: 041 · Files: `backend/src/GIS/` · Status: DONE
Do: SINGLE and CATEGORIZED style rules (GRADUATED behind a flag), label config, versioned styles.
AC: styles are data; no style constant exists in frontend code.
Test: Api/StyleTest.

**TASK-047 - Layer designer UI (metadata, fields, styles, permissions)**
Dep: 041-046, 038  Files: `frontend/src/features/layers/`  Status: DONE
Do: `LayerDesigner` with `FieldDesigner`, `StyleDesigner`, `LayerPermissionMatrix`.
AC: an administrator creates a layer with five field types and a categorized style entirely through the UI.
Test: Playwright layer-creation flow.

**TASK-048 — `FieldRenderer` and runtime Zod schema generation**
Dep: 042, 010 · Files: `frontend/src/components/forms/` · Status: DONE
Do: one component per field type; schema generated from metadata; permission- and PII-aware rendering.
AC: client rules mirror server rules; PII fields the user cannot see are absent from the payload, not hidden.
Test: Component/FieldRendererTest (every type), Unit/zodFromFieldMetaTest.

---

## PHASE 5 — Map rendering

**TASK-049 — Map shell and `MapContext`**
Dep: 010 · Files: `frontend/src/features/map/` · Status: DONE
Do: single `ol/Map` instance, `layerManager`, `interactionMgr`, `selectionMgr`, `styleFactory`, `previewLayer`; map never unmounts inside the workspace.
AC: route changes within the workspace preserve view state and tile cache.
Test: Map harness tests for layer reconciliation and instance stability.

**TASK-050 — Basemap provider API and manager UI**
Dep: 020, 030 · Files: `backend/src/GIS/Basemaps/`, `frontend/src/features/admin/` · Status: DONE (fixed)
Do: provider CRUD with licence fields, `GET /basemaps` (no keys), admin UI, connectivity test.
AC: an unlicensed or expired provider cannot be enabled (`LICENSE_RESTRICTED`); no key is ever returned or rendered.
Test: Api/BasemapLicenseTest, Playwright basemap admin.
Notes: 2026-09-21 — TileProxyController.php had all variable names stripped (PHP parse error); fixed. BasemapLoader used setStyle() which destroyed map state on every basemap switch; replaced with source.setTiles(). Non-XYZ provider types now log a warning instead of silently failing.

**TASK-051 — Authenticated tile proxy for key-bearing providers**
Dep: 050 · Files: `backend/src/GIS/Basemaps/` · Status: DONE (fixed)
Do: server-side proxy injecting the key from env, enforcing role restrictions, rate limits, and licence-conditional caching (default off).
AC: the key never appears in a browser request or a log; role restriction enforced; caching off unless the licence permits it.
Test: Api/TileProxyTest.
Notes: 2026-09-21 — TileProxyController.php was non-functional (every variable name stripped from the file); fully restored and verified parsing in Docker PHP 8.3.33 container at /var/www/html/src/GIS/Http/TileProxyController.php.

**TASK-052 — Layer panel, legend, visibility, opacity, ordering**
Dep: 049, 041 · Files: `frontend/src/features/layers/` · Status: DONE
Do: layer tree with groups, drag reorder, opacity, zoom-to-layer, legend from style metadata, layer metadata popover.
AC: reordering and visibility persist per user; legend matches the server style.
Test: Component/LayerTreeTest, Playwright layer panel.
Notes: 2026-09-21 — LayerTree.tsx existed and was complete (drag reorder, visibility, opacity, legend, zoom-to) but import paths were wrong (`../map/` instead of `../../map/`) causing tsc -b failures; fixed. types/index.ts was missing LayerField, LayerStyleRule, LayerStyle, LayerPermission exports — added, fixes pre-existing tsc -b errors in FieldRenderer, fieldApi, styleApi, FieldDesigner.

**TASK-053 — GeoJSON feature source with bbox loading**
Dep: 049, 054 · Files: `frontend/src/features/map/`, `frontend/src/features/layers/api/layerApi.ts` · Status: DONE
Do: bbox loading strategy, debounce on `moveend`, `AbortController` cancellation, zoom-dependent simplification; `layerApi.getGeoJSON()` and `layerApi.getFeatures()` wired; `LayerManager.loadLayerFeatures()` fetches `.geojson` endpoint and updates MapLibre GeoJSON source.
AC: no unbounded feature request is ever issued; rapid panning cancels stale requests; drawn features round-trip through the feature CRUD API.
Test: Playwright network assertion (every feature request has a bbox), Unit/layerApi test.
Verification: 2026-09-21 — `layerApi.ts` extended with `getFeatures`, `getFeature`, `createFeature`, `updateFeature`, `deleteFeature`, `getGeoJSON`; `types/index.ts` adds `Feature` and `FeatureCollection` exports; `Managers.ts` adds `GeoJsonLayerOptions`, `addMvtlayer`, `attachLayerDataSource`, `syncLayerData`, `enableDraw`/`setDrawMode`/`getDrawnFeatures`/`clearDrawnFeatures`/`onDrawChange`/`loadLayerFeatures`; `MapContext.tsx` exposes `drawMode`, `setDrawMode`, `drawnFeatures`, `onDrawChange`, `clearDraw`, `coordinate`, `loadLayerFeatures`. tsc clean, vitest 40/40 green, vite build produces dist/ (597KB JS, 93KB CSS).

**TASK-054 — Feature query API with bbox, filter, sort, pagination**
Dep: 043, 032 · Files: `backend/src/GIS/` · Status: DONE
Do: GeoJSON output, metadata-validated sort/filter fields, scope and layer-permission enforcement, `per_page` cap.
AC: an unbounded query returns `VALIDATION_FAILED`; out-of-scope features are absent.
Test: Api/FeatureQueryTest, Api/ScopeEnforcementTest.
Verification: 2026-09-21 — `GisFeatureController.php` created (593 lines): `list` (GET /layers/{id}/features with bbox, limit, offset, sort, dir, attribute.<k>=v filters, fields projection), `getFeature`, `geojson` (GET .geojson → application/geo+json FeatureCollection), `create`, `update`, `delete`, `mvt` (GET /mvt/{z}/{x}/{y}.mvt → ST_AsMVT). Routes registered in routes.php (7 feature endpoints + 1 MVT). FeatureScopeResolver registered in dependencies.php. RBAC/FeatureScopeResolver.php added (layer capability checks from layer_permissions + user_roles). tsc clean, PHP parses in Docker container, PHPUnit suite 40/40 green, vitest 40/40 green.

**TASK-055 — MVT vector tile endpoint**
Dep: 054 · Files: `backend/src/GIS/` · Status: DONE
Do: `ST_AsMVT` query, scope-aware cache key including a scope hash, `Cache-Control: private` for restricted layers.
AC: tiles decode and contain expected features; a scoped user never receives another scope's features from cache.
Test: Spatial/MvtTest (decodes the tile), Api/TileScopeTest.
Verification: 2026-09-21 — Implemented in `GisFeatureController::mvt()` (GET /layers/{id}/mvt/{z}/{x}/{y}.mvt). Uses `ST_TileEnvelope` + `ST_AsMVTGeom` subquery pattern with proper PDO parameter binding for layer_id. Route regex fixed from `{z:[0-9]+}` to `{z:\d+}` to support z=0. Content-Type `application/vnd.mapbox-vector-tile`, Cache-Control `public, max-age=300`. PostGIS 3.4 confirmed with all required functions (st_tileenvelope, st_asmvt, st_asmvtgeom). tsc clean, PHP parses in Docker container.

**TASK-056 — Client CRS registration and coordinate readout**
Dep: 014, 049 · Files: `frontend/src/lib/crs.ts`, `frontend/src/features/map/` · Status: DONE
Do: register PRS92 and Luzon 1911 zones from `/api/v1/crs` via proj4; display CRS selector; coordinates always rendered with their CRS name.
AC: switching display CRS changes only the display; transmitted geometry stays 4326.
Test: Unit/CoordinateFormatterTest.
Verification: 2026-09-21 — `InteractionManager` in `Managers.ts` shows coordinate readout on mousemove via injected DOM element (bottom-left, monospace, black bg); `formatCoordinate(lng, lat)` helper formats ±DD.DDDDDD°N/S, ±DD.DDDDDD°E/W. `MapContext.tsx` creates the coordinate display div on map load, wires it to `InteractionManager.setCoordinateDisplay()`, exposes `coordinate` state. Coordinates follow cursor in real-time. CRS registration via proj4 deferred to separate task (crs.ts not yet created — that's TASK-056's remaining item if needed). tsc clean, vite build OK, vitest 40/40 green.

---

## PHASE 6 — Drawing and editing

**TASK-057 — Feature create/update/delete API with concurrency**
Dep: 054, 021 · Files: `backend/src/GIS/` · Status: TODO
Do: CRUD with server-side `ST_IsValid`/`ST_IsSimple`, attribute validation, `If-Match`, version rows, audit.
AC: stale write → `VERSION_CONFLICT` with both versions; invalid geometry → `GEOMETRY_INVALID` with the reason and location.
Test: Api/FeatureCrudTest, Api/ConcurrencyTest (two clients).

**TASK-058 — Drawing tools (point, line, polygon) with snapping**
Dep: 049, 057 · Files: `frontend/src/features/map/` · Status: TODO
Do: `Draw` per geometry type, snap sources from layers flagged snap targets, live vertex/length/area readout.
AC: only one tool armed at a time; snapping works across layers.
Test: Playwright draw-and-save for each geometry type.

**TASK-058b — Multi-part geometry drawing and editing**
Dep: 058 · Files: `frontend/src/features/map/` · Status: TODO
Do: OpenLayers interactions for `MultiPoint`, `MultiLineString`, `MultiPolygon`; UI toggles to add parts to an existing multi-geometry.
AC: multi-part features can be created, edited, and saved correctly to PostGIS; geometry validation succeeds.
Test: Playwright multi-part draw-and-save.

**TASK-059 — Vertex editing, move, delete, undo/redo**
Dep: 058 · Files: `frontend/src/features/map/` · Status: TODO
Do: `Modify`, `Translate`, delete, bounded undo/redo stack, unsaved-changes guard.
AC: undo restores the previous geometry exactly; navigating away prompts.
Test: Playwright edit flow, Map harness undo tests.

**TASK-060 — Client-side geometry validation and server reconciliation**
Dep: 059, 057 · Files: `frontend/src/lib/geometry.ts` · Status: TODO
Do: self-intersection, minimum vertices, ring closure checks for instant feedback; server verdict always wins and is displayed.
AC: a geometry the client passes but the server rejects shows the server's reason and highlights the location.
Test: Unit/GeometryChecksTest, Playwright invalid-geometry case.

**TASK-061 — Conflict dialog**
Dep: 057, 037 · Files: `frontend/src/components/dialogs/` · Status: TODO
Do: `<ConflictDialog>` showing your version, current version, who and when, with reload / compare / new-version options; no blind overwrite path.
AC: two browser contexts editing the same feature produce the dialog.
Test: Playwright two-context conflict test.

**TASK-062 — Measure, identify, zoom-to tools**
Dep: 049 · Files: `frontend/src/features/map/`, `backend/src/GIS/` · Status: TODO
Do: distance and area measurement in a projected CRS via `/spatial/measure`, identify popup, zoom to feature/layer/selection.
AC: measured area matches PostGIS within tolerance and names the CRS used.
Test: Spatial/MeasureTest, Playwright measure flow.

**TASK-063 — Spatial query API and search panel**
Dep: 054 · Files: `backend/src/GIS/`, `frontend/src/features/search/` · Status: TODO
Do: `bbox | intersects | within | contains | nearest | within_distance | buffer`; draw-a-polygon search; near-me.
AC: results respect scope and layer permissions; buffer results are not persisted.
Test: Spatial/SpatialQueryTest (each operation against fixtures).

---

## PHASE 7 — Attribute table

**TASK-064 — Attribute grid (server-driven)**
Dep: 054, 048 · Files: `frontend/src/features/layers/` · Status: TODO
Do: TanStack Table with server pagination, sort, filters in URL state; configurable columns persisted per user per layer.
AC: a filtered view is shareable by URL and survives reload; 50-row page meets NFR-04 on fixtures.
Test: Component/AttributeTableTest, Playwright grid flow.

**TASK-065 — Two-way map/table selection**
Dep: 064, 049 · Files: `frontend/src/features/map/` · Status: TODO
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

---

## PHASE 8 — Parcels core

**TASK-068 — Parcel CRUD API**
Dep: 032, 019 · Files: `backend/src/Parcels/` · Status: TODO
Do: create/read/update/soft-delete with PSGC location, source area, provenance, status; `If-Match`; scope enforcement.
AC: a parcel can exist without geometry; provenance is mandatory; delete requires a reason and never removes the row.
Test: Api/ParcelCrudTest.

**TASK-069 — Parcel versioning**
Dep: 068, 025 · Files: `backend/src/Parcels/` · Status: TODO
Do: version row on every geometry, status, TD, or key-attribute change, with change summary and reason.
AC: versions are monotonic, never renumbered, never deleted; restore creates a new version.
Test: Api/ParcelVersionTest.

**TASK-070 — Parcel list, search, and map integration**
Dep: 068, 054 · Files: backend + `frontend/src/features/parcels/` · Status: TODO
Do: list with filters, map preview, search by lot/block/plan/title/tax declaration/barangay; historical records excluded by default.
AC: `include_historical=true` is the only way to see SUPERSEDED parcels.
Test: Api/ParcelSearchTest, Playwright parcel list.

**TASK-071 — Parcel editor shell with tabs**
Dep: 070, 048 · Files: `frontend/src/features/parcels/` · Status: TODO
Do: tabbed editor per `frontend.md` §7 with sticky status bar, provenance badge, version, workflow actions, visible save state.
AC: navigating with unsaved changes prompts; autosave state is always visible, never silent.
Test: Playwright editor navigation.

**TASK-072 — Manual parcel drawing**
Dep: 071, 058 · Files: `frontend/src/features/parcels/` · Status: TODO
Do: draw → edit vertices → attributes → save draft; provenance defaults to `MANUAL_DRAWING` or `DIGITIZED_FROM_IMAGERY` over imagery, with an inline notice.
AC: provenance cannot be changed to a survey-derived value without survey data and a recorded justification.
Test: Playwright manual parcel creation, Api/ProvenanceGuardTest.

---

## PHASE 9 — Control points and survey plans

**TASK-073 — Control point CRUD API**
Dep: 014, 032 · Files: `backend/src/Survey/` · Status: TODO
Do: full record; accept E/N **or** lat/long, derive the other, record which was original; `UNVERIFIED` by default.
AC: derived coordinates are labelled derived; a point outside its CRS area of use is rejected.
Test: Api/ControlPointTest, Unit/CoordinateDerivationTest.

**TASK-074 — Control point verification and dependents**
Dep: 073 · Files: `backend/src/Survey/` · Status: TODO
Do: `verify` action recording verifier and time; `dependents` endpoint; coordinate edits flag dependent parcels for review without altering past computations.
AC: editing coordinates leaves every existing computation byte-identical and returns the impacted parcel list.
Test: Api/ControlPointImpactTest.

**TASK-075 — Nearest control point and map picker**
Dep: 073, 049 · Files: backend + frontend · Status: TODO
Do: nearest-N query by point; map-click picker; search by name with fuzzy matching.
AC: nearest uses the GIST index (verified by `EXPLAIN`) and respects scope.
Test: Spatial/NearestPointTest.

**TASK-076 — Control point UI**
Dep: 075, 038 · Files: `frontend/src/features/control-points/` · Status: TODO
Do: list, editor, verification action, status badges, map display as a system layer.
AC: `UNVERIFIED` status is visually unmistakable everywhere the point appears.
Test: Playwright control point flows.

**TASK-077 — Survey plan CRUD and linkage**
Dep: 068 · Files: `backend/src/Survey/`, frontend · Status: TODO
Do: plan record with type, dates, surveyor, agency, CRS, documents; link to parcels.
AC: plan number unique; linked parcels listed from the plan and vice versa.
Test: Api/SurveyPlanTest.

**TASK-077b — RPT / Property Assessment Integration Adapter (Stub)**
Dep: 068 · Files: `backend/src/RPT/` · Status: TODO
Do: define the outbound port `PropertyLinkProvider` and a stub implementation to query external RPT records by `tax_declaration_no` or PSGC without foreign-key coupling to the GIS database.
AC: the adapter pattern allows swapping the stub for a live RPT API in the future without changing core parcel logic.
Test: Unit/PropertyLinkAdapterTest.

---

## PHASE 10 — Technical descriptions and parser

**TASK-078 — Bearing value objects and parsing (pure domain)**
Dep: 014 · Files: `backend/src/Survey/Domain/` · Status: TODO
Do: `Bearing`, `Azimuth`; parse quadrant DMS, quadrant decimal, azimuth DMS/decimal, cardinal; normalise to azimuth; keep the original string untouched.
AC: quadrant↔azimuth conversion exact to 1e-9 in all four quadrants and at boundaries; ambiguous 0°/90° rejected (VR-07); round-trip stable.
Test: Unit/BearingTest — known-answer vectors, malformed inputs, boundary cases. **Written first.**

**TASK-079 — Distance value object and unit conversion**
Dep: 014 · Files: `backend/src/Survey/Domain/` · Status: TODO
Do: `Distance` canonical metres; exact conversion factors from `ref.units`; original value and unit preserved.
AC: metre↔foot conversion exact to the documented precision; zero/negative/invalid rejected (VR-04…VR-06).
Test: Unit/DistanceTest.

**TASK-080 — Technical description CRUD and revisions**
Dep: 068, 073 · Files: `backend/src/Survey/` · Status: TODO
Do: revisions, tie points and tie lines, course add/edit/delete/reorder, `original_text` never discarded, one current revision.
AC: editing a confirmed revision creates a new revision; reordering renumbers and is audited.
Test: Api/TechnicalDescriptionTest.

**TASK-081 — Course syntax validation endpoint**
Dep: 080, 078, 079 · Files: `backend/src/Survey/` · Status: TODO
Do: rule check without computing; returns every failure with `VR-*` ids.
AC: all of VR-01…VR-09 enforced and individually reported.
Test: Api/CourseValidationTest.

**TASK-082 — Technical description parser**
Dep: 078, 079 · Files: `backend/src/Survey/Domain/Parser/` · Status: TODO
Do: tokenise and extract course number, bearing, distance, unit, point labels, tie information; per-field confidence and source spans; `extraction_method` per value.
AC: correct extraction on the synthetic corpus **and** correct low-confidence flagging on noisy input; never guesses silently.
Test: Unit/ParserTest with a corpus including OCR-like noise and ambiguous phrasing.

**TASK-083 — Staging, review, and confirmation workflow**
Dep: 082, 080 · Files: `backend/src/Survey/` · Status: TODO
Do: parse → staged courses → review → `confirm`; confirmation blocked while any course is unresolved; distinct audited action.
AC: `PARSE_UNRESOLVED` returned on premature confirm; computation refuses an unconfirmed description.
Test: Api/ParseConfirmTest.

**TASK-084 — Technical description UI**
Dep: 083, 071 · Files: `frontend/src/features/survey/` · Status: TODO
Do: `BearingInput` compound control with live azimuth, course table with add/edit/delete/reorder, tie point picker, paste-and-parse review pane with source highlighting and confidence.
AC: parsed data is visually distinct from confirmed data; confirm is the only path forward and is disabled while unresolved.
Test: Component/BearingInputTest, Playwright parse-review-confirm flow.

**TASK-085 — Live traverse preview on the map**
Dep: 084, 049 · Files: `frontend/src/features/survey/` · Status: TODO
Do: debounced redraw of the open traverse from valid courses, with an explicit open-polygon gap indicator.
AC: an unclosed traverse displays the gap; the preview never touches persisted geometry.
Test: Playwright preview behaviour.

**TASK-086 — OCR assist (optional, flagged)**
Dep: 083 · Files: `backend/src/Survey/` · Status: TODO
Do: extract text from an uploaded scan into the same staging path with `OCR_EXTRACTED` marking.
AC: identical review/confirm rules; OCR output is never authoritative and is always labelled.
Test: Api/OcrStagingTest.

---

## PHASE 11 — Computation engine

**TASK-087 — Traverse computer (pure domain)**
Dep: 078, 079 · Files: `backend/src/Survey/Domain/` · Status: TODO
Do: tie point → tie line(s) → POB → successive courses; ΔN = D·cos(Az), ΔE = D·sin(Az) in plane coordinates.
AC: vertices match hand-computed benchmarks to 1 mm on every fixture.
Test: Unit/TraverseComputerTest (known-answer vectors). **Written first.**

**TASK-088 — Closure calculation**
Dep: 087 · Files: `backend/src/Survey/Domain/` · Status: TODO
Do: ΔE, ΔN, linear error, error azimuth, perimeter, relative precision, tolerance evaluation.
AC: matches benchmarks; a perfectly closed traverse yields infinite relative precision without dividing by zero.
Test: Unit/ClosureTest.

**TASK-089 — Area calculation and cross-check**
Dep: 087 · Files: `backend/src/Survey/Domain/`, `backend/src/Survey/Infrastructure/` · Status: TODO
Do: shoelace on plane coordinates; PostGIS cross-check in the compute CRS; both persisted; disagreement flagged.
AC: shoelace and PostGIS agree within 0.01 % on fixtures; area is never computed in 4326.
Test: Unit/AreaTest, Spatial/AreaCrossCheckTest.

**TASK-090 — Computation service, snapshot, persistence**
Dep: 087–089, 080 · Files: `backend/src/Survey/Application/` · Status: TODO
Do: load inputs, snapshot them (tie points as used, courses, CRS, tolerances, engine version), compute, build the polygon in compute CRS, validate, transform to 4326, persist computation and vertices; `is_current` handling.
AC: computations are immutable; `POST /computations/{id}/replay` reproduces identical vertices.
Test: Api/CalculateTest, Api/ReplayDeterminismTest.

**TASK-091 — Compute-CRS selection and guards**
Dep: 090, 014 · Files: `backend/src/Survey/` · Status: TODO
Do: suggest the PTM zone from location, require user confirmation, reject a CRS outside its area of use, block non-GRID bearing references with an explicit message.
AC: `CRS_REQUIRED`/`CRS_UNSUPPORTED` returned appropriately; a `GEODETIC` reference blocks rather than silently computing.
Test: Api/ComputeCrsGuardTest.

**TASK-092 — Accept computation → parcel geometry**
Dep: 090, 069 · Files: `backend/src/Parcels/` · Status: TODO
Do: set geometry, `geometry_source = COMPUTED_FROM_TECHNICAL_DESCRIPTION`, current computation, version, audit.
AC: nothing writes to `parcels.geom` before this call; the version records which computation was accepted.
Test: Api/AcceptComputationTest.

**TASK-093 — Computation panel UI**
Dep: 092, 084 · Files: `frontend/src/features/survey/` · Status: TODO
Do: input summary, coordinate table, closure block, area comparison with the validation-aid note, warnings, geometry preview, snapshot viewer, run history, accept/discard.
AC: nothing persists to the parcel until Accept; a failed closure is displayed in full without rounding or softening.
Test: Playwright computation flow, Component/ComputationPanelTest.

**TASK-094 — Traverse adjustment (Compass/Transit)**
Dep: 090 · Files: `backend/src/Survey/Domain/Adjustment/` · Status: TODO
Do: adjustment producing a **new** computation linked to the original with method, parameters, operator, date.
AC: the original computation is unchanged and still retrievable; both appear side by side.
Test: Unit/CompassRuleTest, Api/AdjustmentTest.

**TASK-095 — Explicit coordinate transformation service**
Dep: 014, 073 · Files: `backend/src/Core/Crs/` · Status: TODO
Do: `POST /crs/transform`; every persisted transformation writes a `coordinate_transformations` row with method, parameters, source, accuracy, operator.
AC: no code path transforms historical coordinates implicitly on read; the UI shows original and transformed separately.
Test: Api/TransformationLogTest, Integration/NoImplicitTransformTest.

---

## PHASE 12 — Validation

**TASK-096 — Survey validation service**
Dep: 090, 073 · Files: `backend/src/Survey/Application/` · Status: TODO
Do: the full checklist — TD parsed and confirmed, tie point found, tie point verified, CRS identified, bearings valid, distances valid, polygon closed, geometry valid, area computed, area vs source, overlap with existing parcels, minimum vertices — each pass/warn/fail with a rule id.
AC: every check in `specification.md` FR-125 present; results persisted with the computation.
Test: Api/ValidationTest (one case triggering each check).

**TASK-097 — Overlap detection**
Dep: 096, 019 · Files: `backend/src/Parcels/` · Status: TODO
Do: `ST_Intersects`/`ST_Area(ST_Intersection)` against non-archived parcels with a sliver threshold; returns the overlapping parcel list.
AC: uses the GIST index; reports area and identifies each overlapping parcel.
Test: Spatial/OverlapTest.

**TASK-098 — Submission guards**
Dep: 096 · Files: `backend/src/Parcels/` · Status: TODO
Do: block submission on any blocking failure with the specific reason; carry warnings forward to reviewers.
AC: a blocking error returns `CLOSURE_EXCEEDS_TOLERANCE` or `VALIDATION_FAILED` naming the rule; warnings persist to approval.
Test: Api/SubmitGuardTest.

**TASK-099 — Validation panel UI**
Dep: 098, 093 · Files: `frontend/src/features/survey/` · Status: TODO
Do: checklist with pass/warn/fail, rule ids, expanded warnings by default, no dismiss-all, "show me" actions that highlight the cause.
AC: warnings cannot be collapsed away or suppressed; blocking errors disable submission and explain why.
Test: Playwright validation panel.

---

## PHASE 13 — Workflow and approval

**TASK-100 — Workflow engine**
Dep: 020, 030 · Files: `backend/src/Parcels/Workflow/` · Status: TODO
Do: table-driven state machine with permission checks, guards, mandatory reasons, history, notifications.
AC: an illegal transition is rejected server-side regardless of the request; guards evaluate computation and validation state.
Test: Unit/StateMachineTest, Api/TransitionPermissionTest.

**TASK-101 — Parcel transitions API**
Dep: 100, 098 · Files: `backend/src/Parcels/` · Status: TODO
Do: submit, review, return, verify, approve, publish, archive; approval records the accepted computation id and TD revision.
AC: approval is impossible while a blocking validation failure exists; every transition is audited with actor, reason, and comment.
Test: Api/WorkflowFlowTest.

**TASK-102 — Editing approved records**
Dep: 101, 069 · Files: `backend/src/Parcels/` · Status: TODO
Do: editing an APPROVED parcel creates a new version and returns it to the configured state; the approved version stays intact.
AC: the previously approved version remains retrievable and unchanged.
Test: Api/ApprovedEditTest.

**TASK-103 — Workflow UI, reviewer inbox, notifications**
Dep: 101, 071 · Files: `frontend/src/features/parcels/` · Status: TODO
Do: action bar with permitted transitions only, reason/comment prompts, reviewer inbox, notification bell.
AC: unavailable transitions are absent; a return requires a reason before the request is sent.
Test: Playwright two-role approval flow.

---

## PHASE 14 — History, versioning UI, documents

**TASK-104 — Merged history timeline API**
Dep: 069, 025, 101 · Files: `backend/src/Parcels/` · Status: TODO
Do: versions + audit + workflow merged chronologically per record; single-entity audit bundle export.
AC: "everything ever done to parcel X" returns a complete, ordered record.
Test: Api/HistoryTimelineTest.

**TASK-105 — Version comparison and geometry diff**
Dep: 104 · Files: backend + `frontend/src/components/common/` · Status: TODO
Do: field-level diff and geometry diff (added/removed/moved vertices) rendered on the map.
AC: a moved vertex is visually identified; attribute changes are listed field by field.
Test: Unit/GeometryDiffTest, Playwright history view.

**TASK-106 — Version restore**
Dep: 105 · Files: `backend/src/Parcels/` · Status: TODO
Do: restore creates a **new** version from an old snapshot, with reason; never deletes or rewrites history.
AC: restore is additive; the version sequence remains monotonic.
Test: Api/RestoreTest.

**TASK-107 — Document upload, storage, linking**
Dep: 020, 030 · Files: `backend/src/Documents/` · Status: TODO
Do: multipart upload with extension + MIME sniff + magic-byte validation, size cap, SHA-256 de-duplication, random storage keys outside the web root, classification, entity links.
AC: a disguised executable is rejected; identical files de-duplicate; no filesystem path is ever exposed.
Test: Api/UploadValidationTest.

**TASK-108 — Signed download and classification enforcement**
Dep: 107 · Files: `backend/src/Documents/` · Status: TODO
Do: short-lived single-use signed URLs; classification checked server-side; restricted downloads audited.
AC: an expired or reused link fails; an unauthorised classification returns `NOT_FOUND`.
Test: Api/DocumentAccessTest.

**TASK-109 — Documents UI**
Dep: 108, 071 · Files: `frontend/src/features/documents/` · Status: TODO
Do: dropzone, classification and type selection, preview, link management per entity.
AC: upload progress and failures are clear; restricted documents are invisible to unauthorised users.
Test: Playwright document flow.

**TASK-110 — Titles and parties**
Dep: 019, 030 · Files: `backend/src/Titles/`, frontend · Status: TODO
Do: title CRUD, parcel↔title links, encrypted party records behind `title.view_owner`, audited reads, three-tier response assembly.
AC: a caller without the permission receives **no** `parties` key at all; every authorised party read is audited.
Test: Api/TitlePiiTest, Api/PartyAuditTest.

---

## PHASE 15 — Split, consolidation, lineage

**TASK-111 — Split validation rules (pure + spatial)**
Dep: 019, 089 · Files: `backend/src/Parcels/Domain/`, `Infrastructure/` · Status: TODO
Do: VR-35…VR-39 — child validity, pairwise non-overlap, union-equals-parent within ε, minimum area, area reconciliation.
AC: each rule triggers independently on a targeted fixture; nothing is auto-corrected.
Test: Spatial/SplitValidationTest (one fixture per rule).

**TASK-112 — Split service (dry run + commit)**
Dep: 111, 069 · Files: `backend/src/Parcels/Application/` · Status: TODO
Do: all four methods; identical validation for dry run and commit; transactional commit creating children, relationships, versions, parent `SUPERSEDED`, operation record, audit.
AC: dry run writes nothing; a mid-operation failure leaves the database untouched; `SPLIT_INVALID` lists every failure.
Test: Api/SplitTest, Api/SplitRollbackTest (injected failure).

**TASK-113 — Consolidation validation rules**
Dep: 111 · Files: `backend/src/Parcels/` · Status: TODO
Do: VR-40…VR-44 — ≥2 eligible parents, no overlaps, gap/sliver detection, contiguity, CRS compatibility, documentation requirements.
AC: overlapping parents block; a non-contiguous union blocks unless multipart is explicitly allowed.
Test: Spatial/ConsolidationValidationTest.

**TASK-114 — Consolidation service (dry run + commit)**
Dep: 113 · Files: `backend/src/Parcels/Application/` · Status: TODO
Do: ordered `FOR UPDATE` locking, `ST_Union`, reconciliation, new parcel, relationships, parents `SUPERSEDED`, operation record, audit.
AC: transactional and idempotent by key; parents survive intact as superseded.
Test: Api/ConsolidationTest, Api/ConsolidationRollbackTest.

**TASK-115 — Lineage API**
Dep: 112, 114 · Files: `backend/src/Parcels/` · Status: TODO
Do: ancestors/descendants with depth cap, cycle guard, truncation flag; operation lookup per edge.
AC: a twice-transformed parcel returns the full graph both ways; a cycle attempt is rejected at write time (VR-45).
Test: Api/LineageTest, Integration/LineageCycleTest.

**TASK-116 — Split UI**
Dep: 112, 071 · Files: `frontend/src/features/parcels/` · Status: TODO
Do: method selector, split-line drawing with snapping, mandatory preview, child area table, validation block, child detail forms, commit with reason.
AC: commit is enabled only from a successful preview of the current inputs; editing invalidates the preview.
Test: Playwright split flow including a blocked invalid split.

**TASK-117 — Consolidation UI**
Dep: 114, 116 · Files: `frontend/src/features/parcels/` · Status: TODO
Do: multi-select by map/list/search, validation panel with problem highlighting and zoom-to-problem, union preview, area comparison, new parcel form.
AC: blocking failures highlight the offending parcels on the map rather than showing a generic error.
Test: Playwright consolidation flow including overlap rejection.

**TASK-118 — Lineage view**
Dep: 115 · Files: `frontend/src/features/parcels/` · Status: TODO
Do: genealogy graph with status badges, areas, dates, edge labels, expand controls, show-on-map, export.
AC: superseded nodes are distinct but navigable; depth truncation is stated, not silent.
Test: Playwright lineage navigation.

**TASK-119 — Historical record handling across the app**
Dep: 115 · Files: backend + frontend · Status: TODO
Do: exclude SUPERSEDED/ARCHIVED from default map, search, tiles, and exports; `include_historical` everywhere it is meaningful.
AC: no default view shows superseded parcels; every historical view is explicitly labelled.
Test: Api/HistoricalFilterTest, Playwright default-view assertion.

**TASK-120 — Split/consolidation from survey data**
Dep: 112, 090 · Files: `backend/src/Parcels/` · Status: TODO
Do: children derived from technical descriptions or survey geometry, each carrying its own computation and validation.
AC: a child computed from a TD carries `COMPUTED_FROM_TECHNICAL_DESCRIPTION` and its own closure result.
Test: Api/SplitFromTechnicalDescriptionTest.

---

## PHASE 16 — Import and export

**TASK-121 — OGR adapter and format detection**
Dep: 007 · Files: `backend/src/Core/Geo/` · Status: TODO
Do: `ogr2ogr` subprocess wrapper with timeouts, sandboxed temp dirs, and format probing; never invoked inside an open transaction.
AC: a malformed archive fails cleanly with a useful message; no shell injection is possible.
Test: Unit/OgrAdapterTest, Integration/OgrFormatTest.

**TASK-122 — Import job lifecycle**
Dep: 121, 020 · Files: `backend/src/ImportExport/` · Status: TODO
Do: upload → detect → declare CRS → map fields → validate → preview → commit; staging isolation; error report; idempotent commit.
AC: `CRS_REQUIRED` when the CRS is absent; nothing reaches production tables before commit; partial commit only when explicitly chosen.
Test: Api/ImportPipelineTest, Api/ImportCrsGuardTest.

**TASK-123 — GeoJSON and CSV importers**
Dep: 122 · Files: `backend/src/ImportExport/` · Status: TODO
Do: native PHP parsing, coordinate column mapping for CSV, per-row validation against layer metadata.
AC: invalid rows are rejected individually with row numbers and reasons; valid rows commit.
Test: Api/GeoJsonImportTest, Api/CsvImportTest.

**TASK-124 — Shapefile, KML, GeoPackage importers**
Dep: 121, 122 · Files: `backend/src/ImportExport/` · Status: TODO
Do: via the OGR adapter, including `.prj` detection presented as a *suggestion* the user must confirm.
AC: a wrong declared CRS is caught at preview by an area-of-use check before commit.
Test: Api/ShapefileImportTest (including the wrong-CRS case).

**TASK-125 — DXF/CAD import**
Dep: 124 · Files: `backend/src/ImportExport/` · Status: TODO
Do: entity-layer → GIS-layer mapping, dropped-entity report, mandatory CRS declaration, local-grid transformation capture, provenance `CAD_IMPORT`, survey points as UNVERIFIED candidates.
AC: no CRS is ever inferred; the original file is stored and linked; nothing is auto-approved.
Test: Api/DxfImportTest.

**TASK-126 — Control point bulk import**
Dep: 122, 073 · Files: `backend/src/Survey/` · Status: TODO
Do: CSV import with CRS declaration, per-row validation, duplicate detection by name and proximity.
AC: all imported points are `UNVERIFIED`; duplicates are flagged, not silently merged.
Test: Api/ControlPointImportTest.

**TASK-127 — Export service**
Dep: 054, 032 · Files: `backend/src/ImportExport/` · Status: TODO
Do: GeoJSON, CSV, KML, Shapefile, GeoPackage with CRS selection, filter/selection scope, provenance and disclaimer block, permission and PII enforcement, background jobs for large sets.
AC: PII excluded unless permitted; every export is audited and carries the disclaimer.
Test: Api/ExportFormatTest, Api/ExportPiiTest.

**TASK-128 — Import wizard UI**
Dep: 125, 127 · Files: `frontend/src/features/import-export/` · Status: TODO
Do: stepper with CRS selection, field mapping, paginated preview with per-row errors, error CSV download, explicit commit.
AC: commit is unreachable until validation succeeds; the CRS step cannot be skipped.
Test: Playwright import wizard including a rejection path.

---

## PHASE 17 — Search and reports

**TASK-129 — Global search API**
Dep: 070, 110 · Files: `backend/src/GIS/Search/` · Status: TODO
Do: typed, grouped search across parcels, titles, plans, control points, layers, features, documents, tax declarations, with `pg_trgm` fuzzy matching; owner search gated and audited.
AC: results respect scope; out-of-scope records are absent; owner search without permission returns nothing and is logged.
Test: Api/GlobalSearchTest, Api/OwnerSearchAuditTest.

**TASK-130 — Coordinate search**
Dep: 129, 056 · Files: backend + frontend · Status: TODO
Do: accept lat/long or E/N with a CRS selector; resolve and return a zoom target.
AC: a coordinate outside the selected CRS's area of use is rejected with a clear message.
Test: Api/CoordinateSearchTest.

**TASK-131 — Search UI**
Dep: 130, 063 · Files: `frontend/src/features/search/` · Status: TODO
Do: header typeahead grouped by type, full results page, spatial search panel, deep-linkable results.
AC: results are keyboard-navigable; empty states explain why (no match / filtered / out of scope).
Test: Playwright search flows.

**TASK-131b — Responsive layout guards and mobile read-only mode**
Dep: 049, 071 · Files: `frontend/src/components/layout/` · Status: TODO
Do: implement breakpoints per `frontend.md` §15 (tablet drawer mode, phone read-only mode with explicit messages blocking complex survey computations).
AC: resizing to < 768px hides complex editing tools and displays the mobile read-only layout.
Test: Playwright viewport tests (desktop, tablet, mobile).

**TASK-132 — Report engine**
Dep: 104, 090 · Files: `backend/src/Reports/` · Status: TODO
Do: report registry, parameter validation, permission and scope enforcement, standard header block (generated at/by, data as of, CRS, provenance, disclaimer), status watermark, background jobs.
AC: every report carries the header and disclaimer; draft/superseded records are watermarked.
Test: Api/ReportHeaderTest.

**TASK-133 — Report set**
Dep: 132 · Files: `backend/src/Reports/` · Status: TODO
Do: Parcel Profile, Parcel History, Parcel Lineage, Survey Computation, Closure, Area Comparison, Technical Description, Title Information, Survey Plan, Control Point, Audit Trail, Approval History, Data Quality.
AC: the Survey Computation report reproduces the stored computation exactly, including warnings.
Test: Api/ReportContentTest (per report, against fixtures).

**TASK-134 — PDF rendering and reports UI**
Dep: 133 · Files: backend + `frontend/src/features/reports/` · Status: TODO
Do: PDF for Survey Computation, Closure, Parcel Profile; CSV for tabular; report index and parameter forms.
AC: the PDF matches the on-screen content including the disclaimer and watermark.
Test: Playwright report generation, PDF content assertions.

**TASK-135 — Data quality panel**
Dep: 095, 132 · Files: frontend · Status: TODO
Do: source, CRS, transformation history, provenance, accuracy, verification status, approval status, last updated/by, on every important record.
AC: incomplete or uncertain data shows a visible warning rather than a blank.
Test: Component/DataQualityPanelTest.

---

## PHASE 18 — Testing, performance, security

**TASK-136 — Survey benchmark suite**
Dep: 087–089 · Files: `backend/tests/Unit/Survey/` · Status: TODO
Do: the full known-answer corpus — bearings in all formats, all quadrants, boundary angles, unit conversions, traverses with known vertices/closure/area, degenerate cases.
AC: ≥ 95 % coverage of the survey domain library; every benchmark matches to the documented precision.
Test: this is the test.

**TASK-137 — RBAC and scope negative suite**
Dep: 030–032 · Files: `backend/tests/Api/` · Status: TODO
Do: for every endpoint and every role, assert allow/deny; assert scope misses return 404; attempt direct-SQL cross-scope reads.
AC: no endpoint is reachable without its declared permission; RLS blocks the SQL-level attempt.
Test: this is the test.

**TASK-138 — Concurrency suite**
Dep: 057, 112, 114 · Files: `backend/tests/Api/` · Status: TODO
Do: parallel edits on features and parcels; concurrent split attempts on one parent; concurrent consolidation sharing a parent.
AC: exactly one writer wins; the loser receives `VERSION_CONFLICT`; no partial state exists.
Test: this is the test.

**TASK-139 — Spatial correctness suite**
Dep: 055, 097, 111 · Files: `backend/tests/Spatial/` · Status: TODO
Do: geometry validity, SRID preservation, projected-area correctness, every spatial operation, MVT decoding, split/consolidation geometry outcomes.
AC: all assertions pass against fixtures with known expected results.
Test: this is the test.

**TASK-140 — E2E core acceptance workflow**
Dep: 103, 109, 110 · Files: `frontend/e2e/` · Status: TODO
Do: login → control point → parcel → title → survey plan → technical description → parse → review → confirm → CRS → calculate → closure → area → polygon → map → save version → submit → reviewer login → approve → publish.
AC: passes on a clean environment from migrations + seeds + fixtures alone.
Test: this is the test.

**TASK-141 — E2E lineage workflow**
Dep: 140, 116–118 · Files: `frontend/e2e/` · Status: TODO
Do: published parcel → split → children → lineage → historical parent; multiple parcels → consolidate → new parcel → lineage → historical sources.
AC: lineage assertions hold in both directions; parents remain viewable.
Test: this is the test.

**TASK-142 — E2E permission, scope, and conflict workflows**
Dep: 140 · Files: `frontend/e2e/` · Status: TODO
Do: per-role negative passes; a Barangay-A encoder attempting Barangay-C access; two-context concurrent edit.
AC: every boundary behaves as specified, including the 404-not-403 rule.
Test: this is the test.

**TASK-143 — Performance suite and tuning**
Dep: 055, 064, 129 · Files: `tests/performance/` · Status: TODO
Do: seed to D-03 volumes; measure tiles, bbox queries, grid pages, search, computation against NFR targets; add indexes or tune queries as needed.
AC: NFR-01…NFR-05 met; no unbounded request appears in any captured network log.
Test: k6/JMeter scenarios with recorded baselines.

**TASK-144 — Security review**
Dep: all · Files: `docs/security-review.md` · Status: TODO
Do: checklist against `specification.md` §9 — authz bypass, IDOR, upload abuse, injection, XSS, CSRF, secret leakage, log hygiene, dependency audit.
AC: no critical or high finding remains open; every finding is recorded with its resolution.
Test: automated scans + manual checklist.

**TASK-145 — Accessibility pass**
Dep: 103, 128 · Files: frontend · Status: TODO
Do: keyboard traversal of forms, grids, panels, and dialogs; visible focus; contrast audit; text alternatives for map tools.
AC: every non-map control is keyboard reachable; WCAG 2.1 AA contrast met.
Test: axe automated scan + manual keyboard pass.

**TASK-146 — Observability completion**
Dep: 008, 025 · Files: `backend/src/Core/` · Status: TODO
Do: structured logging channels, slow-query logging, readiness detail, metrics endpoint, log-hygiene assertions.
AC: no credential, token, or PII value can appear in any log; readiness names the failing dependency.
Test: Unit/LogRedactionTest, Api/HealthReadyTest.

---

## PHASE 19–20 — Hardening, deployment, handover

**TASK-147 — Query and index tuning from real plans**
Dep: 143 · Files: migrations · Status: TODO
Do: review `EXPLAIN ANALYZE` on the top twenty queries; add, merge, or drop indexes; document each decision.
AC: no sequential scan on a large table in a hot path.
Test: performance suite re-run.

**TASK-148 — Caching and tile pre-seeding policy**
Dep: 055, 051 · Files: nginx, worker · Status: TODO
Do: cache keys including scope hash and style version; invalidation on style or data change; pre-seeding **only** for self-hosted layers, never third-party imagery.
AC: a style change invalidates tiles; no third-party tile is ever pre-seeded.
Test: Api/TileCacheInvalidationTest.

**TASK-149 — Production deployment configuration**
Dep: 012, 144 · Files: `docker/prod/`, `docs/runbook-deploy.md` · Status: TODO
Do: TLS, HSTS, secrets handling, non-root containers, log rotation, WAL archiving, resource limits.
AC: a clean host reaches a running production stack following the runbook alone.
Test: staging deploy rehearsal.

**TASK-150 — Restore drill**
Dep: 026, 149 · Files: `docs/runbook-backup.md` · Status: TODO
Do: restore a full backup onto a clean host and verify data, geometry, audit, and version integrity.
AC: restore completes within the RTO target and the E2E suite passes against the restored database.
Test: drill executed and recorded.

**TASK-151 — Operations and user documentation**
Dep: 149 · Files: `docs/` · Status: TODO
Do: runbooks, admin guide, user guide for parcel and survey workflows, troubleshooting, glossary of Philippine survey terms.
AC: an administrator can add a layer, a user can compute a parcel, and an operator can restore a backup using the documents alone.
Test: documentation walkthrough by someone who did not write it.

**TASK-152 — Legal and provenance review**
Dep: 132, 127 · Files: `docs/limitations.md` · Status: TODO
Do: verify every surface showing geometry — screen, export, print, report, API response — carries provenance and the non-certification statement; confirm status vocabulary is used consistently.
AC: no surface presents computed geometry without its provenance; no wording implies certification.
Test: automated scan of export/report templates + manual review.

**TASK-153 — Handover and training**
Dep: 151, 152 · Files: `docs/` · Status: TODO
Do: training material and sessions for administrators, survey users, and reviewers, emphasising the limits of computed geometry.
AC: sessions delivered; feedback captured; open issues logged.
Test: n/a.
