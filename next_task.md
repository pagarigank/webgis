# Next Task

**TASK-017 — GIS core tables**

Entry criteria:
- TASK-016 is DONE.

To do:
- Create `gis_layers`, `gis_layer_fields`, `gis_layer_styles`, `gis_features`.
- Setup triggers for validation and geometry type constraints.
- Create `audit.gis_feature_versions` table and versioning triggers on `gis_features`.
- Write `Integration/GisCoreTest` to verify that invalid geometry is rejected by the trigger and versions are automatically captured.
