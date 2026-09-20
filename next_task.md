# Next Task

**TASK-034 — User, role, permission, organisation, scope admin APIs**
Dep: 033 · Files: `backend/src/Users/`, `backend/src/RBAC/` · Status: TODO
Entry criteria:
- TASK-033 is DONE (verified: full suite 98 tests / 228 assertions green on the Docker stack on 2026-09-20).
- Scope vocabulary reconciled under TASK-032: `data_scopes.scope_type`/`access_level` enum-checked (migration `20260920000014`), GLOBAL/REGION documented, RLS functions aligned to the same vocabulary.
To do:
- CRUD APIs for users, roles, permissions, organisations, and data scopes per `api.md`, with role assignment, scope assignment, and deactivation (soft delete — never a hard delete).
- An `effective-access` explainer endpoint per user showing the resolved permissions and scopes.
- System roles cannot be deleted; every mutation is audited with actor and reason (TASK-025 `AuditWriter`).
- Tests: `Api/UserAdminTest`, `Api/RoleAdminTest`.
- Update `TASK.md`, `accomplish.md`, `next_task.md` before committing.

Wiring reminder for new endpoints/services: prime primitives explicitly (`\DI\autowire(...)->constructorParameter('x', \DI\get('x'))`), always call `\DI\autowire`/`\DI\get` fully qualified in `config/dependencies.php`, and wrap PSR-6 caches in `Psr16Cache`.