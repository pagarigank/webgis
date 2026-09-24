<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\Distance;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * TASK-079 — Distance value object and unit conversion.
 *
 * Requirements:
 * - Canonical metres (distance_m).
 * - Exact conversion factors.
 * - Original value and unit preserved.
 * - Metre ↔ foot conversion exact to documented precision.
 * - Zero / negative / invalid (< 0.01 m) rejected (VR-04, VR-06).
 * - Distance > 5000 m flagged as warning (VR-05).
 */
class DistanceTest extends TestCase
{
    public function testCanonicalMetersPreserved(): void
    {
        $d = Distance::fromMeters(45.20, '45.20 m');
        $this->assertEqualsWithDelta(45.20, $d->toMeters(), 1e-6);
        $this->assertSame('m', $d->getOriginalUnit());
        $this->assertSame('45.20 m', $d->getOriginalString());
        $this->assertFalse($d->hasWarning());
    }

    public function testInternationalFootConversionExact(): void
    {
        // 100 ft = 30.48 m exact
        $d = Distance::fromUnit(100.0, 'ft');
        $this->assertEqualsWithDelta(30.48, $d->toMeters(), 1e-9);
        $this->assertEqualsWithDelta(100.0, $d->toUnit('ft'), 1e-9);
    }

    public function testUsSurveyFootConversion(): void
    {
        // 1 US survey foot = 1200 / 3937 meters
        $d = Distance::fromUnit(3937.0, 'usft');
        $this->assertEqualsWithDelta(1200.0, $d->toMeters(), 1e-9);
    }

    public function testVaraAndChainConversions(): void
    {
        // 1 Spanish vara = 0.835905 m
        $d1 = Distance::fromUnit(10.0, 'vara');
        $this->assertEqualsWithDelta(8.35905, $d1->toMeters(), 1e-9);

        // 1 Chain = 20.1168 m
        $d2 = Distance::fromUnit(2.0, 'ch');
        $this->assertEqualsWithDelta(40.2336, $d2->toMeters(), 1e-9);
    }

    public function testVr04ZeroOrNegativeOrTinyRejected(): void
    {
        // Zero rejected
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VR-04');
        Distance::fromMeters(0.0);
    }

    public function testVr04NegativeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VR-04');
        Distance::fromMeters(-10.5);
    }

    public function testVr04BelowThresholdRejected(): void
    {
        // VR-04 requires distance > 0.01 m
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VR-04');
        Distance::fromMeters(0.005);
    }

    public function testVr06UnregisteredUnitRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VR-06');
        Distance::fromUnit(50.0, 'lightyear');
    }

    public function testVr05LargeDistanceWarning(): void
    {
        // Distance > 5,000 m produces a warning but is valid
        $d = Distance::fromMeters(5001.0);
        $this->assertTrue($d->hasWarning());
        $this->assertStringContainsString('VR-05', $d->getWarningMessage() ?? '');
    }

    public function testParseDistanceStrings(): void
    {
        $d1 = Distance::parse('45.20 meters');
        $this->assertEqualsWithDelta(45.20, $d1->toMeters(), 1e-4);
        $this->assertSame('m', $d1->getOriginalUnit());

        $d2 = Distance::parse('120.5 m');
        $this->assertEqualsWithDelta(120.5, $d2->toMeters(), 1e-4);

        $d3 = Distance::parse('100.0 ft');
        $this->assertEqualsWithDelta(30.48, $d3->toMeters(), 1e-4);
        $this->assertSame('ft', $d3->getOriginalUnit());

        $d4 = Distance::parse('50.25');
        $this->assertEqualsWithDelta(50.25, $d4->toMeters(), 1e-4);
        $this->assertSame('m', $d4->getOriginalUnit());
    }
}
