<?php
declare(strict_types=1);

namespace App\Survey\Domain;

use InvalidArgumentException;

/**
 * Pure domain Distance value object (TASK-079).
 *
 * Canonical meters (distance_m) with exact conversion factors from registered units.
 * Preserves original value, unit, and string representation.
 * Enforces VR-04, VR-05, and VR-06.
 */
final class Distance
{
    /**
     * Conversion factor to meters: 1 unit = N meters.
     */
    public const UNIT_FACTORS = [
        'm'     => 1.0,
        'km'    => 1000.0,
        'ft'    => 0.3048,
        'usft'  => 1200.0 / 3937.0, // ~0.3048006096012192
        'sft'   => 1200.0 / 3937.0,
        'vara'  => 0.835905,       // Philippine standard Spanish vara
        'ch'    => 20.1168,         // Gunter's survey chain (66 ft)
    ];

    public const UNIT_ALIASES = [
        'meter'   => 'm',
        'meters'  => 'm',
        'metre'   => 'm',
        'metres'  => 'm',
        'feet'    => 'ft',
        'foot'    => 'ft',
        'varas'   => 'vara',
        'chain'   => 'ch',
        'chains'  => 'ch',
    ];

    private float $canonicalMeters;
    private float $originalValue;
    private string $originalUnit;
    private string $originalString;
    private ?string $warning = null;

    private function __construct(
        float $canonicalMeters,
        float $originalValue,
        string $originalUnit,
        string $originalString
    ) {
        $this->canonicalMeters = $canonicalMeters;
        $this->originalValue = $originalValue;
        $this->originalUnit = $originalUnit;
        $this->originalString = $originalString;

        // VR-05: Distance > 5,000 m in a single course (warning: plausible but unusual)
        if ($this->canonicalMeters > 5000.0) {
            $this->warning = sprintf('VR-05: Distance of %.2f m exceeds 5,000 m in a single course.', $this->canonicalMeters);
        }
    }

    /**
     * Create distance from meters.
     */
    public static function fromMeters(float $meters, ?string $original = null): self
    {
        return self::fromUnit($meters, 'm', $original);
    }

    /**
     * Create distance from a specified registered unit.
     * Enforces VR-04 and VR-06.
     */
    public static function fromUnit(float $value, string $unit, ?string $original = null): self
    {
        $normalizedUnit = self::normalizeUnit($unit);

        // Convert to meters
        $factor = self::UNIT_FACTORS[$normalizedUnit];
        $meters = $value * $factor;

        // VR-04: Distance > 0; > 0.01 m
        if ($value <= 0.0 || $meters <= 0.01) {
            throw new InvalidArgumentException(sprintf('VR-04: Distance must be > 0.01 m (given: %f %s = %f m)', $value, $unit, $meters));
        }

        $origStr = $original ?? sprintf('%.4f %s', $value, $normalizedUnit);

        return new self($meters, $value, $normalizedUnit, $origStr);
    }

    /**
     * Normalize and validate registered units.
     * Enforces VR-06.
     */
    public static function normalizeUnit(string $unit): string
    {
        $u = strtolower(trim($unit));
        if (isset(self::UNIT_ALIASES[$u])) {
            $u = self::UNIT_ALIASES[$u];
        }

        if (!array_key_exists($u, self::UNIT_FACTORS)) {
            throw new InvalidArgumentException("VR-06: Unregistered distance unit '{$unit}'. Allowed units: " . implode(', ', array_keys(self::UNIT_FACTORS)));
        }

        return $u;
    }

    /**
     * Parse distance string e.g. "45.20 meters", "120.5 m", "100.0 ft", "50.25".
     */
    public static function parse(string $input, string $defaultUnit = 'm'): self
    {
        $raw = trim($input);
        if ($raw === '') {
            throw new InvalidArgumentException('Distance string cannot be empty.');
        }

        // Match numeric value and optional unit
        if (preg_match('/^([+-]?\d+(?:\.\d+)?)\s*([a-zA-Z]*)$/', $raw, $m)) {
            $val = (float) $m[1];
            $unitStr = trim($m[2]);
            $unit = $unitStr !== '' ? $unitStr : $defaultUnit;

            return self::fromUnit($val, $unit, $raw);
        }

        throw new InvalidArgumentException("VR-04: Could not parse distance string '{$input}'.");
    }

    public function toMeters(): float
    {
        return $this->canonicalMeters;
    }

    public function getMeters(): float
    {
        return $this->canonicalMeters;
    }

    public function toUnit(string $unit): float
    {
        $normalized = self::normalizeUnit($unit);
        $factor = self::UNIT_FACTORS[$normalized];
        return $this->canonicalMeters / $factor;
    }

    public function getOriginalValue(): float
    {
        return $this->originalValue;
    }

    public function getOriginalUnit(): string
    {
        return $this->originalUnit;
    }

    public function getOriginalString(): string
    {
        return $this->originalString;
    }

    public function hasWarning(): bool
    {
        return $this->warning !== null;
    }

    public function getWarningMessage(): ?string
    {
        return $this->warning;
    }

    public function __toString(): string
    {
        return sprintf('%.4f m', $this->canonicalMeters);
    }
}
