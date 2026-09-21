<?php
declare(strict_types=1);

namespace App\GIS\Domain;

use App\Core\Error\ApiError;
use PDO;

/**
 * Spatial measurement helpers (TASK-062).
 *
 * Distance and area are computed in a projected CRS for meaningful metre
 * values.  The default CRS is EPSG:32651 (UTM 51N, covers Metro Manila).
 * Callers pass WGS84 geometry; this service transforms to the target CRS
 * and computes.
 */
class SpatialMeasure
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Compute the geodesic length of a single linear geometry (LineString or
     * MultiLineString) in the target projected CRS.
     *
     * @return array{length_m: float, crs: int, unit: string}
     */
    public function length(array $geometry, ?int $srid = null): array
    {
        $this->requireLine($geometry);
        $srid = $srid ?? 32651;

        $gj = json_encode($geometry);
        if ($gj === false) {
            throw new ApiError('VALIDATION_FAILED', 'geometry is not valid JSON', 400);
        }

        // ST_Transform from 4326 → target CRS, then ST_Length in that CRS.
        $sql = "SELECT ST_Length(ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid::int)) AS len";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':gj' => $gj, ':srid' => $srid]);
        $len = (float) $stmt->fetchColumn();

        return [
            'length_m' => $len,
            'crs'      => $srid,
            'unit'     => 'm',
        ];
    }

    /**
     * Compute the geodesic area of a polygonal geometry (Polygon or
     * MultiPolygon) in the target projected CRS.
     *
     * @return array{area_m2: float, area_ha: float, crs: int, unit: string}
     */
    public function area(array $geometry, ?int $srid = null): array
    {
        $this->requirePolygon($geometry);
        $srid = $srid ?? 32651;

        $gj = json_encode($geometry);
        if ($gj === false) {
            throw new ApiError('VALIDATION_FAILED', 'geometry is not valid JSON', 400);
        }

        $sql = "SELECT ST_Area(ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid::int)) AS a";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':gj' => $gj, ':srid' => $srid]);
        $area = (float) $stmt->fetchColumn();

        return [
            'area_m2' => $area,
            'area_ha' => $area / 10_000,
            'crs'     => $srid,
            'unit'    => 'm2',
        ];
    }

    /**
     * Identify the feature(s) at a point, returning the top match per layer.
     *
     * @param array{west: float, south: float, east: float, north: float} $bbox
     * @return array{features: array<array{id: string, layer_id: int, layer_name: string, status: string, psgc_barangay: ?string, distance_m: float, attributes: array}>}
     */
    public function identify(
        float $lng,
        float $lat,
        int $srid = 32651,
    ): array {
        // Build a 1-metre buffer point in the target CRS so we can snap.
        $pointWkt = sprintf('POINT(%F %F)', $lng, $lat);
        $sql = <<<'SQL'
SELECT
    f.id,
    f.layer_id,
    l.name AS layer_name,
    f.status,
    f.psgc_barangay,
    f.attributes,
    ST_Distance(
        ST_Transform(f.geom, :srid::int),
        ST_Transform(ST_GeomFromText(:point, 4326), :srid::int)
    ) AS dist_m
FROM app.gis_features f
JOIN app.layer_permissions lp ON lp.layer_id = f.layer_id
JOIN app.roles r ON r.id = lp.role_id
JOIN app.gis_layers l ON l.id = f.layer_id
WHERE f.deleted_at IS NULL
  AND lp.can_view = true
  AND r.code = ANY(string_to_array(current_setting('app.role_codes', true), ','))
  AND ST_DWithin(
        ST_Transform(f.geom, :srid::int),
        ST_Transform(ST_GeomFromText(:point, 4326), :srid::int),
        500
      )
ORDER BY dist_m ASC
LIMIT 10
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':srid'  => $srid,
            ':point' => $pointWkt,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $features = [];
        foreach ($rows as $r) {
            $features[] = [
                'id'             => $r['id'],
                'layer_id'       => (int) $r['layer_id'],
                'layer_name'     => $r['layer_name'],
                'status'         => $r['status'],
                'psgc_barangay'  => $r['psgc_barangay'],
                'distance_m'     => (float) $r['dist_m'],
                'attributes'     => json_decode($r['attributes'], true) ?? [],
            ];
        }

        return ['features' => $features];
    }

    private function requireLine(array $geom): void
    {
        $t = $geom['type'] ?? '';
        if (!in_array($t, ['LineString', 'MultiLineString', 'Polygon', 'MultiPolygon'], true)) {
            throw new ApiError('VALIDATION_FAILED', "Unsupported geometry type for length: {$t}", 400);
        }
    }

    private function requirePolygon(array $geom): void
    {
        $t = $geom['type'] ?? '';
        if (!in_array($t, ['Polygon', 'MultiPolygon'], true)) {
            throw new ApiError('VALIDATION_FAILED', "Unsupported geometry type for area: {$t}", 400);
        }
    }
}
