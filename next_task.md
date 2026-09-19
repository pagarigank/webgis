# Next Task

**TASK-016 — Identity and access tables**

Entry criteria:
- TASK-015 is DONE.

To do:
- Create `organizations`, `permissions`, `role_permissions`, `data_scopes`, and `refresh_tokens`. (Note: `users`, `roles`, and `user_roles` were already bootstrapped in TASK-013, so just need to add any missing structures).
- Add constraints and indexes (e.g. scope target check).
- Create `Integration/SchemaIdentityTest`.
