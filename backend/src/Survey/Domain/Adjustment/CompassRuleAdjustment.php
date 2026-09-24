<?php
declare(strict_types=1);

namespace App\Survey\Domain\Adjustment;

use App\Survey\Domain\Distance;
use InvalidArgumentException;

/**
 * Bowditch Compass Rule Adjustment (TASK-094).
 *
 * Distributes closure error in latitude and departure proportional
 * to the length of each course:
 * C_E = -ΔE * (D_i / Perimeter)
 * C_N = -ΔN * (D_i / Perimeter)
 */
class CompassRuleAdjustment implements TraverseAdjustmentInterface
{
    public const METHOD = 'COMPASS';

    public function adjust(
        array $pob,
        array $unadjustedVertices,
        array $courses,
        float $deltaE,
        float $deltaN,
        float $perimeter
    ): array {
        $count = count($courses);
        if ($count < 3) {
            throw new InvalidArgumentException('Traverse adjustment requires at least 3 courses.');
        }
        if ($perimeter <= 0.0) {
            throw new InvalidArgumentException('Traverse perimeter must be greater than zero.');
        }

        $adjustedVertices = [];
        $curE = (float) $pob['easting'];
        $curN = (float) $pob['northing'];

        for ($i = 0; $i < $count; $i++) {
            $origVertex = $unadjustedVertices[$i];
            $courseDist = $this->extractCourseDistance($courses[$i]);

            // Correction for course i
            $corrE = -$deltaE * ($courseDist / $perimeter);
            $corrN = -$deltaN * ($courseDist / $perimeter);

            $adjDE = (float) $origVertex['delta_e'] + $corrE;
            $adjDN = (float) $origVertex['delta_n'] + $corrN;

            $adjustedVertices[] = [
                'seq'         => $i + 1,
                'point_label' => $origVertex['point_label'] ?? (string) ($i + 1),
                'easting'     => round($curE, 4),
                'northing'    => round($curN, 4),
                'delta_e'     => round($adjDE, 4),
                'delta_n'     => round($adjDN, 4),
            ];

            $curE += $adjDE;
            $curN += $adjDN;
        }

        // Final closed coordinates back to POB
        $finalE = round($curE, 4);
        $finalN = round($curN, 4);
        $resDeltaE = round($finalE - (float) $pob['easting'], 4);
        $resDeltaN = round($finalN - (float) $pob['northing'], 4);
        $resLinear = round(sqrt($resDeltaE ** 2 + $resDeltaN ** 2), 4);

        return [
            'method'            => self::METHOD,
            'adjusted_vertices' => $adjustedVertices,
            'closure' => [
                'delta_e'        => $resDeltaE,
                'delta_n'        => $resDeltaN,
                'linear_error_m' => $resLinear,
                'perimeter_m'    => round($perimeter, 4),
            ],
        ];
    }

    private function extractCourseDistance(array $course): float
    {
        $d = $course['distance'] ?? 0.0;
        if ($d instanceof Distance) {
            return $d->getMeters();
        }
        if (is_numeric($d)) {
            return (float) $d;
        }
        return Distance::parse((string) $d)->getMeters();
    }
}
