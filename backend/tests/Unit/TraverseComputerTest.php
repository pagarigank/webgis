<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\Azimuth;
use App\Survey\Domain\Bearing;
use App\Survey\Domain\Distance;
use App\Survey\Domain\TraverseComputer;
use PHPUnit\Framework\TestCase;

/**
 * TASK-087 — Traverse computer (pure domain).
 *
 * AC: vertices match hand-computed benchmarks to 1 mm (0.001 m) on every fixture.
 * Strictly pure domain: no PDO, no HTTP, no framework dependencies.
 */
class TraverseComputerTest extends TestCase
{
    private TraverseComputer $computer;

    protected function setUp(): void
    {
        $this->computer = new TraverseComputer();
    }

    /**
     * Benchmark 1: Orthogonal rectangular lot (100m x 50m).
     * Tie point at (500000, 1000000), tie line 100m Due North to POB.
     */
    public function testOrthogonalRectangleTraverseMatchesBenchmarkTo1Mm(): void
    {
        $tiePoint = [
            'name'     => 'BLLM-1',
            'easting'  => 500000.0000,
            'northing' => 1000000.0000,
        ];

        $tieLines = [
            [
                'bearing'  => Bearing::fromAzimuth(new Azimuth(0.0)), // Due North
                'distance' => Distance::fromMeters(100.0),
            ],
        ];

        // 4 boundary courses
        $courses = [
            [
                'seq'      => 1,
                'from'     => '1',
                'to'       => '2',
                'bearing'  => Bearing::fromAzimuth(new Azimuth(90.0)), // East
                'distance' => Distance::fromMeters(100.0),
            ],
            [
                'seq'      => 2,
                'from'     => '2',
                'to'       => '3',
                'bearing'  => Bearing::fromAzimuth(new Azimuth(0.0)), // North
                'distance' => Distance::fromMeters(50.0),
            ],
            [
                'seq'      => 3,
                'from'     => '3',
                'to'       => '4',
                'bearing'  => Bearing::fromAzimuth(new Azimuth(270.0)), // West
                'distance' => Distance::fromMeters(100.0),
            ],
            [
                'seq'      => 4,
                'from'     => '4',
                'to'       => '1',
                'bearing'  => Bearing::fromAzimuth(new Azimuth(180.0)), // South
                'distance' => Distance::fromMeters(50.0),
            ],
        ];

        $result = $this->computer->compute($tiePoint, $tieLines, $courses);

        // POB should be exactly (500000.000, 1000100.000)
        $this->assertEqualsWithDelta(500000.000, $result['pob']['easting'], 0.001);
        $this->assertEqualsWithDelta(1000100.000, $result['pob']['northing'], 0.001);

        $vertices = $result['vertices'];
        $this->assertCount(4, $vertices);

        // Point 1 (POB)
        $this->assertSame('1', $vertices[0]['point_label']);
        $this->assertEqualsWithDelta(500000.000, $vertices[0]['easting'], 0.001);
        $this->assertEqualsWithDelta(1000100.000, $vertices[0]['northing'], 0.001);

        // Point 2
        $this->assertSame('2', $vertices[1]['point_label']);
        $this->assertEqualsWithDelta(500100.000, $vertices[1]['easting'], 0.001);
        $this->assertEqualsWithDelta(1000100.000, $vertices[1]['northing'], 0.001);

        // Point 3
        $this->assertSame('3', $vertices[2]['point_label']);
        $this->assertEqualsWithDelta(500100.000, $vertices[2]['easting'], 0.001);
        $this->assertEqualsWithDelta(1000150.000, $vertices[2]['northing'], 0.001);

        // Point 4
        $this->assertSame('4', $vertices[3]['point_label']);
        $this->assertEqualsWithDelta(500000.000, $vertices[3]['easting'], 0.001);
        $this->assertEqualsWithDelta(1000150.000, $vertices[3]['northing'], 0.001);

        // Closing coordinates back to 1
        $this->assertEqualsWithDelta(500000.000, $result['close']['easting'], 0.001);
        $this->assertEqualsWithDelta(1000100.000, $result['close']['northing'], 0.001);

        // Departures and Latitudes
        $this->assertEqualsWithDelta(0.000, $result['closure']['delta_e'], 0.001);
        $this->assertEqualsWithDelta(0.000, $result['closure']['delta_n'], 0.001);
        $this->assertEqualsWithDelta(0.000, $result['closure']['linear_error_m'], 0.001);
        $this->assertEqualsWithDelta(300.000, $result['closure']['perimeter_m'], 0.001);
    }

    /**
     * Benchmark 2: Rotated diamond lot (45 degrees).
     * Sides of 141.4214 m (~100*sqrt(2)).
     */
    public function testRotatedDiamondTraverseMatchesBenchmarkTo1Mm(): void
    {
        $tiePoint = [
            'name'     => 'BLLM-2',
            'easting'  => 512000.0000,
            'northing' => 1678000.0000,
        ];

        // Zero tie lines: POB is directly at tie point
        $tieLines = [];

        $dist = 141.421356;
        $courses = [
            [
                'seq'      => 1,
                'bearing'  => Bearing::fromString('N 45°00\'00" E'),
                'distance' => Distance::fromMeters($dist),
            ],
            [
                'seq'      => 2,
                'bearing'  => Bearing::fromString('S 45°00\'00" E'),
                'distance' => Distance::fromMeters($dist),
            ],
            [
                'seq'      => 3,
                'bearing'  => Bearing::fromString('S 45°00\'00" W'),
                'distance' => Distance::fromMeters($dist),
            ],
            [
                'seq'      => 4,
                'bearing'  => Bearing::fromString('N 45°00\'00" W'),
                'distance' => Distance::fromMeters($dist),
            ],
        ];

        $result = $this->computer->compute($tiePoint, $tieLines, $courses);
        $vertices = $result['vertices'];

        // Point 1: (512000.000, 1678000.000)
        $this->assertEqualsWithDelta(512000.000, $vertices[0]['easting'], 0.001);
        $this->assertEqualsWithDelta(1678000.000, $vertices[0]['northing'], 0.001);

        // Point 2: (512100.000, 1678100.000)
        $this->assertEqualsWithDelta(512100.000, $vertices[1]['easting'], 0.001);
        $this->assertEqualsWithDelta(1678100.000, $vertices[1]['northing'], 0.001);

        // Point 3: (512200.000, 1678000.000)
        $this->assertEqualsWithDelta(512200.000, $vertices[2]['easting'], 0.001);
        $this->assertEqualsWithDelta(1678000.000, $vertices[2]['northing'], 0.001);

        // Point 4: (512100.000, 1677900.000)
        $this->assertEqualsWithDelta(512100.000, $vertices[3]['easting'], 0.001);
        $this->assertEqualsWithDelta(1677900.000, $vertices[3]['northing'], 0.001);

        // Closing back to Point 1 within 1 mm
        $this->assertEqualsWithDelta(512000.000, $result['close']['easting'], 0.001);
        $this->assertEqualsWithDelta(1678000.000, $result['close']['northing'], 0.001);
    }

    /**
     * Benchmark 3: Multi-segment tie line (traverse through traverse stations to POB).
     */
    public function testMultiSegmentTieLineResolvesPobCorrectly(): void
    {
        $tiePoint = [
            'name'     => 'BLLM-10',
            'easting'  => 500000.000,
            'northing' => 1500000.000,
        ];

        $tieLines = [
            [
                'bearing'  => Bearing::fromString('DUE EAST'),
                'distance' => Distance::fromMeters(200.000),
            ],
            [
                'bearing'  => Bearing::fromString('DUE NORTH'),
                'distance' => Distance::fromMeters(150.000),
            ],
        ];

        $courses = [
            [
                'seq'      => 1,
                'bearing'  => Bearing::fromString('DUE EAST'),
                'distance' => Distance::fromMeters(50.000),
            ],
            [
                'seq'      => 2,
                'bearing'  => Bearing::fromString('DUE SOUTH'),
                'distance' => Distance::fromMeters(50.000),
            ],
            [
                'seq'      => 3,
                'bearing'  => Bearing::fromString('DUE WEST'),
                'distance' => Distance::fromMeters(50.000),
            ],
            [
                'seq'      => 4,
                'bearing'  => Bearing::fromString('DUE NORTH'),
                'distance' => Distance::fromMeters(50.000),
            ],
        ];

        $result = $this->computer->compute($tiePoint, $tieLines, $courses);

        // POB should be 500000 + 200 = 500200, 1500000 + 150 = 1500150
        $this->assertEqualsWithDelta(500200.000, $result['pob']['easting'], 0.001);
        $this->assertEqualsWithDelta(1500150.000, $result['pob']['northing'], 0.001);
        $this->assertEqualsWithDelta(500200.000, $result['vertices'][0]['easting'], 0.001);
        $this->assertEqualsWithDelta(1500150.000, $result['vertices'][0]['northing'], 0.001);
    }
}
