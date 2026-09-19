# TASK.md

**Active implementation queue.** `todo.md` holds the full roadmap; this file holds what is being worked on now, in order, with live status.

**Current phase:** PHASE 0 — Planning
**Implementation status:** NOT STARTED — blocked pending sponsor approval (TASK-004)
**Last updated:** 2026-09-20

---

## Status vocabulary

`TODO` not started · `IN_PROGRESS` being worked · `BLOCKED` stopped, reason recorded · `REVIEW` done, awaiting check · `DONE` meets the definition of done in `todo.md`

---

## Queue

| # | Task | Title | Dep | Status | Notes |
|---|---|---|---|---|---|
| 1 | TASK-001 | Produce the eight planning documents | — | DONE | v0.2 set produced |
| 2 | TASK-002 | Cross-check documents for contradictions | 001 | DONE | report below |
| 3 | TASK-003 | Resolve open decisions D-01…D-19 | 002 | DONE | Sponsor skipped formal approval, accepted defaults |
| 4 | TASK-004 | Sponsor approval of the planning set | 003 | DONE | Sponsor requested to start Phase 1 |
| 5 | TASK-005 | Repository skeleton and Git hygiene | 004 | DONE | first implementation task |
| 6 | TASK-006 | `.env.example` and configuration loader | 005 | DONE | |
| 7 | TASK-007 | Docker Compose stack | 005 | DONE | |
| 8 | TASK-008 | Backend bootstrap (Slim 4 + DI + pipeline) | 006,007 | DONE | |
| 9 | TASK-009 | Migration tooling and DB connection | 008 | DONE | |
| 10 | TASK-010 | Frontend bootstrap | 005 | DONE | can run parallel to 006–009 |
| 11 | TASK-011 | Static analysis, lint, format | 008,010 | DONE | |
| 12 | TASK-012 | CI pipeline | 011 | DONE | closes M1 entry criteria |
| 13 | TASK-013 | Extensions, schemas, database roles | 009 | DONE | Start of Phase 2 |
| 14 | TASK-014 | `ref` schema and CRS registry | 013 | DONE | |
| 15 | TASK-015 | PSGC reference data load | 014 | DONE | |
| 16 | TASK-016 | Identity and access tables | 013 | DONE | |
| 17 | TASK-017 | GIS core tables | 016 | TODO | |
| 18 | TASK-018 | Survey tables | 017 | TODO | |
| 19 | TASK-019 | Parcel, lineage, title tables | 018 | TODO | |
| 20 | TASK-020 | Document and workflow tables | 019 | TODO | |
| 21 | TASK-021 | Database seeders | 020 | TODO | |
| 22 | TASK-022 | Migration testing and rollback | 021 | TODO | closes M2 |

Nothing below TASK-022 is queued yet. The queue is extended one milestone at a time so it reflects reality rather than intention; the full ordered plan is in `todo.md`.

---

## Blocked items

| Task | Blocked since | Error / reason | Attempts | Suspected cause | Recommended next action |
|---|---|---|---|---|---|
| TASK-003 | 2026-09-20 | Requires sponsor input on D-01…D-19 | n/a | external dependency | Put the decision list to the sponsor; accept the stated defaults for any unanswered item and record the acceptance |

---

## Cross-check report (TASK-002)

Contradictions found between the v0.1 documents and the revised master prompt, and how each was resolved:

| # | Contradiction | Resolution | Documents changed |
|---|---|---|---|
| 1 | Error format: RFC 7807 vs the prescribed `{success, error:{code,message,details}}` envelope | Adopted the prescribed envelope; added a closed error-code set | ADR-14, `specification.md` §6.1, `api.md` §1 |
| 2 | HTTP client: bare `fetch` wrapper vs "Axios or equivalent" | Adopted Axios under TanStack Query | ADR-13, `frontend.md` §1, §12 |
| 3 | Backend layout: layer-first `src/Http|Application|Domain` vs prescribed module folders | Module-first with uniform internal layering; survey library canonical path `backend/src/Survey/Domain` | `architecture.md` §2, §5.1, §21 |
| 4 | `tie_points` listed as a table while v0.1 merged tie points into control points | `survey_control_points` is the registry; `tie_points` records per-description usage with as-used coordinates | ADR-17, `database.md` §6.1 |
| 5 | `parcel_courses` and `technical_description_courses` both listed | `parcel_courses` is a view over the course table | ADR-18, `database.md` §6 |
| 6 | Parcel status set lacked `SUPERSEDED` | Added; set only by the lineage engine, terminal | FR-135/135a, `database.md` §7, `architecture.md` §18 |
| 7 | Provenance set lacked `CAD_IMPORT` and `MANUAL_DRAWING` | Added to the enumeration and to the UI badge set | `database.md` §7, `specification.md` §3.21 |
| 8 | Field types lacked currency, reference, user, document | Added with storage and resolution rules | FR-026/026a/026b, `database.md` §5 |
| 9 | Split, consolidation, and lineage absent from v0.1 | New architecture §18, requirements §3.22–3.24, rules VR-35…VR-47, API §8.2–8.4, tasks 111–120, UI §21 | all |
| 10 | Basemaps were configuration; the prompt requires a provider manager with licensing | New `basemap_providers` table, licence constraints, tile proxy, admin UI | `architecture.md` §16, FR-240–247, `database.md` §8 |
| 11 | CAD-Earth workflow and imagery-licensing prohibitions absent | New architecture §17 stating the prohibitions as absent capability; DXF import requirements | `architecture.md` §17, FR-250–255 |
| 12 | Reports absent | New requirements §3.27, tasks 132–134 | `specification.md`, `todo.md` |
| 13 | Observability, transactions, and retry protocol under-specified | New architecture §19–20, FR-280–284, retry protocol in `PLANNING.md` §6 | multiple |

**Consistency checks passed:** status vocabularies match across `specification.md`, `database.md`, `api.md`, and `frontend.md`; permission codes match between `specification.md` §3.3, `api.md`, and the seed list in `todo.md` TASK-023; every `VR-*` referenced in `api.md` exists in `specification.md` §5; every table referenced in `api.md` and `architecture.md` exists in `database.md`; every phase in `todo.md` has at least one acceptance criterion in `specification.md` §11; the CRS policy (store 4326, compute in PRS92 zone, never transform silently) is stated identically in all four technical documents.

**Known gaps accepted for now:** curve courses, traverse adjustment, geodetic-to-grid convergence, and NTv2 grids are designed for but deferred (`PLANNING.md` §2.3); multi-tenancy is explicitly not designed in.

---

## Accomplishment log

Maintained in `accomplish.md` once implementation begins. Each completed task records: what shipped, decisions made, approaches that failed and why, and follow-up items. `next_task.md` always names the single next task and its entry criteria.

---

## Working rules

1. One task at a time, taken in queue order. No task starts before its dependencies are `DONE`.
2. Read `architecture.md` before structural work, `database.md` before migrations, `api.md` before endpoints, `frontend.md` before UI.
3. Do not skip dependencies. Do not redesign without an ADR. Do not duplicate an existing component or service.
4. Survey calculations never enter UI components and stay independently unit-testable.
5. Validate geometry and permissions server-side, always.
6. Tests ship with the feature; run them after every meaningful change; fix failures before moving on.
7. Maximum 3 retries on a failing test, then `BLOCKED` with the full record.
8. Update `TASK.md`, `accomplish.md`, and `next_task.md` before committing.
9. Never commit `.env`, secrets, keys, credentials, or build artefacts.
