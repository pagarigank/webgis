**TASK-100 — Workflow engine**
Dep: 020, 030 · Files: `backend/src/Parcels/Workflow/` · Status: TODO
Do: table-driven state machine with permission checks, guards, mandatory reasons, history, notifications.
AC: an illegal transition is rejected server-side regardless of the request; guards evaluate computation and validation state.
Test: Unit/StateMachineTest, Api/TransitionPermissionTest.
Entry criteria: Phase 12 (Validation, tasks 096–099) is DONE.
