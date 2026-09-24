**TASK-103 — Workflow UI, reviewer inbox, notifications**
Dep: 101, 071 · Files: `frontend/src/features/parcels/` · Status: TODO
Do: action bar with permitted transitions only, reason/comment prompts, reviewer inbox, notification bell.
AC: unavailable transitions are absent; a return requires a reason before the request is sent.
Test: Playwright two-role approval flow.
Entry criteria: TASK-100/101 (engine + transitions API), TASK-102 (approved-edit cycle), and Phase 14 TASK-104..108 are DONE. Next: TASK-103 (workflow UI + reviewer inbox + notification bell), then the version-compare/history UI pass (version-compare + geometry-diff map overlay + timeline rendering, deferred from TASK-105).

Backend API surface for TASK-103 (all shipped and tested):
- `GET /parcels/{id}/transitions` — available actions annotated with `allowed` for the caller (unavailable actions are absent, FR-103).
- `POST /parcels/{id}/transitions` — `{ action, reason, comment }`; REOPEN (approved-edit) is a seeded row appearing for APPROVED parcels with `parcel.approve`.
- `GET /parcels/{id}/transitions/history` — approval actions, newest first.
- `GET /notifications` surface: engine writes `app.notifications` (type `WORKFLOW_<ACTION>`) to the parcel creator on every transition; a read/unread bell endpoint may still be needed (check `notifications` routes before assuming).
- `requires_reason` / `requires_comment` per action drive the reason prompt (FR-137).

Completed (2026-09-24): TASK-100/101 (workflow engine + transitions API); TASK-102 approved-edit cycle (REOPEN transition, approved-version preservation, configured target state via WORKFLOW_APPROVED_EDIT_TARGET_STATE); TASK-104 merged history timeline; TASK-105 version compare + geometry diff (backend); TASK-106 restore verified; TASK-107 document upload/validation/de-dup; TASK-108 signed single-use downloads with classification enforcement. Full suite: 420 backend tests green.
