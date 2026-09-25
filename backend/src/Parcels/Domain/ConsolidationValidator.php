<?php
declare(strict_types=1);

namespace App\Parcels\Domain;

use PDO;

/**
 * TASK-113 — Consolidation validation, VR-40…VR-44 (specification.md §5.4)
 * per architecture.md §18.4.
 *
 * Read-only, nothing repaired or auto-corrected: every rule is evaluated
 * independently so CONSOLIDATION_INVALID can enumerate all failures, and the
 * identical code path serves the dry-run preview and the commit.
 *
 * VR-40  ≥ 2 distinct parents, none SUPERSEDED/ARCHIVED  (service-enforced;
 *        the validator re-checks geometry-independent facets for safety)
 * VR-41  parents do not overlap (intersection area ≤ ε) — blocking
 * VR-42  gaps/slivers between parents ≤ ε — blocking
 * VR-43  union is a single contiguous polygon unless multipart is allowed
 * VR-44  all inputs share one SRID
 */
final class ConsolidationValidator
{
    /** VR-41 — parent intersection area above this is an overlap (m²). */
    public const OVERLAP_EPSILON_SQM = 0.01;

    /** VR-42 — gap/sliver area above this blocks consolidation (m²). */
    public const GAP_EPSILON_SQM = 0.05;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param string[] $parentGeoms Parent geometries (GeoJSON, WKT or EWKT)
     * @return array{
     *   passed: bool,
     *   checks: list<array{rule: string, status: string, message: string}>,
     *   warnings: list<array{rule: string, message: string}>,
     *   parents: list<array{index: int, area_sqm: float, parse_failed: bool, is_empty: bool, is_valid: bool, srid: int|null}>,
     *   pairwise_overlaps: list<array{i: int, j: int, overlap_area_sqm: float}>,
     *   union_geometry_type: string|null,
     *   union_part_count: int|null,
     *   union_area_sqm: float|null
     * }
     */
    public function validateConsolidation(array $parentGeoms, bool $allowMultipart = false): array
    {
        $parentGeoms = array_values($parentGeoms);
        $checks = [];
        $warnings = [];

        if (count($parentGeoms) < 2) {
            // The input cannot describe a consolidation; report the minimum.
            $checks[] = ['rule' => 'VR-40', 'status' => 'fail', 'message' => sprintf('Consolidation requires at least 2 distinct parents, got %d.', count($parentGeoms))];
            return $this->fail($checks, $warnings, count($parentGeoms));
        }

        // Per-parent probe — individual round trips, because a statement
        // error inside a transaction aborts it (PostgreSQL), so the combined
        // query may only run over known-parseable, single-SRID input.
        $parents = [];
        $sridGroups = [];
        foreach ($parentGeoms as $i => $g) {
            $p = $this->probe((string) $g);
            $p['idx'] = $i;
            $parents[] = $p;
            if (!$p['parse_failed']) {
                $sridGroups[(int) $p['srid']][] = $i;
            }
        }

        // ----- VR-40 (geometry facets): valid, non-empty inputs --------------
        $bad = [];
        foreach ($parents as $p) {
            $i = (int) $p['idx'];
            if ($p['parse_failed']) {
                $bad[] = sprintf('parent #%d could not be parsed', $i);
            } elseif ($p['is_empty']) {
                $bad[] = sprintf('parent #%d is empty', $i);
            } elseif (!$p['is_valid']) {
                $bad[] = sprintf('parent #%d fails ST_IsValid', $i);
            }
        }
        $checks[] = $bad === []
            ? ['rule' => 'VR-40', 'status' => 'pass', 'message' => sprintf('%d parent geometries, all valid and non-empty.', count($parents))]
            : ['rule' => 'VR-40', 'status' => 'fail', 'message' => 'Invalid parents: ' . implode('; ', $bad) . '.'];

        // ----- VR-44: one SRID across all inputs ------------------------------
        $checks[] = count($sridGroups) <= 1
            ? ['rule' => 'VR-44', 'status' => 'pass', 'message' => $sridGroups === [] ? 'No resolvable SRID.' : sprintf('All parents share SRID %d.', array_key_first($sridGroups))]
            : ['rule' => 'VR-44', 'status' => 'fail', 'message' => 'SRID mismatch: ' . implode('; ', array_map(
                fn (int $srid, array $idxs): string => sprintf('SRID %d on %s', $srid, implode(', ', array_map(fn (int $i): string => '#' . $i, $idxs))),
                array_keys($sridGroups),
                array_values($sridGroups)
            )) . '.'];

        // Mixed SRIDs are never silently transformed: the spatial rules below
        // are not evaluable cross-SRID and are reported, not guessed.
        if (count($sridGroups) > 1) {
            foreach (['VR-41', 'VR-42', 'VR-43'] as $rule) {
                $checks[] = ['rule' => $rule, 'status' => 'fail', 'message' => 'Not evaluated: parents do not share one SRID (VR-44).'];
            }
            return $this->result($checks, $warnings, $parents, [], null, null, null, null);
        }

        try {
            $m = $this->measureOverlaps($parentGeoms);
        } catch (\PDOException $e) {
            // All inputs probed parseable and single-SRID, yet the combined
            // query failed — report the DB error verbatim and block.
            foreach (['VR-41', 'VR-42', 'VR-43'] as $rule) {
                $checks[] = ['rule' => $rule, 'status' => 'fail', 'message' => 'Geometry could not be evaluated: ' . $e->getMessage()];
            }
            return $this->result($checks, $warnings, $parents, [], null, null, null, null);
        }

        // ----- VR-41: no overlaps (blocking) ---------------------------------
        $overlapping = array_values(array_filter($m['overlaps'], fn (array $o): bool => (float) $o['overlap_area_sqm'] > self::OVERLAP_EPSILON_SQM));
        $checks[] = $overlapping === []
            ? ['rule' => 'VR-41', 'status' => 'pass', 'message' => 'No overlap between parents.']
            : ['rule' => 'VR-41', 'status' => 'fail', 'message' => 'Parents overlap: ' . implode('; ', array_map(
                fn (array $o): string => sprintf('#%d × #%d by %.4f m² (ε %.2f m²)', (int) $o['i'], (int) $o['j'], (float) $o['overlap_area_sqm'], self::OVERLAP_EPSILON_SQM),
                $overlapping
            )) . '.'];

        // ----- VR-42: gap/sliver detection (blocking) -------------------------
        // Metric: the parents' convex hull vs Σ parent areas. (The union's
        // area always equals the parent sum for disjoint parents — gaps show
        // up as hollows inside the hull, not as a smaller union.)
        $sumAreas = 0.0;
        foreach ($parents as $p) {
            if (!$p['parse_failed']) {
                $sumAreas += (float) $p['area_sqm'];
            }
        }
        $hullArea = $m['hull_area_sqm'];
        $gapArea = $hullArea !== null ? max(0.0, $hullArea - $sumAreas) : null;
        if ($gapArea === null) {
            $checks[] = ['rule' => 'VR-42', 'status' => 'fail', 'message' => 'Gap could not be evaluated (no valid parent geometry).'];
        } elseif ($gapArea > self::GAP_EPSILON_SQM) {
            $checks[] = ['rule' => 'VR-42', 'status' => 'fail', 'message' => sprintf('Gap between parents of %.4f m² (ε %.2f m²).', $gapArea, self::GAP_EPSILON_SQM)];
        } else {
            $checks[] = ['rule' => 'VR-42', 'status' => 'pass', 'message' => sprintf('No gaps above ε (Σ parents %.4f m² vs hull %.4f m²).', $sumAreas, $hullArea)];
        }

        // ----- VR-43: contiguity unless multipart is allowed ------------------
        $partCount = $m['union_part_count'];
        if ($partCount === null) {
            $checks[] = ['rule' => 'VR-43', 'status' => 'fail', 'message' => 'Union could not be evaluated.'];
        } elseif ($partCount > 1 && !$allowMultipart) {
            $checks[] = ['rule' => 'VR-43', 'status' => 'fail', 'message' => sprintf('Union is a %d-part multipart polygon; multipart is not allowed for this operation.', $partCount)];
        } else {
            $checks[] = ['rule' => 'VR-43', 'status' => 'pass', 'message' => $partCount === 1
                ? 'Union is a single contiguous polygon.'
                : sprintf('Union is a %d-part multipart polygon (multipart allowed).', $partCount)];
        }

        return $this->result($checks, $warnings, $parents, $m['overlaps'], $m['union_geometry_type'], $partCount, $m['union_area_sqm'], $hullArea);
    }

    /**
     * Normalize the final payload; $hullArea feeds the VR-42 metric.
     */
    private function result(array $checks, array $warnings, array $parents, array $overlaps, ?string $unionType, ?int $partCount, ?float $unionArea, ?float $hullArea): array
    {
        $parentsOut = array_map(static fn (array $p): array => [
            'index' => (int) $p['idx'],
            'area_sqm' => round((float) $p['area_sqm'], 4),
            'parse_failed' => (bool) $p['parse_failed'],
            'is_empty' => (bool) $p['is_empty'],
            'is_valid' => (bool) $p['is_valid'],
            'srid' => (int) $p['srid'],
        ], $parents);

        return [
            'passed' => !in_array('fail', array_column($checks, 'status'), true),
            'checks' => $checks,
            'warnings' => $warnings,
            'parents' => $parentsOut,
            'pairwise_overlaps' => array_map(static fn (array $o): array => [
                'i' => (int) $o['i'],
                'j' => (int) $o['j'],
                'overlap_area_sqm' => round((float) $o['overlap_area_sqm'], 4),
            ], $overlaps),
            'union_geometry_type' => $unionType,
            'union_part_count' => $partCount,
            'union_area_sqm' => $unionArea !== null ? round($unionArea, 4) : null,
            'hull_area_sqm' => $hullArea !== null ? round($hullArea, 4) : null,
        ];
    }

    /**
     * Measure one parent in isolation: parse, emptiness, validity, SRID and
     * geodesic area. One round trip per parent — a parse failure here cannot
     * abort a statement covering the other parents.
     *
     * @return array{idx: int, parse_failed: bool, is_empty: bool, is_valid: bool, srid: int|null, area_sqm: float}
     */
    private function probe(string $geom): array
    {
        $empty = ['idx' => 0, 'parse_failed' => true, 'is_empty' => false, 'is_valid' => false, 'srid' => null, 'area_sqm' => 0.0];
        if (trim($geom) === '') {
            return $empty;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT g IS NULL AS parse_failed,
                        COALESCE(ST_IsEmpty(g), false) AS is_empty,
                        COALESCE(ST_IsValid(g), false) AS is_valid,
                        COALESCE(ST_SRID(g), 0) AS srid,
                        CASE WHEN g IS NOT NULL AND NOT ST_IsEmpty(g) AND ST_IsValid(g) AND ST_SRID(g) = 4326
                             THEN ST_Area(g::geography) ELSE 0::double precision END AS area_sqm
                   FROM (SELECT ' . $this->geomExpr(':g', $geom) . ' AS g) s'
            );
            $stmt->execute([':g' => $geom]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return $empty;
            }
            return [
                'idx' => 0,
                'parse_failed' => (bool) $row['parse_failed'],
                'is_empty' => (bool) $row['is_empty'],
                'is_valid' => (bool) $row['is_valid'],
                'srid' => (int) $row['srid'],
                'area_sqm' => (float) $row['area_sqm'],
            ];
        } catch (\PDOException) {
            return $empty;
        }
    }

    /**
     * Pairwise overlaps, union part count/area and the parents' convex-hull
     * area in one round trip over the usable (valid, non-empty) parents.
     * Callers probe first and gate on VR-44: a statement error here aborts
     * the ambient transaction, so the combined query may only run over
     * known-parseable, single-SRID input (TASK-113).
     *
     * @param string[] $parentGeoms
     * @return array{overlaps: list<array<string,mixed>>, union_geometry_type: string|null, union_part_count: int|null, union_area_sqm: float|null, hull_area_sqm: float|null}
     */
    private function measureOverlaps(array $parentGeoms): array
    {
        $params = [];
        $selects = [];
        foreach ($parentGeoms as $i => $g) {
            $ph = ':p' . $i;
            $params[$ph] = (string) $g;
            $selects[] = sprintf('SELECT %d::int AS idx, %s AS g', $i, $this->geomExpr($ph, (string) $g));
        }

        $sql = '
            WITH parents AS (
                ' . implode("\n                UNION ALL ", $selects) . '
            ),
            usable AS (
                SELECT idx, g FROM parents
                WHERE g IS NOT NULL AND NOT ST_IsEmpty(g) AND ST_IsValid(g)
            ),
            u AS (
                SELECT ST_UnaryUnion(ST_Collect(g)) AS g FROM usable
            ),
            pairwise AS (
                SELECT a.idx AS i, b.idx AS j,
                       ST_Area(ST_Intersection(a.g, b.g)::geography) AS overlap_area_sqm
                FROM usable a
                JOIN usable b ON a.idx < b.idx
                WHERE ST_Intersects(a.g, b.g)
            )
            SELECT
                (SELECT COALESCE(json_agg(row_to_json(x))::text, \'[]\') FROM (SELECT * FROM pairwise ORDER BY i, j) x) AS overlaps,
                (SELECT ST_GeometryType(g) FROM u WHERE g IS NOT NULL) AS union_type,
                (SELECT ST_NumGeometries(g) FROM u WHERE g IS NOT NULL) AS union_parts,
                (SELECT ST_Area(g::geography) FROM u WHERE g IS NOT NULL) AS union_area,
                (SELECT ST_Area(ST_ConvexHull(ST_Collect(g))::geography) FROM usable) AS hull_area
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'overlaps' => isset($row['overlaps']) ? (array) json_decode((string) $row['overlaps'], true) : [],
            'union_geometry_type' => $row['union_type'] ?? null,
            'union_part_count' => isset($row['union_parts']) && $row['union_parts'] !== null ? (int) $row['union_parts'] : null,
            'union_area_sqm' => isset($row['union_area']) && $row['union_area'] !== null ? (float) $row['union_area'] : null,
            'hull_area_sqm' => isset($row['hull_area']) && $row['hull_area'] !== null ? (float) $row['hull_area'] : null,
        ];
    }

    /** Mirrors SplitValidator::geomExpr — kept local so the classes stay standalone. */
    private function geomExpr(string $placeholder, string $geom): string
    {
        $trimmed = trim($geom);
        if (str_starts_with($trimmed, '{')) {
            return "ST_SetSRID(ST_GeomFromGeoJSON({$placeholder}), 4326)";
        }
        if (str_starts_with($trimmed, 'SRID=')) {
            return "ST_GeomFromEWKT({$placeholder})";
        }
        return "ST_SetSRID(ST_GeomFromText({$placeholder}), 4326)";
    }

    /** Minimum-shaped failure result. */
    private function fail(array $checks, array $warnings, int $count): array
    {
        return [
            'passed' => false,
            'checks' => $checks,
            'warnings' => $warnings,
            'parents' => array_map(static fn (int $i): array => [
                'index' => $i, 'area_sqm' => 0.0, 'parse_failed' => true, 'is_empty' => false, 'is_valid' => false, 'srid' => null,
            ], array_keys(array_fill(0, $count, null))),
            'pairwise_overlaps' => [],
            'union_geometry_type' => null,
            'union_part_count' => null,
            'union_area_sqm' => null,
            'hull_area_sqm' => null,
        ];
    }
}
