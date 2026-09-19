# Next Task

**TASK-018 — Survey tables**

Entry criteria:
- TASK-017 is DONE.

To do:
- Create `survey_plans`, `survey_control_points`, `tie_points`.
- Create `technical_descriptions`, `technical_description_courses`, `tie_lines`.
- Ensure constraints (`ck_tie_source`), defaults, and unique indexes are applied based on `database.md` §6.
- Create view `app.parcel_courses`.
- Write Integration test for the `survey_control_points` unique constraints and relationships.
