<?php
declare(strict_types=1);

namespace App\GIS\Domain;

use App\Core\Error\ApiError;
use PDO;

/**
 * Spatial query engine (TASK-063).
 *
 * Implements: bbox | intersects | within | contains | nearest | within_distance | buffer.
 * All results respect layer scope (rbac_layer_capabilities + organization_members).
 * Buffer results are ephemeral — never persisted.
 */
class SpatialQuery
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Execute one spatial query operation.
     *
     * @param array{
     *   operation: 'bbox'|'intersects'|'within'|'contains'|'nearest'|'within_distance'|'buffer',
     *   layer_id: int,
     *   geometry: array,           // GeoJSON geometry object (or bbox string for 'bbox')
     *   srid: ?int,                // target CRS for distance/area (default 32651)
     *   limit: ?int,
     *   offset: ?int,
     * } $params
     * @return array{features: array, total: int, operation: string, count: int}
     */
    public function execute(array $params): array
    {
        $operation = $params['operation'] ?? '';
        if (!in_array($operation, ['bbox', 'intersects', 'within', 'contains', 'nearest', 'within_distance', 'buffer'], true)) {
            throw new ApiError('VALIDATION_FAILED', "Unsupported operation: {$operation}", 400);
        }

        $layerId = (int) ($params['layer_id'] ?? 0);
        if ($layerId <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'layer_id is required', 400);
        }

        $limit  = min(max((int) ($params['limit'] ?? 100), 1), 500);
        $offset = (int) ($params['offset'] ?? 0);
        $srid   = isset($params['srid']) && is_int($params['srid']) ? $params['srid'] : 32651;

        $geom = $params['geometry'] ?? null;

        // Extract the user id from session
        $userId = (int) $this->pdo->query("SELECT current_setting('app.current_user_id', true)")->fetchColumn();
        $userId = $userId ?: 0;

        // Scope-predicate: user can see this layer's features
        $scopeWhere = $this->scopeSql($layerId, $userId);

        // Geometry filter for the operation
        $geomFilter = $this->operationSql($operation, $geom, $srid);
        if ($geomFilter === null) {
            throw new ApiError('VALIDATION_FAILED', "Geometry required for operation: {$operation}", 400);
        }

        $where = ["f.layer_id = :lid", "f.deleted_at IS NULL", $scopeWhere, $geomFilter];
        $paramsList = [':lid' => $layerId];

        // Bind geometry params
        foreach ($geomFilter['params'] as $k => $v) {
            $paramsList[$k] = $v;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Count
        $countSql = "SELECT COUNT(*) FROM app.gis_features f {$whereSql}";
        $cntStmt = $this->pdo->prepare($countSql);
        foreach ($paramsList as $k => $v) {
            $cntStmt->bindValue($k, $v);
        }
        $cntStmt->execute();
        $total = (int) $cntStmt->fetchColumn();

        // Data
        $sel = 'f.id, f.status, f.psgc_barangay, f.provenance, f.attributes, ST_AsGeoJSON(f.geom)::json AS geometry';
        if ($operation === 'nearest' || $operation === 'within_distance') {
            $sel .= ', ST_Distance(ST_Transform(f.geom, :srid), ST_Transform(ST_GeomFromGeoJSON(:gj_query)::geometry, :srid)) AS dist_m';
            $paramsList[':srid'] = $srid;
        }
        $dataSql = "SELECT {$sel} FROM app.gis_features f {$whereSql} ORDER BY dist_m ASC LIMIT :lim OFFSET :off";
        $dataStmt = $this->pdo->prepare($dataSql);
        foreach ($paramsList as $k => $v) {
            $dataStmt->bindValue($k, $v);
        }
        $dataStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $dataStmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        $features = array_map(function ($r) use ($operation) {
            $out = [
                'type'       => 'Feature',
                'id'         => $r['id'],
                'geometry'   => $r['geometry'],
                'properties' => [
                    'status'        => $r['status'],
                    'psgc_barangay' => $r['psgc_barangay'],
                    'provenance'    => $r['provenance'],
                    'attributes'    => json_decode($r['attributes'], true) ?? [],
                ],
            ];
            if (($operation === 'nearest' || $operation === 'within_distance') && isset($r['dist_m'])) {
                $out['properties']['distance_m'] = (float) $r['dist_m'];
            }
            if (($operation === 'buffer') && isset($r['dist_m'])) {
                $out['properties']['buffer_distance_m'] = (float) $r['dist_m'];
            }
            return $out;
        }, $rows);

        return [
            'features' => $features,
            'total'    => $total,
            'operation' => $operation,
            'count'    => count($features),
        ];
    }

    /**
     * Build the WHERE clause fragment + bound params for the given operation.
     *
     * @param array|null $geom GeoJSON geometry (or null for bbox which takes a string)
     * @return array{sql: string, params: array}|null
     */
    private function operationSql(string $operation, ?array $geom, int $srid): ?array
    {
        $gj = $geom !== null ? json_encode($geom) : null;
        if ($gj === false && $geom !== null) {
            throw new ApiError('VALIDATION_FAILED', 'geometry is not valid JSON', 400);
        }

        return match ($operation) {
            'bbox' => $this->bboxSql($geom),
            'intersects' => $this->intersectsSql($gj, $srid),
            'within'      => $this->withinSql($gj, $srid),
            'contains'     => $this->containsSql($gj, $srid),
            'nearest'      => $this->nearestSql($gj, $srid),
            'within_distance' => $this->withinDistanceSql($gj, $srid),
            'buffer'       => $this->bufferSql($gj, $srid),
            default => null,
        };
    }

    private function bboxSql(?array $geom): array
    {
        // bbox takes a [west, south, east, north] array as "geometry"
        if (!is_array($geom) || count($geom) !== 4) {
            throw new ApiError('VALIDATION_FAILED', 'bbox operation requires [west,south,east,north] array', 400);
        }
        $w = (float) $geom[0];
        $s = (float) $geom[1];
        $e = (float) $geom[2];
        $n = (float) $geom[3];
        if ($w >= $e || $s >= $n) {
            throw new ApiError('VALIDATION_FAILED', 'bbox must have west < east and south < north', 400);
        }
        return [
            'sql' => 'f.geom && ST_MakeEnvelope(:minx, :miny, :maxx, :maxy, 4326)',
            'params' => [
                ':minx' => $w,
                ':miny' => $s,
                ':maxx' => $e,
                ':maxy' => $n,
            ],
        ];
    }

    private function intersectsSql(?string $gj, int $srid): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        return [
            'sql' => 'ST_Intersects(ST_Transform(f.geom, :srid), ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid))',
            'params' => [':gj' => $gj, ':srid' => $srid],
        ];
    }

    private function withinSql(?string $gj, int $srid): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        return [
            'sql' => 'ST_Within(ST_Transform(f.geom, :srid), ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid))',
            'params' => [':gj' => $gj, ':srid' => $srid],
        ];
    }

    private function containsSql(?string $gj, int $srid): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        return [
            'sql' => 'ST_Contains(ST_Transform(f.geom, :srid), ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid))',
            'params' => [':gj' => $gj, ':srid' => $srid],
        ];
    }

    private function nearestSql(?string $gj, int $srid): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        // Nearest uses ST_Distance ordering; must appear in SELECT for ORDER BY
        return [
            'sql' => 'true',  // ordering handled in main query
            'params' => [':gj_query' => $gj, ':srid' => $srid],
        ];
    }

    private function withinDistanceSql(?string $gj, int $srid): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        $dist = (float) ($_POST['distance_m'] ?? 500);
        return [
            'sql' => 'ST_DWithin(ST_Transform(f.geom, :srid), ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid), :dist)',
            'params' => [':gj' => $gj, ':srid' => $srid, ':dist' => $dist],
        ];
    }

    private function bufferSql(?string $gj, int $srid): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        $bufferM = (float) ($_POST['buffer_m'] ?? 100);
        return [
            'sql' => 'ST_Intersects(ST_Transform(f.geom, :srid), ST_Buffer(ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid), :buffer))',
            'params' => [':gj' => $gj, ':srid' => $srid, ':buffer' => $bufferM],
        ];
    }

    /**
     * Build the scope WHERE clause for the given layer + user.
     */
    private function scopeSql(int $layerId, int $userId): string
    {
        if ($userId <= 0) {
            return 'false';
        }
        return <<<'SQL'
EXISTS (
    SELECT 1 FROM app.rbac_layer_capabilities c
    WHERE c.layer_id = :lid
      AND c.user_id = :uid
      AND c.can_view = true
)
OR EXISTS (
    SELECT 1 FROM app.organization_members om
    JOIN app.gis_layers l ON l.organization_id = om.organization_id
    WHERE l.id = :lid
      AND om.user_id = :uid
)
SQL;
    }
}
