<?php
declare(strict_types=1);

namespace App\Survey\Domain;

use InvalidArgumentException;

/**
 * TASK-087 — Traverse Computer (Pure Domain).
 *
 * Implements plane traverse coordinate computation:
 * Tie point → tie line(s) → POB → successive courses;
 * ΔN = D·cos(Az), ΔE = D·sin(Az) in plane coordinates.
 *
 * Strictly pure domain: no PDO, no PostGIS, no HTTP, no framework coupling.
 */
class TraverseComputer
{
    /**
     * Compute plane traverse from tie point, tie lines, and courses.
     *
     * @param array{easting: float, northing: float, name?: string} $tiePoint
     * @param array<int, array{bearing: Bearing|string, distance: Distance|float}> $tieLines
     * @param array<int, array{seq?: int, from?: string, to?: string, bearing: Bearing|string, distance: Distance|float}> $courses
     * @return array{
     *     pob: array{easting: float, northing: float},
     *     close: array{easting: float, northing: float},
     *     vertices: array<int, array{seq: int, point_label: string, easting: float, northing: float, delta_e: float, delta_n: float}>,
     *     closure: array{delta_e: float, delta_n: float, linear_error_m: float, perimeter_m: float}
     * }
     */
    public function compute(array $tiePoint, array $tieLines, array $courses): array
    {
        if (!isset($tiePoint['easting'], $tiePoint['northing'])) {
            throw new InvalidArgumentException('Tie point must provide easting and northing coordinates.');
        }

        $currentE = (float) $tiePoint['easting'];
        $currentN = (float) $tiePoint['northing'];

        // Traverse through tie lines to Point of Beginning (POB)
        foreach ($tieLines as $tie) {
            $bearing  = $this->normalizeBearing($tie['bearing']);
            $distance = $this->normalizeDistance($tie['distance']);

            $azRad = deg2rad($bearing->toAzimuth()->toDecimalDegrees());
            $distM = $distance->getMeters();

            $currentE += $distM * sin($azRad);
            $currentN += $distM * cos($azRad);
        }

        $pobE = $currentE;
        $pobN = $currentN;

        $vertices = [];
        $totalPerimeter = 0.0;
        $numCourses = count($courses);

        if ($numCourses === 0) {
            throw new InvalidArgumentException('Traverse requires at least 1 course.');
        }

        $curE = $pobE;
        $curN = $pobN;

        for ($i = 0; $i < $numCourses; $i++) {
            $c = $courses[$i];
            $bearing  = $this->normalizeBearing($c['bearing']);
            $distance = $this->normalizeDistance($c['distance']);

            $azRad = deg2rad($bearing->toAzimuth()->toDecimalDegrees());
            $distM = $distance->getMeters();
            $totalPerimeter += $distM;

            $dE = $distM * sin($azRad);
            $dN = $distM * cos($azRad);

            $label = isset($c['from']) && $c['from'] !== '' ? (string) $c['from'] : (string) ($i + 1);

            $vertices[] = [
                'seq'         => $i + 1,
                'point_label' => $label,
                'easting'     => round($curE, 4),
                'northing'    => round($curN, 4),
                'delta_e'     => round($dE, 4),
                'delta_n'     => round($dN, 4),
            ];

            $curE += $dE;
            $curN += $dN;
        }

        $closeE = round($curE, 4);
        $closeN = round($curN, 4);

        $deltaE = round($closeE - $pobE, 4);
        $deltaN = round($closeN - $pobN, 4);
        $linearError = round(sqrt($deltaE ** 2 + $deltaN ** 2), 4);

        return [
            'pob' => [
                'easting'  => round($pobE, 4),
                'northing' => round($pobN, 4),
            ],
            'close' => [
                'easting'  => $closeE,
                'northing' => $closeN,
            ],
            'vertices' => $vertices,
            'closure' => [
                'delta_e'        => $deltaE,
                'delta_n'        => $deltaN,
                'linear_error_m' => $linearError,
                'perimeter_m'    => round($totalPerimeter, 4),
            ],
        ];
    }

    private function normalizeBearing(Bearing|string $bearing): Bearing
    {
        return is_string($bearing) ? Bearing::fromString($bearing) : $bearing;
    }

    private function normalizeDistance(Distance|float|int $distance): Distance
    {
        if ($distance instanceof Distance) {
            return $distance;
        }
        return Distance::fromMeters((float) $distance);
    }
}
