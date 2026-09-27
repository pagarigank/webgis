<?php
declare(strict_types=1);

namespace App\Survey\Application;

use App\Core\Error\ApiError;
use App\Survey\Domain\CoordinateDerivation;
use PDO;

/**
 * CRS resolution and coordinate derivation for survey control points
 * (extracted from ControlPointController for TASK-126).
 *
 * Both the single-point CRUD endpoint and the bulk CSV import must agree on:
 *  - which CRS a reference resolves to (registry id / SRID / code),
 *  - that the CRS is projected,
 *  - which coordinate pair is original and which is derived,
 *  - the CRS area-of-use rejection rule.
 *
 * Holding that in one place is what stops the import from becoming a way to
 * write records the single-point endpoint would refuse. TASK-126 therefore
 * resolves every row through this same class rather than re-implementing the
 * checks.
 */
final class ControlPointCoordinateResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Resolve a CRS reference - accepts an integer id, a numeric SRID, or an
     * "EPSG:3123"-style code - against ref.crs_registry.
     *
     * @return array<string,mixed>
     */
    public function resolveCrs(mixed $raw): array
    {
        if (is_int($raw)) {
            $stmt = $this->pdo->prepare('SELECT ' . $this->crsSelect() . ' FROM ref.crs_registry WHERE id = :v');
            $stmt->execute([':v' => $raw]);
        } elseif (is_string($raw)) {
            $value = trim($raw);
            if ($value === '') {
                throw new ApiError('VALIDATION_FAILED', 'native_crs is required', 400);
            }
            if (preg_match('/^(?:EPSG:)?(\d+)$/i', $value, $m)) {
                $stmt = $this->pdo->prepare('SELECT ' . $this->crsSelect() . ' FROM ref.crs_registry WHERE srid = :v');
                $stmt->execute([':v' => (int) $m[1]]);
            } else {
                $stmt = $this->pdo->prepare('SELECT ' . $this->crsSelect() . ' FROM ref.crs_registry WHERE upper(code) = upper(:c)');
                $stmt->execute([':c' => $value]);
            }
        } else {
            throw new ApiError('VALIDATION_FAILED', 'native_crs must be an EPSG code such as "EPSG:3123"', 400);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('VALIDATION_FAILED', 'native_crs not found in the CRS registry', 400);
        }
        return $this->castCrs($row);
    }

    /**
     * The native CRS must be projected: easting/northing are stored in a
     * projected coordinate reference system.
     *
     * @param array<string,mixed> $crs
     */
    public function assertProjectedCrs(array $crs): void
    {
        if (!$crs['is_projected']) {
            throw new ApiError(
                'VALIDATION_FAILED',
                'native_crs must be a projected coordinate reference system (easting/northing are stored in a projected CRS)',
                400,
                ['fields' => ['native_crs' => 'must be a projected coordinate reference system']]
            );
        }
    }

    /**
     * Execute the derivation plan against PostGIS and enforce the CRS
     * area-of-use check (TASK-073 AC).
     *
     * The original pair is returned exactly as supplied (rounded to the column
     * scale); the derived pair comes from ST_Transform. The point is rejected
     * with 400 when the transform fails, yields a non-finite / out-of-range
     * geographic position, or falls outside the CRS area-of-use bounding box
     * recorded in ref.crs_registry (NULL bounds skip only the bounding-box
     * comparison, never the finite/range checks).
     *
     * @param array<string,mixed> $crs
     * @param array<string,mixed> $plan result of CoordinateDerivation::plan()
     * @return array{easting: float, northing: float, latitude: float, longitude: float}
     */
    public function deriveCoordinates(array $crs, array $plan): array
    {
        $nativeSrid = (int) $crs['srid'];

        if ($plan['origin'] === CoordinateDerivation::ORIGIN_PROJECTED) {
            $inSrid   = $nativeSrid;
            $px       = (float) $plan['original']['easting'];
            $py       = (float) $plan['original']['northing'];
            $easting  = round($px, 4);
            $northing = round($py, 4);
        } else {
            $inSrid    = 4326;
            $px        = (float) $plan['original']['longitude'];
            $py        = (float) $plan['original']['latitude'];
            $latitude  = round($py, 9);
            $longitude = round($px, 9);
        }

        // Positional placeholders: PDO native prepares forbid repeating named
        // placeholders, so each coordinate is bound by position even though it
        // feeds both the geographic and the projected transform.
        try {
            $stmt = $this->pdo->prepare(
                'SELECT '
                . 'ST_Y(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), ?::int), 4326)) AS latitude, '
                . 'ST_X(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), ?::int), 4326)) AS longitude, '
                . 'ST_Y(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), ?::int), ?::int)) AS northing, '
                . 'ST_X(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), ?::int), ?::int)) AS easting'
            );
            $args = [
                $px, $py, $inSrid,
                $px, $py, $inSrid,
                $px, $py, $inSrid, $nativeSrid,
                $px, $py, $inSrid, $nativeSrid,
            ];
            $stmt->execute($args);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            throw $this->areaOfUseError($crs);
        }

        if ($row === false) {
            throw $this->areaOfUseError($crs);
        }

        $lat = $row['latitude']  === null ? NAN : (float) $row['latitude'];
        $lon = $row['longitude'] === null ? NAN : (float) $row['longitude'];
        $e   = $row['easting']   === null ? NAN : (float) $row['easting'];
        $n   = $row['northing']  === null ? NAN : (float) $row['northing'];

        // Area-of-use: a geographic position the projection cannot produce, or
        // one outside the CRS bounding box, is outside the CRS area of use.
        if (!is_finite($lat) || !is_finite($lon) || $lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            throw $this->areaOfUseError($crs);
        }
        if ($crs['area_south'] !== null && $crs['area_west'] !== null
            && $crs['area_north'] !== null && $crs['area_east'] !== null
            && ($lat < $crs['area_south'] || $lat > $crs['area_north']
                || $lon < $crs['area_west'] || $lon > $crs['area_east'])) {
            throw $this->areaOfUseError($crs);
        }
        if (!is_finite($e) || !is_finite($n)) {
            throw $this->areaOfUseError($crs);
        }

        if ($plan['origin'] === CoordinateDerivation::ORIGIN_PROJECTED) {
            return [
                'easting'   => $easting,
                'northing'  => $northing,
                'latitude'  => round($lat, 9),
                'longitude' => round($lon, 9),
            ];
        }

        return [
            'easting'   => round($e, 4),
            'northing'  => round($n, 4),
            'latitude'  => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * Area-of-use rejection. The column name in `fields` follows the coordinate
     * pair the caller supplied, so the message points at what the user typed.
     *
     * @param array<string,mixed> $crs
     */
    public function areaOfUseError(array $crs, string $origin = CoordinateDerivation::ORIGIN_PROJECTED): ApiError
    {
        $field = $origin === CoordinateDerivation::ORIGIN_PROJECTED ? 'easting' : 'latitude';

        return new ApiError(
            'VALIDATION_FAILED',
            sprintf('Point lies outside the area of use of %s (%s)', $crs['code'], $crs['name']),
            400,
            ['fields' => [$field => 'outside CRS area of use']]
        );
    }

    private function crsSelect(): string
    {
        return 'id, srid, code, name, datum, zone, is_projected, '
             . 'area_south, area_west, area_north, area_east';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castCrs(array $row): array
    {
        $isProjected = $row['is_projected'];
        if (is_string($isProjected)) {
            $isProjected = in_array(strtolower($isProjected), ['1', 't', 'true', 'y', 'yes', 'on'], true);
        }
        $row['id']           = (int) $row['id'];
        $row['srid']         = (int) $row['srid'];
        $row['is_projected'] = (bool) $isProjected;
        foreach (['area_south', 'area_west', 'area_north', 'area_east'] as $bound) {
            $row[$bound] = $row[$bound] === null ? null : (float) $row[$bound];
        }
        return $row;
    }
}
