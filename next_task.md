# Next Task

**TASK-028 — Login, tokens, refresh rotation, reuse detection**
Dep: 027 · Files: `backend/src/Auth/` · Status: TODO
Entry criteria:
- TASK-027 is DONE (verified: 27 tests / 36 assertions green on the Docker stack on 2026-09-20).
To do:
- Implement access JWT (15 min) + rotating refresh cookie (14 d, httpOnly), hashed and family-tracked in `app.refresh_tokens`.
- Token issuance on login; refresh endpoint that rotates the token and detects reuse (reuse revokes the whole family).
- Account lockout: failed-attempt counting with exponential backoff (default 5 attempts), `locked_until` on `app.users`.
- Login/logout auditing via `AuditWriter` (TASK-025).
- Tests: `Api/AuthFlowTest`, `Api/RefreshReuseTest`.
- Update `Integration/SeederTest.php` (if applicable) to verify idempotency.
