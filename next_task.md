# Next Task

**Status: TASK-043 is implemented; queue is ready for TASK-044.**

## TASK-044 — Retype cast logic (field conversion)

- **Dependencies:** 042 (DONE)
- **Status:** TODO
- **Do:** safely upcast/downcast existing values when a layer administrator changes `field_type` in `gis_layer_fields`.
- **AC:** Conversion follows safe upcasting/downcasting rules.

## Verification checklist before marking done
- [ ] Implement casting logic to convert values based on the new `field_type`.
- [ ] Write and pass unit tests for the conversion logic.