<?php

declare(strict_types=1);

namespace App\Parcels\Domain;

use App\Core\Crs\DefaultProjectedCrs;
use App\Core\Error\ApiError;
use PDO;

/**
 * Point / bbox locator for parcels (TASK-104b).
 *
 * The generic GIS identify tool answers against app.gis_features, which is a
 * separate feature store with no relationship to app.parcels. A parcel is
 * therefore invisible to `/spatial/identify`. This locator queries app.parcels
 * directly so the map can resolve a click to a real parcel and offer the
 * parcel actions (open, split, consolidate, lineage, history).
 *
 * Scope enforcement (TASK-104c): every query repeats the
 * app.fn_user_can_see(...) predicate in its WHERE clause instead of trusting
 * the app.parcels RLS policy alone. The app connects as `app_rw`, which is both
 * the table owner and a superuser with BYPASSRLS, so the `parcels_scope_*`
 * policies never fire and a scope-leaking map overlay is otherwise possible.
 * ControlPointController and SpatialQuery already guard their tables this way.
 */
class ParcelLocator
{
    /**
     * Explicit scope predicate, shared by every query in this class.
     */
    private const SCOPE_PREDICATE = "app.fn_user_can_see(NULLIF(current_setting('app.user_id', true), '')::bigint, p.psgc_barangay, NULL)";
    /** Max candidates returned for a single point lookup. */
    private const MAX_LIMIT = 25;

    /** Default search radius in metres when the caller does not supply one. */
    private const DEFAULT_TOLERANCE_M = 25.0;

    /** Hard ceiling on the search radius so a click cannot scan the whole table. */
    private const MAX_TOLERANCE_M = 500.0;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Parcels whose geometry contains the point, then parcels nearest to it.
     *
     * @return array{parcels: list<array<string, mixed>>, tolerance_m: float}
     */
    public function atPoint(float $lng, float $lat, int $srid = DefaultProjectedCrs::SRID, ?float $toleranceM = null, int $limit = 10): array
    {
        if ($lng < -180.0 || $lng > 180.0) {
            throw new ApiError('VALIDATION_FAILED', 'lng must be between -180 and 180', 400);
        }
        if ($lat < -90.0 || $lat > 90.0) {
            throw new ApiError('VALIDATION_FAILED', 'lat must be between -90 and 90', 400);
        }

        $tolerance = $toleranceM === null ? self::DEFAULT_TOLERANCE_M : (float) $toleranceM;
        if ($tolerance < 0.0) {
            throw new ApiError('VALIDATION_FAILED', 'tolerance_m must be zero or greater', 400);
        }
        $tolerance = min($tolerance, self::MAX_TOLERANCE_M);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $pointWkt = sprintf('POINT(%F %F)', $lng, $lat);
        $sridInt = (int) $srid;

        // A tolerance of 0 means "containment only": ST_DWithin(0) is true for
        // an exact boundary match, which is the useful behaviour for a
        // point that sits on a shared parcel edge.
        $sql = <<<'SQL'
SELECT
    p.id,
    p.parcel_code,
    p.status,
    p.geometry_source,
    p.psgc_province,
    p.psgc_municipality,
    p.psgc_barangay,
    p.source_area_sqm,
    p.source_area_unit,
    p.computed_area_sqm,
    p.version,
    ST_Area(p.geom::geography)                                        AS area_m2,
    ST_Distance(
        p.geom,
        ST_SetSRID(ST_GeomFromText(:ptwkt), 4326)::geometry
    )                                                                 AS edge_distance_deg,
    ST_Distance(
        ST_Transform(p.geom, :srid::int),
        ST_Transform(ST_SetSRID(ST_GeomFromText(:ptwkt), 4326)::geometry, :srid::int)
    )                                                                 AS distance_m,
    ST_Contains(p.geom, ST_SetSRID(ST_GeomFromText(:ptwkt), 4326)::geometry) AS contains_point
FROM app.parcels p
WHERE p.deleted_at IS NULL
  AND p.geom IS NOT NULL
  AND NOT ST_IsEmpty(p.geom)
  AND {#SCOPE}
  AND (
        ST_Contains(p.geom, ST_SetSRID(ST_GeomFromText(:ptwkt), 4326)::geometry)
     OR ST_DWithin(
            ST_Transform(p.geom, :srid::int),
            ST_Transform(ST_SetSRID(ST_GeomFromText(:ptwkt), 4326)::geometry, :srid::int),
            :tol
        )
      )
ORDER BY contains_point DESC, distance_m ASC, p.parcel_code ASC
LIMIT :lim
SQL;

        $stmt = $this->pdo->prepare(str_replace('{#SCOPE}', self::SCOPE_PREDICATE, $sql));
        $stmt->bindValue(':ptwkt', $pointWkt);
        $stmt->bindValue(':srid', $sridInt, PDO::PARAM_INT);
        $stmt->bindValue(':tol', $tolerance);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $parcels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parcels[] = $this->hydrate($row);
        }

        return ['parcels' => $parcels, 'tolerance_m' => $tolerance];
    }

    /**
     * Lightweight GeoJSON payload for the parcels in a viewport, used to draw
     * the parcel overlay. Unlike atPoint() this returns no per-feature
     * distance and caps the result set so a wide viewport cannot flood the map.
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    public function inBbox(float $west, float $south, float $east, float $north, int $limit = 500): array
    {
        if ($west > $east || $south > $north) {
            throw new ApiError('VALIDATION_FAILED', 'bbox must be west,south,east,north', 400);
        }
        if ($east - $west > 5.0 || $north - $south > 5.0) {
            throw new ApiError('VALIDATION_FAILED', 'bbox must be 5 degrees or smaller', 400);
        }

        $limit = max(1, min($limit, 2000));

        $sql = <<<'SQL'
SELECT
    p.id,
    p.parcel_code,
    p.status,
    p.geometry_source,
    p.version,
    ST_Area(p.geom::geography) AS area_m2,
    ST_AsGeoJSON(p.geom)      AS geometry
FROM app.parcels p
WHERE p.deleted_at IS NULL
  AND p.geom IS NOT NULL
  AND NOT ST_IsEmpty(p.geom)
  AND {#SCOPE}
  AND ST_Intersects(
        p.geom,
        ST_MakeEnvelope(:west, :south, :east, :north, 4326)
      )
ORDER BY p.parcel_code ASC
LIMIT :lim
SQL;

        $stmt = $this->pdo->prepare(str_replace('{#SCOPE}', self::SCOPE_PREDICATE, $sql));
        $stmt->bindValue(':west', $west);
        $stmt->bindValue(':south', $south);
        $stmt->bindValue(':east', $east);
        $stmt->bindValue(':north', $north);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $features = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $geom = $row['geometry'];
            $decoded = is_string($geom) && $geom !== '' ? json_decode($geom, true) : null;

            $features[] = [
                'type' => 'Feature',
                'id' => (string) $row['id'],
                'geometry' => is_array($decoded) ? $decoded : null,
                'properties' => [
                    // Also exposed as a property (not just the GeoJSON feature
                    // id) so clients can build a MapLibre `['in', ['get',
                    // 'id'], ...]` filter to highlight selected parcels.
                    'id' => (string) $row['id'],
                    'parcel_code' => $row['parcel_code'],
                    'status' => $row['status'],
                    'geometry_source' => $row['geometry_source'],
                    'version' => (int) $row['version'],
                    'area_m2' => $row['area_m2'] === null ? null : (float) $row['area_m2'],
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $area = $row['area_m2'] === null ? null : (float) $row['area_m2'];

        return [
            'id' => (string) $row['id'],
            'parcel_code' => $row['parcel_code'],
            'status' => $row['status'],
            'geometry_source' => $row['geometry_source'],
            'psgc_province' => $row['psgc_province'],
            'psgc_municipality' => $row['psgc_municipality'],
            'psgc_barangay' => $row['psgc_barangay'],
            'source_area_sqm' => $row['source_area_sqm'] === null ? null : (float) $row['source_area_sqm'],
            'source_area_unit' => $row['source_area_unit'],
            'computed_area_sqm' => $row['computed_area_sqm'] === null ? null : (float) $row['computed_area_sqm'],
            'version' => (int) $row['version'],
            'area_m2' => $area,
            'area_ha' => $area === null ? null : round($area / 10000.0, 4),
            'distance_m' => (float) $row['distance_m'],
            'contains_point' => (bool) $row['contains_point'],
        ];
    }
}
