<?php
declare(strict_types=1);

namespace App\Survey\Domain\Adjustment;

/**
 * Interface for survey traverse adjustments (TASK-094).
 */
interface TraverseAdjustmentInterface
{
    /**
     * Adjust traverse courses and vertices to close error.
     *
     * @param array{easting: float, northing: float} $pob Starting point of beginning
     * @param array<int, array{seq: int, point_label: string, easting: float, northing: float, delta_e: float, delta_n: float}> $unadjustedVertices
     * @param array<int, array{bearing: mixed, distance: mixed}> $courses
     * @param float $deltaE Departure closure error
     * @param float $deltaN Latitude closure error
     * @param float $perimeter Total perimeter
     * @return array{
     *     method: string,
     *     adjusted_vertices: array<int, array{seq: int, point_label: string, easting: float, northing: float, delta_e: float, delta_n: float}>,
     *     closure: array{delta_e: float, delta_n: float, linear_error_m: float, perimeter_m: float}
     * }
     */
    public function adjust(
        array $pob,
        array $unadjustedVertices,
        array $courses,
        float $deltaE,
        float $deltaN,
        float $perimeter
    ): array;
}
