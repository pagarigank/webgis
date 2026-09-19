---
name: agent-initialization
description: >-
  Essential workflow rules and initialization steps for the agent.
  Use this to understand what to do first before starting any coding task,
  how to pick tasks from the queue, and what documents to read.
---

# Agent Initialization & Workflow Rules

Before starting any implementation, coding task, or doing anything else in this project, you MUST follow these steps to ensure alignment with the project's planning and architecture documents.

## 1. Check the Active Queue (`TASK.md`)
- The `TASK.md` file contains the active implementation queue. 
- You must pick **one task at a time**, strictly in queue order.
- Check the `Status` and `Dep` (dependencies) columns. No task starts before its dependencies are `DONE`.
- Never start work on a task if it is `BLOCKED` or if its dependencies are incomplete.

## 2. Read Required Documentation
Before making changes to specific domains, you must read the authoritative documentation for that domain:
- **Structural work, System Design, or ADRs:** Read `architecture.md`
- **Database migrations, Tables, or ERD:** Read `database.md`
- **Backend endpoints, API schemas, or errors:** Read `api.md`
- **User Interface, Components, or State:** Read `frontend.md`
- **Scope, Rules, or Non-functional reqs:** Read `specification.md`
- **Roadmap & Definition of Done:** Read `todo.md`

## 3. Strict Rules of Engagement
- **Do not skip dependencies.**
- **Do not redesign without an ADR.** (Architecture Decision Record in `architecture.md`). If a redesign is needed, amend `architecture.md` §14 first, then dependent documents.
- **Do not duplicate an existing component or service.** Always check for existing implementations.
- **Pure Survey Logic:** Survey calculations never enter UI components and must stay independently unit-testable.
- **Server-Side Validation:** Validate geometry and permissions server-side, always. Never rely purely on the frontend.
- **Testing:** Tests ship with the feature; run them after every meaningful change; fix failures before moving on. Maximum 3 retries on a failing test, then mark as `BLOCKED` with the full record.

## 4. Accomplishment Tracking
After completing a task and before committing your changes, you MUST:
- Update `accomplish.md` with what shipped, decisions made, and failed approaches.
- Set `next_task.md` to name the single next task and its entry criteria.
- Update `TASK.md` status to `DONE` (or `BLOCKED` / `REVIEW` as appropriate).
- Update the status for the task in `todo.md` to match the current status.
- Ensure you never commit `.env`, secrets, keys, credentials, or build artefacts.
