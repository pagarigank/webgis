<?php
declare(strict_types=1);

namespace App\Core\Crs;

/**
 * Default projected CRS ("core PCS") for measurement and analysis.
 *
 * All geometry is stored and transmitted as EPSG:4326 (WGS 84); this constant
 * only names the *target* CRS that metric computations are performed in. It is
 * never a substitute for an explicit per-request `srid`, and stored geometry is
 * never reprojected in place.
 *
 * PRS92 is the Philippines' national modern datum and its Philippine Transverse
 * Mercator grid is divided into 4-degree zones with central meridians at 117E,
 * 119E, 121E, 123E and 125E (zones I-V). A zone is only accurate near its own
 * central meridian, so naming the datum alone is not enough - the zone matters,
 * and using the wrong one silently inflates every distance and area.
 *
 * Zone III (EPSG:3123, CM 121E) covers Central Luzon - Metro Manila, Bulacan,
 * Pampanga, Tarlac, Nueva Ecija - and sits ~43 km off its central meridian at
 * Angeles City, where scale distortion is a few parts per million. This was
 * EPSG:32651 (UTM 51N) previously; UTM is a global grid referenced to WGS 84
 * rather than the national datum, and is displaced from PRS92 by roughly a few
 * hundred metres locally. Callers outside Central Luzon should pass their own
 * zone SRID explicitly.
 */
final class DefaultProjectedCrs
{
    /** PRS92 / Philippines zone III - the app-wide default working CRS. */
    public const SRID = 3123;

    /** Human label for logs, API docs and error messages. */
    public const LABEL = 'EPSG:3123 (PRS92 / Philippines zone III)';

    /**
     * Previous default, kept only so migrations and audit notes can name it.
     * Not used as a fallback anywhere.
     */
    public const LEGACY_SRID = 32651;

    private function __construct()
    {
    }
}
