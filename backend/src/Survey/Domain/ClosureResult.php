<?php
declare(strict_types=1);

namespace App\Survey\Domain;

/**
 * Value object representing traverse closure calculation results (TASK-088).
 */
final class ClosureResult
{
    public const STATUS_WITHIN_TOLERANCE  = 'WITHIN_TOLERANCE';
    public const STATUS_EXCEEDS_TOLERANCE = 'EXCEEDS_TOLERANCE';
    public const STATUS_NOT_CLOSED        = 'NOT_CLOSED';
    public const STATUS_INDETERMINATE     = 'INDETERMINATE';

    private float $deltaE;
    private float $deltaN;
    private float $linearErrorM;
    private ?float $errorAzimuthDd;
    private float $perimeterM;
    private ?float $relativePrecisionDenominator;
    private string $relativePrecisionString;
    private string $status;
    private array $warnings;

    public function __construct(
        float $deltaE,
        float $deltaN,
        float $linearErrorM,
        ?float $errorAzimuthDd,
        float $perimeterM,
        ?float $relativePrecisionDenominator,
        string $relativePrecisionString,
        string $status,
        array $warnings = []
    ) {
        $this->deltaE = $deltaE;
        $this->deltaN = $deltaN;
        $this->linearErrorM = $linearErrorM;
        $this->errorAzimuthDd = $errorAzimuthDd;
        $this->perimeterM = $perimeterM;
        $this->relativePrecisionDenominator = $relativePrecisionDenominator;
        $this->relativePrecisionString = $relativePrecisionString;
        $this->status = $status;
        $this->warnings = $warnings;
    }

    public function getDeltaE(): float
    {
        return $this->deltaE;
    }

    public function getDeltaN(): float
    {
        return $this->deltaN;
    }

    public function getLinearErrorM(): float
    {
        return $this->linearErrorM;
    }

    public function getErrorAzimuthDd(): ?float
    {
        return $this->errorAzimuthDd;
    }

    public function getPerimeterM(): float
    {
        return $this->perimeterM;
    }

    public function getRelativePrecisionDenominator(): ?float
    {
        return $this->relativePrecisionDenominator;
    }

    public function getRelativePrecisionString(): string
    {
        return $this->relativePrecisionString;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function toArray(): array
    {
        return [
            'delta_e'                        => $this->deltaE,
            'delta_n'                        => $this->deltaN,
            'linear_error_m'                 => $this->linearErrorM,
            'error_azimuth_dd'               => $this->errorAzimuthDd,
            'perimeter_m'                    => $this->perimeterM,
            'relative_precision_denominator' => $this->relativePrecisionDenominator,
            'relative_precision'             => $this->relativePrecisionString,
            'status'                         => $this->status,
            'warnings'                       => $this->warnings,
        ];
    }
}
