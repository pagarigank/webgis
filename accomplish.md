# Accomplishments

## TASK-005: Repository skeleton and Git hygiene
- **What shipped**: Created the root directory structure according to `architecture.md` (backend with module/layer folders, frontend, database, docker, docs). Created `.gitignore` to protect `.env`, `vendor`, `node_modules`, `storage`, build output, and keys.
- **Decisions made**: Set up the 4-layer structure (Http, Application, Domain, Infrastructure) inside every module in `backend/src/`.
- **Failed approaches**: N/A
- **Follow-up items**: Proceed to TASK-006.

## TASK-006: `.env.example` and configuration loader
- **What shipped**: Created `backend/.env.example` with safe placeholders for all expected configuration secrets. Created `App\Core\Config\Config` class that parses environment variables and throws `\RuntimeException` if any required secret is missing. Added `Unit/ConfigTest` for testing. Created `backend/composer.json` defining the PHP 8.3 target and PSR-4 autoloading structure.
- **Decisions made**: The loader directly uses PHP's `parse_ini_file` for local development if a path is provided, but falls back to `getenv` ensuring Docker's environment injection works out of the box in production.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-007 to set up the Docker Compose stack.

## TASK-007: Docker Compose stack
- **What shipped**: Created `docker-compose.yml`, `docker/php/Dockerfile`, `docker/nginx/default.conf`, and `Makefile`. Configured `nginx` (unprivileged), `php-fpm` (with pgsql, zip, intl, bcmath, gdal, and composer), `postgres` (with postgis), and `worker` services.
- **Decisions made**: Used `nginxinc/nginx-unprivileged:alpine` for non-root proxy mapping container 8080 to host 80. PHP container installs `gdal-tools`, includes composer, and drops privileges to `www-data`.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-008 to set up Slim 4 bootstrap.

## TASK-008: Backend bootstrap (Slim 4 + DI + pipeline)
- **What shipped**: Implemented `public/index.php`, `config/dependencies.php`, and `config/routes.php`. Built the core middleware pipeline: `RequestIdMiddleware` (X-Request-Id), `CorsMiddleware`, and `HttpErrorHandler`. Created the `Envelope` class for standardising all JSON API responses. Created `/api/v1/health` and test suites (`HealthTest`, `ErrorEnvelopeTest`). Updated `composer.json` with slim and php-di dependencies.
- **Decisions made**: Adhered exactly to ADR-14 envelope format (`{success,error:{code,message,details}}`).
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-009 to set up migrations.

## TASK-009: Migration tooling and DB connection
- **What shipped**: Added `robmorgan/phinx` to `composer.json`. Created `phinx.php` using the backend `Config` loader to read `.env` and map to `database/migrations`. Registered the PostgreSQL PDO connection in `dependencies.php`. Created the first schema migration for `audit_logs` matching the architecture spec.
- **Decisions made**: `phinx.php` uses `%%PHINX_CONFIG_DIR%%/../database/migrations` to route migrations out of the backend module into the shared database folder.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-010 to set up frontend bootstrap.

## TASK-010: Frontend bootstrap
- **What shipped**: Bootstrapped React 18 + TS + Vite inside `frontend/`. Installed and wired up `react-router-dom`, `@tanstack/react-query`, `zustand`, and `axios`. Configured `apiClient.ts` interceptors to automatically unwrap standard envelope success responses (ADR-13). Added a basic health check UI to test query execution and routing. Wrote a baseline Vitest suite.
- **Decisions made**: Configured Axios to default to `/api/v1` and handle envelopes transparently for React Query.
- **Failed approaches**: N/A
- **Follow-up items**: Proceed to TASK-011 for static analysis tools setup.

## TASK-011: Static analysis, lint, format
- **What shipped**: Configured PHPStan (level 8) and PHP-CS-Fixer for the backend. Added Prettier to the frontend Vite Oxlint setup. Updated the `Makefile` with a unified `lint` command.
- **Decisions made**: Leveraged the native Oxlint provided by Vite for speed, paired with Prettier for standard formatting, instead of manually scaffolding ESLint.
- **Failed approaches**: N/A
- **Follow-up items**: Proceed to TASK-012 (CI pipeline).

## TASK-012: CI pipeline
- **What shipped**: Created GitHub Actions workflow (`.github/workflows/ci.yml`). Configured jobs to check out the repository, run PHPStan, PHP-CS-Fixer, and PHPUnit (against a PostGIS sidecar) for the backend. Configured parallel jobs for Vite build, Prettier formatting check, Oxlint, and Vitest for the frontend. Added composer and npm vulnerability scans.
- **Decisions made**: Added a PostGIS service container directly to the backend test job so migrations can be run on a true spatial database.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-013 (first task of Phase 2).
