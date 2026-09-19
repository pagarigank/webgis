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
- **What shipped**: Created `docker-compose.yml`, `docker/php/Dockerfile`, `docker/nginx/default.conf`, and `Makefile`. Configured `nginx` (unprivileged), `php-fpm` (with pgsql, zip, intl, bcmath, gdal), `postgres` (with postgis), and `worker` services.
- **Decisions made**: Used `nginxinc/nginx-unprivileged:alpine` for non-root proxy mapping container 8080 to host 80. PHP container installs `gdal-tools` and drops privileges to `www-data`.
- **Failed approaches**: N/A
- **Follow-up items**: Move to TASK-008 to set up Slim 4 bootstrap.
