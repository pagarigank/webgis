<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\Adjustment\CompassRuleAdjustment;
use App\Survey\Domain\Adjustment\TransitRuleAdjustment;
use App\Survey\Domain\Distance;
use PHPUnit\Framework\TestCase;

/**
 * TASK-094 — Traverse adjustment tests (Compass / Transit).
 */
class CompassRuleTest extends TestCase
{
    private CompassRuleAdjustment $compass;
    private TransitRuleAdjustment $transit;

    protected function setUp(): void
    {
        $this->compass = new CompassRuleAdjustment();
        $this->transit = new TransitRuleAdjustment();
    }

    public function testCompassRuleClosesTraverseToZeroLinearError(): void
    {
        // 4-sided traverse with small closure error:
        // POB: (1000, 2000)
        // Course 1: dE = 100.02, dN = 0.01, dist = 100.02
        // Course 2: dE = 0.01, dN = 100.01, dist = 100.01
        // Course 3: dE = -100.00, dN = 0.00, dist = 100.00
        // Course 4: dE = 0.00, dN = -100.00, dist = 100.00
        // Total deltaE = +0.03, deltaN = +0.02, perimeter = 400.03
        $pob = ['easting' => 1000.0, 'northing' => 2000.0];

        $courses = [
            ['distance' => Distance::fromMeters(100.02)],
            ['distance' => Distance::fromMeters(100.01)],
            ['distance' => Distance::fromMeters(100.00)],
            ['distance' => Distance::fromMeters(100.00)],
        ];

        $unadjustedVertices = [
            ['seq' => 1, 'point_label' => '1', 'easting' => 1000.0, 'northing' => 2000.0, 'delta_e' => 100.02, 'delta_n' => 0.01],
            ['seq' => 2, 'point_label' => '2', 'easting' => 1100.02, 'northing' => 2000.01, 'delta_e' => 0.01, 'delta_n' => 100.01],
            ['seq' => 3, 'point_label' => '3', 'easting' => 1100.03, 'northing' => 2100.02, 'delta_e' => -100.00, 'delta_n' => 0.00],
            ['seq' => 4, 'point_label' => '4', 'easting' => 1000.03, 'northing' => 2100.02, 'delta_e' => 0.00, 'delta_n' => -100.00],
        ];

        $deltaE = 0.03;
        $deltaN = 0.02;
        $perimeter = 400.03;

        $result = $this->compass->adjust($pob, $unadjustedVertices, $courses, $deltaE, $deltaN, $perimeter);

        $this->assertSame('COMPASS', $result['method']);
        $this->assertCount(4, $result['adjusted_vertices']);

        // After compass adjustment, residual closure error should be 0.000m (within 1 mm delta)
        $this->assertEqualsWithDelta(0.000, $result['closure']['delta_e'], 0.001);
        $this->assertEqualsWithDelta(0.000, $result['closure']['delta_n'], 0.001);
        $this->assertEqualsWithDelta(0.000, $result['closure']['linear_error_m'], 0.001);

        // Point 1 should remain at POB
        $this->assertEqualsWithDelta(1000.0, $result['adjusted_vertices'][0]['easting'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $result['adjusted_vertices'][0]['northing'], 0.001);
    }

    public function testTransitRuleClosesTraverseToZeroLinearError(): void
    {
        $pob = ['easting' => 5000.0, 'northing' => 5000.0];

        $courses = [
            ['distance' => 50.0],
            ['distance' => 50.0],
            ['distance' => 50.0],
            ['distance' => 50.0],
        ];

        $unadjustedVertices = [
            ['seq' => 1, 'point_label' => '1', 'easting' => 5000.0, 'northing' => 5000.0, 'delta_e' => 50.01, 'delta_n' => 0.0],
            ['seq' => 2, 'point_label' => '2', 'easting' => 5050.01, 'northing' => 5000.0, 'delta_e' => 0.0, 'delta_n' => 50.02],
            ['seq' => 3, 'point_label' => '3', 'easting' => 5050.01, 'northing' => 5050.02, 'delta_e' => -50.00, 'delta_n' => 0.0],
            ['seq' => 4, 'point_label' => '4', 'easting' => 5000.01, 'northing' => 5050.02, 'delta_e' => 0.0, 'delta_n' => -50.00],
        ];

        $deltaE = 0.01;
        $deltaN = 0.02;
        $perimeter = 200.0;

        $result = $this->transit->adjust($pob, $unadjustedVertices, $courses, $deltaE, $deltaN, $perimeter);

        $this->assertSame('TRANSIT', $result['method']);
        $this->assertEqualsWithDelta(0.000, $result['closure']['linear_error_m'], 0.001);
    }
}
