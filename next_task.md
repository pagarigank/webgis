**TASK-096 — Survey validation service**
Dep: 090, 073 · Files: `backend/src/Survey/Application/` · Status: TODO
Do: the full checklist — TD parsed and confirmed, tie point found, tie point verified, CRS identified, bearings valid, distances valid, polygon closed, geometry valid, area computed, area vs source, overlap with existing parcels, minimum vertices — each pass/warn/fail with a rule id.
AC: every check in `specification.md` FR-125 present; results persisted with the computation.
Test: Api/ValidationTest (one case triggering each check).
Entry criteria: Phase 11 (Computation engine, tasks 087–095) is DONE.
