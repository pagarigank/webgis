<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\CoordinateDerivation;
use PHPUnit\Framework\TestCase;

/**
 * TASK-073 — pure coordinate-origin planning for survey control points.
 *
 * Whichever pair the caller supplies (projected or geographic) is the
 * ORIGINAL input; the other pair is derived and labelled as such. This class
 * has no I/O, so the suite runs without the database.
 */
class CoordinateDerivationTest extends TestCase
{
    public function testProjectedOriginIsOriginalPair(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'PROJECTED',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
        ]);

        $this->assertTrue($plan['valid'], implode(', ', $plan['errors']));
        $this->assertSame('PROJECTED', $plan['origin']);
        $this->assertSame(512225.12, $plan['original']['easting']);
        $this->assertSame(1678780.45, $plan['original']['northing']);
        $this->assertSame([false, false], [$plan['derived']['easting'], $plan['derived']['northing']]);
        $this->assertSame([true, true], [$plan['derived']['latitude'], $plan['derived']['longitude']]);
    }

    public function testGeographicOriginIsOriginalPair(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'GEOGRAPHIC',
            'latitude'          => 14.5995,
            'longitude'         => 120.9842,
        ]);

        $this->assertTrue($plan['valid'], implode(', ', $plan['errors']));
        $this->assertSame('GEOGRAPHIC', $plan['origin']);
        $this->assertSame([false, false], [$plan['derived']['latitude'], $plan['derived']['longitude']]);
        $this->assertSame([true, true], [$plan['derived']['easting'], $plan['derived']['northing']]);
    }

    public function testProjectedOriginDefaultsWhenCoordinateOriginMissing(): void
    {
        $plan = CoordinateDerivation::plan([
            'easting'  => 512225.12,
            'northing' => 1678780.45,
        ]);

        $this->assertTrue($plan['valid'], implode(', ', $plan['errors']));
        $this->assertSame('PROJECTED', $plan['origin']);
    }

    public function testMissingBothPairsReportsExpectedFields(): void
    {
        $plan = CoordinateDerivation::plan(['coordinate_origin' => 'PROJECTED']);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('easting', $plan['errors']);
        $this->assertArrayHasKey('northing', $plan['errors']);

        $plan = CoordinateDerivation::plan(['coordinate_origin' => 'GEOGRAPHIC']);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('latitude', $plan['errors']);
        $this->assertArrayHasKey('longitude', $plan['errors']);
    }

    public function testIncompletePairReportsMissingMember(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'PROJECTED',
            'easting'           => 512225.12,
        ]);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('northing', $plan['errors']);

        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'GEOGRAPHIC',
            'latitude'          => 14.5995,
        ]);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('longitude', $plan['errors']);
    }

    public function testBothPairsIsAmbiguous(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'PROJECTED',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
            'latitude'          => 14.5995,
            'longitude'         => 120.9842,
        ]);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('easting', $plan['errors']);
    }

    public function testLatitudeOutOfRangeRejected(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'GEOGRAPHIC',
            'latitude'          => 95,
            'longitude'         => 120.9842,
        ]);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('latitude', $plan['errors']);
    }

    public function testLongitudeOutOfRangeRejected(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'GEOGRAPHIC',
            'latitude'          => 14.5995,
            'longitude'         => 181,
        ]);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('longitude', $plan['errors']);
    }

    public function testNonNumericCoordinateRejected(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'PROJECTED',
            'easting'           => 'abc',
            'northing'          => 1678780.45,
        ]);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('easting', $plan['errors']);
    }

    public function testNonFiniteCoordinateRejected(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'PROJECTED',
            'easting'           => INF,
            'northing'          => 1678780.45,
        ]);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('easting', $plan['errors']);
    }

    public function testNumericStringsAreAccepted(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'PROJECTED',
            'easting'           => '512225.12',
            'northing'          => '1678780.45',
        ]);
        $this->assertTrue($plan['valid'], implode(', ', $plan['errors']));
        $this->assertSame(512225.12, $plan['original']['easting']);
    }

    public function testInvalidCoordinateOriginRejected(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'EQUATORIAL',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
        ]);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('coordinate_origin', $plan['errors']);
    }

    public function testElevationIsValidated(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'PROJECTED',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
            'elevation'         => 125.5,
        ]);
        $this->assertTrue($plan['valid'], implode(', ', $plan['errors']));
        $this->assertSame(125.5, $plan['elevation']);

        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'PROJECTED',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
            'elevation'         => 'not-a-number',
        ]);
        $this->assertFalse($plan['valid']);
        $this->assertArrayHasKey('elevation', $plan['errors']);
    }

    public function testAbsentElevationIsNull(): void
    {
        $plan = CoordinateDerivation::plan([
            'coordinate_origin' => 'PROJECTED',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
        ]);
        $this->assertTrue($plan['valid']);
        $this->assertNull($plan['elevation']);
    }
}