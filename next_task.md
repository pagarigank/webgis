**TASK-121 — OGR adapter and format detection** (Phase 16 opener)
Dep: 007 · Files: `backend/src/Core/Geo/` · Status: TODO
Do: `ogr2ogr` subprocess wrapper with timeouts, sandboxed temp dirs, and format probing; never invoked inside an open transaction.
AC: a malformed archive fails cleanly with a useful message; no shell injection is possible.
Test: Unit/OgrAdapterTest, Integration/OgrFormatTest.

Entry criteria: Phase 15 complete (backend + frontend UI: TASK-111..120) verified on the Docker stack — full suite 471 backend tests / 2086 assertions green; frontend 89 Vitest + tsc + vite clean.

First steps for TASK-121:
1. Check whether GDAL `ogr2ogr`/`ogrinfo` exists inside the php-fpm image (`docker compose exec php-fpm sh -c "command -v ogr2ogr"`). If absent, decide: add to the Dockerfile vs pure-PHP probing with graceful degradation (the task's AC only requires clean failure + no shell injection, so a pure-PHP probe with an ogr2ogr fast-path is acceptable if GDAL is not installable).
2. `backend/src/Core/Geo/OgrAdapter.php` — command builder (argument array via `escapeshellarg`/proc_open, never string concatenation), timeout (proc_terminate + partial-output discard), sandboxed temp dir (`sys_get_temp_dir()` + random subdir, removed in finally), structured result (format, layer list, feature count, CRS, error message).
3. Format detection: magic bytes/extension probe first (GeoJSON/CSV/ZIP shapefile/GPKG/KML), OGR probe second; malformed archives must fail with a useful message, never a PHP warning/500.
4. Tests: `Unit/OgrAdapterTest` (command construction, injection attempts, timeout, temp-dir cleanup — mockable executor so GDAL is not needed for unit tests), `Integration/OgrFormatTest` (real fixtures if GDAL is present in the image; skip gracefully with a marked-skipped note when it is not).

Previous task (done 2026-09-25): TASK-103 + Phase 15 (backend & UI) — see accomplish.md. TASK-104a (version-compare/history UI pass) remains queued alongside Phase 16.

Cross-cutting work since (does not change the queue): the phases 5-15 acceptance pass (2026-09-26) and the parcel data-entry UI pass (2026-09-26) — see the AUDIT 2 / AUDIT 3 entries in accomplish.md. Current stack state: backend `495 tests / 2 159 assertions`; frontend `104 tests / 17 files`; full Playwright 40/40. Open UI items for whoever picks up TASK-104a or the form pass are `U-8`/`U-11`/`U-12`/`U-13` in todo.md.
