<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\ClosureCalculator;
use App\Survey\Domain\ClosureResult;
use PHPUnit\Framework\TestCase;

/**
 * TASK-088 — Closure calculation tests.
 *
 * AC: matches benchmarks; a perfectly closed traverse yields infinite relative precision without dividing by zero.
 */
class ClosureTest extends TestCase
{
    private ClosureCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ClosureCalculator();
    }

    public function testPerfectClosureYieldsInfinitePrecisionWithoutDividingByZero(): void
    {
        $result = $this->calculator->calculate(0.0, 0.0, 400.0);

        $this->assertSame(0.0, $result->getLinearErrorM());
        $this->assertNull($result->getErrorAzimuthDd());
        $this->assertNull($result->getRelativePrecisionDenominator());
        $this->assertSame('1:INF', $result->getRelativePrecisionString());
        $this->assertSame(ClosureResult::STATUS_WITHIN_TOLERANCE, $result->getStatus());
        $this->assertEmpty($result->getWarnings());
    }

    public function testRealisticSmallClosureWithinTolerance(): void
    {
        // deltaE = +0.014 m, deltaN = -0.009 m, perimeter = 412.88 m
        // linearError = sqrt(0.014^2 + (-0.009)^2) = sqrt(0.000196 + 0.000081) = sqrt(0.000277) = 0.016643 m
        // precision = 412.88 / 0.016643 = ~24,807.5
        $result = $this->calculator->calculate(0.014, -0.009, 412.88);

        $this->assertEqualsWithDelta(0.0166, $result->getLinearErrorM(), 0.001);
        $this->assertSame('1:24808', $result->getRelativePrecisionString());
        $this->assertSame(ClosureResult::STATUS_WITHIN_TOLERANCE, $result->getStatus());
        $this->assertEmpty($result->getWarnings());

        // Error azimuth: atan2(0.014, -0.009) in rad -> deg
        // deltaE > 0, deltaN < 0 -> 2nd quadrant -> ~122.7 deg
        $this->assertEqualsWithDelta(122.74, $result->getErrorAzimuthDd(), 0.1);
    }

    public function testExceedingLinearToleranceTriggersWarningAndStatus(): void
    {
        // Linear error 0.15 m > 0.10 m default tolerance
        $result = $this->calculator->calculate(0.12, 0.09, 1000.0);

        $this->assertEqualsWithDelta(0.150, $result->getLinearErrorM(), 0.001);
        $this->assertSame(ClosureResult::STATUS_EXCEEDS_TOLERANCE, $result->getStatus());

        $warnings = $result->getWarnings();
        $this->assertNotEmpty($warnings);
        $rules = array_column($warnings, 'rule');
        $this->assertContains('VR-11', $rules);
    }

    public function testExceedingPrecisionToleranceTriggersWarning(): void
    {
        // Linear error = 0.08m (within 0.10m), but perimeter = 200m
        // precision = 200 / 0.08 = 2500 < 5000 required
        $result = $this->calculator->calculate(0.08, 0.0, 200.0);

        $this->assertSame(ClosureResult::STATUS_EXCEEDS_TOLERANCE, $result->getStatus());
        $this->assertSame('1:2500', $result->getRelativePrecisionString());

        $warnings = $result->getWarnings();
        $this->assertNotEmpty($warnings);
        $rules = array_column($warnings, 'rule');
        $this->assertContains('VR-12', $rules);
    }

    public function testExtremeLinearErrorFlagsNotClosed(): void
    {
        // Linear error 200m > perimeter 100m
        $result = $this->calculator->calculate(150.0, 150.0, 100.0);
        $this->assertSame(ClosureResult::STATUS_NOT_CLOSED, $result->getStatus());
    }
}
