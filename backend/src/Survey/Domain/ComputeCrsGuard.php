<?php
declare(strict_types=1);

namespace App\Survey\Domain;

use InvalidArgumentException;

/**
 * TASK-091 — Compute-CRS selection and guards (Pure Domain).
 *
 * Enforces:
 * - PTM Zone suggestion based on longitude.
 * - Guard against non-GRID bearing reference (GEODETIC / MAGNETIC).
 * - Guard against CRS outside area of use (VR-20).
 */
class ComputeCrsGuard
{
    /**
     * Map longitude to recommended Philippine PRS92 PTM zone.
     *
     * @return array{srid: int, code: string, name: string, zone: string, datum: string}
     */
    public function suggestPtmZone(float $longitude): array
    {
        if ($longitude < 118.0) {
            return [
                'srid'  => 3121,
                'code'  => 'EPSG:3121',
                'name'  => 'PRS92 / Philippines Zone I',
                'zone'  => 'PTM Zone I',
                'datum' => 'PRS92',
            ];
        }
        if ($longitude < 120.0) {
            return [
                'srid'  => 3122,
                'code'  => 'EPSG:3122',
                'name'  => 'PRS92 / Philippines Zone II',
                'zone'  => 'PTM Zone II',
                'datum' => 'PRS92',
            ];
        }
        if ($longitude < 122.0) {
            return [
                'srid'  => 3123,
                'code'  => 'EPSG:3123',
                'name'  => 'PRS92 / Philippines Zone III',
                'zone'  => 'PTM Zone III',
                'datum' => 'PRS92',
            ];
        }
        if ($longitude < 124.0) {
            return [
                'srid'  => 3124,
                'code'  => 'EPSG:3124',
                'name'  => 'PRS92 / Philippines Zone IV',
                'zone'  => 'PTM Zone IV',
                'datum' => 'PRS92',
            ];
        }
        return [
            'srid'  => 3125,
            'code'  => 'EPSG:3125',
            'name'  => 'PRS92 / Philippines Zone V',
            'zone'  => 'PTM Zone V',
            'datum' => 'PRS92',
        ];
    }

    /**
     * Block non-GRID bearing references (GEODETIC, MAGNETIC, etc.).
     */
    public function assertGridBearingReference(?string $bearingReference): void
    {
        if ($bearingReference === null || trim($bearingReference) === '') {
            return;
        }

        $upper = strtoupper(trim($bearingReference));
        if ($upper !== 'GRID') {
            throw new InvalidArgumentException(
                "Bearing reference '{$bearingReference}' cannot be computed directly. "
                . "Plane traverse computation strictly requires GRID bearings. "
                . "GEODETIC or MAGNETIC bearings require grid convergence and magnetic declination corrections before computing."
            );
        }
    }

    /**
     * Enforce that geographic coordinates fall within CRS area of use (VR-20).
     *
     * @param array{code?: string, area_south?: float|string|null, area_west?: float|string|null, area_north?: float|string|null, area_east?: float|string|null} $crs
     */
    public function assertWithinAreaOfUse(array $crs, float $latitude, float $longitude): void
    {
        $code = $crs['code'] ?? 'selected CRS';

        if (isset($crs['area_south'], $crs['area_west'], $crs['area_north'], $crs['area_east'])
            && $crs['area_south'] !== null && $crs['area_west'] !== null
            && $crs['area_north'] !== null && $crs['area_east'] !== null) {
            $s = (float) $crs['area_south'];
            $w = (float) $crs['area_west'];
            $n = (float) $crs['area_north'];
            $e = (float) $crs['area_east'];

            if ($latitude < $s || $latitude > $n || $longitude < $w || $longitude > $e) {
                throw new InvalidArgumentException(
                    "VR-20: Coordinates (lat {$latitude}, lon {$longitude}) fall outside the declared area of use "
                    . "[S: {$s}, W: {$w}, N: {$n}, E: {$e}] for {$code}."
                );
            }
        }
    }
}
