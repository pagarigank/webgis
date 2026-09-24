<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\Azimuth;
use App\Survey\Domain\Bearing;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * TASK-078 — Bearing value objects and parsing (pure domain).
 *
 * Requirements:
 * - Quadrant DMS, quadrant decimal, azimuth DMS/decimal, cardinal.
 * - Normalise to azimuth.
 * - Keep the original string untouched.
 * - Quadrant ↔ azimuth conversion exact to 1e-9 in all four quadrants and boundaries.
 * - Ambiguous 0°/90° with quadrant rejected (VR-07).
 * - Round-trip stable.
 */
class BearingTest extends TestCase
{
    public function testQuadrantToAzimuthKnownVectors(): void
    {
        // Quadrant 1: NE (Azimuth = angle)
        $ne = Bearing::fromQuadrant('NE', 25, 30, 0.0);
        $this->assertEqualsWithDelta(25.5, $ne->toAzimuth()->toDecimalDegrees(), 1e-9);
        $this->assertSame('NE', $ne->getQuadrant());
        $this->assertSame(25, $ne->getDegrees());
        $this->assertSame(30, $ne->getMinutes());
        $this->assertEqualsWithDelta(0.0, $ne->getSeconds(), 1e-9);

        // Quadrant 2: SE (Azimuth = 180 - angle)
        $se = Bearing::fromQuadrant('SE', 64, 30, 0.0);
        $this->assertEqualsWithDelta(115.5, $se->toAzimuth()->toDecimalDegrees(), 1e-9);

        // Quadrant 3: SW (Azimuth = 180 + angle)
        $sw = Bearing::fromQuadrant('SW', 30, 0, 0.0);
        $this->assertEqualsWithDelta(210.0, $sw->toAzimuth()->toDecimalDegrees(), 1e-9);

        // Quadrant 4: NW (Azimuth = 360 - angle)
        $nw = Bearing::fromQuadrant('NW', 45, 15, 30.0);
        $expectedAngle = 45.0 + (15.0 / 60.0) + (30.0 / 3600.0);
        $this->assertEqualsWithDelta(360.0 - $expectedAngle, $nw->toAzimuth()->toDecimalDegrees(), 1e-9);
    }

    public function testCardinalBearings(): void
    {
        $north = Bearing::fromCardinal('N');
        $this->assertEqualsWithDelta(0.0, $north->toAzimuth()->toDecimalDegrees(), 1e-9);
        $this->assertSame('CARDINAL', $north->getType());

        $east = Bearing::fromCardinal('E');
        $this->assertEqualsWithDelta(90.0, $east->toAzimuth()->toDecimalDegrees(), 1e-9);

        $south = Bearing::fromCardinal('S');
        $this->assertEqualsWithDelta(180.0, $south->toAzimuth()->toDecimalDegrees(), 1e-9);

        $west = Bearing::fromCardinal('W');
        $this->assertEqualsWithDelta(270.0, $west->toAzimuth()->toDecimalDegrees(), 1e-9);
    }

    public function testVr07AmbiguousZeroAndNinetyDegreesRejected(): void
    {
        // 0° with quadrant is ambiguous (should be DUE NORTH or DUE SOUTH)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VR-07');
        Bearing::fromQuadrant('NE', 0, 0, 0.0);
    }

    public function testVr07AmbiguousNinetyDegreesRejected(): void
    {
        // 90° with quadrant is ambiguous (should be DUE EAST or DUE WEST)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VR-07');
        Bearing::fromQuadrant('SE', 90, 0, 0.0);
    }

    public function testVr01AngleRangeValidation(): void
    {
        try {
            Bearing::fromQuadrant('NE', 91, 0, 0.0);
            $this->fail('Expected exception for degrees > 90');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('VR-01', $e->getMessage());
        }

        try {
            Bearing::fromQuadrant('NE', 45, 60, 0.0);
            $this->fail('Expected exception for minutes >= 60');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('VR-01', $e->getMessage());
        }

        try {
            Bearing::fromQuadrant('NE', 45, 30, 60.0);
            $this->fail('Expected exception for seconds >= 60');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('VR-01', $e->getMessage());
        }
    }

    public function testParseQuadrantFormats(): void
    {
        // Standard DMS format
        $b1 = Bearing::parse("N 25°30'00\" E");
        $this->assertEqualsWithDelta(25.5, $b1->toAzimuth()->toDecimalDegrees(), 1e-9);
        $this->assertSame("N 25°30'00\" E", $b1->getOriginalString());

        // Hyphenated format
        $b2 = Bearing::parse('S 64-30-00 E');
        $this->assertEqualsWithDelta(115.5, $b2->toAzimuth()->toDecimalDegrees(), 1e-9);

        // Compact format without spaces
        $b3 = Bearing::parse("N25°30'E");
        $this->assertEqualsWithDelta(25.5, $b3->toAzimuth()->toDecimalDegrees(), 1e-9);

        // Letter degree format
        $b4 = Bearing::parse('S 30d 00m 00s W');
        $this->assertEqualsWithDelta(210.0, $b4->toAzimuth()->toDecimalDegrees(), 1e-9);

        // Decimal quadrant format
        $b5 = Bearing::parse('N 25.5 E');
        $this->assertEqualsWithDelta(25.5, $b5->toAzimuth()->toDecimalDegrees(), 1e-9);

        // Cardinal phrasing
        $b6 = Bearing::parse('DUE NORTH');
        $this->assertEqualsWithDelta(0.0, $b6->toAzimuth()->toDecimalDegrees(), 1e-9);
        $b7 = Bearing::parse('due west');
        $this->assertEqualsWithDelta(270.0, $b7->toAzimuth()->toDecimalDegrees(), 1e-9);

        // Raw azimuth decimal
        $b8 = Bearing::parse('115.5');
        $this->assertEqualsWithDelta(115.5, $b8->toAzimuth()->toDecimalDegrees(), 1e-9);
    }

    public function testAzimuthToBearingRoundTrip(): void
    {
        $originalAzimuth = 115.5; // S 64°30'00" E
        $azimuth = new Azimuth($originalAzimuth);
        $bearing = $azimuth->toBearing();

        $this->assertSame('SE', $bearing->getQuadrant());
        $this->assertSame(64, $bearing->getDegrees());
        $this->assertSame(30, $bearing->getMinutes());
        $this->assertEqualsWithDelta(0.0, $bearing->getSeconds(), 1e-9);
        $this->assertEqualsWithDelta($originalAzimuth, $bearing->toAzimuth()->toDecimalDegrees(), 1e-9);
    }

    public function testAzimuthBoundaryValues(): void
    {
        $az0 = new Azimuth(0.0);
        $this->assertEqualsWithDelta(0.0, $az0->toDecimalDegrees(), 1e-9);
        $this->assertSame('N', $az0->toBearing()->getCardinalDirection());

        $az359 = new Azimuth(359.999999999);
        $this->assertEqualsWithDelta(359.999999999, $az359->toDecimalDegrees(), 1e-9);

        // Normalization: 360 wraps to 0
        $az360 = new Azimuth(360.0);
        $this->assertEqualsWithDelta(0.0, $az360->toDecimalDegrees(), 1e-9);

        // Normalization: -90 wraps to 270
        $azNeg = new Azimuth(-90.0);
        $this->assertEqualsWithDelta(270.0, $azNeg->toDecimalDegrees(), 1e-9);
    }
}
