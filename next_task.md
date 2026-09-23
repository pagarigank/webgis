**TASK-078 — Bearing value objects and parsing (pure domain)**
Dep: 014 · Files: `backend/src/Survey/Domain/Bearing.php`, `backend/src/Survey/Domain/Azimuth.php`, `backend/tests/Unit/BearingTest.php` · Status: TODO
Do: `Bearing`, `Azimuth`; parse quadrant DMS, quadrant decimal, azimuth DMS/decimal, cardinal; normalise to azimuth; keep the original string untouched.
AC: quadrant↔azimuth conversion exact to 1e-9 in all four quadrants and at boundaries; ambiguous 0°/90° rejected (VR-07); round-trip stable.
Test: Unit/BearingTest — known-answer vectors, malformed inputs, boundary cases. Written first.
Entry criteria: Phase 9 (Control points and survey plans, tasks 073–077b) is DONE.
