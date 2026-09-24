<?php
declare(strict_types=1);

namespace App\Survey\Domain\Adjustment;

use InvalidArgumentException;

/**
 * Transit Rule Adjustment (TASK-094).
 *
 * Distributes closure error proportional to the absolute values
 * of the individual departure and latitude of each course:
 * C_E = -ΔE * (|ΔE_i| / Σ|ΔE|)
 * C_N = -ΔN * (|ΔN_i| / Σ|ΔN|)
 */
class TransitRuleAdjustment implements TraverseAdjustmentInterface
{
    public const METHOD = 'TRANSIT';

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

        // Calculate sum of absolute departures and latitudes
        $sumAbsE = 0.0;
        $sumAbsN = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $sumAbsE += abs((float) $unadjustedVertices[$i]['delta_e']);
            $sumAbsN += abs((float) $unadjustedVertices[$i]['delta_n']);
        }

        $adjustedVertices = [];
        $curE = (float) $pob['easting'];
        $curN = (float) $pob['northing'];

        for ($i = 0; $i < $count; $i++) {
            $origVertex = $unadjustedVertices[$i];
            $dE = (float) $origVertex['delta_e'];
            $dN = (float) $origVertex['delta_n'];

            $corrE = $sumAbsE > 1e-9 ? -$deltaE * (abs($dE) / $sumAbsE) : 0.0;
            $corrN = $sumAbsN > 1e-9 ? -$deltaN * (abs($dN) / $sumAbsN) : 0.0;

            $adjDE = $dE + $corrE;
            $adjDN = $dN + $corrN;

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
}
