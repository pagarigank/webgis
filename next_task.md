**TASK-102 — Editing approved records**
Dep: 101, 069 · Files: `backend/src/Parcels/` · Status: TODO
Do: editing an APPROVED parcel creates a new version and returns it to the configured state; the approved version stays intact.
AC: the previously approved version remains retrievable and unchanged.
Test: Api/ApprovedEditTest.
Entry criteria: TASK-100/101 (workflow engine + transitions API) and Phase 14 TASK-104..108 are DONE. Next: TASK-102 (approved-edit cycle), then TASK-103 (workflow UI + reviewer inbox), then the version-compare/history UI pass.

Completed (2026-09-24): TASK-100/101 (workflow engine + transitions API); TASK-104 merged history timeline; TASK-105 version compare + geometry diff (backend); TASK-106 restore verified; TASK-107 document upload/validation/de-dup; TASK-108 signed single-use downloads with classification enforcement. Full suite: 415 backend tests green.
