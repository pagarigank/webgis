<?php
declare(strict_types=1);

namespace App\Survey\Domain;

/**
 * TASK-088 — Closure Calculator (Pure Domain).
 *
 * Calculates:
 * - ΔE, ΔN, linear error, error azimuth, perimeter, relative precision.
 * - Tolerance evaluation (VR-11, VR-12).
 * - Safe handling of perfect closure (zero division guard, 1:INF).
 */
class ClosureCalculator
{
    public const DEFAULT_LINEAR_TOLERANCE_M = 0.10; // VR-11
    public const DEFAULT_MIN_PRECISION_DENOM = 5000; // VR-12 (1:5000)
    public const BLOCKING_PRECISION_DENOM = 1000;    // VR-12 error below 1:1000

    /**
     * Compute closure metrics from closing coordinates or deltas.
     *
     * @param float $deltaE Departure closure error (closeE - pobE)
     * @param float $deltaN Latitude closure error (closeN - pobN)
     * @param float $perimeter Total perimeter of the traverse in meters
     * @param array{linear_closure_m?: float, relative_precision_min?: float} $tolerances
     */
    public function calculate(
        float $deltaE,
        float $deltaN,
        float $perimeter,
        array $tolerances = []
    ): ClosureResult {
        $linearTolerance = (float) ($tolerances['linear_closure_m'] ?? self::DEFAULT_LINEAR_TOLERANCE_M);
        $minPrecision = (float) ($tolerances['relative_precision_min'] ?? self::DEFAULT_MIN_PRECISION_DENOM);

        $linearError = sqrt($deltaE ** 2 + $deltaN ** 2);
        $warnings = [];

        // Error azimuth calculation
        if ($linearError < 1e-7) {
            $errorAzimuth = null;
        } else {
            $azDeg = rad2deg(atan2($deltaE, $deltaN));
            $errorAzimuth = round(fmod($azDeg + 360.0, 360.0), 6);
        }

        // Relative precision calculation (guarding against divide-by-zero)
        if ($linearError < 1e-7) {
            $precisionDenom = null;
            $precisionStr = '1:INF';
        } else {
            $precisionDenom = round($perimeter / $linearError, 2);
            $precisionStr = sprintf('1:%d', (int) round($precisionDenom));
        }

        // Tolerance evaluation
        if ($perimeter <= 0.0) {
            $status = ClosureResult::STATUS_INDETERMINATE;
            $warnings[] = ['rule' => 'VR-10', 'message' => 'Traverse perimeter is zero or negative.'];
        } elseif ($linearError > $perimeter) {
            $status = ClosureResult::STATUS_NOT_CLOSED;
            $warnings[] = ['rule' => 'VR-11', 'message' => 'Traverse does not close: linear error exceeds perimeter.'];
        } else {
            $exceedsLinear = $linearError > $linearTolerance;
            $exceedsPrecision = $precisionDenom !== null && $precisionDenom < $minPrecision;

            if ($exceedsLinear) {
                $warnings[] = [
                    'rule'    => 'VR-11',
                    'message' => sprintf('Linear closure error (%.4f m) exceeds tolerance (%.4f m).', $linearError, $linearTolerance),
                ];
            }

            if ($precisionDenom !== null && $precisionDenom < self::BLOCKING_PRECISION_DENOM) {
                $warnings[] = [
                    'rule'    => 'VR-12',
                    'message' => sprintf('Relative precision (%s) is critically below 1:%d.', $precisionStr, self::BLOCKING_PRECISION_DENOM),
                ];
            } elseif ($exceedsPrecision) {
                $warnings[] = [
                    'rule'    => 'VR-12',
                    'message' => sprintf('Relative precision (%s) is below required standard 1:%d.', $precisionStr, (int) $minPrecision),
                ];
            }

            if ($exceedsLinear || $exceedsPrecision) {
                $status = ClosureResult::STATUS_EXCEEDS_TOLERANCE;
            } else {
                $status = ClosureResult::STATUS_WITHIN_TOLERANCE;
            }
        }

        return new ClosureResult(
            round($deltaE, 4),
            round($deltaN, 4),
            round($linearError, 4),
            $errorAzimuth,
            round($perimeter, 4),
            $precisionDenom,
            $precisionStr,
            $status,
            $warnings
        );
    }
}
