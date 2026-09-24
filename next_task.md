**TASK-087 — Traverse computer (pure domain)**
Dep: 078, 079 · Files: `backend/src/Survey/Domain/TraverseComputer.php`, `backend/tests/Unit/TraverseComputerTest.php` · Status: TODO
Do: tie point → tie line(s) → POB → successive courses; ΔN = D·cos(Az), ΔE = D·sin(Az) in plane coordinates.
AC: vertices match hand-computed benchmarks to 1 mm on every fixture.
Test: Unit/TraverseComputerTest (known-answer vectors). Written first.
Entry criteria: Phase 10 (Technical descriptions and parser, tasks 078–086) is DONE.
