<?php
declare(strict_types=1);

namespace App\GIS\Domain;

use App\Core\Crs\DefaultProjectedCrs;
use App\Core\Error\ApiError;
use PDO;

/**
 * Identify popup helper (TASK-062).
 *
 * Lightweight read-only lookup used by the identify tool.  Returns a single
 * feature's display-ready payload for the popup, plus a distance for
 * "near-miss" rendering.
 */
class IdentifyPopup
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param string $featureId
     * @param int    $layerId
     * @param array  $mapPoint {lng, lat}
     * @param int    $srid     target projected CRS
     * @return array{feature: array|null, distance_m: ?float, layer_name: ?string}
     */
    public function forPoint(string $featureId, int $layerId, array $mapPoint, int $srid = DefaultProjectedCrs::SRID): array
    {
        $gj = json_encode(['type' => 'Point', 'coordinates' => [$mapPoint['lng'], $mapPoint['lat']]]);
        if ($gj === false) {
            throw new ApiError('VALIDATION_FAILED', 'invalid map point', 400);
        }

        $hasFeature = $featureId !== '';
        $fidCond = $hasFeature ? 'f.id = :fid' : 'true';
        $sridCast = (int) $srid;

        $sql = <<<'SQL'
SELECT
    f.id,
    f.status,
    f.psgc_barangay,
    f.attributes,
    l.name AS layer_name,
    ST_Distance(
        ST_Transform(f.geom, :srid),
        ST_Transform(ST_GeomFromGeoJSON(:pt)::geometry, :srid)
    ) AS dist_m
FROM app.gis_features f
JOIN app.gis_layers l ON l.id = f.layer_id
WHERE {#COND}
  AND f.layer_id = :lid
  AND f.deleted_at IS NULL
ORDER BY dist_m ASC
LIMIT 1
SQL;

        $sql = str_replace('{#COND}', $fidCond, $sql);
        $sql = str_replace(':srid', ':srid::int', $sql);

        $params = [
            ':lid'  => $layerId,
            ':srid' => $srid,
            ':pt'   => $gj,
        ];
        if ($hasFeature) {
            $params[':fid'] = $featureId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return ['feature' => null, 'distance_m' => null, 'layer_name' => null];
        }

        return [
            'feature'    => [
                'id'            => $row['id'],
                'status'        => $row['status'],
                'psgc_barangay' => $row['psgc_barangay'],
                'attributes'    => json_decode($row['attributes'], true) ?? [],
            ],
            'distance_m' => (float) $row['dist_m'],
            'layer_name' => $row['layer_name'],
        ];
    }
}
