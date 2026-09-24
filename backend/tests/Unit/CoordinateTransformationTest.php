<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Crs\CoordinateTransformationService;
use App\Core\Error\ApiError;
use Tests\TestCase;

/**
 * TASK-095 — Explicit Coordinate Transformation tests.
 */
class CoordinateTransformationTest extends TestCase
{
    private CoordinateTransformationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CoordinateTransformationService($this->pdo());
    }

    public function testResolvesCrsByIdOrCode(): void
    {
        $crs = $this->service->resolveCrs('EPSG:3123');
        $this->assertSame(3123, $crs['srid']);
        $this->assertSame('EPSG:3123', $crs['code']);
        $this->assertTrue($crs['is_projected']);

        $bySrid = $this->service->resolveCrs(4326);
        $this->assertSame(4326, $bySrid['srid']);
        $this->assertFalse($bySrid['is_projected']);
    }

    public function testTransformsProjectedToGeographic(): void
    {
        // PRS92 Zone III (EPSG:3123) coordinates in Metro Manila
        $coords = [
            ['x' => 500000.0, 'y' => 1600000.0],
        ];

        $result = $this->service->transform($coords, 'EPSG:3123', 'EPSG:4326', [
            'persist_log' => false,
        ]);

        $this->assertSame('EPSG:3123', $result['source_crs']['code']);
        $this->assertSame('EPSG:4326', $result['target_crs']['code']);

        $tx = $result['transformed'][0];
        // Central meridian of Zone III is 121.0 deg E. Easting 500,000 should have longitude ~121.0
        $this->assertEqualsWithDelta(121.0, $tx['x'], 0.01);
        // Northing 1,600,000 is ~14.46 deg N
        $this->assertEqualsWithDelta(14.46, $tx['y'], 0.1);
    }

    public function testRejectsUnsupportedCrs(): void
    {
        $this->expectException(ApiError::class);
        $this->service->resolveCrs('EPSG:999999');
    }
}
