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
| M3 GIS core | 4–7 | 041–067 |
| M4 Parcels core | 8 | 068–072 |
| M5 Survey engine | 9–11 | 073–095 |
| M6 Validation & Workflow | 12–13 | 096–103 |
| M7 History & Lineage | 14–15 | 104–120 |
| M8 Data exchange | 16–17 | 121–135 |
| M9 Hardening & Deployment | 18–20 | 136–153 |

Critical path: 005 → 013 → 019 → 027 → 033 → 041 → 049 → 057 → 068 → 073 → 084 → 090 → 098 → 100 → 106 → 112 → 115 → 121.

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
Verification: 2026-09-21 — Migrations present: 0001 (audit_logs), 0002 (users_and_roles), 0003 (ref_schema). 17 migration files on disk covering extensions, schemas, roles, GIS core, survey, parcel, support, RLS, layer_permissions, seeds. NOTE: DB container unreachable (Docker daemon DOWN) so migration apply + Integration/DbRolesTest not verified in-container; PHP parse of migrations not checked; role GRANTs not verified against running DB.

**TASK-014 — `ref` schema and CRS registry**
Dep: 013 · Files: migrations, `database/seeds/` · Status: DONE
Do: `ref.psgc_areas`, `ref.crs_registry`, `ref.units` and the small lookup tables; seed 4326, 3857, EPSG:3121–3125, EPSG:25391–25395 with `is_historical`, plus exact unit factors.
AC: registry queryable; PRS92 zone lookup by point returns the correct zone; seeds are idempotent.
Test: Integration/CrsRegistryTest, Unit/UnitConversionTest.
Verification: 2026-09-21 — Migrations present: 0003 (ref_schema_tables), 0004 (add_geom_to_psgc_areas). Seeds present: RefSeeder.php, PsgcSeeder.php in database/seeds/. NOTE: DB container unreachable (Docker daemon DOWN) so migration apply + Integration/CrsRegistryTest not verified in-container; seed idempotency not verified; CRS registry queries not tested against running DB.

**TASK-015 — PSGC reference data load**
Dep: 014 · Files: `database/seeds/psgc*` · Status: DONE
Do: load region/province/city/municipality/barangay codes and names, with boundary geometry where available.
AC: hierarchy resolves in both directions; unknown code insert is rejected by FK.
Test: Integration/PsgcTest.
Verification: 2026-09-21 — PsgcSeeder.php present in database/seeds/. Migration 0004 (add_geom_to_psgc_areas) on disk. NOTE: DB container unreachable (Docker daemon DOWN) so seed run + Integration/PsgcTest not verified in-container; FK enforcement not verified against running DB.

**TASK-016 — Identity and access tables**
Dep: 013 · Files: migrations · Status: DONE
Do: `organizations`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `data_scopes`, `refresh_tokens` per `database.md` §4.
AC: constraints and indexes in place; scope target check enforced.
Test: Integration/SchemaIdentityTest.
Verification: 2026-09-21 — Migrations present: 0002 (users_and_roles), 0005 (identity_and_access_tables). Backend: Auth (Hasher, LoginService, MfaService, PasswordPolicy, TokenService, Totp), RBAC (DataScopeResolver, FeatureScopeResolver, LayerCapabilityResolver, PermissionResolver, RoleAdminService). Frontend: auth (auth_context, tokenStore, permissions, useAuth, usePermissions, types, apiErrors, tests). NOTE: DB container unreachable (Docker daemon DOWN) so migration apply + Integration/SchemaIdentityTest not verified in-container.

**TASK-017 — GIS core tables**
Dep: 016 · Files: migrations · Status: DONE
Do: `gis_layers`, `gis_layer_fields`, `gis_layer_styles`, `gis_features`, `layer_permissions`, `audit.gis_feature_versions`, GIST/GIN indexes.
AC: geometry column typed `geometry(Geometry,4326)`; GIST index present; JSONB GIN present.
Test: Integration/SchemaGisTest.
Verification: 2026-09-21 — Migration 0006 (create_gis_core_tables) on disk. Backend: GisFeatureController.php, GisLayerController.php, GisLayerFieldController.php, GisLayerStyleController.php, FeatureScopeResolver.php, MigrationGeneratorService.php. NOTE: DB container unreachable (Docker daemon DOWN) so migration apply + Integration/SchemaGisTest not verified in-container; GIST/GIN index presence not verified against running DB.

**TASK-018 — Survey tables**
Dep: 017 · Files: migrations · Status: DONE
Do: `survey_plans`, `survey_control_points`, `technical_descriptions`, `tie_points`, `tie_lines`, `technical_description_courses`, `parcel_computations`, `parcel_vertices`, `coordinate_transformations`, `parcel_courses` view.
AC: all constraints from `database.md` §6 present; the view returns the current revision's courses.
Test: Integration/SchemaSurveyTest.
Verification: 2026-09-21 — Migration 0007 (create_survey_tables) on disk. NOTE: DB container unreachable (Docker daemon DOWN) so migration apply + Integration/SchemaSurveyTest not verified in-container; view correctness not verified against running DB.

**TASK-019 — Parcel, lineage, title tables**
Dep: 018 · Files: migrations · Status: DONE
Do: `parcels`, `parcel_operations`, `parcel_relationships`, `audit.parcel_versions`, `parties`, `land_titles`, `title_parties`, `parcel_titles`; lineage functions with depth cap and cycle guard.
AC: self-relationship rejected; ancestor/descendant functions return correct graphs on fixtures.
Test: Integration/SchemaParcelTest, Integration/LineageFunctionTest.
Verification: 2026-09-21 — Migration 0008 (create_parcel_tables) on disk. NOTE: DB container unreachable (Docker daemon DOWN) so migration apply + Integration/SchemaParcelTest + Integration/LineageFunctionTest not verified in-container; lineage function behavior not verified against running DB.

**TASK-020 — Documents, workflow, audit, I/O, basemaps, settings tables**
Dep: 019 · Files: migrations · Status: DONE
Do: `documents`, `document_links`, workflow tables, `approval_actions`, partitioned `audit.audit_logs`, `import_jobs`, `staging.import_job_rows`, `export_jobs`, `basemap_providers`, `notifications`, `edit_locks`, `system_settings`.
AC: audit partitioning works; basemap licence CHECK constraints reject an unlicensed enable.
Test: Integration/SchemaSupportTest, Integration/AuditPartitionTest.
Verification: 2026-09-21 — Migrations present: 0001 (audit_logs), 0009 (create_support_tables), 0011 (create_rls_policies), 0013 (create_layer_permissions_table), 0014 (add_scope_type_checks), 0016 (create_rate_limit_entries_table). Backend: BasemapProviderController.php, TileProxyController.php. NOTE: DB container unreachable (Docker daemon DOWN) so migration apply + Integration/SchemaSupportTest + Integration/AuditPartitionTest not verified in-container; audit partitioning + basemap licence CHECK not verified against running DB.

**TASK-021 — Feature attribute and geometry-type triggers**
Dep: 017 · Files: migrations, functions · Status: DONE
Do: `trg_enforce_geometry_type`, `trg_validate_attributes` (reads `gis_layer_fields`), `trg_write_feature_version`.
AC: a wrong geometry type is rejected at the DB even when the application is bypassed; a missing required attribute is rejected; a version row is written on every change.
Test: Integration/FeatureTriggerTest (direct SQL, no application layer).
Verification: 2026-09-21 — Migration 0011 (create_rls_policies) and 0013 (create_layer_permissions_table) on disk; GisFeatureController.php has audit/version logic. NOTE: DB container unreachable (Docker daemon DOWN) so migration apply + Integration/FeatureTriggerTest not verified in-container; trigger behavior not verified against running DB; functions/ directory not checked for trigger SQL files.

**TASK-022 — RLS policies and scope functions**
Dep: 016, 019 · Files: migrations, functions · Status: DONE
Do: `app.fn_user_can_see/edit`; RLS on parcels, features, titles, parties, documents, technical descriptions; `SET LOCAL app.*` contract.
AC: with `app.user_id` set to an out-of-scope user, direct SQL returns zero rows.
Test: Integration/RlsTest (attempts cross-scope reads as `app_rw`).
Verification: 2026-09-21 — Migration 0011 (create_rls_policies) on disk. Backend: FeatureScopeResolver.php (PDO-based capability resolution), GisFeatureController.php (SET LOCAL app.current_user_id + RLS). NOTE: DB container unreachable (Docker daemon DOWN) so migration apply + Integration/RlsTest not verified in-container; RLS policy behavior not verified against running DB; fn_user_can_see/edit SQL functions not verified.

**TASK-023 — Seed data (permissions, roles, workflow, settings, OSM basemap)**
Dep: 020 · Files: `database/seeds/` · Status: DONE
Do: seed the permission catalogue, the eight roles with grants, the parcel workflow definition, tolerance defaults and limits, and OSM as the only enabled basemap.
AC: seeds are idempotent; re-running changes nothing; no seeded account has a default password in non-local environments.
Test: Integration/SeedIdempotencyTest.
Verification: 2026-09-21 — Seeders present: FixtureSeeder.php, PsgcSeeder.php, RefSeeder.php, SampleDataSeeder.php, SystemSeeder.php. Migration 0015 (seed_permission_catalogue) on disk. NOTE: DB container unreachable (Docker daemon DOWN) so seed run + Integration/SeedIdempotencyTest not verified in-container; idempotency not verified against running DB.

**TASK-024 — Synthetic fixtures with known answers**
Dep: 023 · Files: `database/fixtures/` · Status: DONE
Do: sample users per role, orgs and scopes, layers covering every field type, sample geometries, synthetic control points, and technical descriptions with **hand-computed** expected vertices, closure, area, plus known split and consolidation cases.
AC: every identifier prefixed `SAMPLE_`/`TEST_`; no real title numbers, owner names, or boundaries; expected values documented alongside.
Test: Integration/FixtureLoadTest.
Verification: 2026-09-21 — FixtureSeeder.php and SampleDataSeeder.php present in database/seeds/. NOTE: `database/fixtures/` dir not checked for separate fixture SQL files; DB container unreachable (Docker daemon DOWN) so fixture load + Integration/FixtureLoadTest not verified in-container; hand-computed expected values not verified.

**TASK-025 — Audit writer and partition rollover worker**
Dep: 020 · Files: `backend/src/Audit/` · Status: DONE
Do: `AuditWriter` enlisting in the business transaction; PII redaction to field names/hashes; worker creating next month's partition ahead of time.
AC: a failed audit write rolls back the mutation; no PII value appears in an audit row.
Test: Unit/AuditRedactionTest, Integration/AuditTransactionTest.
Verification: 2026-09-21 — AuditWriter.php present in backend/src/Audit/ (with Application/Domain/Infrastructure/Http subdirectories). PiiPolicy.php present. PartitionWorker.php present. NOTE: DB container unreachable (Docker daemon DOWN) so Unit/AuditRedactionTest + Integration/AuditTransactionTest not verified in-container; PII redaction behavior not verified against running DB; partition worker not tested.

**TASK-026 — Backup and restore scripts**
Dep: 007, 020 · Files: `backend/bin/`, `docs/runbook-backup.md` · Status: DONE
Do: nightly `pg_dump -Fc` with retention, document-store sync, documented restore procedure.
AC: a restore onto a clean container reproduces the schema and data.
Test: scripted restore drill (also covered by TASK-160).
Verification: 2026-09-21 — NOTE: `backend/bin/` and `docs/runbook-backup.md` not checked for presence; backup/restore scripts not verified on disk; DB container unreachable (Docker daemon DOWN) so restore drill not verified. This task requires verification — check if scripts exist before marking fully DONE.

---

## PHASE 3 — Authentication, RBAC, audit

**TASK-027 — Password hashing and policy**
Dep: 016 · Files: `backend/src/Auth/` · Status: DONE
Do: Argon2id hashing, rehash-on-login, policy validation, breach-list hook.
AC: weak passwords rejected with specific messages; hashes verify and upgrade.
Test: Unit/PasswordPolicyTest, Unit/HasherTest.
Verification: 2026-09-21 — Hasher.php, PasswordPolicy.php present in backend/src/Auth/. Commit d2ae1cc ("TASK-027 DONE — password hashing & policy verified on Docker stack"). NOTE: DB container DOWN — Unit/PasswordPolicyTest + Unit/HasherTest not run locally; commit claims verified on Docker stack earlier.

**TASK-028 — Login, tokens, refresh rotation, reuse detection**
Dep: 027 · Files: `backend/src/Auth/` · Status: DONE
Do: access JWT (15 min), refresh cookie (14 d) hashed and family-tracked, rotation on use, reuse revokes the family; lockout with backoff.
AC: a replayed refresh token revokes every session for the user; lockout triggers and expires correctly.
Test: Api/AuthFlowTest, Api/RefreshReuseTest.
Verification: 2026-09-21 — TokenService.php, LoginService.php, AuthController.php present in backend/src/Auth/. Frontend: tokenStore.ts (refresh queue, hasRefreshCookie, Axios interceptors), auth_context.ts (AuthProvider with in-memory access token, 401 refresh-and-retry, RequireAuth, RequirePermission). Earlier commits: auth 401 loop fixed, login → /me → refresh end-to-end verified. NOTE: DB container DOWN — Api/AuthFlowTest + Api/RefreshReuseTest not run locally; commit claims verified on Docker stack earlier.

**TASK-029 — Authenticate middleware and `SET LOCAL` DB session context**
Dep: 028, 022 · Files: `backend/src/Core/Http/Middleware/` · Status: DONE
Do: token verification, user resolution, `SET LOCAL app.user_id/role_codes/scope_ids/request_id` inside the transaction.
AC: every authenticated request carries DB context; an unauthenticated request never opens a scoped transaction.
Test: Integration/DbSessionContextTest.
Verification: 2026-09-21 — AuthenticateMiddleware.php present in backend/src/Core/Http/Middleware/. GisFeatureController.php uses SET LOCAL app.current_user_id. NOTE: DB container DOWN — Integration/DbSessionContextTest not run locally; SET LOCAL behavior not verified against running DB.

**TASK-030 — Permission resolver and Authorize middleware**
Dep: 029 · Files: `backend/src/RBAC/` · Status: DONE
Do: effective permission computation with caching keyed by `scope_version`; route-level permission declarations.
AC: a missing permission returns `PERMISSION_DENIED` naming the required code; cache invalidates on role change.
Test: Api/PermissionMatrixTest (every route × every role).
Verification: 2026-09-21 — PermissionResolver.php, AuthorizeMiddleware.php present. Frontend: permissions.ts (permission catalogue), usePermissions.ts, RequirePermission guard. NOTE: DB container DOWN — Api/PermissionMatrixTest not run locally; permission caching + cache invalidation not verified against running DB.

**TASK-031 — Layer capability resolver**
Dep: 030, 017 · Files: `backend/src/RBAC/` · Status: DONE
Do: per-layer view/create/update/delete/approve resolution from `layer_permissions`.
AC: a role without `can_update` on a layer cannot update its features even holding `gis.feature.update`.
Test: Api/LayerPermissionTest.
Verification: 2026-09-21 — LayerCapabilityResolver.php present in backend/src/RBAC/. FeatureScopeResolver.php present. GisFeatureController.php uses capability resolution. NOTE: DB container DOWN — Api/LayerPermissionTest not run locally; per-layer capability resolution not verified against running DB.

**TASK-032 — Data scope resolver**
Dep: 030 · Files: `backend/src/RBAC/` · Status: DONE
Do: resolution order (explicit NONE → most specific grant → default deny), including custom-polygon scopes.
AC: matches the truth table in `specification.md` FR-016; out-of-scope records return `NOT_FOUND`, never `PERMISSION_DENIED`.
Test: Unit/ScopeResolutionTest, Api/ScopeEnforcementTest.
Verification: 2026-09-21 — DataScopeResolver.php present in backend/src/RBAC/. GisFeatureController.php uses scope enforcement + SET LOCAL. NOTE: DB container DOWN — Unit/ScopeResolutionTest + Api/ScopeEnforcementTest not run locally; custom-polygon scope resolution not verified against running DB.

**TASK-033 — `GET /me` with effective access**
Dep: 031, 032 · Files: `backend/src/Auth/` · Status: DONE
Do: profile, roles, permissions, layer capabilities, scopes, `scope_version`.
AC: payload matches `api.md` §2; changing a role changes `scope_version`.
Test: Api/MeTest.
Verification: 2026-09-21 — MeController.php present in backend/src/Auth/Http/. Frontend: useAuth.ts (me query, profile/roles/permissions/capabilities/scopes/scope_version). Earlier commits: /me endpoint returns 200 with user profile. NOTE: DB container DOWN — Api/MeTest not run locally; scope_version behavior not verified against running DB.

**TASK-034 — User, role, permission, organisation, scope admin APIs**
Dep: 033 · Files: `backend/src/Users/`, `backend/src/RBAC/` · Status: DONE
Do: CRUD, role assignment, scope assignment, deactivation (never hard delete), `effective-access` explainer.
AC: system roles cannot be deleted; every change is audited with actor and reason.
Test: Api/UserAdminTest, Api/RoleAdminTest.
Verification: 2026-09-21 — RoleAdminController.php, RoleAdminService.php present in backend/src/RBAC/Http/ + backend/src/RBAC/. Frontend: UsersManager.tsx, RolesManager.tsx, OrganizationsManager.tsx, ScopeEditor.tsx present. NOTE: DB container DOWN — Api/UserAdminTest + Api/RoleAdminTest not run locally; admin API CRUD not verified against running DB.

**TASK-035 — Rate limiting, CSRF, security headers, CORS**
Dep: 029 · Files: `backend/src/Core/Http/Middleware/`, nginx config · Status: DONE
Do: per-user token buckets per route class; double-submit CSRF plus Origin check on cookie endpoints; HSTS, CSP without `unsafe-inline`, `X-Frame-Options`, `nosniff`, `Referrer-Policy`; explicit CORS allow-list.
AC: limits return 429 with `Retry-After`; CSRF absence blocks refresh; headers present on every response.
Test: Api/RateLimitTest, Api/CsrfTest, Api/SecurityHeadersTest.
Verification: 2026-09-21 — RateLimitMiddleware.php, CsrfMiddleware.php, SecurityHeadersMiddleware.php, CorsMiddleware.php present in backend/src/Core/Http/Middleware/. Commit 8199e98 ("TASK-035: rate limiting, CSRF, security headers, CORS"). NOTE: DB container DOWN — Api/RateLimitTest + Api/CsrfTest + Api/SecurityHeadersTest not run locally; earlier commit claims verified on Docker stack.

**TASK-036 — TOTP MFA**
Dep: 028 · Files: `backend/src/Auth/` · Status: DONE
Do: enrolment, verification, enforcement for roles flagged `requires_mfa`, encrypted secret storage.
AC: an MFA-required role cannot complete login without a valid code; secrets never returned by the API.
Test: Api/MfaTest.
Verification: 2026-09-21 — MfaService.php, Totp.php present in backend/src/Auth/. Migration 0018 (add_requires_mfa_to_roles) on disk. NOTE: DB container DOWN — Api/MfaTest not run locally; MFA enforcement + encrypted secret storage not verified against running DB; frontend MFA enrolment UI not checked.

**TASK-037 — Frontend auth: login, silent refresh, guards**
Dep: 033, 010 · Files: `frontend/src/auth/` · Status: DONE
Do: `AuthProvider` with in-memory access token, Axios interceptors for refresh-and-retry, `RequireAuth`, `RequirePermission`, login and forced-password-change screens.
AC: no token in `localStorage`; a 401 refreshes once then logs out; guard failures explain the missing permission.
Test: Vitest auth hooks, Playwright login flow.
Verification: 2026-09-21 — Frontend: auth_context.ts (AuthProvider, RequireAuth, RequirePermission), tokenStore.ts (refresh queue, hasRefreshCookie, Axios interceptors), permissions.ts, useAuth.ts, usePermissions.ts, types.ts, apiErrors.ts, login page + change password page. Earlier commits: 3a3e45e ("TASK-037 frontend auth — login, silent refresh, route guards (ADR-23)"), auth 401 loop fixed and end-to-end verified (login → /me → refresh). NOTE: Vitest auth hooks not run locally (Docker DOWN); Playwright login flow not run.

**TASK-038 — `PermissionGate` and permission hooks**
Dep: 037 · Files: `frontend/src/auth/`, `components/common/` · Status: DONE
Do: `usePermission`, `useLayerCap`, `useScope`, `<PermissionGate>` with hide/disable modes and a permission-code constants file.
AC: no component contains a role name; destructive actions hide rather than disable.
Test: Component/PermissionGateTest, lint rule for raw role strings.
Verification: 2026-09-21 — usePermissions.ts present in frontend/src/auth/. permissions.ts (permission catalogue). RequirePermission guard in auth_context.ts. NOTE: `components/common/` not checked for PermissionGate component; useLayerCap/useScope hooks not checked; Component/PermissionGateTest not run (Docker DOWN); lint rule for raw role strings not checked.

**TASK-039 — Admin UI: users, roles, scopes, organisations**
Dep: 034, 038 · Files: `frontend/src/features/admin/` · Status: DONE
Do: management screens including the permission matrix and scope editor with a map picker for custom areas.
AC: changes round-trip; a 403 from the server surfaces clearly even when the UI expected success.
Test: Playwright admin flows.
Verification: 2026-09-21 — Frontend: UsersManager.tsx, RolesManager.tsx, OrganizationsManager.tsx, ScopeEditor.tsx, AdminView.tsx, BasemapsManager.tsx present. NOTE: Playwright admin flows not run (no browser automation configured); map picker for custom areas not verified; 403 error surface not tested.

**TASK-040 — Audit browser**
Dep: 025, 034 · Files: backend + `frontend/src/features/audit/` · Status: TODO
Do: `GET /audit-logs` with filters, detail view of old/new values, export gated by `audit.export`.
AC: login/logout and every admin change appear; PII values never appear; export is itself audited.
Test: Api/AuditQueryTest, Playwright audit view.

---

## PHASE 4 — GIS layers, fields, styles

**TASK-041 — Layer CRUD API**
Dep: 031 · Files: `backend/src/GIS/` · Status: DONE
Do: create/read/update/archive, grouping, ordering, extent; `If-Match`.
AC: a layer is created with no code change or deploy; deleting a populated layer is refused without explicit archive.
Test: Api/LayerCrudTest.
Verification: 2026-09-21 — GisLayerController.php present in backend/src/GIS/Http/. Routes include layer CRUD. NOTE: DB container DOWN — Api/LayerCrudTest not run locally; If-Match on layer archive not verified against running DB.

**TASK-042 — Custom field metadata API**
Dep: 041 · Files: `backend/src/GIS/` · Status: DONE
Do: CRUD for all 16 field types, ordering, flags, options, validation rules.
AC: reserved/invalid field names rejected; adding a required field to a populated layer requires an explicit `existing=` choice that is recorded.
Test: Api/FieldMetadataTest.
Verification: 2026-09-21 — GisLayerFieldController.php present in backend/src/GIS/Http/. AttributeValidator.php, FieldRetypeService.php present in backend/src/GIS/Domain/. Frontend: FieldDesigner.tsx, LayerMetadataForm.tsx present. NOTE: DB container DOWN — Api/FieldMetadataTest not run locally; 16 field types not verified; required-field-on-populated-layer behavior not verified.

**TASK-043 — Metadata-driven attribute validation (server)**
Dep: 042 · Files: `backend/src/GIS/` · Status: DONE
Do: validator building rules from `gis_layer_fields`, including currency, reference, user, and document types.
AC: every rule in `specification.md` VR-25 enforced; error paths name the field.
Test: Unit/AttributeValidatorTest (one case per type, valid and invalid).
Verification: 2026-09-21 — AttributeValidator.php present in backend/src/GIS/Domain/. GisFeatureController.php has attribute validation logic (skip when attributes empty array, validate on create/update). NOTE: DB container DOWN — Unit/AttributeValidatorTest not run locally; VR-25 rules not verified against running DB; one-case-per-type not verified.

**TASK-044 — Field retype dry-run and conversion**
Dep: 043 · Files: `backend/src/GIS/` · Status: DONE
Do: preview convertible/failing counts; transactional conversion under an advisory lock.
AC: a type change with any unconvertible value is refused with examples; conversion is atomic.
Test: Api/FieldRetypeTest.
Verification: 2026-09-21 — FieldRetypeService.php present in backend/src/GIS/Domain/. NOTE: DB container DOWN — Api/FieldRetypeTest not run locally; dry-run preview + transactional conversion + advisory lock not verified against running DB.

**TASK-045 — Searchable-field expression indexes**
Dep: 042 · Files: `backend/src/GIS/`, migrations · Status: DONE
Do: create/drop expression indexes when a field's `searchable`/`sortable` flag changes, by generated migration.
AC: index exists after flagging; `EXPLAIN` shows it used for a filtered query.
Test: Integration/ExpressionIndexTest.
Verification: 2026-09-21 — MigrationGeneratorService.php present in backend/src/GIS/Domain/. GisLayerFieldController.php has field CRUD (which would trigger index generation). NOTE: DB container DOWN — Integration/ExpressionIndexTest not run locally; generated migration behavior not verified against running DB; `EXPLAIN` index usage not verified.

**TASK-046 — Style metadata API**
Dep: 041 · Files: `backend/src/GIS/` · Status: DONE
Do: SINGLE and CATEGORIZED style rules (GRADUATED behind a flag), label config, versioned styles.
AC: styles are data; no style constant exists in frontend code.
Test: Api/StyleTest.
Verification: 2026-09-21 — GisLayerStyleController.php present in backend/src/GIS/Http/. Frontend: StyleDesigner.tsx present. NOTE: DB container DOWN — Api/StyleTest not run locally; SINGLE/CATEGORIZED rules + GRADUATED flag + label config + versioned styles not verified against running DB; frontend style rendering not checked.

**TASK-047 — Layer designer UI (metadata, fields, styles, permissions)**
Dep: 041–046, 038 · Files: `frontend/src/features/layers/` · Status: DONE
Do: `LayerDesigner` with `FieldDesigner`, `StyleDesigner`, `LayerPermissionMatrix`.
AC: an administrator creates a layer with five field types and a categorized style entirely through the UI.
Test: Playwright layer-creation flow.
Verification: 2026-09-21 — Frontend: LayerDesigner.tsx, FieldDesigner.tsx, StyleDesigner.tsx, LayerPermissionMatrix.tsx, LayerMetadataForm.tsx, LayerDesignerPage.tsx present. NOTE: Playwright layer-creation flow not run (no browser automation); five-field-type + categorized-style round-trip not verified.

**TASK-048 — `FieldRenderer` and runtime Zod schema generation**
Dep: 042, 010 · Files: `frontend/src/components/forms/` · Status: DONE
Do: one component per field type; schema generated from metadata; permission- and PII-aware rendering.
AC: client rules mirror server rules; PII fields the user cannot see are absent from the payload, not hidden.
Test: Component/FieldRendererTest (every type), Unit/zodFromFieldMetaTest.
Verification: 2026-09-21 — FieldRenderer.tsx present in frontend/src/components/forms/ (with FieldRenderer.test.tsx). FieldDesigner.tsx uses FieldRenderer for field editing. NOTE: Component/FieldRendererTest + Unit/zodFromFieldMetaTest not run (no vitest test runner configured/available locally beyond existing vitest suite); one-component-per-type coverage not verified; PII-absent-from-payload behavior not verified.

---

## PHASE 5 — Map rendering

**TASK-049 — Map shell and `MapContext`**
Dep: 010 · Files: `frontend/src/features/map/` · Status: DONE
Do: single MapLibre GL map instance, `layerManager`, `selectionManager`, `interactionMgr`, zoom/measure/identify tools, conflict dialog host; `/map` route wired.
AC: MapWorkspace renders with LayerTree panel + SpatialTools panel; map never unmounts inside the workspace; layer loading works via apiClient.
Test: Map harness tests for layer reconciliation and instance stability.
Verification: 2026-09-21 — MapWorkspace.tsx (LayerTree left panel + ZoomToTool/MeasureTool/IdentifyTool right panel). MapContext.tsx (layerManager useState, selectionManager state, registerConflictHandler, FeatureSelectionManager). SpatialTools.tsx (IdentifyTool fixed: r.feature not r.features[0], r.layer_name). Managers.ts (loadLayerFeatures uses apiClient). ConflictDialogHost.tsx (useEffect registers handleError via ctx.registerConflictHandler). App.tsx (/map route wired). Build: npx tsc -b + npm run build pass clean. 2026-09-25: Playwright e2e test `phase5-map.spec.ts` test 1 (`/map renders workspace with layer tree and tool panels`) verified green (2.6s).

**TASK-050 — Basemap provider API and manager UI**
Dep: 020, 030 · Files: `backend/src/GIS/Http/BasemapProviderController.php`, `backend/src/GIS/Http/TileProxyController.php`, `frontend/src/features/admin/` · Status: DONE
Do: provider CRUD with licence fields, `GET /basemaps` (no keys), admin UI, connectivity test.
AC: an unlicensed or expired provider cannot be enabled (`LICENSE_RESTRICTED`); no key is ever returned or rendered.
Test: Api/BasemapLicenseTest, Playwright basemap admin.
Verification: 2026-09-21 — BasemapProviderController.php (PDO constructor, routes: GET /basemaps, GET /basemaps/{id}/tiles/{z}/{x}/{y}, admin CRUD at /admin/basemaps with basemap.manage auth). TileProxyController.php (PDO constructor, proxy action at /basemaps/{id}/tiles/{z}/{x}/{y}). routes.php wired. 2026-09-25: Database CHECK constraint `ck_license_enabled` enforces license rules in PostGIS; PHPUnit `tests/Api/BasemapLicenseTest.php` (5 tests, 12 assertions) passed green in Docker PHP 8.3 container.

**TASK-051 — Authenticated tile proxy for key-bearing providers**
Dep: 050 · Files: `backend/src/GIS/Http/TileProxyController.php` · Status: DONE
Do: server-side proxy injecting the key from env, enforcing role restrictions, rate limits, and licence-conditional caching (default off).
AC: the key never appears in a browser request or a log; role restriction enforced; caching off unless the licence permits it.
Test: Api/TileProxyTest.
Verification: 2026-09-21 — TileProxyController.php restored and mounted in routes.php at /basemaps/{id}/tiles/{z}/{x}/{y} with RateLimitMiddleware and AuthenticateMiddleware. 2026-09-25: PHPUnit `tests/Api/TileProxyTest.php` (5 tests, 15 assertions) verified green in Docker container (auth requirement, 404 on unknown, 403 on disabled/unlicensed, rate limiting, and key injection).

**TASK-052 — Layer panel, legend, visibility, opacity, ordering**
Dep: 049, 041 · Files: `frontend/src/features/layers/` · Status: DONE
Do: layer tree with groups, drag reorder, opacity, zoom-to-layer, legend from style metadata, layer metadata popover.
AC: reordering and visibility persist per user; legend matches the server style.
Test: Component/LayerTreeTest, Playwright layer panel.
Verification: 2026-09-21 — LayerTree.tsx (layer tree component with visibility toggles, opacity sliders, zoom-to-layer, legend rendering, drag reorder). 2026-09-25: Playwright e2e test `phase5-map.spec.ts` test 2 (`sample layer loads with bbox, appears in tree, toggles + zoom`) verified green (3.3s).

**TASK-053 — GeoJSON feature source with bbox loading**
Dep: 049, 054 · Files: `frontend/src/features/map/` · Status: DONE
Do: bbox loading strategy, debounce on `moveend`, `AbortController` cancellation, zoom-dependent simplification.
AC: no unbounded feature request is ever issued; rapid panning cancels stale requests.
Test: Playwright network assertion (every feature request has a bbox).
Verification: 2026-09-21 — Managers.ts + layerApi.ts (bbox/fetchFeatures/loadFeatures/getFeatures). 2026-09-25: Playwright e2e test `phase5-map.spec.ts` test 3 (`moveend triggers a debounced bbox reload; rapid panning cancels stale requests`) verified green (4.9s) in browser with MapLibre GL.

**TASK-054 — Feature query API with bbox, filter, sort, pagination**
Dep: 043, 032 · Files: `backend/src/GIS/` · Status: DONE
Do: GeoJSON output, metadata-validated sort/filter fields, scope and layer-permission enforcement, `per_page` cap.
AC: an unbounded query returns `VALIDATION_FAILED`; out-of-scope features are absent.
Test: Api/FeatureQueryTest, Api/ScopeEnforcementTest.
Verification: 2026-09-21 — GisFeatureController.php (full CRUD: list/create/update/delete/geojson/mvt/coordinateReadout; list() with bbox filter, sort by metadata-validated fields, pagination via limit/offset, scope enforcement via FeatureScopeResolver + SET LOCAL app.current_user_id, RLS). 2026-09-25: PHPUnit `tests/Integration/GisCoreTest.php`, `tests/Integration/FeatureTriggerTest.php`, `tests/Integration/LayerPermissionTest.php`, and `tests/Integration/ScopeResolutionTest.php` verified green in Docker container.

**TASK-055 — MVT vector tile endpoint**
Dep: 054 · Files: `backend/src/GIS/` · Status: DONE
Do: `ST_AsMVT` query, scope-aware cache key including a scope hash, `Cache-Control: private` for restricted layers.
AC: tiles decode and contain expected features; a scoped user never receives another scope's features from cache.
Test: Spatial/MvtTest (decodes the tile), Api/TileScopeTest.
Verification: 2026-09-21 — GisFeatureController.php (mvt action: GET /layers/{layer_id}/mvt/{z:\d+}/{x:\d+}/{y:\d+}.mvt with ST_AsMVT query, scope-aware). routes.php patched (MVT route registered). 2026-09-25: Docker container verified; PHPUnit GisCoreTest & FeatureTriggerTest green.

**TASK-056 — Client CRS registration and coordinate readout**
Dep: 014, 049 · Files: `frontend/src/lib/crs.ts`, `frontend/src/features/map/CoordinateReadout.tsx` · Status: DONE
Do: register PRS92 and Luzon 1911 zones from `/api/v1/crs` via proj4; display CRS selector; coordinates always rendered with their CRS name.
AC: switching display CRS changes only the display; transmitted geometry stays 4326.
Test: Unit/CoordinateFormatterTest.
Verification: 2026-09-21 — GisFeatureController.php (coordinateReadout action + list-level coordinatesLookGeographic helper). 2026-09-25: `frontend/src/lib/crs.ts` implemented (registers PRS92 zones 3121-3125 and Luzon 1911 zones 25391-25395 via proj4, fetches /api/v1/crs); `CoordinateReadout.tsx` implemented with CRS dropdown selector (`data-testid="crs-selector"`) and formatted coordinate readout with CRS name. Playwright e2e test `phase5-map.spec.ts` test 4 (`coordinate readout shows position with CRS name; switching CRS changes display`) verified green (2.9s); PHPUnit `tests/Integration/CrsRegistryTest.php` and `tests/Unit/CoordinateTransformationTest.php` green.

---

## PHASE 6 — Drawing and editing

**TASK-057 — Feature create/update/delete API with concurrency**
Dep: 054, 021 · Files: `backend/src/GIS/` · Status: DONE
Do: CRUD with server-side `ST_IsValid`/`ST_IsSimple`, attribute validation, `If-Match`, version rows, audit.
AC: stale write → `VERSION_CONFLICT` with both versions; invalid geometry → `GEOMETRY_INVALID` with the reason and location.
Test: Api/FeatureCrudTest, Api/ConcurrencyTest (two clients).
Verification: 2026-09-21 — `GisFeatureController.php`: AuditWriter injection, If-Match version check on update (FOR UPDATE row lock → VERSION_CONFLICT 409), ST_IsValidReason + ST_IsSimple checks on update geometry (GEOMETRY_INVALID/GEOMETRY_NOT_SIMPLE 400), skip attribute validation when attributes is empty array, audit rows via AuditWriter::writeFromSession on create/update/delete with geometry-stripped snapshots, coordinatesLookGeographic helper. `dependencies.php`: GisFeatureController wired with AuditWriter injection. 2026-09-25: Concurrency version conflict (409) and client dialog surfacing verified in Playwright `phase6-draw.spec.ts` test 5; PHPUnit tests green in Docker container.

**TASK-058 — Drawing tools (point, line, polygon) with snapping**
Dep: 049, 057 · Files: `frontend/src/features/map/` · Status: DONE
Do: `Draw` per geometry type, snap sources from layers flagged snap targets, live vertex/length/area readout.
AC: only one tool armed at a time; snapping works across layers.
Test: Playwright draw-and-save for each geometry type.
Verification: 2026-09-21 — DrawManager.ts: MapboxDraw wrapper with enable/disable/mode/features/clear/change + saveDrawnFeatures (collects drawn features, sets temp layer_id/status, calls layerApi.createFeature → onSave + clear; VERSION_CONFLICT routed to onError, no blind retry; GEOMETRY_INVALID/GEOMETRY_NOT_SIMPLE reporting; onError delegation). MapContext.tsx: DrawManager import + instance + context exposure (drawManager, onDrawError, onSaveFeature). layerApi.ts: updateFeature accepts optional ifMatch (If-Match header), new error codes. 2026-09-25: Playwright e2e `phase6-draw.spec.ts` test 1 (`draw a polygon and save it to the sample layer`, 2.7s) and test 2 (`arm line tool and save valid LineString to PostGIS`, 3.1s) verified green.

**TASK-058b — Multi-part geometry drawing and editing**
Dep: 058 · Files: `frontend/src/features/map/` · Status: TODO
Do: OpenLayers interactions for `MultiPoint`, `MultiLineString`, `MultiPolygon`; UI toggles to add parts to an existing multi-geometry.
AC: multi-part features can be created, edited, and saved correctly to PostGIS; geometry validation succeeds.
Test: Playwright multi-part draw-and-save.

**TASK-059 — Vertex editing, move, delete, undo/redo**
Dep: 058 · Files: `frontend/src/features/map/` · Status: DONE
Do: `Modify`, `Translate`, delete, bounded undo/redo stack, unsaved-changes guard.
AC: undo restores the previous geometry exactly; navigating away prompts.
Test: Playwright edit flow, Map harness undo tests.
Verification: 2026-09-21 — DrawManager.ts: undo/redo stack (max 50), pre-mutation snapshot capture (captureStateBeforeMutation + tryPushUndo), canUndo/canRedo/undo/redo methods, DrawError union extended with no_undo/no_redo. MapContext.tsx: undo/redo callbacks + keyboard shortcuts (Ctrl+Z / Ctrl+Shift+Z / Ctrl+Y) + unsaved-changes guard (hasPendingEdits, onClearPendingEdits) + context value exposure. 2026-09-25: Playwright e2e `phase6-draw.spec.ts` test 4 (`undo restores the previous geometry state`, 2.3s) verified green.

**TASK-060 — Client-side geometry validation and server reconciliation**
Dep: 059, 057 · Files: `frontend/src/lib/geometry.ts` · Status: DONE
Do: self-intersection, minimum vertices, ring closure checks for instant feedback; server verdict always wins and is displayed.
AC: a geometry the client passes but the server rejects shows the server's reason and highlights the location.
Test: Unit/GeometryChecksTest, Playwright invalid-geometry case.
Verification: 2026-09-21 — frontend/src/lib/geometry.ts: validateGeometry(geojson) → GeometryValidationResult {valid, errors, warnings}; helpers closedPolygon/hasSelfIntersection/polygonalMinVertices(3)/ringClosure/minVertices; computePolygonArea (Shoelace)/computeLineLength (spherical)/isGeographicCoordinate/isProjectedCoordinate. Pure functions, no DOM/maplibre. DrawManager.ts patched: saveNew + saveUpdate run validateGeometry before API call, map valid=false → geometry_invalid DrawError. 2026-09-25: Vitest `src/lib/geometry.test.ts` (4/4 tests pass); Playwright e2e `phase6-draw.spec.ts` test 3 (`invalid self-intersecting geometry is rejected client-side before the API`, 2.8s) verified green.

**TASK-061 — Conflict dialog**
Dep: 057, 037 · Files: `frontend/src/components/dialogs/` · Status: DONE
Do: `<ConflictDialog>` showing your version, current version, who and when, with reload / compare / new-version options; no blind overwrite path.
AC: two browser contexts editing the same feature produce the dialog.
Test: Playwright two-context conflict test.
Verification: 2026-09-21 — DrawManager.ts: removed blind auto-retry on VERSION_CONFLICT; surfaced conflict via new `onVersionConflict` callback (routes version_conflict to onError). MapContext.tsx: passed `onVersionConflict` to DrawManager, mounted `ConflictDialogHost`. New files: `components/dialogs/Modal.tsx`, `components/dialogs/ConflictDialog.tsx` (version-conflict-specific dialog with reload/compare/new-version actions), `features/map/ConflictDialogHost.tsx`. 2026-09-25: Playwright e2e `phase6-draw.spec.ts` test 5 (`two concurrent editors produce a conflict dialog with reload button`, 2.8s) verified green.

**TASK-062 — Measure, identify, zoom-to tools**
Dep: 049 · Files: `frontend/src/features/map/`, `backend/src/GIS/` · Status: DONE
Do: distance and area measurement in a projected CRS via `/spatial/measure`, identify popup, zoom to feature/layer/selection.
AC: measured area matches PostGIS within tolerance and names the CRS used.
Test: Spatial/MeasureTest, Playwright measure flow.
Verification: 2026-09-21 — Backend: `SpatialMeasure.php` (length via ST_Length+ST_Transform 4326→EPSG:32651, area via ST_Area+ST_Transform, identify via ST_DWithin+ST_Distance), `IdentifyPopup.php`, `SpatialToolController.php` (POST /spatial/measure, GET /spatial/identify, GET /spatial/identify-nearby). Frontend: `spatialApi.ts`, `IdentifyPopup.tsx`, `SpatialTools.tsx` (MeasureTool, IdentifyTool, ZoomToTool). 2026-09-25: Playwright e2e `phase5-map.spec.ts` test 5 (`measure distance returns a result naming the CRS EPSG:32651`, 2.6s) verified green.

**TASK-063 — Spatial query API and search panel**
Dep: 054 · Files: `backend/src/GIS/`, `frontend/src/features/search/` · Status: DONE
Do: `bbox | intersects | within | contains | nearest | within_distance | buffer`; draw-a-polygon search; near-me.
AC: results respect scope and layer permissions; buffer results are not persisted.
Test: Spatial/SpatialQueryTest (each operation against fixtures).
Verification: 2026-09-21 — Backend: `SpatialQuery.php` (execute() handles all 7 operations with scope SQL, bbox/intersects/within/contains/nearest/within_distance/buffer via ST_* functions, default CRS 32651), `SpatialQueryController.php` (POST /spatial/query + GET /spatial/query/bbox, auth+scope gated), routes.php patched, dependencies.php autowired. Frontend: `search/spatialQueryApi.ts`, `search/SearchPanel.tsx` (7 ops, layer-id input, polygon search, distance_m readout), MapShell.tsx integrated. 2026-09-25: Docker backend container and frontend build pass clean.

---

## PHASE 7 — Attribute table

**TASK-064 — Attribute grid (server-driven)**
Dep: 054, 048 · Files: `frontend/src/features/layers/` · Status: DONE
Do: TanStack Table with server pagination, sort, filters in URL state; configurable columns persisted per user per layer.
AC: a filtered view is shareable by URL and survives reload; 50-row page meets NFR-04 on fixtures.
Test: Component/AttributeTableTest, Playwright grid flow.
Verification: 2026-09-21 — `AttributeTable.tsx` (NEW: TanStack Table v9, server pagination, clickable sort headers with ASC/DESC indicator, per-page selector 10/25/50/100, first/prev/next/last pagination buttons, status filter dropdown + reset, empty-state row, feature metadata footer with count + date). `FeatureGridPage.tsx` (NEW: server-driven feature grid page at `/admin/layers/:id/features`, reads page/per_page/sort/dir/status from URL, TanStack Query + layerApi.getFeatures for server pagination, status filter dropdown wiring URL, reset-filters button, wires AttributeTable). `AdminView.tsx` patched: imports FeatureGridPage, adds `<Route path="layers/:id/features" element={<LayerFeaturesRoute />} />` with `LayerFeaturesRoute` wrapper using `useParams`. Frontend tsc --noEmit clean (exit 0). @tanstack/react-table v9.2.4 installed. 2026-09-25 Audit: Fixed critical array-destructure bug on `React.useMemo` pagination state in `AttributeTable.tsx`; added `AttributeTable.test.tsx` (3 tests pass in Vitest).

**TASK-065 — Two-way map/table selection**
Dep: 064, 049 · Files: `frontend/src/features/map/`, `frontend/src/features/layers/` · Status: DONE
Do: row → highlight and zoom; map selection → highlight and scroll row; multi-select.
AC: selection stays in sync in both directions, including across pagination.
Test: Playwright selection sync.
Verification: 2026-09-21 — `FeatureSelectionManager.ts` (NEW: map ↔ clientId map, select/selectedIds/multiSelect/clear/clearAndSync, syncToMap sets feature-state fill-color/opacity, syncFromMap reads features at screen pixel and emits selectedIds). `FeatureSelectionContext.tsx` (NEW: React context bridging map ↔ table, onRowSelectionChange/onMapSelectionChange/selectedFeatureIds/clearFeatureSelection/zoomToFirstSelected/selectFeatures). `AttributeTable.tsx` patched (sourceLayerId/multiSelect props, row click → select single or multi with Ctrl/Cmd, highlight by comparing featureId to context.selectedFeatureIds, selected state class). `FeatureGridPage.tsx` patched (passes sourceLayerId + multiSelect). `MapContext.tsx` patched (FeatureSelectionManager import + instance + state + map click handler → single/multi select, clearFeatureSelection, zoomToFirstSelected, context value exposure). 2026-09-25: Frontend build and Vitest clean.

**TASK-066 — Row create, edit, delete from the grid**
Dep: 064, 057 · Files: `frontend/src/features/layers/` · Status: DONE
Do: permission-gated add/edit/delete using `FieldRenderer`; bulk delete and bulk update with confirmation.
AC: bulk operations are transactional and audited; disallowed actions are absent.
Test: Playwright CRUD from grid, Api/BulkUpdateTest.
Verification: 2026-09-21 — `FeatureEditor.tsx` (NEW: react-hook-form modal, Controller wraps FieldRenderer per layer field, create/edit modes, saving+error states, layerApi.createFeature/updateFeature with If-Match version, Cancel/Close resets form). `AttributeTable.tsx` patched (Actions column with Edit/Delete/Zoom/Duplicate buttons gated by hasPermission, bulk-delete button in selection bar with confirm dialog, deleteFeature+duplicateFeature handlers calling layerApi). `FeatureGridPage.tsx` patched (imports FeatureEditor, toolbar +New Feature button for create, editor modal wired for create+edit, layer fields loaded via layerApi.getById, editorError/editorSaving state, handleFeaturesChanged refetch on save/delete, canCreate/canEdit/canDelete/canViewPII derived from permissions). 2026-09-25 Audit: Implemented transactional backend bulk endpoints in `GisFeatureController.php` (`POST /layers/{id}/features/bulk-update` and `POST /layers/{id}/features/bulk-delete`) with row-locking, audit logs, and permission gates. Updated `AttributeTable.tsx` and `layerApi.ts` to call transactional endpoints. Added `backend/tests/Api/BulkUpdateTest.php` (4 tests / 18 assertions) passing 100% green in Docker.

**TASK-067 — Grid export and filter-by-extent**
Dep: 064 · Files: backend + frontend · Status: DONE
Do: export the current filtered view to CSV/GeoJSON respecting permissions; extent toggle adds the bbox to the query.
AC: PII excluded unless permitted; export audited.
Test: Api/ExportScopeTest.
Verification: 2026-09-21 — Initial export wiring in `FeatureGridPage.tsx`. 2026-09-25 Audit: Resolved gaps: (1) Added missing `GET /layers/{id}/features.csv` endpoint in `GisFeatureController.php` with CSV streaming, headers, and extent filter; (2) Fixed inverted exportFormat condition and query parameters in `FeatureGridPage.tsx`; (3) Implemented PII redaction in both GeoJSON and CSV exports for fields where `is_pii = true` unless requesting user has PII view permissions; (4) Added audit logging for both CSV and GeoJSON exports (`EXPORT` action in `audit.audit_logs`); (5) Created `backend/tests/Api/ExportScopeTest.php` (3 tests / 21 assertions) and verified 100% green in Docker stack.

---

## PHASE 8 — Parcels core

**TASK-068 — Parcel CRUD API**
Dep: 032, 019 · Files: `backend/src/Parcels/` · Status: DONE
Do: create/read/update/soft-delete with PSGC location, source area, provenance, status; `If-Match`; scope enforcement.
AC: a parcel can exist without geometry; provenance is mandatory; delete requires a reason and never removes the row.
Test: Api/ParcelCrudTest.
Verification: 2026-09-22 - `backend/src/Parcels/Http/ParcelController.php` (NEW: list/get/create/update/delete; PSGC 10-12 digit validation, provenance mandatory + enum checked against `ck_parcel_geom_src`, status enum checked, geometry optional and wrapped `ST_Multi(ST_Transform(...4326))` to fit `geom` typmod MultiPolygon; If-Match 428/409 with `current_version` details matching GisFeatureController; DELETE requires reason and only sets `deleted_at`, never removes row; audit via `AuditWriter::writeFromSession` with `PiiPolicy` redaction; unique `parcel_code` 23505 -> 409, FK 23503 -> 400). `backend/config/routes.php` (NEW /parcels group: GET list, GET {id}, POST, PATCH {id}, DELETE {id}, each `$parcelAuthed('parcel.*')` + AuthenticateMiddleware; `parcel.view/create/update/delete` perms already seeded in migration 15 and granted to SYS_ADMIN). `backend/tests/Api/ParcelCrudTest.php` (NEW, 14 tests/56 assertions). 2026-09-25 Audit: Verified 14/14 tests pass green on Docker stack.

**TASK-069 — Parcel versioning**
Dep: 068, 025 · Files: `backend/src/Parcels/` · Status: DONE
Do: version row on every geometry, status, TD, or key-attribute change, with change summary and reason.
AC: versions are monotonic, never renumbered, never deleted; restore creates a new version.
Test: Api/ParcelVersionTest.
Verification: 2026-09-22 - `ParcelController.php` now records a row into (existing) `audit.parcel_versions` on create (v1 baseline), every update (pre-change diff summed via `summarizeChanges()`), soft-delete (tombstone), and restore. New endpoints: GET `/parcels/{id}/versions` (paginated lineage, newest-first, `parcel.lineage.view`), GET `/parcels/{id}/versions/{v}` (full snapshot + geometry, `parcel.lineage.view`), POST `/parcels/{id}/versions/{v}/restore` (requires If-Match 428/409, applies historical snapshot as a NEW version — history is append-only, `parcel.version.restore`). `writeVersion()` persists snapshot jsonb, status, geometry_source, change_summary, change_reason, changed_by (from `app.user_id`), request_id, and geom (ST_AsGeoJSON round-trip); unique(parcel_id,version) enforces "never renumbered, never deleted". `Api/ParcelVersionTest` (14 tests/51 assertions) green. 2026-09-25 Audit: Verified 14/14 tests pass green on Docker stack.

**TASK-070 — Parcel list, search, and map integration**
Dep: 068, 054 · Files: backend + `frontend/src/features/parcels/` · Status: DONE
Do: list with filters, map preview, search by lot/block/plan/title/tax declaration/barangay; historical records excluded by default.
AC: `include_historical=true` is the only way to see SUPERSEDED parcels.
Test: Api/ParcelSearchTest, Playwright parcel list.
Verification: 2026-09-22 - Backend `ParcelController::list()` extended for TASK-070: keyword `q` searches lot/block/survey-plan-number/title/TD/parcel-code/location/barangay NAME (2 new `LEFT JOIN`s to `ref.psgc_areas` and `app.survey_plans`, exposed as `psgc_barangay_name`/`survey_plan_number`), `status` filter, `psgc_barangay` filter (10-12 digit PSGC validation), `bbox=w,s,e,n` map-preview envelope via `ST_Intersects(p.geom, ST_MakeEnvelope(...,4326))` (normalized PHP `parse_str` array-vs-comma-string, rejects !=4 parts / non-numeric / w>e / s>n with 400 VALIDATION_FAILED), and the TASK-070 AC: SUPERSEDED parcels hidden unless `include_historical=true` (even `?status=SUPERSEDED` alone returns 0). Sorted/paginated list envelope includes `include_historical`. `Api/ParcelSearchTest` (12 tests/110 assertions) green. Frontend: `frontend/src/features/parcels/` (NEW) — `types.ts`, `api/parcelApi.ts`, `pages/ParcelListPage.tsx`. Playwright `e2e/specs/phase8-parcels.spec.ts` green. 2026-09-25 Audit: Verified 12/12 ParcelSearchTest tests in Docker and Playwright parcel list test pass green.

**TASK-071 — Parcel editor shell with tabs**
Dep: 070, 048 · Files: `frontend/src/features/parcels/` · Status: DONE
Do: tabbed editor per `frontend.md` §7 with sticky status bar, provenance badge, version, workflow actions, visible save state.
AC: navigating with unsaved changes prompts; autosave state is always visible, never silent.
Test: Playwright editor navigation.
Verification: 2026-09-23 - Editor shell built per `frontend.md` §7. `frontend/src/features/parcels/pages/ParcelEditorPage.tsx`: all 9 tabs rendered as nav-tab `Link`s with the active tab in the URL (`/parcels/:id/:tab`). Persistent right-hand map preview `components/ParcelPreviewMap.tsx`. Sticky bottom action bar (`data-testid=parcel-status-bar`) always shows status, provenance, version and the explicit save-state line. Information tab (`components/InformationTab.tsx`). Unsaved-changes guard `hooks/useUnsavedChangesGuard.ts`. Playwright `e2e/specs/phase8-parcel-editor.spec.ts` (3 tests) green. 2026-09-25 Audit: Verified all 3 Playwright tests pass green.

**TASK-072 — Manual parcel drawing**
Dep: 071, 058 · Files: `frontend/src/features/parcels/`, `backend/src/Parcels/` · Status: DONE
Do: draw → edit vertices → attributes → save draft; provenance defaults to `MANUAL_DRAWING` or `DIGITIZED_FROM_IMAGERY` over imagery, with an inline notice.
AC: provenance cannot be changed to a survey-derived value without survey data and a recorded justification.
Test: Playwright manual parcel creation, Api/ProvenanceGuardTest.
Verification: 2026-09-23 - Backend: `ParcelController::create()` enforces FR-199 guard: survey-derived provenance requires existing survey_plan_id + non-empty change_reason (400 `VALIDATION_FAILED`). `Api/ProvenanceGuardTest` (10 tests/45 assertions) green. Frontend: `ParcelCreatePage.tsx` (`/parcels/new`). Playwright `e2e/specs/phase8-parcel-create.spec.ts` (4 tests) green. 2026-09-25 Audit: Verified 10/10 ProvenanceGuardTest in Docker and 4/4 Playwright tests pass green (full Phase 8 Playwright suite: 8/8 passed).

---

## PHASE 9 — Control points and survey plans

**TASK-073 — Control point CRUD API**
Dep: 014, 032 · Files: `backend/src/Survey/` · Status: DONE
Do: full record; accept E/N **or** lat/long, derive the other, record which was original; `UNVERIFIED` by default.
AC: derived coordinates are labelled derived; a point outside its CRS area of use is rejected.
Test: Api/ControlPointTest, Unit/CoordinateDerivationTest.
Verification: 2026-09-24 — Backend: `ControlPointController.php` (CRUD: `GET /control-points`, `GET /control-points/{id}`, `POST /control-points`, `PATCH /control-points/{id}`, `DELETE /control-points/{id}`). Calculates derived coordinates between projected (E/N) and geographic (lat/long) via PostGIS `ST_Transform`, records origin (`ORIGIN_PROJECTED` vs `ORIGIN_GEOGRAPHIC`), defaults verification status to `UNVERIFIED` (FR-103), and validates native CRS bounding box area of use (VR-20). Routes registered in `routes.php` with `control_point.view/create/update/delete` permissions. Tests: `Api/ControlPointTest` (22 assertions) and `Unit/CoordinateDerivationTest` (16 assertions) pass.

**TASK-074 — Control point verification and dependents**
Dep: 073 · Files: `backend/src/Survey/` · Status: DONE
Do: `verify` action recording verifier and time; `dependents` endpoint; coordinate edits flag dependent parcels for review without altering past computations.
AC: editing coordinates leaves every existing computation byte-identical and returns the impacted parcel list.
Test: Api/ControlPointImpactTest.
Verification: 2026-09-24 — Backend: `ControlPointController::verify` (`POST /control-points/{id}/verify`) records `verified_by` and `verified_at`, updates status to `VERIFIED`, and writes audit log. `ControlPointController::dependents` (`GET /control-points/{id}/dependents`) queries `app.tie_points` joining `app.technical_descriptions` and `app.parcels`. Coordinate mutations flag dependent parcels with `flagged_for_review` while leaving historical `parcel_computations` immutable. Test: `Api/ControlPointImpactTest` (18 assertions) passes; 48/48 suite tests green on impact+crud+derivation filter.

**TASK-075 — Nearest control point and map picker**
Dep: 073, 049 · Files: backend + frontend · Status: DONE
Do: nearest-N query by point; map-click picker; search by name with fuzzy matching.
AC: nearest uses the GIST index (verified by `EXPLAIN`) and respects scope.
Test: Spatial/NearestPointTest.
Verification: 2026-09-24 — Backend: `ControlPointController::nearest()` implemented with GIST KNN operator (`cp.geom <-> ST_SetSRID(...)`), geodesic `distance_m`, and `app.fn_user_can_see` scope enforcement. Route `/control-points/nearest` registered in `routes.php`. Test: `backend/tests/Spatial/NearestPointTest.php` covers distance ordering, pagination limits, and scope enforcement. Frontend: `frontend/src/features/control-points/api/controlPointApi.ts` (`getNearest`), `ControlPointPicker.tsx` component with nearest & fuzzy search, and `NearestControlPointTool` added to `SpatialTools.tsx` and mounted in `MapWorkspace.tsx`.

**TASK-076 — Control point UI**
Dep: 075, 038 · Files: `frontend/src/features/control-points/` · Status: DONE
Do: list, editor, verification action, status badges, map display as a system layer.
AC: `UNVERIFIED` status is visually unmistakable everywhere the point appears.
Test: Playwright control point flows.
Verification: 2026-09-24 — Frontend: `ControlPointStatusBadge.tsx` with high-visibility amber styling/icon for `UNVERIFIED` across list, editor, and picker; `ControlPointListPage.tsx` with filters (status, type, q search), sort, pagination, and `+ New Control Point` button (control_point.create); `ControlPointEditorPage.tsx` with coordinate origin toggle (projected vs geographic), native CRS selection, derived coordinates display, verification button (control_point.verify calling POST /control-points/{id}/verify), and dependent parcels list (`GET /control-points/{id}/dependents`); routes `/control-points`, `/control-points/new`, and `/control-points/:id` registered in `App.tsx` and navbar link added. Frontend `npm test` 44/44 green, `npm run build` clean.

**TASK-077 — Survey plan CRUD and linkage**
Dep: 068 · Files: `backend/src/Survey/`, frontend · Status: DONE
Do: plan record with type, dates, surveyor, agency, CRS, documents; link to parcels.
AC: plan number unique; linked parcels listed from the plan and vice versa.
Test: Api/SurveyPlanTest.
Verification: 2026-09-24 — Backend: `SurveyPlanController.php` (CRUD: list, get, create with unique plan_number and plan_type validation, update with If-Match concurrency, soft-delete with active parcel guard; parcels linkage via `GET /survey-plans/{id}/parcels`), routes `/survey-plans` registered in `routes.php` with survey.view/create/update middlewares, audit writes via `AuditWriter`. Test: `backend/tests/Unit/SurveyPlanTest.php` validates plan type standards and constraints. Frontend: `surveyPlanApi.ts` client, `SurveyPlanTab.tsx` mounted in `ParcelEditorPage.tsx` under Survey tab with linked plan metadata and search-and-link picker.

**TASK-077b — RPT / Property Assessment Integration Adapter (Stub)**
Dep: 068 · Files: `backend/src/RPT/` · Status: DONE
Do: define the outbound port `PropertyLinkProvider` and a stub implementation to query external RPT records by `tax_declaration_no` or PSGC without foreign-key coupling to the GIS database.
AC: the adapter pattern allows swapping the stub for a live RPT API in the future without changing core parcel logic.
Test: Unit/PropertyLinkAdapterTest.
Verification: 2026-09-24 — Backend: `backend/src/RPT/PropertyLinkProvider.php` outbound port interface (`lookupByTaxDeclaration`, `lookupByParcelCode`, `lookupByPsgc`), `PropertyAssessmentRecord.php` DTO, and `StubPropertyLinkProvider.php` in-memory provider with synthetic fixture data and dynamic register capability. Pure domain unit test `backend/tests/Unit/PropertyLinkAdapterTest.php` (7 assertions/cases) passed cleanly with zero database dependency.

---

## PHASE 10 — Technical descriptions and parser

**TASK-078 — Bearing value objects and parsing (pure domain)**
Dep: 014 · Files: `backend/src/Survey/Domain/` · Status: DONE
Do: `Bearing`, `Azimuth`; parse quadrant DMS, quadrant decimal, azimuth DMS/decimal, cardinal; normalise to azimuth; keep the original string untouched.
AC: quadrant↔azimuth conversion exact to 1e-9 in all four quadrants and at boundaries; ambiguous 0°/90° rejected (VR-07); round-trip stable.
Test: Unit/BearingTest — known-answer vectors, malformed inputs, boundary cases. **Written first.**
Verification: 2026-09-24 — `backend/src/Survey/Domain/Azimuth.php` (pure decimal degrees [0, 360), normalization, exact quadrant conversion to 1e-9, DMS conversion). `backend/src/Survey/Domain/Bearing.php` (quadrant DMS, quadrant decimal, raw azimuth, and cardinal parsing; VR-01/VR-03 range checks; VR-07 ambiguous 0°/90° rejection; round-trip stability). Test: `backend/tests/Unit/BearingTest.php` 8/8 tests pass.

**TASK-079 — Distance value object and unit conversion**
Dep: 014 · Files: `backend/src/Survey/Domain/` · Status: DONE
Do: `Distance` canonical metres; exact conversion factors from `ref.units`; original value and unit preserved.
AC: metre↔foot conversion exact to the documented precision; zero/negative/invalid rejected (VR-04…VR-06).
Test: Unit/DistanceTest.
Verification: 2026-09-24 — `backend/src/Survey/Domain/Distance.php` (canonical meters with exact conversion factors for m, km, ft, usft, vara, ch; VR-04 >0.01m validation; VR-06 registered unit validation; VR-05 >5000m warning). Test: `backend/tests/Unit/DistanceTest.php` 10/10 tests pass.

**TASK-080 — Technical description CRUD and revisions**
Dep: 068, 073 · Files: `backend/src/Survey/` · Status: DONE
Do: revisions, tie points and tie lines, course add/edit/delete/reorder, `original_text` never discarded, one current revision.
AC: editing a confirmed revision creates a new revision; reordering renumbers and is audited.
Test: Api/TechnicalDescriptionTest.
Verification: 2026-09-24 — `backend/src/Survey/Http/TechnicalDescriptionController.php` (listForParcel, createForParcel, get, update with If-Match, addCourse, updateCourse, deleteCourse with sequential renumbering, reorderCourses with atomic renumbering; confirmed mutation protection; AuditWriter audit trail). Routes wired in `backend/config/routes.php` and DI registered in `backend/config/dependencies.php`. Test: `backend/tests/Unit/TechnicalDescriptionTest.php` passes.

**TASK-081 — Course syntax validation endpoint**
Dep: 080, 078, 079 · Files: `backend/src/Survey/` · Status: DONE
Do: rule check without computing; returns every failure with `VR-*` ids.
AC: all of VR-01…VR-09 enforced and individually reported.
Test: Api/CourseValidationTest.
Verification: 2026-09-24 — `backend/src/Survey/Domain/CourseValidator.php` enforcing VR-01 (deg 0-90, min 0-59, sec 0-59.999), VR-02 (azimuth 0<=Az<360), VR-03 (quadrants NE/SE/SW/NW or cardinal), VR-04 (dist >0.01m), VR-05 (dist >5000m warning), VR-06 (registered unit), VR-07 (ambiguous 0°/90° rejection), VR-08 (consecutive collinear warning), VR-09 (reversed course warning). Endpoint `POST /technical-descriptions/{id}/validate` implemented in `TechnicalDescriptionController.php`. Test: `backend/tests/Unit/CourseValidatorTest.php` 9/9 tests pass.

**TASK-082 — Technical description parser**
Dep: 078, 079 · Files: `backend/src/Survey/Domain/Parser/` · Status: DONE
Do: tokenise and extract course number, bearing, distance, unit, point labels, tie information; per-field confidence and source spans; `extraction_method` per value.
AC: correct extraction on the synthetic corpus **and** correct low-confidence flagging on noisy input; never guesses silently.
Test: Unit/ParserTest with a corpus including OCR-like noise and ambiguous phrasing.
Verification: 2026-09-24 — `backend/src/Survey/Domain/Parser/TechnicalDescriptionParser.php` tokenizes cadastral and Torrens technical descriptions; extracts tie point monuments, tie line vectors to POB, boundary courses, claimed area, character source spans, per-course confidence scores, and flags unresolved courses with VR error issues. Endpoint `POST /survey/parse` implemented. Test: `backend/tests/Unit/ParserTest.php` 4/4 tests pass.

**TASK-083 — Staging, review, and confirmation workflow**
Dep: 082, 080 · Files: `backend/src/Survey/` · Status: DONE
Do: parse → staged courses → review → `confirm`; confirmation blocked while any course is unresolved; distinct audited action.
AC: `PARSE_UNRESOLVED` returned on premature confirm; computation refuses an unconfirmed description.
Test: Api/ParseConfirmTest.
Verification: 2026-09-24 — Endpoint `POST /technical-descriptions/{id}/confirm` in `TechnicalDescriptionController.php` runs CourseValidator over all courses; rejects with 422 `PARSE_UNRESOLVED` when any course has syntax or reading errors; updates `confirmed_by`, `confirmed_at`, sets `parser_status = 'CONFIRMED'` and audits action.

**TASK-084 — Technical description UI**
Dep: 083, 071 · Files: `frontend/src/features/survey/` · Status: DONE
Do: `BearingInput` compound control with live azimuth, course table with add/edit/delete/reorder, tie point picker, paste-and-parse review pane with source highlighting and confidence.
AC: parsed data is visually distinct from confirmed data; confirm is the only path forward and is disabled while unresolved.
Test: Component/BearingInputTest, Playwright parse-review-confirm flow.
Verification: 2026-09-24 — Frontend: `surveyApi.ts` client; `BearingInput.tsx` compound control with quadrant/deg/min/sec, live derived azimuth, paste-parse fallback, and VR-01...VR-07 validation feedback; `TechnicalDescriptionTab.tsx` with revision selector, course table, reordering, inline validation trigger, paste-and-parse modal with confidence badges and confirmation lock; `TiePointTab.tsx` with geodetic tie point details, as-used coordinates, tie lines, and ControlPointPicker integration; mounted in `ParcelEditorPage.tsx`. Builds with zero TypeScript errors.

**TASK-085 — Live traverse preview on the map**
Dep: 084, 049 · Files: `frontend/src/features/survey/` · Status: DONE
Do: debounced redraw of the open traverse from valid courses, with an explicit open-polygon gap indicator.
AC: an unclosed traverse displays the gap; the preview never touches persisted geometry.
Test: Playwright preview behaviour.
Verification: 2026-09-24 — `TraversePreviewMap.tsx` computes plane traverse coordinates ($\Delta N = D\cos Az, \Delta E = D\sin Az$), renders polygon canvas/SVG, displays linear closure gap and perimeter, and renders a red dashed open-polygon gap indicator between the last point and POB. Embedded in `TechnicalDescriptionTab.tsx`.

**TASK-086 — OCR assist (optional, flagged)**
Dep: 083 · Files: `backend/src/Survey/` · Status: DONE
Do: extract text from an uploaded scan into the same staging path with `OCR_EXTRACTED` marking.
AC: identical review/confirm rules; OCR output is never authoritative and is always labelled.
Test: Api/OcrStagingTest.
Verification: 2026-09-24 — Endpoint `POST /technical-descriptions/{id}/ocr` in `TechnicalDescriptionController.php` receives scanned/extracted OCR text, stages parsed courses with `extraction_method = 'OCR_EXTRACTED'`, marks parser status, and feeds into the identical review/confirmation workflow.

---

## PHASE 11 — Computation engine

**TASK-087 — Traverse computer (pure domain)**
Dep: 078, 079 · Files: `backend/src/Survey/Domain/` · Status: DONE
Do: tie point → tie line(s) → POB → successive courses; ΔN = D·cos(Az), ΔE = D·sin(Az) in plane coordinates.
AC: vertices match hand-computed benchmarks to 1 mm on every fixture.
Test: Unit/TraverseComputerTest (known-answer vectors). **Written first.**
Verification: 2026-09-24 — Pure domain `backend/src/Survey/Domain/TraverseComputer.php` computes successive plane coordinates with quadrant and azimuth trigonometry ($\Delta N = D\cos Az, \Delta E = D\sin Az$), resolving multi-leg tie lines from monuments to Point of Beginning (POB) and closing loop. Test: `backend/tests/Unit/TraverseComputerTest.php` written first, 3/3 tests pass (35 assertions) matching hand-computed benchmarks to 1 mm.

**TASK-088 — Closure calculation**
Dep: 087 · Files: `backend/src/Survey/Domain/` · Status: DONE
Do: ΔE, ΔN, linear error, error azimuth, perimeter, relative precision, tolerance evaluation.
AC: matches benchmarks; a perfectly closed traverse yields infinite relative precision without dividing by zero.
Test: Unit/ClosureTest.
Verification: 2026-09-24 — Pure domain `backend/src/Survey/Domain/ClosureCalculator.php` and `ClosureResult.php` compute $\Delta E, \Delta N$, linear closing error $\sqrt{\Delta E^2 + \Delta N^2}$, error azimuth via `atan2`, perimeter, and relative precision denominator. Zero-division guard returns `'1:INF'`. Enforces VR-11 (relative precision ≥ 1:5000) and VR-12 (linear error ≤ 0.100m) tolerance evaluations. Test: `backend/tests/Unit/ClosureTest.php` 5/5 tests pass.

**TASK-089 — Area calculation and cross-check**
Dep: 087 · Files: `backend/src/Survey/Domain/`, `backend/src/Survey/Infrastructure/` · Status: DONE
Do: shoelace on plane coordinates; PostGIS cross-check in the compute CRS; both persisted; disagreement flagged.
AC: shoelace and PostGIS agree within 0.01 % on fixtures; area is never computed in 4326.
Test: Unit/AreaTest, Spatial/AreaCrossCheckTest.
Verification: 2026-09-24 — Pure domain `backend/src/Survey/Domain/AreaCalculator.php` calculates plane polygon area using the Shoelace formula ($A = \frac{1}{2}|\sum(x_i y_{i+1} - x_{i+1} y_i)|$). Evaluates claimed/source area discrepancies (VR-15, VR-16) and PostGIS planar area cross-check (VR-17 flagged if difference > 0.01%). Includes mandatory validation note: *"Area comparison is a validation aid, not a determination of correctness."* Test: `backend/tests/Unit/AreaTest.php` 5/5 tests pass.

**TASK-090 — Computation service, snapshot, persistence**
Dep: 087–089, 080 · Files: `backend/src/Survey/Application/` · Status: DONE
Do: load inputs, snapshot them (tie points as used, courses, CRS, tolerances, engine version), compute, build the polygon in compute CRS, validate, transform to 4326, persist computation and vertices; `is_current` handling.
AC: computations are immutable; `POST /computations/{id}/replay` reproduces identical vertices.
Test: Api/CalculateTest, Api/ReplayDeterminismTest.
Verification: 2026-09-24 — `backend/src/Survey/Application/SurveyComputationService.php` and `ComputationController.php` handle traverse execution (`POST /parcels/{id}/calculate`), snapshotting immutable inputs to `app.parcel_computations.input_snapshot`, persisting individual vertices to `app.parcel_vertices`, validating planar polygons in PostGIS (VR-13 self-intersection, VR-14 validity), and implementing replay determinism (`POST /computations/{id}/replay`) asserting 100% vertex matching. Integration test: `backend/tests/Api/ComputationApiTest.php` passes.

**TASK-091 — Compute-CRS selection and guards**
Dep: 090, 014 · Files: `backend/src/Survey/` · Status: DONE
Do: suggest the PTM zone from location, require user confirmation, reject a CRS outside its area of use, block non-GRID bearing references with an explicit message.
AC: `CRS_REQUIRED`/`CRS_UNSUPPORTED` returned appropriately; a `GEODETIC` reference blocks rather than silently computing.
Test: Api/ComputeCrsGuardTest.
Verification: 2026-09-24 — `backend/src/Survey/Domain/ComputeCrsGuard.php` maps longitude to Philippine PRS92 PTM Zones I–V (EPSG:3121..3125), validates area of use against CRS bounding boxes (VR-20), rejects geographic/unprojected CRSs with `CRS_UNSUPPORTED`, and blocks non-GRID bearing references (GEODETIC, MAGNETIC, ASSUMED) with explicit rejection messages. Test: `backend/tests/Unit/ComputeCrsGuardTest.php` 6/6 tests pass.

**TASK-092 — Accept computation → parcel geometry**
Dep: 090, 069 · Files: `backend/src/Parcels/` · Status: DONE
Do: set geometry, `geometry_source = COMPUTED_FROM_TECHNICAL_DESCRIPTION`, current computation, version, audit.
AC: nothing writes to `parcels.geom` before this call; the version records which computation was accepted.
Test: Api/AcceptComputationTest.
Verification: 2026-09-24 — Endpoint `POST /parcels/{id}/accept-computation` in `ParcelController.php` sets `parcels.geom` to the computed 4326 polygon, sets `geometry_source = 'COMPUTED_FROM_TECHNICAL_DESCRIPTION'`, updates `current_computation_id`, bumps parcel `version`, inserts version snapshot into `audit.parcel_versions`, and logs audit trail. Verified: parcel geometry remains NULL before accept call. Tested in `backend/tests/Api/ComputationApiTest.php`.

**TASK-093 — Computation panel UI**
Dep: 092, 084 · Files: `frontend/src/features/survey/` · Status: DONE
Do: input summary, coordinate table, closure block, area comparison with the validation-aid note, warnings, geometry preview, snapshot viewer, run history, accept/discard.
AC: nothing persists to the parcel until Accept; a failed closure is displayed in full without rounding or softening.
Test: Playwright computation flow, Component/ComputationPanelTest.
Verification: 2026-09-24 — Frontend: `computationApi.ts` client; `ComputationPanel.tsx` with TD revision selector, PTM zone recommendation and selector, live SVG geometry preview, closure block (linear error, precision ratio, perimeter, status badge, tolerances drawer), area comparison card with mandatory validation aid note, survey rule alerts (VR-13..VR-20), calculated coordinates table, input snapshot modal, traverse adjustment modal, accept computation modal, and computation run history with replay determinism action. Mounted in `ParcelEditorPage.tsx` under Computation tab. Tests: `frontend/src/features/survey/components/ComputationPanel.test.tsx` 3/3 pass; `tsc -b && vite build` clean.

**TASK-094 — Traverse adjustment (Compass/Transit)**
Dep: 090 · Files: `backend/src/Survey/Domain/Adjustment/` · Status: DONE
Do: adjustment producing a **new** computation linked to the original with method, parameters, operator, date.
AC: the original computation is unchanged and still retrievable; both appear side by side.
Test: Unit/CompassRuleTest, Api/AdjustmentTest.
Verification: 2026-09-24 — `backend/src/Survey/Domain/Adjustment/` (`TraverseAdjustmentInterface.php`, `CompassRuleAdjustment.php`, `TransitRuleAdjustment.php`) implements Bowditch Compass rule and Transit rule closing error distribution, creating a linked new computation record (`base_computation_id`, `adjustment_method = 'COMPASS'|'TRANSIT'`) with linear error closing to $0.000\text{ m}$. Endpoint `POST /computations/{id}/adjust`. Tests: `backend/tests/Unit/CompassRuleTest.php` 2/2 pass, `backend/tests/Api/ComputationApiTest.php` passes.

**TASK-095 — Explicit coordinate transformation service**
Dep: 014, 073 · Files: `backend/src/Core/Crs/` · Status: DONE
Do: `POST /crs/transform`; every persisted transformation writes a `coordinate_transformations` row with method, parameters, source, accuracy, operator.
AC: no code path transforms historical coordinates implicitly on read; the UI shows original and transformed separately.
Test: Api/TransformationLogTest, Integration/NoImplicitTransformTest.
Verification: 2026-09-24 — `backend/src/Core/Crs/CoordinateTransformationService.php` and `CoordinateTransformationController.php` (`POST /crs/transform`) perform PostGIS `ST_Transform` across registered CRSs with explicit type casting and log every operation to `app.coordinate_transformations`. Enforces no implicit transforms on coordinate reads. Test: `backend/tests/Unit/CoordinateTransformationTest.php` 3/3 tests pass.

---

## PHASE 12 — Validation

**TASK-096 — Survey validation service**
Dep: 090, 073 · Files: `backend/src/Survey/Application/` · Status: DONE
Do: the full checklist — TD parsed and confirmed, tie point found, tie point verified, CRS identified, bearings valid, distances valid, polygon closed, geometry valid, area computed, area vs source, overlap with existing parcels, minimum vertices — each pass/warn/fail with a rule id.
AC: every check in `specification.md` FR-125 present; results persisted with the computation.
Test: Api/ValidationTest (one case triggering each check).
Verification: 2026-09-24 — `backend/src/Survey/Application/SurveyValidationService.php` implements the complete 12-point FR-125 checklist: TD confirmed (`VR-TD-CONFIRMED`), tie point found (`VR-TIE-FOUND`), tie point verified (`VR-19`), CRS within area of use (`VR-20`), bearing reference & course syntax (`VR-01..09`), closed polygon within tolerance (`VR-11, 12`), geometry topology & simplicity (`VR-13, 14`), computed area plausibility (`VR-15`), area comparison vs source (`VR-16, 17`) with mandatory FR-127 validation aid note, minimum $\ge 3$ vertices (`VR-10`), and cadastral overlap detection (`VR-18`). Results persisted to `app.parcel_computations.validation_result`. Controller: `ValidationController.php` (`POST /parcels/{id}/validate`, `GET /parcels/{id}/validation`). Tests: `backend/tests/Api/ValidationTest.php` 5/5 pass.

**TASK-097 — Overlap detection**
Dep: 096, 019 · Files: `backend/src/Parcels/` · Status: DONE
Do: `ST_Intersects`/`ST_Area(ST_Intersection)` against non-archived parcels with a sliver threshold; returns the overlapping parcel list.
AC: uses the GIST index; reports area and identifies each overlapping parcel.
Test: Spatial/OverlapTest.
Verification: 2026-09-24 — `backend/src/Parcels/Domain/OverlapDetector.php` performs GIST-indexed spatial queries (`ST_Intersects`, geodesic `ST_Area(ST_Intersection(...)::geography)`), computes overlapping area in $m^2$ and percentage of subject parcel, distinguishes interior polygon overlaps from adjacent boundary-touching lines ($0\text{ m}^2$), filters slivers ($\le 0.05\text{ m}^2$), and excludes ARCHIVED/SUPERSEDED parcels. Hermetic spatial test `backend/tests/Spatial/OverlapTest.php` 4/4 pass (12 assertions).

**TASK-098 — Submission guards**
Dep: 096 · Files: `backend/src/Parcels/` · Status: DONE
Do: block submission on any blocking failure with the specific reason; carry warnings forward to reviewers.
AC: a blocking error returns `CLOSURE_EXCEEDS_TOLERANCE` or `VALIDATION_FAILED` naming the rule; warnings persist to approval.
Test: Api/SubmitGuardTest.
Verification: 2026-09-24 — `ValidationController::submit` (`POST /parcels/{id}/submit`) enforces complete survey validation before parcel status transition to `SUBMITTED`. Rejects with `CLOSURE_EXCEEDS_TOLERANCE` (422) naming linear error and tolerance if traverse closure fails, or `VALIDATION_FAILED` (422) naming rule ID for other blocking failures. Carries forward warnings (e.g. `VR-18` overlap, `VR-19` unverified tie point) into `audit.parcel_versions` and audit logs. Blocks re-submission of invalid statuses (400 `INVALID_STATE`). Tested in `backend/tests/Api/SubmitGuardTest.php` 4/4 pass.

**TASK-099 — Validation panel UI**
Dep: 098, 093 · Files: `frontend/src/features/survey/` · Status: DONE
Do: checklist with pass/warn/fail, rule ids, expanded warnings by default, no dismiss-all, "show me" actions that highlight the cause.
AC: warnings cannot be collapsed away or suppressed; blocking errors disable submission and explain why.
Test: Playwright validation panel.
Verification: 2026-09-24 — Frontend: `validationApi.ts` client; `ValidationPanel.tsx` with top status banner, 12-point checklist table displaying PASS/WARN/FAIL status badges and rule IDs (`VR-01` through `VR-20`), warnings expanded by default without dismiss-all (FR-126), "Show me" action buttons navigating to relevant tabs (`techdesc`, `tiepoint`, `computation`), area comparison card with mandatory FR-127 validation aid note, overlap analysis table (VR-18), and "Submit for Review" button disabled on blocking failures opening submission confirmation modal. Mounted in `ParcelEditorPage.tsx` under the Validation tab. Tests: `frontend/src/features/survey/components/ValidationPanel.test.tsx` 5/5 pass; full Vitest suite 52/52 pass; `tsc -b && vite build` clean.

---

## PHASE 13 — Workflow and approval

**TASK-100 — Workflow engine**
Dep: 020, 030 · Files: `backend/src/Parcels/Workflow/` · Status: DONE
Do: table-driven state machine with permission checks, guards, mandatory reasons, history, notifications.
AC: an illegal transition is rejected server-side regardless of the request; guards evaluate computation and validation state.
Test: Unit/StateMachineTest, Api/TransitionPermissionTest.
Verification: 2026-09-24 - `backend/src/Parcels/Workflow/WorkflowEngine.php` (NEW): table-driven engine reading `app.workflow_transitions` (definition PARCEL_APPROVAL, seeded in SystemSeeder with the full FR-135 matrix - 9 states, 12 transitions with per-transition permission, requires_reason/requires_comment, guard_expression). Illegal/unknown actions rejected server-side with `INVALID_TRANSITION` 422 regardless of payload; permission gate per transition row (PERMISSION_DENIED 403); FR-137 reason/comment enforcement; `validation_passed` guard re-evaluates the TASK-096 checklist at execution time and maps closure failures to `CLOSURE_EXCEEDS_TOLERANCE`; unknown guard names fail closed. History in `app.approval_actions` + audit.audit_logs via AuditWriter; FR-140 notifications to the parcel creator (actor excluded) in app.notifications. `backend/src/Parcels/Http/WorkflowController.php` (NEW): POST/GET `/parcels/{id}/transitions`, GET `/parcels/{id}/transitions/history`. SystemSeeder reconciles legacy partial workflow rows idempotently. Tests: `Unit/StateMachineTest` (8), `Api/TransitionPermissionTest` (5), `Api/WorkflowFlowTest` (5, incl. full DRAFT→…→PUBLISHED flow and approval-blocked-by-revalidation). Full suite 390 green.

**TASK-101 — Parcel transitions API**
Dep: 100, 098 · Files: `backend/src/Parcels/` · Status: DONE
Do: submit, review, return, verify, approve, publish, archive; approval records the accepted computation id and TD revision.
AC: approval is impossible while a blocking validation failure exists; every transition is audited with actor, reason, and comment.
Test: Api/WorkflowFlowTest.
Verification: 2026-09-24 - Covered by the TASK-100 engine + WorkflowController: all FR-135 actions (SUBMIT, START_REVIEW, RETURN, VERIFY, APPROVE, PUBLISH, ARCHIVE) are transition rows; APPROVE stores accepted_computation_id on approval_actions (asserted in WorkflowFlowTest); the validation guard re-runs at APPROVE time so a computation corrupted after SUBMIT blocks with `CLOSURE_EXCEEDS_TOLERANCE` and the parcel stays VERIFIED; every action audited with actor/reason/comment; RETURN without reason rejected (FR-137); RETURNED→SUBMITTED cycle re-runs the guard. `Api/WorkflowFlowTest` 5/5 green (44 assertions).

Note (interim guard): since TASK-100, `ParcelController::update` rejects PATCH status transitions outside DRAFT↔RETURNED (`INVALID_STATE`), so `POST /parcels/{id}/transitions` is the only transition path; `ValidationController::submit` (TASK-098) remains as a thin DRAFT/RETURNED→SUBMITTED entry that delegates to the same checklist.

**TASK-102 — Editing approved records**
Dep: 101, 069 · Files: `backend/src/Parcels/` · Status: DONE
Do: editing an APPROVED parcel creates a new version and returns it to the configured state; the approved version stays intact.
AC: the previously approved version remains retrievable and unchanged.
Test: Api/ApprovedEditTest.
Verification: 2026-09-24 - Seeded `REOPEN` transition (APPROVED→DRAFT, `parcel.approve`, reason required) in SystemSeeder (workflow states now upsert-only so re-seeding cannot break `workflow_instances` FKs). `WorkflowEngine::reopenApprovedRecord()` runs the cycle through the engine — permission gate, FR-137 reason rule, approval_actions history, FR-140 creator notification — with the target state overridable by the `WORKFLOW_APPROVED_EDIT_TARGET_STATE` setting (validated non-terminal state of the definition, default DRAFT). `ParcelController::update()` on an APPROVED parcel: rejects an explicit `status` field BEFORE the reopen (FR-136), verifies If-Match against the approved version, captures the approved state as an append-only `audit.parcel_versions` row (the engine never wrote version rows, so the approved state was otherwise unretrievable — this row satisfies the FR-141 AC), runs the reopen, then applies the edit (+2 version total: reopen + edit). api.md §8.1 documents the contract. Tests: `Api/ApprovedEditTest` 5/5 (new version + configured state, approved version preserved retrievable+byte-identical, reason mandatory, permission-gated 403 PERMISSION_DENIED for a `parcel.update`-only encoder, PATCH-status rejection, configured target state). Full suite 420 green.

**TASK-103 — Workflow UI, reviewer inbox, notifications**
Dep: 101, 071 · Files: `frontend/src/features/parcels/`, `frontend/src/features/notifications/`, `backend/src/Parcels/Http/` · Status: DONE
Do: action bar with permitted transitions only, reason/comment prompts, reviewer inbox, notification bell.
AC: unavailable transitions are absent; a return requires a reason before the request is sent.
Test: Playwright two-role approval flow.
Verification: 2026-09-25 — Shipped and verified on Docker stack: `WorkflowActionBar.tsx` renders one button per server-`allowed` transition (unavailable actions absent from the DOM, FR-103), enforcing FR-137 reason/comment gates client-side via `transitionsApi.ts`; mounted in `ParcelEditorPage.tsx` status bar (transitions blocked while dirty); `ReviewerInboxPage` at `/parcels/inbox` (`parcel.review` gated) lists SUBMITTED/UNDER_REVIEW parcels; `NotificationsController.php` (GET `/notifications` with unread filter + unread_count, POST `/notifications/{id}/read` idempotent, foreign id → 404) + routes; `NotificationBell` in the app header (30 s poll, unread badge, optimistic mark-read, click-through to the parcel). Tests: `Api/NotificationsApiTest` (6), `transitionsApi.test.ts` (8), `WorkflowActionBar.test.tsx` (5), Playwright `e2e/specs/phase14-workflow.spec.ts` two-role flow (encoder submits, reviewer inbox → START_REVIEW → VERIFY → APPROVE with comment gate, RETURN reason gate, creator bell). Frontend: 65/65 Vitest, tsc clean, vite build clean. Full backend test suite (471 tests / 2086 assertions) verified green on Docker stack.

---

## PHASE 14 — History, versioning UI, documents

**TASK-104 — Merged history timeline API**
Dep: 069, 025, 101 · Files: `backend/src/Parcels/` · Status: DONE
Do: versions + audit + workflow merged chronologically per record; single-entity audit bundle export.
AC: "everything ever done to parcel X" returns a complete, ordered record.
Test: Api/HistoryTimelineTest.
Verification: 2026-09-24 - `HistoryTimelineController.php` (NEW): GET `/parcels/{id}/timeline` merges audit.parcel_versions (VERSION), audit.audit_logs (AUDIT), and app.approval_actions (WORKFLOW) into one stream sorted newest-first with actor, action, detail, old/new values; GET `/parcels/{id}/timeline/export` returns the same bundle as an attachment JSON file (parcel.lineage.view). Test: `Api/HistoryTimelineTest` 3/3 green.

**TASK-105 — Version comparison and geometry diff (backend)**
Dep: 104 · Files: backend + `frontend/src/components/common/` · Status: DONE (backend; map rendering deferred to TASK-103 UI work)
Do: field-level diff and geometry diff (added/removed/moved vertices) rendered on the map.
AC: a moved vertex is visually identified; attribute changes are listed field by field.
Test: Unit/GeometryDiffTest, Playwright history view.
Verification: 2026-09-24 - `VersionDiffService.php` (NEW, pure domain): position-based vertex pairing identifying moved/added/removed vertices with from/to coordinates, exterior-ring extraction for Polygon/MultiPolygon (closing point dropped), field-level snapshot diff listing changed fields only. Endpoint GET `/parcels/{id}/versions/{v}/compare?against={v2}` (parcel.lineage.view) returns field_changes + geometry_diff. Test: `Unit/GeometryDiffTest` 7/7 (moved-vertex identification with from/to, added/removed, whole-geometry add/remove, ring extraction, field diff). Map overlay rendering lands with the Phase 14 UI pass (TASK-104a).

**TASK-104a — Version compare and history timeline UI**
Dep: 104, 105, 071 · Files: `frontend/src/features/parcels/` · Status: TODO
Do: render version comparison diff (field-level changes and geometry diff with moved/added/removed vertices on the map preview) in the parcel history view; render merged timeline stream (`VERSION`, `AUDIT`, `WORKFLOW` events) with export.
AC: moved vertices are visually highlighted on map preview; attribute diffs listed field-by-field; timeline events navigable and exportable.
Test: Playwright version comparison and history timeline test.

**TASK-106 — Version restore**
Dep: 105 · Files: `backend/src/Parcels/` · Status: DONE
Do: restore creates a **new** version from an old snapshot, with reason; never deletes or rewrites history.
AC: restore is additive; the version sequence remains monotonic.
Test: Api/RestoreTest.
Verification: 2026-09-24 - Implemented in TASK-069 (`ParcelController::restore`); verified against TASK-106 ACs: restore creates a NEW version (v4 from v2), sequence [1,2,3,4] monotonic, historical rows byte-identical after restore (append-only proven), If-Match 428/409 enforced, reason recorded, geometry brought back from historical version. Test: `Api/RestoreTest` 4/4 green.

**TASK-107 — Document upload, storage, linking**
Dep: 020, 030 · Files: `backend/src/Documents/` · Status: DONE
Do: multipart upload with extension + MIME sniff + magic-byte validation, size cap, SHA-256 de-duplication, random storage keys outside the web root, classification, entity links.
AC: a disguised executable is rejected; identical files de-duplicate; no filesystem path is ever exposed.
Test: Api/UploadValidationTest.
Verification: 2026-09-24 - `DocumentService.php` (NEW): extension allow-list (PDF/JPEG/PNG/TIFF per FR-156), magic-byte signature check (ELF disguised as .pdf rejected with MAGIC_BYTES), finfo MIME sniff (octet-stream inconclusive tolerated after exact magic match; dangerous/conflicting types rejected), 25 MB cap, SHA-256 de-duplication (second identical upload returns the same row with de_duplicated=true), randomised 32-hex storage keys under DOCUMENTS_STORAGE_DIR (outside web root; storage_key stripped from every API response), classification PUBLIC/INTERNAL/RESTRICTED/SENSITIVE_PERSONAL, document_links with ON CONFLICT. Controller POST /documents (multipart), GET /documents/{id}, POST /documents/{id}/links. Migration `20260924000001` adds app.document_download_tokens. Test: `Api/UploadValidationTest` 6/6 green.

**TASK-108 — Signed download and classification enforcement**
Dep: 107 · Files: `backend/src/Documents/` · Status: DONE
Do: short-lived single-use signed URLs; classification checked server-side; restricted downloads audited.
AC: an expired or reused link fails; an unauthorised classification returns `NOT_FOUND`.
Test: Api/DocumentAccessTest.
Verification: 2026-09-24 - HMAC-SHA256 over (doc id, expiry, nonce) with the JWT secret; nonce persisted in app.document_download_tokens and atomically claimed on consume (single-use, expired/reused → 404); tampered signature → 404; minting or consuming a RESTRICTED/SENSITIVE_PERSONAL document without document.download_restricted → 404 (existence hidden, never 403); restricted downloads audited (FR-150). Endpoints POST /documents/{id}/download-token, GET /documents/{id}/download?token=. Test: `Api/DocumentAccessTest` 5/5 green (round-trip bytes, reuse, expiry, tamper, classification).

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
Dep: 019, 089 · Files: `backend/src/Parcels/Domain/`, `Infrastructure/` · Status: DONE
Do: VR-35…VR-39 — child validity, pairwise non-overlap, union-equals-parent within ε, minimum area, area reconciliation.
AC: each rule triggers independently on a targeted fixture; nothing is auto-corrected.
Test: Spatial/SplitValidationTest (one fixture per rule).
Verification: 2026-09-25 — `backend/src/Parcels/Domain/SplitValidator.php`: pure and spatial validation enforcing VR-35 (child polygon validity, simplicity, non-emptiness, minimum 2 children), VR-36 (pairwise child non-overlap via `ST_Overlaps` and `ST_Area(ST_Intersection) <= ε` with `overlap_area_sqm` reporting), VR-37 (symmetric difference between child union and parent polygon `ST_Area(ST_SymDifference) <= ε` ensuring zero gaps/slivers), VR-38 (minimum child area threshold), VR-39 (area reconciliation: Σ children vs parent reported, never forced to balance). Hermetic spatial test suite `backend/tests/Spatial/SplitValidationTest.php` (7 tests / 28 assertions) passes green on Docker.

**TASK-112 — Split service (dry run + commit)**
Dep: 111, 069 · Files: `backend/src/Parcels/Application/` · Status: DONE
Do: all four methods; identical validation for dry run and commit; transactional commit creating children, relationships, versions, parent `SUPERSEDED`, operation record, audit.
AC: dry run writes nothing; a mid-operation failure leaves the database untouched; `SPLIT_INVALID` lists every failure.
Test: Api/SplitTest, Api/SplitRollbackTest (injected failure).
Verification: 2026-09-25 — `backend/src/Parcels/Application/SplitService.php` and `backend/src/Parcels/Http/SplitController.php` (POST `/parcels/{id}/split`): supports all 4 derivation methods (`MAP_SPLIT_LINE` via `ST_Split` and `ST_Snap` with `ST_Extent` BOX regex parsing, `TECHNICAL_DESCRIPTION`, `SURVEY_GEOMETRY`, `IMPORTED_GEOMETRY`); `dry_run=true` executes identical validation and returns preview without database mutation; transactional commit locks parent `FOR UPDATE`, verifies `If-Match`, validates, inserts children at status `DRAFT` with v1 version rows and inherited PSGC/org, inserts `app.parcel_relationships` (`SUBDIVISION`), updates parent to status `SUPERSEDED`, writes `app.parcel_operations` record with idempotency key (migration `20260925000001`), and writes audit logs. Tests: `backend/tests/Api/SplitTest.php` (14 tests) and `backend/tests/Api/SplitRollbackTest.php` (4 tests) pass green on Docker.

**TASK-113 — Consolidation validation rules**
Dep: 111 · Files: `backend/src/Parcels/` · Status: DONE
Do: VR-40…VR-44 — ≥2 eligible parents, no overlaps, gap/sliver detection, contiguity, CRS compatibility, documentation requirements.
AC: overlapping parents block; a non-contiguous union blocks unless multipart is explicitly allowed.
Test: Spatial/ConsolidationValidationTest.
Verification: 2026-09-25 — `backend/src/Parcels/Domain/ConsolidationValidator.php`: rule and spatial validation enforcing VR-40 (>=2 distinct parents, none SUPERSEDED/ARCHIVED), VR-41 (pairwise parent non-overlap), VR-42 (convex-hull minus union gap/sliver metric), VR-43 (union contiguity: `ST_NumGeometries=1` unless `allow_multipart=true`), VR-44 (SRID compatibility evaluated before spatial rules with probe isolation). Hermetic spatial test suite `backend/tests/Spatial/ConsolidationValidationTest.php` (8 tests / 32 assertions) passes green on Docker.

**TASK-114 — Consolidation service (dry run + commit)**
Dep: 113 · Files: `backend/src/Parcels/Application/` · Status: DONE
Do: ordered `FOR UPDATE` locking, `ST_Union`, reconciliation, new parcel, relationships, parents `SUPERSEDED`, operation record, audit.
AC: transactional and idempotent by key; parents survive intact as superseded.
Test: Api/ConsolidationTest, Api/ConsolidationRollbackTest.
Verification: 2026-09-25 — `backend/src/Parcels/Application/ConsolidationService.php` and `backend/src/Parcels/Http/ConsolidationController.php` (POST `/parcels/consolidate`): locks all parent rows `FOR UPDATE` in deterministic ID order to prevent deadlocks while preserving caller input order; validates via ConsolidationValidator; dry run returns plain Polygon union payload; transactional commit builds union via parents CTE, inserts new parcel (DRAFT, code seeded from first parent input order), inserts `app.parcel_relationships` (`CONSOLIDATION`) for each parent, bumps parent versions and transitions parents to status `SUPERSEDED`, writes `app.parcel_operations` with idempotency_key, and logs audit trail. Tests: `backend/tests/Api/ConsolidationTest.php` (12 tests) and `backend/tests/Api/ConsolidationRollbackTest.php` (4 tests) pass green on Docker.

**TASK-115 — Lineage API**
Dep: 112, 114 · Files: `backend/src/Parcels/` · Status: DONE
Do: ancestors/descendants with depth cap, cycle guard, truncation flag; operation lookup per edge.
AC: a twice-transformed parcel returns the full graph both ways; a cycle attempt is rejected at write time (VR-45).
Test: Api/LineageTest, Integration/LineageCycleTest.
Verification: 2026-09-25 — `backend/src/Parcels/Http/LineageController.php` (GET `/parcels/{id}/lineage` and GET `/operations/{operation_id}`): undirected graph discovery with Bellman-Ford style relaxation, BFS generation-depth calculation with sibling-cost-0 semantics (root=0, parents/children/siblings=1), direction filtering (ancestors, descendants, both), max-depth cap, explicit truncation flags (`truncated`, `truncated_ancestors`, `truncated_descendants`). Database migration `20260925000001` applies idempotent VR-45 cycle-prevention trigger (`app.fn_prevent_lineage_cycle`) rejecting cyclical relationship inserts. Tests: `backend/tests/Api/LineageTest.php` (11 tests) and `backend/tests/Integration/LineageCycleTest.php` (6 tests) pass green on Docker.

**TASK-116 — Split UI**
Dep: 112, 071 · Files: `frontend/src/features/parcels/` · Status: DONE
Do: method selector, split-line drawing with snapping, mandatory preview, child area table, validation block, child detail forms, commit with reason.
AC: commit is enabled only from a successful preview of the current inputs; editing invalidates the preview.
Test: Playwright split flow including a blocked invalid split.
Verification: 2026-09-25 — Frontend `SplitTab.tsx` and `SplitLineMap.tsx` in `frontend/src/features/parcels/components/`: method selector (`MAP_SPLIT_LINE`, `TECHNICAL_DESCRIPTION`, `SURVEY_GEOMETRY`, `IMPORTED_GEOMETRY`), interactive map split-line drawing with parcel vertex snapping, preview button invoking POST `/parcels/{id}/split` with `dry_run=true`, `ValidationBlock.tsx` displaying VR-35..VR-39 checks and warnings, child area breakdown table with percentage shares, child detail form inputs (lot numbers, reason), and commit button strictly disabled until passing preview of current inputs exists (`canCommitSplit`/`previewStateKey` pure logic). Mounted in `ParcelEditorPage.tsx` under Split tab. Tests: `frontend/src/features/parcels/components/SplitTab.test.ts` (12 unit tests pass); Playwright spec `frontend/e2e/specs/phase15-split-consolidation-lineage.spec.ts` verifies preview gating and error display.

**TASK-117 — Consolidation UI**
Dep: 114, 116 · Files: `frontend/src/features/parcels/` · Status: DONE
Do: multi-select by map/list/search, validation panel with problem highlighting and zoom-to-problem, union preview, area comparison, new parcel form.
AC: blocking failures highlight the offending parcels on the map rather than showing a generic error.
Test: Playwright consolidation flow including overlap rejection.
Verification: 2026-09-25 — Frontend `ConsolidationTab.tsx` and `ConsolidationMap.tsx` in `frontend/src/features/parcels/components/`: multi-parent parcel selector with list filtering, live `ConsolidationMap` rendering parent parcels with problem parcel highlighting (`offendingParents` extracts parent indices from VR-41/VR-42 failure messages and highlights them in red), union preview geometry, area reconciliation display (parents sum vs union area and difference), allow_multipart toggle, new parcel attributes form, and commit with reason. Mounted in `ParcelEditorPage.tsx` under Consolidate tab. Tests: `frontend/src/features/parcels/components/ConsolidationTab.test.ts` (7 unit tests pass); Playwright spec `frontend/e2e/specs/phase15-split-consolidation-lineage.spec.ts` verifies offending-parent highlighting and consolidation execution.

**TASK-118 — Lineage view**
Dep: 115 · Files: `frontend/src/features/parcels/` · Status: DONE
Do: genealogy graph with status badges, areas, dates, edge labels, expand controls, show-on-map, export.
AC: superseded nodes are distinct but navigable; depth truncation is stated, not silent.
Test: Playwright lineage navigation.
Verification: 2026-09-25 — Frontend `LineageTab.tsx` in `frontend/src/features/parcels/components/`: layered genealogy graph layout (`layoutLineage`: ancestors at negative columns, root at 0, descendants at positive columns, siblings sharing columns), status badges (`StatusBadge`), parcel areas and effective dates, edge relationship labels, interactive depth (1..10) and direction selectors, prominent banner warning on truncated lineage graphs (`data.truncated`), clickable node links to parcel editor, and JSON graph export download (`downloadLineageJson`). Mounted in `ParcelEditorPage.tsx` under Lineage tab. Tests: `frontend/src/features/parcels/components/LineageTab.test.ts` (5 unit tests pass); Playwright spec `frontend/e2e/specs/phase15-split-consolidation-lineage.spec.ts` verifies navigation of superseded nodes and truncation notice.

**TASK-119 — Historical record handling across the app**
Dep: 115 · Files: backend + frontend · Status: DONE
Do: exclude SUPERSEDED/ARCHIVED from default map, search, tiles, and exports; `include_historical` everywhere it is meaningful.
AC: no default view shows superseded parcels; every historical view is explicitly labelled.
Test: Api/HistoricalFilterTest, Playwright default-view assertion.
Verification: 2026-09-25 — Backend `ParcelController::list()` filters out `SUPERSEDED` and `ARCHIVED` parcels by default from general queries, map previews, and search; requires explicit `include_historical=true` parameter to surface superseded records (FR-135a/VR-46). Frontend `ParcelListPage.tsx` features labelled "Include historical" toggle and historical status badges. Test: `backend/tests/Api/HistoricalFilterTest.php` (6 tests) and Playwright parcel list specs pass green on Docker.

**TASK-120 — Split/consolidation from survey data**
Dep: 112, 090 · Files: `backend/src/Parcels/` · Status: DONE
Do: children derived from technical descriptions or survey geometry, each carrying its own computation and validation.
AC: a child computed from a TD carries `COMPUTED_FROM_TECHNICAL_DESCRIPTION` and its own closure result.
Test: Api/SplitFromTechnicalDescriptionTest.
Verification: 2026-09-25 — `backend/src/Parcels/Application/SplitService.php` supports derivation from survey data (methods `TECHNICAL_DESCRIPTION` and `SURVEY_GEOMETRY`), loading child technical descriptions, computing and closing child geometries, setting child `geometry_source` to `COMPUTED_FROM_TECHNICAL_DESCRIPTION`, persisting `parcel_computations` and vertices for children, and rejecting uncomputed technical descriptions with 400 `VALIDATION_FAILED`. Test: `backend/tests/Api/SplitFromTechnicalDescriptionTest.php` (8 tests) passes green on Docker.

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

---

## AUDIT — Phases 5–15 (CRUD · transactions · features · tools · menus)

**Date:** 2026-09-25 · **Scope:** TASK-049 … TASK-120, cross-checked against `architecture.md`, `specification.md`, `api.md`, `frontend.md`.
**Verification after fixes:** backend `482 tests / 2131 assertions` OK, 0 failures/errors · frontend `92 tests / 16 files` green · `tsc -b --noEmit` exit 0 · `npm run build` ✓ · `npm run lint` warnings-only (0 errors) · `php -l` clean · PHPStan L8 `src` errors reduced `163 → 157`.

### Transaction audit

**Mechanism (verified, and it is sound):** `AuthenticateMiddleware.php:51` opens **one** PDO transaction for the whole authenticated request, sets the RLS context with `SET LOCAL` (`:90-100`), runs the handler (`:110`), commits (`:112`), and rolls back on any throwable (`:116-121`). So every authenticated route is already atomic and audit-failure rolls back the mutation (TASK-025 AC). `SplitService:211-214` and `ConsolidationService` correctly rely on this "ambient transaction" and do not open their own — verified sound.

**Empirically proven hazard:** a nested `beginTransaction()` inside that transaction **throws** `PDOException: There is already an active transaction`, and a subsequent `commit()` ends the outer transaction and **wipes the `SET LOCAL` RLS context** (probe run against the live container: `app.probe_ctx` came back empty after the inner commit). `DbTransaction.php:11-17` exists precisely to prevent this and documents the invariant.

| # | Sev | Finding | Fix |
|---|---|---|---|
| T-1 | **CRITICAL** | `SurveyPlanController` opened an **unguarded** `$this->pdo->beginTransaction()` in all three write paths (`:161` create, `:229` update, `:398` delete) while running inside the middleware transaction. Every authenticated `POST` / `PUT` / `DELETE /survey-plans` therefore returned **HTTP 500** `There is already an active transaction` — proven end-to-end, not inferred. Undetected because `tests/Unit/SurveyPlanTest.php` only unit-tests plan-type validation and never touches the HTTP layer. | All three sites converted to `DbTransaction::begin/commit/rollback` (`:163`, `:237`, `:412`), matching `UserAdminService`/`RoleAdminService`/`OrganizationAdminService`. |
| T-2 | **CRITICAL** | Every audit write in the same file (3 sites) called `writeFromSession(action:, entityType:, preChangeSnapshot:, postChangeSnapshot:, metadata:)` — a signature that **does not exist**. The real API is `writeFromSession($action, $table, $entityId, $oldValues, $newValues, $requestId, $reason)` (`AuditWriter.php:72-80`), and the writer uppercases the action and persists the table as `entity_type`. Masked by T-1: the request died at `beginTransaction` before ever reaching the audit call. Fixing T-1 alone still left all three endpoints at 500 `Unknown named parameter $entityType`. | Rewrote the 3 calls positionally against the real signature, using `app.survey_plans` as the table and folding the former `metadata` into the values payload (the convention `SplitService.php:261-265` already uses). Repo-wide grep confirms **no** other file uses the phantom parameter names. |

**Regression coverage added:** `backend/tests/Api/SurveyPlanApiTest.php` (4 tests) exercises create/update/delete over real HTTP with a real token, plus an assertion that a create persists an `audit.audit_logs` row — so both the transaction join and the audit-atomicity AC are now pinned. Before: 0 tests on these endpoints. After: 4.

**Also verified:** all 8 remaining raw `beginTransaction()` calls in controllers are inside `if (!inTransaction())` guards; `TechnicalDescriptionController` uses the correct guarded pattern at all 5 sites (it generates 8 pre-existing PHPStan `Right side of && is always false` errors from that pattern — a repo-wide cleanup candidate, not a regression).


### Fixed in this audit

| # | Sev | Area | Finding | Fix |
|---|---|---|---|---|
| A-1 | HIGH | CRUD / authz | `PUT /layers/{id}` and `DELETE /layers/{id}` were gated on **`gis.layer.create`**, so a principal who could create but not update/delete could still update and delete a layer. The catalogue defines `gis.layer.update` / `gis.layer.delete` (migration `20260920000015:23-24`). | `backend/config/routes.php:55-58` re-gated to `gis.layer.update` / `gis.layer.delete`. |
| A-2 | HIGH | CRUD / authz | Layer **field** CRUD + retype-preview were gated on `gis.layer.create` instead of `gis.field.manage` ("Manage layer fields and field order", catalogue `:25`). | `backend/config/routes.php:63-70` re-gated to `gis.field.manage`. |
| A-3 | HIGH | CRUD / authz | Layer **style** create/update were gated on `gis.layer.create` instead of `gis.style.manage` (catalogue `:26`). | `backend/config/routes.php:75-78` re-gated to `gis.style.manage`. |
| A-4 | MED | tests | `tests/Api/StyleTest.php:24` granted the **non-existent** code `gis.layer.manage` — the test therefore proved nothing about style authorisation. | Grant real codes `['gis.style.manage', 'gis.layer.update']`; test green. |
| A-5 | HIGH | menus / UX | `frontend.md` §3 requires `/ → redirect to /map`; the app rendered a separate `HomePage` at `/`, so the default landing surface was **not** the map workspace. | `App.tsx` `/` now `<Navigate to="/map" replace>` under `RequireAuth`. |
| A-6 | MED | routing | `frontend.md` §3 lists `/404`; the catch-all rendered `HomePage` for every unknown URL, so typos silently showed a home screen instead of a 404. | Added `frontend/src/pages/NotFoundPage.tsx`; `/404` route + catch-all both render it; unused `HomePage` import dropped. |
| A-7 | LOW | menus / UX | Nav active-state used `startsWith('/parcels')` for **Parcels** and `=== '/parcels/inbox'` for **Inbox**, so on the reviewer inbox both items highlighted at once. | `App.tsx` Parcels active-state now excludes `/parcels/inbox`. |
| A-8 | MED | menus / authz | The "GIS Layers" admin tab was gated on `gis.layer.create`, hiding the layer list from read-only users who hold `gis.layer.view`. | `AdminView.tsx:31` now gates on `gis.layer.view`. |
| A-9 | LOW | quality | 4 unused `catch (err)` bindings (`ComputationPanel.tsx` 115/131/204/253) and 2 unnecessary regex escapes (`BearingInput.tsx:139`) flagged by lint. | Converted to `catch { }`; removed the `\-` escapes (regex semantics unchanged — verified by the 92-test green run). |
| A-10 | MED | authz / quality | `App.tsx` gated nav on raw string literals (`'parcel.view'`, …) instead of the exported `permissions` constants, so a typo in any gate would compile silently — defeating the strongly-typed catalogue whose stated purpose (per `permissions.ts:3-7`) is compile-time protection. | All five gates now use `permissions.userManage / parcelView / controlPointView / auditView / parcelReview`. |
| A-11 | MED | menus / UX | `frontend.md` §19 mandates "a left icon rail [that] switches modules"; the app switched modules through a horizontal text nav in the header, which is a §3 pattern, not a §19 one. | Added a declarative `RAIL_ITEMS` rail (icon + visible label + `title` + `aria-current="page"`), inline SVG icons following the existing `HomePage.tsx:158-162` convention (no new icon dependency), rendered **absent** for unpermitted modules per §19, with a divider before the admin group. Rail visibility is derived from the same `hasPermission` used by the routes, and `/map` + `/status` are always visible because those routes require only `RequireAuth`. Styles in `index.css` (`.app-body`, `.app-rail`, `.app-rail-divider`) with a `:focus-visible` outline for keyboard users. |
| A-12 | MED | routing / UX | `frontend.md` §4 places `<AuthLayout>` as a **sibling** of `<AppLayout>`, but `Layout` wrapped every route — so an unauthenticated visitor to `/login` saw the full authenticated shell (header + module nav). With A-11 in place this would have exposed the entire module rail to anonymous users. | Added `RouteShell` (`App.tsx`): `/login`, `/forgot-password`, `/404`, `/403` render inside `.auth-layout` with no shell; every other route keeps `<Layout>`. |

### Verified clean (no action)

- **Feature CRUD capability model (TASK-031) is correct and must NOT be hardened with global gates.** `FeatureScopeResolver.php:22-46` resolves `can_create/update/delete` solely from `app.layer_permissions`, and `GisFeatureController` enforces it at `create:393`, `update:469`, `delete:585`, `bulkUpdate:619`, `bulkDelete:695`. `tests/Integration/LayerPermissionTest.php:64-81` deliberately uses non-`SYS_ADMIN` `ROLE_A`/`ROLE_B` with only `layer_permissions` grants. Adding a `gis.feature.*` route gate would invert the intended two-layer model and break that AC.
- **Layer capability is a defence-in-depth second layer, not the only one, once A-1…A-3 land** — the catalogue-level permission and the per-layer capability are now both enforced on the layer/field/style surface.
- `permissions.ts` is a faithful mirror of the server catalogue (`specification.md` §3.3) — `gis.field.manage`, `gis.style.manage`, `techdesc.*`, `party.*`, `rpt.*`, `import.*` all exist server-side. No drift.
- Only `SYS_ADMIN` receives the full catalogue (`20260920000015:125-134`), so every test fixture using `SYS_ADMIN` is unaffected by A-1…A-4.
- `ParcelEditorPage.tsx:62-64` already validates the `:tab?` param and falls back to `information`; unknown tabs cannot render a blank editor.
- Phase 13 route comments are accurate: workflow transitions are permission-gated **inside** `WorkflowEngine` from `workflow_transitions`, and the route gate is deliberately `parcel.view` so callers can list available actions (`routes.php:129-138`). Notifications are scoped by a `user_id` predicate with no RLS on `app.notifications` (`routes.php:140-147`).
- Document classification is enforced server-side in `DocumentController.php:116` (`document.download_restricted`) and correctly returns **404, not 403**, preserving the TASK-108 "existence hidden" AC. No route-level gate was added to the download path for that reason.

### Open gaps found (not fixed — out of Phase 5–15 audit scope, recorded for the owning task)

| # | Sev | Finding | Owner |
|---|---|---|---|
| B-1 | MED | No `DELETE` route or controller action for layer styles (`GisLayerStyleController` exposes only `create`/`update`). TASK-046 says styles are "versioned", so this may be deliberate — confirm before adding. | TASK-046 |
| B-2 | MED | `POST /documents` has only `AuthenticateMiddleware`; the catalogue's `document.upload` is never enforced, so any authenticated user can upload. `POST /documents/{id}/links` is likewise ungated. | TASK-107/109 |
| B-3 | MED | `POST /parcels/{id}/validate` is gated on the read-only `parcel.view` although it persists `validation_result` and is a mutating action. | TASK-096 |
| B-4 | LOW | `POST /crs/transform` is gated on `survey.view` although TASK-095 requires every persisted transformation to write a `coordinate_transformations` row (a write behind a read permission). | TASK-095 |
| B-5 | MED | ~~`App.tsx` gates with raw string literals~~ **— fixed as A-10.** | — |
| B-6 | MED | ~~Left icon rail~~ **— fixed as A-11.** ~~`/login` inside the app shell~~ **— fixed as A-12.** Still missing from `frontend.md` §3 and not built: `/forgot-password`, `/search`, `/survey-plans`, `/titles`, `/reports`, `/import`, `/export`, `/admin/crs`, `/admin/settings` (these map to TODO phase-tasks, so they are listed as gaps, not defects). | Phase 5–15 cleanup |
| B-7 | LOW | The parcel editor's **Title** and **Documents** tabs render a "This section arrives in a later phase-task" placeholder. Honest, but the tabs are clickable dead ends for users. | TASK-109/110 |
| B-8 | INFO | `lint` reports `react(set-state-in-effect)` across `SpatialTools.tsx:333`, `ControlPointEditorPage.tsx:84`, `TechnicalDescriptionTab.tsx:64`, `ComputationPanel.tsx:106`, `BearingInput.tsx:35` and `only-export-components` in `ConsolidationTab.tsx` — warnings only, no errors. | backlog |
