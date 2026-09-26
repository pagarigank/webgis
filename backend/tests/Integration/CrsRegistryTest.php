<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;

class CrsRegistryTest extends TestCase
{
    public function testCrsRegistryContainsPrs92(): void
    {
        $app = $this->getAppInstance();
        $pdo = $app->getContainer()->get(PDO::class);

        $stmt = $pdo->query("SELECT * FROM ref.crs_registry WHERE code = 'EPSG:3121'");
        $row = $stmt->fetch();

        $this->assertNotEmpty($row, 'CRS registry should be queryable and contain EPSG:3121');
        $this->assertSame(3121, $row['srid']);
        $this->assertSame('PRS92', $row['datum']);
        $this->assertFalse($row['is_historical']);
    }

    /**
     * The PPCS zones the BLLM schedules are expressed in.
     *
     * These carry no datum shift on purpose: the schedules state a geographic
     * position and a projected grid position for the same monument, and in the
     * local Clarke 1866 datum the two agree. Applying a real clrk66 -> WGS 84
     * transformation separates them by roughly 200 m.
     */
    public function testPpcsZonesAreRegistered(): void
    {
        $pdo = $this->getAppInstance()->getContainer()->get(PDO::class);

        $stmt = $pdo->query(
            "SELECT srid, code, name, datum, zone, is_projected, is_active
               FROM ref.crs_registry
              WHERE code LIKE 'PPCS:%'
           ORDER BY srid"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(5, $rows, 'all five PPCS zones should be registered');

        $roman = ['I', 'II', 'III', 'IV', 'V'];
        foreach ($rows as $i => $row) {
            $this->assertSame(990101 + $i, (int) $row['srid']);
            $this->assertSame('PPCS:' . $row['srid'], $row['code']);
            $this->assertSame('PPCS zone ' . $roman[$i], $row['name']);
            $this->assertSame('PPCS (Clarke 1866)', $row['datum']);
            $this->assertSame($roman[$i], $row['zone']);
            $this->assertTrue((bool) $row['is_projected']);
            $this->assertTrue((bool) $row['is_active']);
        }
    }

    /**
     * Each zone must also exist in spatial_ref_sys, or PostGIS cannot transform
     * through it and the controller would fail at ST_Transform time.
     */
    public function testPpcsZonesExistInSpatialRefSys(): void
    {
        $pdo = $this->getAppInstance()->getContainer()->get(PDO::class);

        $stmt = $pdo->query(
            "SELECT srid, srtext, proj4text FROM spatial_ref_sys
              WHERE srid BETWEEN 990101 AND 990105 ORDER BY srid"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(5, $rows, 'every registered PPCS zone needs a spatial_ref_sys row');

        foreach ($rows as $row) {
            $srid = (int) $row['srid'];
            $this->assertNotEmpty($row['srtext'], "SRID $srid needs WKT");
            $this->assertNotEmpty($row['proj4text'], "SRID $srid needs a proj4 definition");

            // proj4text must not smuggle a datum shift back in either.
            $this->assertStringNotContainsStringIgnoringCase(
                'towgs84',
                (string) $row['proj4text'],
                "SRID $srid proj4text must stay unshifted"
            );
        }
    }

    /**
     * Regression guard for a subtle failure: writing TOWGS84[0,0,0,0,0,0,0]
     * into the WKT looks like it declares "no shift", but PROJ discards that
     * degenerate parameter set and silently falls back to a genuine Clarke 1866
     * -> WGS 84 conversion, moving every northing by about 117 m. The correct
     * form is a DATUM node carrying only a SPHEROID, with no TOWGS84 element.
     */
    public function testPpcsWktDeclaresNoDatumShift(): void
    {
        $pdo = $this->getAppInstance()->getContainer()->get(PDO::class);

        $stmt = $pdo->query(
            "SELECT srid, srtext FROM spatial_ref_sys
              WHERE srid BETWEEN 990101 AND 990105 ORDER BY srid"
        );

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $srid = (int) $row['srid'];
            $this->assertStringNotContainsStringIgnoringCase(
                'TOWGS84',
                (string) $row['srtext'],
                "SRID $srid WKT must omit TOWGS84; a zero-valued TOWGS84 is not an identity transform to PROJ"
            );
            $this->assertStringContainsStringIgnoringCase(
                'SPHEROID["Clarke 1866"',
                (string) $row['srtext'],
                "SRID $srid WKT must name the Clarke 1866 ellipsoid"
            );
        }
    }

    /**
     * The end-to-end proof, measured rather than asserted by inspection.
     *
     * Hagonoy BLLM 1 as printed in the BLLM schedule. If the CRS is registered
     * correctly, PostGIS reproduces both the published grid position from the
     * published geographic position and the geographic position back from the
     * grid, to within the schedule's own rounding.
     */
    public function testPpcsZoneThreeReproducesPublishedCadastreCoordinates(): void
    {
        $pdo = $this->getAppInstance()->getContainer()->get(PDO::class);

        $published = [
            'name' => 'HAGONOY BLLM 1',
            'lat' => 14.83683611,
            'lon' => 120.73244722,
            'easting' => 471203.829,
            'northing' => 1640770.202,
        ];

        $forward = $pdo->prepare(
            'SELECT ST_X(ST_Transform(ST_SetSRID(ST_MakePoint(:lon, :lat), 4326), 990103)) AS e,
                    ST_Y(ST_Transform(ST_SetSRID(ST_MakePoint(:lon, :lat), 4326), 990103)) AS n'
        );
        $forward->execute(['lon' => $published['lon'], 'lat' => $published['lat']]);
        $fwd = $forward->fetch(\PDO::FETCH_ASSOC);

        $this->assertEqualsWithDelta($published['easting'], (float) $fwd['e'], 0.001);
        $this->assertEqualsWithDelta($published['northing'], (float) $fwd['n'], 0.001);

        $reverse = $pdo->prepare(
            'SELECT ST_X(ST_Transform(ST_SetSRID(ST_MakePoint(:e, :n), 990103), 4326)) AS lon,
                    ST_Y(ST_Transform(ST_SetSRID(ST_MakePoint(:e, :n), 990103), 4326)) AS lat'
        );
        $reverse->execute(['e' => $published['easting'], 'n' => $published['northing']]);
        $rev = $reverse->fetch(\PDO::FETCH_ASSOC);

        $this->assertEqualsWithDelta($published['lon'], (float) $rev['lon'], 0.000001);
        $this->assertEqualsWithDelta($published['lat'], (float) $rev['lat'], 0.000001);
    }

    /**
     * A shifted definition would separate the published geographic and grid
     * positions by about 200 m. This pins the magnitude of the gap so a future
     * "cleanup" that adds a towgs84 fails here with a readable number rather
     * than as a mysterious survey that no longer lines up.
     */
    public function testPpcsDiffersFromPrs92ByTheClarke1866Gap(): void
    {
        $pdo = $this->getAppInstance()->getContainer()->get(PDO::class);

        $stmt = $pdo->query(
            'SELECT prs92_e - 471203.829 AS de,
                    prs92_n - 1640770.202 AS dn
               FROM (
                 SELECT ST_X(ST_Transform(ST_SetSRID(ST_MakePoint(120.73244722, 14.83683611), 4326), 3123)) AS prs92_e,
                        ST_Y(ST_Transform(ST_SetSRID(ST_MakePoint(120.73244722, 14.83683611), 4326), 3123)) AS prs92_n
               ) q'
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $gap = sqrt((float) $row['de'] ** 2 + (float) $row['dn'] ** 2);

        $this->assertGreaterThan(
            150.0,
            $gap,
            'PPCS and PRS92 should differ by the clrk66 -> WGS84 gap; a near-zero gap means a shift was applied'
        );
        $this->assertLessThan(250.0, $gap, 'gap should be on the order of 200 m');
    }
}
