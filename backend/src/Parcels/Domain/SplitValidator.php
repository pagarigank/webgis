<?php
declare(strict_types=1);

namespace App\Parcels\Domain;

use PDO;

/**
 * Split validation — VR-35…VR-39 (specification.md §5.4) per the transactional
 * algorithm in architecture.md §18.3.
 *
 * The validator is read-only: it evaluates candidate child geometries against
 * the parent and reports every rule outcome. Nothing is auto-corrected — a
 * failing rule is reported with its rule id and the caller (SplitService)
 * blocks the commit. The same code path serves dry-run previews and commits
 * so validation cannot diverge between the two.
 *
 * Input contract: geometries are WKT or GeoJSON strings interpreted as
 * EPSG:4326 (the caller transforms anything else before validating).
 */
final class SplitValidator
{
    /** VR-36 — child intersection area above this is an overlap (m²). */
    public const OVERLAP_EPSILON_SQM = 0.01;

    /** VR-37 — symmetric-difference area above this is a gap/sliver (m²). */
    public const UNION_EPSILON_SQM = 0.05;

    /** VR-39 — |Σ children − parent| above this percentage is a warning. */
    public const RECONCILIATION_WARNING_PCT = 0.1;

    /** VR-38 — spec default when SYSTEM_MIN_LOT_AREA is not configured. */
    public const MIN_AREA_FALLBACK_SQM = 1.0;

    public const MIN_AREA_SETTING = 'SYSTEM_MIN_LOT_AREA';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Validate a candidate split. Computes every check independently so
     * SPLIT_INVALID can list all failures at once.
     *
     * @param string $parentGeom Parent geometry (WKT or GeoJSON, 4326)
     * @param string[] $childGeoms Candidate child polygons, in child order
     * @param float|null $minAreaSqm VR-38 override; null reads SYSTEM_MIN_LOT_AREA
     * @return array{
     *   passed: bool,
     *   checks: list<array{rule: string, status: string, message: string}>,
     *   warnings: list<array{rule: string, message: string}>,
     *   children: list<array{index: int, area_sqm: float, share_pct: float|null, geometry_type: string|null, parse_failed: bool, is_empty: bool, is_valid: bool, is_simple: bool}>,
     *   pairwise_overlaps: list<array{i: int, j: int, overlap_area_sqm: float}>,
     *   area_reconciliation: array{parent_sqm: float|null, children_sum_sqm: float, difference_sqm: float|null, difference_pct: float|null}
     * }
     */
    public function validateSplit(string $parentGeom, array $childGeoms, ?float $minAreaSqm = null): array
    {
        $minArea = $minAreaSqm ?? $this->readMinAreaSetting();
        $childGeoms = array_values($childGeoms);

        if (trim($parentGeom) === '') {
            return $this->unparsedFailure('Parent geometry is empty.');
        }

        try {
            $m = $this->measureGeometries($parentGeom, $childGeoms);
        } catch (\PDOException $e) {
            return $this->parseFailureReport($parentGeom, $childGeoms, $e);
        }

        $checks = [];
        $warnings = [];

        // ----- VR-35: ≥ 2 children, each valid, simple, non-empty ------------
        $vr35Problems = [];
        if (count($childGeoms) < 2) {
            $vr35Problems[] = sprintf('split produces %d child (at least 2 required)', count($childGeoms));
        }
        foreach ($m['children'] as $c) {
            $idx = (int) $c['idx'];
            if ($c['parse_failed']) {
                $vr35Problems[] = sprintf('child #%d could not be parsed', $idx);
            } elseif ($c['is_empty']) {
                $vr35Problems[] = sprintf('child #%d has an empty geometry', $idx);
            } elseif (!$c['is_valid']) {
                $vr35Problems[] = sprintf('child #%d fails ST_IsValid', $idx);
            } elseif (!$c['is_simple']) {
                $vr35Problems[] = sprintf('child #%d fails ST_IsSimple', $idx);
            }
        }
        $checks[] = $vr35Problems === []
            ? $this->check('VR-35', true, sprintf('%d children, all valid, simple and non-empty.', count($childGeoms)))
            : $this->check('VR-35', false, 'Invalid split geometry: ' . implode('; ', $vr35Problems) . '.');

        // ----- VR-36: children pairwise non-overlapping -----------------------
        $overlapping = array_values(array_filter(
            $m['overlaps'],
            fn (array $o): bool => (float) $o['overlap_area_sqm'] > self::OVERLAP_EPSILON_SQM
        ));
        $checks[] = $overlapping === []
            ? $this->check('VR-36', true, 'No overlap between children.')
            : $this->check('VR-36', false, 'Children overlap: ' . implode('; ', array_map(
                fn (array $o): string => sprintf(
                    '#%d × #%d by %.4f m² (ε %.2f m²)',
                    (int) $o['i'],
                    (int) $o['j'],
                    (float) $o['overlap_area_sqm'],
                    self::OVERLAP_EPSILON_SQM
                ),
                $overlapping
            )) . '.');

        // ----- VR-37: union of children matches the parent --------------------
        $symdiff = $m['symdiff_sqm'];
        if ($symdiff === null) {
            $checks[] = $this->check('VR-37', false, 'Union of children could not be evaluated (no valid child geometry, or the parent geometry is invalid).');
        } elseif ($symdiff > self::UNION_EPSILON_SQM) {
            $checks[] = $this->check(
                'VR-37',
                false,
                sprintf(
                    'Union of children differs from the parent by %.4f m² (ε %.2f m²) — gap or sliver.',
                    $symdiff,
                    self::UNION_EPSILON_SQM
                )
            );
        } else {
            $checks[] = $this->check('VR-37', true, sprintf('Union matches the parent (Δ %.4f m²).', $symdiff));
        }

        // ----- VR-38: each child area ≥ minimum -------------------------------
        $small = array_values(array_filter(
            $m['children'],
            fn (array $c): bool => !$c['parse_failed'] && (float) $c['area_sqm'] < $minArea
        ));
        $checks[] = $small === []
            ? $this->check('VR-38', true, sprintf('All children meet the minimum area of %.2f m².', $minArea))
            : $this->check('VR-38', false, 'Below minimum area: ' . implode('; ', array_map(
                fn (array $c): string => sprintf(
                    'child #%d has %.4f m² (minimum %.2f m²)',
                    (int) $c['idx'],
                    (float) $c['area_sqm'],
                    $minArea
                ),
                $small
            )) . '.');

        // ----- SRID: all geometries share the parent's SRID (§18.3) -----------
        $parentSrid = $m['parent_srid'];
        $sridMismatches = array_values(array_filter(
            $m['children'],
            fn (array $c): bool => !$c['parse_failed'] && (int) $c['srid'] !== (int) $parentSrid
        ));
        $checks[] = ($parentSrid !== null && $sridMismatches === [])
            ? $this->check('SRID_COMPAT', true, sprintf('All geometries share SRID %d.', $parentSrid))
            : $this->check(
                'SRID_COMPAT',
                false,
                $parentSrid === null
                    ? 'Parent geometry has no resolvable SRID.'
                    : 'SRID mismatch against parent SRID ' . $parentSrid . ': ' . implode('; ', array_map(
                        fn (array $c): string => sprintf('child #%d has SRID %d', (int) $c['idx'], (int) $c['srid']),
                        $sridMismatches
                    )) . '.'
            );

        // ----- VR-39: area reconciliation (reported, never forced) ------------
        $childrenSum = 0.0;
        foreach ($m['children'] as $c) {
            if (!$c['parse_failed']) {
                $childrenSum += (float) $c['area_sqm'];
            }
        }
        $parentArea = $m['parent_area_sqm'];
        $difference = $parentArea !== null ? $childrenSum - $parentArea : null;
        $differencePct = ($parentArea !== null && $parentArea > 0.0) ? ($difference / $parentArea) * 100.0 : null;

        if ($differencePct !== null && abs($differencePct) > self::RECONCILIATION_WARNING_PCT) {
            $warnings[] = [
                'rule' => 'VR-39',
                'message' => sprintf(
                    'Σ child areas differ from the parent area by %.4f%% (children %.4f m² vs parent %.4f m²).',
                    $differencePct,
                    $childrenSum,
                    $parentArea
                ),
            ];
        }

        $childrenOut = array_map(
            static fn (array $c): array => [
                'index' => (int) $c['idx'],
                'area_sqm' => round((float) $c['area_sqm'], 4),
                'share_pct' => ($parentArea !== null && $parentArea > 0.0 && !$c['parse_failed'])
                    ? round(((float) $c['area_sqm'] / $parentArea) * 100.0, 2)
                    : null,
                'geometry_type' => $c['geometry_type'],
                'parse_failed' => (bool) $c['parse_failed'],
                'is_empty' => (bool) $c['is_empty'],
                'is_valid' => (bool) $c['is_valid'],
                'is_simple' => (bool) $c['is_simple'],
            ],
            $m['children']
        );

        return [
            'passed' => !in_array('fail', array_column($checks, 'status'), true),
            'checks' => $checks,
            'warnings' => $warnings,
            'children' => $childrenOut,
            'pairwise_overlaps' => array_map(
                static fn (array $o): array => [
                    'i' => (int) $o['i'],
                    'j' => (int) $o['j'],
                    'overlap_area_sqm' => round((float) $o['overlap_area_sqm'], 4),
                ],
                $m['overlaps']
            ),
            'area_reconciliation' => [
                'parent_sqm' => $parentArea !== null ? round($parentArea, 4) : null,
                'children_sum_sqm' => round($childrenSum, 4),
                'difference_sqm' => $difference !== null ? round($difference, 4) : null,
                'difference_pct' => $differencePct !== null ? round($differencePct, 4) : null,
            ],
        ];
    }

    /**
     * Measure parent and children in one round trip: per-child validity and
     * area, pairwise overlap areas, and the union-vs-parent symmetric
     * difference. Only valid, non-empty children take part in the union and
     * pairwise checks so an invalid child cannot break the SQL (VR-35 already
     * rejects it and the split is blocked regardless).
     *
     * @param string[] $childGeoms
     * @return array{
     *   parent_srid: int|null,
     *   parent_area_sqm: float|null,
     *   symdiff_sqm: float|null,
     *   children: list<array<string, mixed>>,
     *   overlaps: list<array<string, mixed>>
     * }
     */
    private function measureGeometries(string $parentGeom, array $childGeoms): array
    {
        $params = [':parent' => $parentGeom];
        $parentExpr = $this->geomExpr(':parent', $parentGeom);

        $childSelects = [];
        foreach ($childGeoms as $i => $g) {
            $ph = ':c' . $i;
            $params[$ph] = (string) $g;
            $childSelects[] = sprintf('SELECT %d::int AS idx, %s AS g', $i, $this->geomExpr($ph, (string) $g));
        }

        $childrenCte = $childSelects === []
            ? 'SELECT NULL::int AS idx, NULL::geometry AS g WHERE false'
            : implode("\n                UNION ALL ", $childSelects);

        $sql = "
            WITH parent AS (
                SELECT {$parentExpr} AS g
            ),
            children AS (
                {$childrenCte}
            ),
            usable AS (
                SELECT idx, g FROM children
                WHERE g IS NOT NULL AND NOT ST_IsEmpty(g) AND ST_IsValid(g)
            ),
            child_union AS (
                SELECT ST_UnaryUnion(ST_Collect(g)) AS g FROM usable
            ),
            pairwise AS (
                SELECT a.idx AS i, b.idx AS j,
                       ST_Area(ST_Intersection(a.g, b.g)::geography) AS overlap_area_sqm
                FROM usable a
                JOIN usable b ON a.idx < b.idx
                WHERE ST_Intersects(a.g, b.g)
            ),
            measures AS (
                SELECT c.idx,
                       (c.g IS NULL) AS parse_failed,
                       COALESCE(ST_IsEmpty(c.g), false) AS is_empty,
                       COALESCE(ST_IsValid(c.g), false) AS is_valid,
                       COALESCE(ST_IsSimple(c.g), false) AS is_simple,
                       ST_GeometryType(c.g) AS geometry_type,
                       COALESCE(ST_SRID(c.g), 0) AS srid,
                       CASE WHEN c.g IS NOT NULL AND NOT ST_IsEmpty(c.g) AND ST_IsValid(c.g)
                            THEN ST_Area(c.g::geography) ELSE 0::double precision END AS area_sqm
                FROM children c
            )
            SELECT
                (SELECT ST_SRID(g) FROM parent) AS parent_srid,
                (SELECT CASE WHEN ST_IsValid(g) THEN ST_Area(g::geography) END FROM parent) AS parent_area_sqm,
                (SELECT ST_Area(ST_SymDifference(cu.g, p.g)::geography)
                   FROM child_union cu CROSS JOIN parent p
                  WHERE cu.g IS NOT NULL AND p.g IS NOT NULL
                    AND ST_IsValid(p.g)) AS symdiff_sqm,
                (SELECT COALESCE(json_agg(row_to_json(x))::text, '[]')
                   FROM (SELECT * FROM measures ORDER BY idx) x) AS child_measures,
                (SELECT COALESCE(json_agg(row_to_json(x))::text, '[]')
                   FROM (SELECT * FROM pairwise ORDER BY i, j) x) AS overlaps
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'parent_srid' => $row['parent_srid'] !== null ? (int) $row['parent_srid'] : null,
            'parent_area_sqm' => $row['parent_area_sqm'] !== null ? (float) $row['parent_area_sqm'] : null,
            'symdiff_sqm' => isset($row['symdiff_sqm']) && $row['symdiff_sqm'] !== null ? (float) $row['symdiff_sqm'] : null,
            'children' => isset($row['child_measures']) ? (array) json_decode((string) $row['child_measures'], true) : [],
            'overlaps' => isset($row['overlaps']) ? (array) json_decode((string) $row['overlaps'], true) : [],
        ];
    }

    /** GeoJSON, WKT, or EWKT binding expression. GeoJSON/WKT are taken as
     *  SRID 4326; EWKT (SRID=xxxx;…) carries its own SRID so the VR-44/
     *  SRID_COMPAT mismatch check can actually observe a wrong SRID. */
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

    /**
     * VR-38 minimum from SYSTEM_MIN_LOT_AREA; spec default (1 m²) when the
     * setting is absent or unparseable.
     */
    private function readMinAreaSetting(): float
    {
        try {
            $stmt = $this->pdo->prepare('SELECT value FROM app.system_settings WHERE key = :key LIMIT 1');
            $stmt->execute([':key' => self::MIN_AREA_SETTING]);
            $raw = $stmt->fetchColumn();
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $candidate = is_array($decoded) ? ($decoded['value'] ?? null) : $decoded;
                if (is_numeric($candidate)) {
                    return (float) $candidate;
                }
            }
        } catch (\PDOException) {
            // Settings table unavailable — fall back to the spec default.
        }

        return self::MIN_AREA_FALLBACK_SQM;
    }

    private function check(string $rule, bool $passed, string $message): array
    {
        return ['rule' => $rule, 'status' => $passed ? 'pass' : 'fail', 'message' => $message];
    }

    /**
     * The combined measure query failed. Probe each geometry individually to
     * name the offender. Note: when this runs inside an aborted transaction
     * every probe fails too — the split is still blocked either way, but the
     * services must probe before BEGIN for precise detail (TASK-112).
     *
     * @param string[] $childGeoms
     */
    private function parseFailureReport(string $parentGeom, array $childGeoms, \PDOException $e): array
    {
        if (!$this->parses($parentGeom)) {
            return $this->unparsedFailure('Parent geometry could not be parsed.');
        }

        $offenders = [];
        foreach ($childGeoms as $i => $g) {
            if (!$this->parses((string) $g)) {
                $offenders[] = sprintf('child #%d could not be parsed', $i);
            }
        }

        return $this->unparsedFailure(
            $offenders !== []
                ? 'Invalid split geometry: ' . implode('; ', $offenders) . '.'
                : 'Geometry could not be evaluated: ' . $e->getMessage()
        );
    }

    private function parses(string $geom): bool
    {
        if (trim($geom) === '') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT ' . $this->geomExpr(':g', $geom));
            $stmt->execute([':g' => $geom]);
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Hard input failure (unparseable/blank geometry): every rule reported as
     * failing so the caller's SPLIT_INVALID still lists the reason.
     *
     * @return array{passed: bool, checks: list<array{rule: string, status: string, message: string}>, warnings: list<array{rule: string, message: string}>, children: list<array<string, mixed>>, pairwise_overlaps: list<array<string, mixed>>, area_reconciliation: array{parent_sqm: float|null, children_sum_sqm: float, difference_sqm: float|null, difference_pct: float|null}}
     */
    private function unparsedFailure(string $message): array
    {
        $rules = ['VR-35', 'VR-36', 'VR-37', 'VR-38', 'SRID_COMPAT'];
        return [
            'passed' => false,
            'checks' => array_map(fn (string $r): array => $this->check($r, false, $message), $rules),
            'warnings' => [],
            'children' => [],
            'pairwise_overlaps' => [],
            'area_reconciliation' => [
                'parent_sqm' => null,
                'children_sum_sqm' => 0.0,
                'difference_sqm' => null,
                'difference_pct' => null,
            ],
        ];
    }
}
