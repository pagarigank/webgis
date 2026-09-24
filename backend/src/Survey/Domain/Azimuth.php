<?php
declare(strict_types=1);

namespace App\Survey\Domain;

/**
 * Pure domain Azimuth value object (TASK-078).
 *
 * Decimal degrees clockwise from North in the half-open interval [0, 360).
 * Conversion to and from Bearing is exact to 1e-9 degrees.
 */
final class Azimuth
{
    private float $decimalDegrees;

    public function __construct(float $degrees)
    {
        $this->decimalDegrees = self::normalizeDegrees($degrees);
    }

    public static function normalizeDegrees(float $degrees): float
    {
        $d = fmod($degrees, 360.0);
        if ($d < 0.0) {
            $d += 360.0;
        }
        // Handle floating precision boundary where fmod leaves 360.0
        if (abs($d - 360.0) < 1e-12 || $d < 0.0) {
            $d = 0.0;
        }
        return $d;
    }

    public function toDecimalDegrees(): float
    {
        return $this->decimalDegrees;
    }

    /**
     * Convert azimuth to a Bearing value object.
     */
    public function toBearing(): Bearing
    {
        $deg = $this->decimalDegrees;

        // Cardinals at exact boundaries
        if (abs($deg - 0.0) < 1e-9) {
            return Bearing::fromCardinal('N');
        }
        if (abs($deg - 90.0) < 1e-9) {
            return Bearing::fromCardinal('E');
        }
        if (abs($deg - 180.0) < 1e-9) {
            return Bearing::fromCardinal('S');
        }
        if (abs($deg - 270.0) < 1e-9) {
            return Bearing::fromCardinal('W');
        }

        // Quadrants
        if ($deg > 0.0 && $deg < 90.0) {
            $dms = self::decimalToDms($deg);
            return Bearing::fromQuadrant('NE', $dms['deg'], $dms['min'], $dms['sec']);
        }
        if ($deg > 90.0 && $deg < 180.0) {
            $dms = self::decimalToDms(180.0 - $deg);
            return Bearing::fromQuadrant('SE', $dms['deg'], $dms['min'], $dms['sec']);
        }
        if ($deg > 180.0 && $deg < 270.0) {
            $dms = self::decimalToDms($deg - 180.0);
            return Bearing::fromQuadrant('SW', $dms['deg'], $dms['min'], $dms['sec']);
        }

        // 270 < deg < 360
        $dms = self::decimalToDms(360.0 - $deg);
        return Bearing::fromQuadrant('NW', $dms['deg'], $dms['min'], $dms['sec']);
    }

    /**
     * @return array{deg: int, min: int, sec: float}
     */
    public static function decimalToDms(float $decimal): array
    {
        $d = (int) floor($decimal);
        $remainder = ($decimal - $d) * 60.0;
        $m = (int) floor($remainder);
        $s = ($remainder - $m) * 60.0;

        // Round seconds to microsecond precision for floating safety
        $s = round($s, 6);
        if ($s >= 60.0) {
            $s = 0.0;
            $m++;
        }
        if ($m >= 60) {
            $m = 0;
            $d++;
        }

        return ['deg' => $d, 'min' => $m, 'sec' => $s];
    }

    public function __toString(): string
    {
        return sprintf('%.8f°', $this->decimalDegrees);
    }
}
