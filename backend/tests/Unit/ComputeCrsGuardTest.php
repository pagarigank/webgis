<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\ComputeCrsGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * TASK-091 — Compute-CRS selection and guards tests.
 */
class ComputeCrsGuardTest extends TestCase
{
    private ComputeCrsGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new ComputeCrsGuard();
    }

    public function testSuggestsCorrectPtmZonesByLongitude(): void
    {
        // Zone I: Longitude < 118 (Palawan)
        $z1 = $this->guard->suggestPtmZone(117.5);
        $this->assertSame(3121, $z1['srid']);
        $this->assertSame('PTM Zone I', $z1['zone']);

        // Zone II: 118 <= Lon < 120
        $z2 = $this->guard->suggestPtmZone(119.2);
        $this->assertSame(3122, $z2['srid']);
        $this->assertSame('PTM Zone II', $z2['zone']);

        // Zone III: 120 <= Lon < 122 (Manila / NCR: ~120.98)
        $z3 = $this->guard->suggestPtmZone(120.98);
        $this->assertSame(3123, $z3['srid']);
        $this->assertSame('PTM Zone III', $z3['zone']);

        // Zone IV: 122 <= Lon < 124 (Iloilo / Cebu: ~123.8)
        $z4 = $this->guard->suggestPtmZone(123.5);
        $this->assertSame(3124, $z4['srid']);
        $this->assertSame('PTM Zone IV', $z4['zone']);

        // Zone V: Lon >= 124 (Davao: ~125.6)
        $z5 = $this->guard->suggestPtmZone(125.6);
        $this->assertSame(3125, $z5['srid']);
        $this->assertSame('PTM Zone V', $z5['zone']);
    }

    public function testAllowsGridBearingReference(): void
    {
        $this->expectNotToPerformAssertions();
        $this->guard->assertGridBearingReference('GRID');
        $this->guard->assertGridBearingReference('grid');
        $this->guard->assertGridBearingReference(null);
    }

    public function testRejectsGeodeticOrMagneticBearingReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Plane traverse computation strictly requires GRID bearings');
        $this->guard->assertGridBearingReference('GEODETIC');
    }

    public function testRejectsMagneticBearingReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->guard->assertGridBearingReference('MAGNETIC');
    }

    public function testAreaOfUseBoundsChecking(): void
    {
        $crs = [
            'code'       => 'EPSG:3123',
            'area_south' => 4.40,
            'area_west'  => 116.50,
            'area_north' => 21.50,
            'area_east'  => 127.00,
        ];

        // Inside Philippines bounds (Manila: 14.6, 120.98)
        $this->expectNotToPerformAssertions();
        $this->guard->assertWithinAreaOfUse($crs, 14.60, 120.98);
    }

    public function testAreaOfUseRejectsCoordinatesOutsideBounds(): void
    {
        $crs = [
            'code'       => 'EPSG:3123',
            'area_south' => 4.40,
            'area_west'  => 116.50,
            'area_north' => 21.50,
            'area_east'  => 127.00,
        ];

        // Coordinates in Tokyo, Japan (35.6, 139.6)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VR-20: Coordinates (lat 35.6, lon 139.6) fall outside the declared area of use');
        $this->guard->assertWithinAreaOfUse($crs, 35.6, 139.6);
    }
}
