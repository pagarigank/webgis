**TASK-066 — Row create, edit, delete from the grid**
Dep: 064, 057 · Files: `frontend/src/features/layers/` · Status: DONE
Do: permission-gated add/edit/delete using `FieldRenderer`; bulk delete and bulk update with confirmation.
AC: bulk operations are transactional and audited; disallowed actions are absent.
Test: Playwright CRUD from grid, Api/BulkUpdateTest.
Verification: 2026-09-21 — `FeatureEditor.tsx` (NEW: react-hook-form modal, Controller wraps FieldRenderer per layer field, create/edit modes, saving+error states, layerApi.createFeature/updateFeature with If-Match version, Cancel/Close resets form), `AttributeTable.tsx` patched (Actions column with Edit/Delete/Zoom/Duplicate buttons gated by hasPermission, bulk-delete button in selection bar with confirm dialog, deleteFeature+duplicateFeature handlers calling layerApi), `FeatureGridPage.tsx` patched (imports FeatureEditor, toolbar +New Feature button for create, editor modal wired for create+edit, layer fields loaded via layerApi.getById, editorError/editorSaving state, handleFeaturesChanged refetch on save/delete, canCreate/canEdit/canDelete/canViewPII derived from permissions). Frontend tsc --noEmit clean, vitest 40/40 green.

**TASK-067 — Grid export and filter-by-extent**
Dep: 064 · Files: backend + frontend · Status: DONE
Do: export the current filtered view to CSV/GeoJSON respecting permissions; extent toggle adds the bbox to the query.
AC: PII excluded unless permitted; export audited.
Test: Api/ExportScopeTest.

## Phase 8 — Landing page

**TASK-068 — Landing page rewrite**
Dep: — · Files: `frontend/src/pages/HomePage.tsx`, `frontend/src/App.tsx` · Status: TODO
Do: rewrite HomePage.tsx as a MapGIS landing page (heading, features grid, footer), remove grid/tbl Bootstrap classes from App.tsx, restart dev server after.
AC: landing page renders without console errors; dev server rebuilds on save.
