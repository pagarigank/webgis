<?php
declare(strict_types=1);

namespace App\Survey\Domain;

use InvalidArgumentException;

/**
 * Pure domain Bearing value object (TASK-078).
 *
 * Supports quadrant bearings (NE, SE, SW, NW with deg, min, sec),
 * cardinal directions (DUE NORTH, DUE EAST, etc.), and azimuth conversion.
 * Strictly adheres to VR-01, VR-02, VR-03, and VR-07.
 */
final class Bearing
{
    public const TYPE_QUADRANT = 'QUADRANT';
    public const TYPE_CARDINAL = 'CARDINAL';
    public const TYPE_AZIMUTH  = 'AZIMUTH';

    private string $type;
    private ?string $quadrant;
    private ?int $degrees;
    private ?int $minutes;
    private ?float $seconds;
    private ?string $cardinalDirection;
    private ?Azimuth $azimuth;
    private string $originalString;

    private function __construct(
        string $type,
        ?string $quadrant,
        ?int $degrees,
        ?int $minutes,
        ?float $seconds,
        ?string $cardinalDirection,
        ?Azimuth $azimuth,
        string $originalString
    ) {
        $this->type = $type;
        $this->quadrant = $quadrant;
        $this->degrees = $degrees;
        $this->minutes = $minutes;
        $this->seconds = $seconds !== null ? round($seconds, 6) : null;
        $this->cardinalDirection = $cardinalDirection;
        $this->azimuth = $azimuth;
        $this->originalString = $originalString;
    }

    /**
     * Create from quadrant DMS components.
     * Enforces VR-01, VR-03, and VR-07.
     */
    public static function fromQuadrant(
        string $quadrant,
        int $degrees,
        int $minutes,
        float $seconds,
        ?string $original = null
    ): self {
        $quadrant = strtoupper(trim($quadrant));
        if (!in_array($quadrant, ['NE', 'SE', 'SW', 'NW'], true)) {
            throw new InvalidArgumentException("VR-03: Quadrant must be one of NE, SE, SW, NW (given: {$quadrant})");
        }

        if ($degrees < 0 || $degrees > 90) {
            throw new InvalidArgumentException("VR-01: Quadrant bearing degrees must be 0–90 (given: {$degrees})");
        }
        if ($minutes < 0 || $minutes > 59) {
            throw new InvalidArgumentException("VR-01: Minutes must be 0–59 (given: {$minutes})");
        }
        if ($seconds < 0.0 || $seconds >= 60.0) {
            throw new InvalidArgumentException("VR-01: Seconds must be 0–59.999 (given: {$seconds})");
        }

        // VR-07: Bearing of exactly 0° or 90° with an ambiguous quadrant must be re-entered as a cardinal
        $totalAngle = (float) $degrees + ($minutes / 60.0) + ($seconds / 3600.0);
        if (abs($totalAngle - 0.0) < 1e-9) {
            throw new InvalidArgumentException("VR-07: Bearing of exactly 0° with quadrant {$quadrant} is ambiguous; must be entered as cardinal (e.g. DUE NORTH or DUE SOUTH).");
        }
        if (abs($totalAngle - 90.0) < 1e-9) {
            throw new InvalidArgumentException("VR-07: Bearing of exactly 90° with quadrant {$quadrant} is ambiguous; must be entered as cardinal (e.g. DUE EAST or DUE WEST).");
        }

        // Compute exact azimuth
        $azimuthDd = match ($quadrant) {
            'NE' => $totalAngle,
            'SE' => 180.0 - $totalAngle,
            'SW' => 180.0 + $totalAngle,
            'NW' => 360.0 - $totalAngle,
        };

        $azimuth = new Azimuth($azimuthDd);
        $originalStr = $original ?? sprintf('%s %d°%02d\'%05.2f" %s', $quadrant[0], $degrees, $minutes, $seconds, $quadrant[1]);

        return new self(
            self::TYPE_QUADRANT,
            $quadrant,
            $degrees,
            $minutes,
            $seconds,
            null,
            $azimuth,
            $originalStr
        );
    }

    /**
     * Create from cardinal direction (N, S, E, W, DUE NORTH, etc.).
     */
    public static function fromCardinal(string $cardinal, ?string $original = null): self
    {
        $c = strtoupper(trim($cardinal));
        $c = str_replace('DUE', '', $c);
        $c = trim($c);

        $normalized = match ($c) {
            'N', 'NORTH' => 'N',
            'E', 'EAST'  => 'E',
            'S', 'SOUTH' => 'S',
            'W', 'WEST'  => 'W',
            default => throw new InvalidArgumentException("VR-03: Unrecognized cardinal direction: {$cardinal}"),
        };

        $azimuthDd = match ($normalized) {
            'N' => 0.0,
            'E' => 90.0,
            'S' => 180.0,
            'W' => 270.0,
        };

        $originalStr = $original ?? ($cardinal !== '' ? $cardinal : "DUE {$normalized}");

        return new self(
            self::TYPE_CARDINAL,
            null,
            null,
            null,
            null,
            $normalized,
            new Azimuth($azimuthDd),
            $originalStr
        );
    }

    /**
     * Create from raw Azimuth value object.
     */
    public static function fromAzimuth(Azimuth $azimuth, ?string $original = null): self
    {
        $bearing = $azimuth->toBearing();
        if ($bearing->isCardinal()) {
            return self::fromCardinal($bearing->getCardinalDirection() ?? 'N', $original ?? (string) $azimuth);
        }

        return self::fromQuadrant(
            $bearing->getQuadrant() ?? 'NE',
            $bearing->getDegrees() ?? 0,
            $bearing->getMinutes() ?? 0,
            $bearing->getSeconds() ?? 0.0,
            $original ?? (string) $azimuth
        );
    }

    /**
     * Parse any standard bearing string.
     * Supports:
     * - "N 25°30'00\" E", "N25-30-00E", "S 64d 30m 00s E", "N25°30'E"
     * - "N 25.5 E" (decimal quadrant)
     * - "DUE NORTH", "EAST", "N", "S"
     * - "115.5", "25.5000°" (direct azimuth)
     */
    public static function parse(string $input): self
    {
        $raw = trim($input);
        if ($raw === '') {
            throw new InvalidArgumentException('Bearing string cannot be empty.');
        }

        $upper = strtoupper($raw);

        // 1. Cardinal check
        if (preg_match('/^(?:DUE\s+)?(NORTH|SOUTH|EAST|WEST|N|S|E|W)$/i', $upper, $m)) {
            return self::fromCardinal($m[1], $raw);
        }

        // 2. Full Quadrant DMS: e.g. "N 25°30'00\" E", "N 25-30-00 E", "N 25d 30m 00s E"
        if (preg_match('/^([NS])\s*(\d{1,2})[\s°dD\-]+(\d{1,2})[\s\'mM\-]+([\d\.]+)[\s"sS]*\s*([EW])$/u', $upper, $m)) {
            $quadrant = $m[1] . $m[5];
            $deg = (int) $m[2];
            $min = (int) $m[3];
            $sec = (float) $m[4];
            return self::fromQuadrant($quadrant, $deg, $min, $sec, $raw);
        }

        // 3. Quadrant DM (seconds omitted): e.g. "N 25°30' E", "N 25-30 E"
        if (preg_match('/^([NS])\s*(\d{1,2})[\s°dD\-]+(\d{1,2})[\s\'mM]*\s*([EW])$/u', $upper, $m)) {
            $quadrant = $m[1] . $m[4];
            $deg = (int) $m[2];
            $min = (int) $m[3];
            return self::fromQuadrant($quadrant, $deg, $min, 0.0, $raw);
        }

        // 4. Quadrant Decimal: e.g. "N 25.5 E", "N 25.50° E"
        if (preg_match('/^([NS])\s*(\d{1,2}(?:\.\d+)?)[\s°dD]*\s*([EW])$/u', $upper, $m)) {
            $quadrant = $m[1] . $m[3];
            $dec = (float) $m[2];
            $dms = Azimuth::decimalToDms($dec);
            return self::fromQuadrant($quadrant, $dms['deg'], $dms['min'], $dms['sec'], $raw);
        }

        // 5. Raw decimal azimuth: e.g. "115.5", "25.5°"
        if (preg_match('/^(\d{1,3}(?:\.\d+)?)\s*(?:°|DEG)?$/u', $upper, $m)) {
            $deg = (float) $m[1];
            if ($deg < 0.0 || $deg >= 360.0) {
                throw new InvalidArgumentException("VR-02: Azimuth must be 0 <= Az < 360 (given: {$deg})");
            }
            $azimuth = new Azimuth($deg);
            return self::fromAzimuth($azimuth, $raw);
        }

        throw new InvalidArgumentException("VR-01: Could not parse bearing '{$input}'. Expected formats: 'N 25°30\'00\" E', 'N 25.5 E', or 'DUE NORTH'.");
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isCardinal(): bool
    {
        return $this->type === self::TYPE_CARDINAL;
    }

    public function isQuadrant(): bool
    {
        return $this->type === self::TYPE_QUADRANT;
    }

    public function getQuadrant(): ?string
    {
        return $this->quadrant;
    }

    public function getDegrees(): ?int
    {
        return $this->degrees;
    }

    public function getMinutes(): ?int
    {
        return $this->minutes;
    }

    public function getSeconds(): ?float
    {
        return $this->seconds;
    }

    public function getCardinalDirection(): ?string
    {
        return $this->cardinalDirection;
    }

    public function getOriginalString(): string
    {
        return $this->originalString;
    }

    public function toAzimuth(): Azimuth
    {
        if ($this->azimuth === null) {
            throw new \LogicException('Azimuth not calculated');
        }
        return $this->azimuth;
    }

    /**
     * Normalized standard string representation.
     */
    public function toNormalizedString(): string
    {
        if ($this->isCardinal()) {
            return match ($this->cardinalDirection) {
                'N' => 'DUE NORTH',
                'E' => 'DUE EAST',
                'S' => 'DUE SOUTH',
                'W' => 'DUE WEST',
                default => (string) $this->cardinalDirection,
            };
        }

        if ($this->quadrant !== null && $this->degrees !== null && $this->minutes !== null && $this->seconds !== null) {
            return sprintf(
                '%s %d°%02d\'%05.2f" %s',
                $this->quadrant[0],
                $this->degrees,
                $this->minutes,
                $this->seconds,
                $this->quadrant[1]
            );
        }

        return $this->originalString;
    }

    public function __toString(): string
    {
        return $this->toNormalizedString();
    }
}
