**TASK-102 — Editing approved records**
Dep: 101, 069 · Files: `backend/src/Parcels/` · Status: TODO
Do: editing an APPROVED parcel creates a new version and returns it to the configured state; the approved version stays intact.
AC: the previously approved version remains retrievable and unchanged.
Test: Api/ApprovedEditTest.
Entry criteria: TASK-100/101 (workflow engine + transitions API) are DONE. The workflow engine and its transition matrix now own all parcel status changes; TASK-102 extends the editor + engine for the approved-edit cycle.

Completed just now (2026-09-24): TASK-100 (WorkflowEngine, FR-135 matrix seeded into app.workflow_transitions, guards, notifications) and TASK-101 (transitions API, approval re-validation, accepted-computation recording). Full suite: 390 backend tests green.
