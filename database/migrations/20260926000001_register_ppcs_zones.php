<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Register the Philippine Plane Coordinate System (PPCS) zones I-V.
 *
 * PPCS is the plane coordinate system printed in the BLLM tie-point schedules
 * ("PPCS COORDINATES" column). It is a Transverse Mercator grid on Clarke 1866
 * split into 2-degree zones with central meridians at 117E, 119E, 121E, 123E
 * and 125E - the same zone geometry as the PRS92 and Luzon 1911 national grids,
 * but with NO datum shift applied to WGS 84.
 *
 * The no-shift behaviour is not an assumption: it was recovered empirically from
 * 168 independent "point of geographic position" / "PPCS coordinates" pairs in
 * the source schedule, spanning 6N-17N. The implied central meridian of every
 * pair falls on one of the five values above, and ~155 of the pairs reproduce
 * to within 5 cm when the published geographic position is projected with the
 * definition below. Applying a towgs84 shift instead (as EPSG:3123 and
 * EPSG:25393 both do) moves the result ~145 m east and ~170 m north, so the
 * schedule's two coordinate columns stop agreeing with each other.
 *
 * Consequence, stated plainly: the geographic positions in these schedules are
 * in the local Clarke 1866 datum, not WGS 84. Storing them under PPCS keeps both
 * published columns mutually faithful, which is what the survey record needs;
 * the ~220 m offset to true WGS 84 is a property of the source, not of this
 * definition, and is not silently applied.
 *
 * Area-of-use bounds follow the existing convention for the national zones
 * (whole-Philippines box) so that registering PPCS does not tighten validation
 * for records that were accepted before.
 */
final class RegisterPpcsZones extends AbstractMigration
{
    /** Central meridian, in degrees east, for each PPCS zone. */
    private const ZONES = [
        1 => ['I', 117],
        2 => ['II', 119],
        3 => ['III', 121],
        4 => ['IV', 123],
        5 => ['V', 125],
    ];

    /** First SRID of the PPCS block (must stay <= 998999: spatial_ref_sys check). */
    private const SRID_BASE = 990100;

    private const BOUNDS = [4.40, 116.50, 21.50, 127.00];

    private const NOTE = 'Philippine Plane Coordinate System (PPCS) zone on Clarke 1866, '
        . 'Transverse Mercator k0=0.99995, FE=500000, FN=0. No towgs84: the published '
        . 'geographic positions in PPCS schedules are in the local Clarke 1866 datum, '
        . 'not WGS 84.';

    private function srid(int $zone): int
    {
        return self::SRID_BASE + $zone;
    }

    private function proj4(int $cm): string
    {
        return sprintf(
            '+proj=tmerc +lat_0=0 +lon_0=%d +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
            $cm
        );
    }

    /**
     * WKT1 for the zone.
     *
     * The GEOGCS deliberately carries a DATUM node with a SPHEROID but NO
     * TOWGS84 element, which is what makes the CRS behave as a bound local
     * datum with no transformation to WGS 84. Do not "helpfully" add
     * TOWGS84[0,0,0,0,0,0,0] here: PROJ discards that degenerate 7-parameter
     * set, falls back to a real Clarke 1866 -> WGS 84 conversion, and shifts
     * every northing by ~117 m. Measured against the source schedule, the
     * form below reproduces the published coordinates to 0.4 mm; the
     * zero-TOWGS84 form is off by 117.4 m.
     */
    private function wkt(string $roman, int $cm): string
    {
        return sprintf(
            'PROJCS["PPCS zone %s",'
            . 'GEOGCS["PPCS_Clarke_1866",'
            . 'DATUM["PPCS_Clarke_1866",'
            . 'SPHEROID["Clarke 1866",6378206.4,294.9786982138982]],'
            . 'PRIMEM["Greenwich",0],UNIT["degree",0.0174532925199433]],'
            . 'PROJECTION["Transverse_Mercator"],'
            . 'PARAMETER["latitude_of_origin",0],'
            . 'PARAMETER["central_meridian",%d],'
            . 'PARAMETER["scale_factor",0.99995],'
            . 'PARAMETER["false_easting",500000],'
            . 'PARAMETER["false_northing",0],'
            . 'UNIT["metre",1],AXIS["Easting",EAST],AXIS["Northing",NORTH]]',
            $roman,
            $cm
        );
    }

    public function up(): void
    {
        [$south, $west, $north, $east] = self::BOUNDS;

        foreach (self::ZONES as $zone => [$roman, $cm]) {
            $srid  = $this->srid($zone);
            $code  = sprintf('PPCS:%d', $srid);
            $name  = sprintf('PPCS zone %s', $roman);
            $proj4 = $this->proj4($cm);
            $wkt   = $this->wkt($roman, $cm);

            $this->execute(sprintf(
                'INSERT INTO ref.crs_registry
                     (srid, code, name, datum, zone, is_projected, proj4text, wkt,
                      area_of_use_note, area_south, area_west, area_north, area_east,
                      is_historical, is_active)
                 VALUES (%d, %s, %s, %s, %s, true, %s, %s, %s, %s, %s, %s, %s, false, true)
                 ON CONFLICT (srid) DO NOTHING',
                $srid,
                $this->quote($code),
                $this->quote($name),
                $this->quote('PPCS (Clarke 1866)'),
                $this->quote($roman),
                $this->quote($proj4),
                $this->quote($wkt),
                $this->quote(self::NOTE),
                $south,
                $west,
                $north,
                $east
            ));

            // ST_Transform resolves SRIDs from spatial_ref_sys, so the projection
            // must exist there too, not only in the project registry.
            $this->execute(sprintf(
                'INSERT INTO spatial_ref_sys (srid, auth_name, auth_srid, srtext, proj4text)
                 VALUES (%d, %s, %d, %s, %s)
                 ON CONFLICT (srid) DO NOTHING',
                $srid,
                $this->quote('PPCS'),
                $srid,
                $this->quote($wkt),
                $this->quote($proj4)
            ));
        }
    }

    public function down(): void
    {
        foreach (self::ZONES as $zone => $_) {
            $srid = $this->srid($zone);
            $this->execute(sprintf('DELETE FROM spatial_ref_sys WHERE srid = %d', $srid));
            $this->execute(sprintf('DELETE FROM ref.crs_registry WHERE srid = %d', $srid));
        }
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
