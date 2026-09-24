<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\AreaCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * TASK-089 — Area calculation and cross-check tests.
 */
class AreaTest extends TestCase
{
    private AreaCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AreaCalculator();
    }

    public function testRectangleShoelaceMatchesKnownGeometry(): void
    {
        // 100m x 50m rectangle = 5000 sqm
        $vertices = [
            ['easting' => 100.0, 'northing' => 100.0],
            ['easting' => 200.0, 'northing' => 100.0],
            ['easting' => 200.0, 'northing' => 150.0],
            ['easting' => 100.0, 'northing' => 150.0],
        ];

        $area = $this->calculator->computeShoelaceArea($vertices);
        $this->assertSame(5000.0, $area);
    }

    public function testTriangleShoelaceArea(): void
    {
        // Right triangle with base 30m, height 40m = 600 sqm
        $vertices = [
            ['easting' => 0.0, 'northing' => 0.0],
            ['easting' => 30.0, 'northing' => 0.0],
            ['easting' => 0.0, 'northing' => 40.0],
        ];

        $area = $this->calculator->computeShoelaceArea($vertices);
        $this->assertSame(600.0, $area);
    }

    public function testLessThanThreeVerticesThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->computeShoelaceArea([
            ['easting' => 0.0, 'northing' => 0.0],
            ['easting' => 10.0, 'northing' => 10.0],
        ]);
    }

    public function testCompareAreasFlagsSourceDisagreementAboveThreshold(): void
    {
        // 10,000 sqm computed, 9,900 sqm source -> diff 100 sqm (1.01% > 0.5% warning)
        $comparison = $this->calculator->compareAreas(10000.0, 9900.0, 10000.1);

        $this->assertSame(100.0, $comparison['diff_source_sqm']);
        $this->assertEqualsWithDelta(1.0101, $comparison['diff_source_pct'], 0.001);

        $rules = array_column($comparison['warnings'], 'rule');
        $this->assertContains('VR-16', $rules);
        $this->assertSame(AreaCalculator::VALIDATION_AID_NOTE, $comparison['note']);
    }

    public function testCompareAreasFlagsPostgisDisagreementAbove001Percent(): void
    {
        // PostGIS area differs by 0.05%
        $comparison = $this->calculator->compareAreas(10000.0, 10000.0, 9995.0);

        $rules = array_column($comparison['warnings'], 'rule');
        $this->assertContains('VR-17', $rules);
    }
}
