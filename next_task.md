# Next Task

**TASK-036 — TOTP MFA**
Dep: 028 · Files: `backend/src/Auth/` · Status: TODO
Entry criteria:
- TASK-035 is DONE (verified: rate limiting, CSRF, security headers, CORS shipped with 18 new tests; full suite 139 tests / 366 assertions green on the Docker stack on 2026-09-20).
- The auth claim is in the access JWT (`sub`) and `POST /api/v1/auth/login` currently returns `MFA_REQUIRED` in the design (`api.md` §2) but no MFA flow exists yet. The login endpoint HTTP route is not yet registered either — check `config/routes.php` before wiring.
To do:
- Optional TOTP login and verification; endpoints `POST /auth/mfa/verify` (`{mfa_token, code}` → same payload as login).
- Enforcement for roles flagged `requires_mfa` so those users cannot complete login without a valid code.
- Encrypted secret storage for enrolled secrets (libsodium, app-managed key from env); secrets never returned by the API.
- Tests: `Api/MfaTest`.

Wiring reminder for new endpoints/services: prime primitives explicitly (`\DI\autowire(...)->constructorParameter('x', \DI\get('x'))`), always call `\DI\autowire`/`\DI\get` fully qualified in `config/dependencies.php`, and wrap PSR-6 caches in `Psr16Cache`.