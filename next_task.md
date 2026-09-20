**TASK-059 — Vertex editing, move, delete, undo/redo**
Dep: 058, 049 · Files: `frontend/src/features/map/` · Status: TODO
Entry Criteria: TASK-058 DONE (draw tools save flow wired with DrawManager.ts, If-Match on update, VERSION_CONFLICT retry).
Note: TASK-058 built DrawManager.ts with MapboxDraw saveNew/saveUpdate/clearDraw, MapContext integration, layerApi updateFeature with If-Match. The next step is vertex-level editing: Move, Delete, and bounded undo/redo stack for drawn/edited features, with an unsaved-changes guard before navigation.
Frontend: MapboxDraw has built-in vertex editing (direct_select mode supports vertex drag + delete); TASK-059 needs to wrap that into our DrawManager with an undo/redo stack (capture geometry before each mutation, restore on undo), and an unsaved-changes prompt when navigating away with pending edits.
Test: Playwright edit flow, Map harness undo tests.
