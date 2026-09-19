# Next Task

**TASK-009 — Migration tooling and DB connection**

Entry criteria:
- TASK-008 is DONE.

To do:
- Set up Phinx (or PDO-based migrations) in `database/migrations`.
- Register the PDO connection in the DI container.
- Create the audit log table schema as the first migration.
