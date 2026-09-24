<?php
declare(strict_types=1);

namespace App\Survey\Domain;

use InvalidArgumentException;

/**
 * TASK-089 — Area Calculator (Pure Domain).
 *
 * Implements the Shoelace formula on plane coordinates.
 * Evaluates VR-15 (area bounds), VR-16 (source area comparison),
 * and VR-17 (PostGIS planar area cross-check).
 *
 * Note: Area is NEVER calculated on 4326 geographic coordinates.
 */
class AreaCalculator
{
    public const VALIDATION_AID_NOTE = 'Area comparison is a validation aid, not a determination of correctness.';

    /**
     * Compute ground area from planar coordinates using the Shoelace formula.
     *
     * @param array<int, array{easting: float, northing: float}> $vertices Ordered ring of vertices
     * @return float Area in square meters (rounded to 4 decimal places)
     */
    public function computeShoelaceArea(array $vertices): float
    {
        $count = count($vertices);
        if ($count < 3) {
            throw new InvalidArgumentException('VR-10: At least 3 vertices are required to compute polygon area.');
        }

        $sum = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $next = ($i + 1) % $count;
            $x1 = (float) $vertices[$i]['easting'];
            $y1 = (float) $vertices[$i]['northing'];
            $x2 = (float) $vertices[$next]['easting'];
            $y2 = (float) $vertices[$next]['northing'];

            $sum += ($x1 * $y2) - ($x2 * $y1);
        }

        $area = abs($sum) / 2.0;
        return round($area, 4);
    }

    /**
     * Cross-check shoelace area against source area and PostGIS planar area.
     *
     * @param float $computedAreaSqm
     * @param float|null $sourceAreaSqm
     * @param float|null $postgisAreaSqm
     * @return array{
     *     computed_sqm: float,
     *     postgis_sqm: ?float,
     *     source_sqm: ?float,
     *     diff_source_sqm: ?float,
     *     diff_source_pct: ?float,
     *     diff_postgis_sqm: ?float,
     *     diff_postgis_pct: ?float,
     *     note: string,
     *     warnings: array<int, array{rule: string, message: string}>
     * }
     */
    public function compareAreas(
        float $computedAreaSqm,
        ?float $sourceAreaSqm = null,
        ?float $postgisAreaSqm = null
    ): array {
        $warnings = [];

        // VR-15: Bounds checking (> 1 sqm, < 10,000 ha = 100,000,000 sqm)
        if ($computedAreaSqm < 1.0) {
            $warnings[] = [
                'rule'    => 'VR-15',
                'message' => sprintf('Computed area (%.4f m²) is below the plausible minimum lot size of 1 m².', $computedAreaSqm),
            ];
        } elseif ($computedAreaSqm > 100000000.0) {
            $warnings[] = [
                'rule'    => 'VR-15',
                'message' => sprintf('Computed area (%.4f m²) exceeds 10,000 hectares (unusually large for a single parcel).', $computedAreaSqm),
            ];
        }

        // VR-16: Source area cross-check
        $diffSourceSqm = null;
        $diffSourcePct = null;
        if ($sourceAreaSqm !== null && $sourceAreaSqm > 0.0) {
            $diffSourceSqm = round($computedAreaSqm - $sourceAreaSqm, 4);
            $diffSourcePct = round(($diffSourceSqm / $sourceAreaSqm) * 100.0, 4);

            $absPct = abs($diffSourcePct);
            if ($absPct > 2.0) {
                $warnings[] = [
                    'rule'    => 'VR-16',
                    'message' => sprintf('Computed area differs from source area by %.4f%% (> 2.0%% flag threshold).', $absPct),
                ];
            } elseif ($absPct > 0.5) {
                $warnings[] = [
                    'rule'    => 'VR-16',
                    'message' => sprintf('Computed area differs from source area by %.4f%% (> 0.5%% warning threshold).', $absPct),
                ];
            }
        }

        // VR-17: PostGIS planar area cross-check (agreement within 0.01%)
        $diffPostgisSqm = null;
        $diffPostgisPct = null;
        if ($postgisAreaSqm !== null && $postgisAreaSqm > 0.0) {
            $diffPostgisSqm = round($computedAreaSqm - $postgisAreaSqm, 4);
            $diffPostgisPct = round(($diffPostgisSqm / $postgisAreaSqm) * 100.0, 6);

            $absPct = abs($diffPostgisPct);
            if ($absPct > 0.01) {
                $warnings[] = [
                    'rule'    => 'VR-17',
                    'message' => sprintf('Shoelace area and PostGIS planar area differ by %.4f%% (> 0.01%% tolerance). Check coordinate precision or projection.', $absPct),
                ];
            }
        }

        return [
            'computed_sqm'     => $computedAreaSqm,
            'postgis_sqm'      => $postgisAreaSqm !== null ? round($postgisAreaSqm, 4) : null,
            'source_sqm'       => $sourceAreaSqm !== null ? round($sourceAreaSqm, 4) : null,
            'diff_source_sqm'  => $diffSourceSqm,
            'diff_source_pct'  => $diffSourcePct,
            'diff_postgis_sqm' => $diffPostgisSqm,
            'diff_postgis_pct' => $diffPostgisPct,
            'note'             => self::VALIDATION_AID_NOTE,
            'warnings'         => $warnings,
        ];
    }
}
