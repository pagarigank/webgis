# Next Task

**TASK-035 — Rate limiting, CSRF, security headers, CORS**
Dep: 029 · Files: `backend/src/Core/Http/Middleware/`, nginx config · Status: TODO
Entry criteria:
- TASK-034 is DONE (verified: admin APIs shipped with tests; full suite 121 tests / 306 assertions green on the Docker stack on 2026-09-20).
- The admin routes in `config/routes.php` are wired with `AuthorizeMiddleware`; `AuthenticateMiddleware` transports an ambient PDO transaction per request (see `App\Core\Db\DbTransaction`) — new middleware must not open nested transactions.
To do:
- Per-user token buckets per route class; limits return 429 with `Retry-After`.
- Double-submit CSRF plus Origin check on cookie endpoints; CSRF absence blocks refresh.
- HSTS, CSP without `unsafe-inline`, `X-Frame-Options`, `nosniff`, `Referrer-Policy` headers on every response.
- Explicit CORS allow-list.
- Tests: `Api/RateLimitTest`, `Api/CsrfTest`, `Api/SecurityHeadersTest`.

Wiring reminder for new endpoints/services: prime primitives explicitly (`\DI\autowire(...)->constructorParameter('x', \DI\get('x'))`), always call `\DI\autowire`/`\DI\get` fully qualified in `config/dependencies.php`, and wrap PSR-6 caches in `Psr16Cache`.