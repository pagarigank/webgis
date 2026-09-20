**TASK-060 — Client-side geometry validation and server reconciliation**
Dep: 059, 057 · Files: `frontend/src/lib/geometry.ts` · Status: TODO
Entry Criteria: TASK-059 DONE (undo/redo stack, keyboard shortcuts, unsaved-changes guard).
Note: TASK-059 built undo/redo in DrawManager.ts + keyboard shortcuts + hasUnsavedChanges in MapContext. The next step is client-side geometry validation: self-intersection, minimum vertices, ring closure checks for instant feedback before sending to server; server verdict always wins and is displayed.
Frontend: frontend/src/lib/geometry.ts — pure functions for geometry validation (isClosedRing, hasSelfIntersection, hasMinVertices, validateGeometry). Called before saveNew/saveUpdate to give instant feedback; server-side GEOMETRY_INVALID/GEOMETRY_NOT_SIMPLE errors still shown.
Test: Unit/GeometryChecksTest, Playwright invalid-geometry case.
