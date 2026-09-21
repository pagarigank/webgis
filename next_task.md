**TASK-068 — Landing page rewrite**
Dep: — · Files: `frontend/src/pages/HomePage.tsx`, `frontend/src/App.tsx` · Status: DONE
Do: rewrite HomePage.tsx as a MapGIS landing page (heading, features grid, footer), remove grid/tbl Bootstrap classes from App.tsx, restart dev server after.
AC: landing page renders without console errors; dev server rebuilds on save.
Verification: 2026-09-21 — `HomePage.tsx` rewritten (MapGIS branded header, 4-up feature cards grid, quick-start CTA, footer; no Bootstrap classes — pure inline styles + CSS grid). `App.tsx` verified clean (custom app-container/app-header/app-nav/app-main classes only; no Bootstrap row/col/table/btn-* classes remain). Frontend tsc --noEmit clean (exit 0), vitest 40/40 green (exit 0). Docker daemon DOWN — local-only verification.
