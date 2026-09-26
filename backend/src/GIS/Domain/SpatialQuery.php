<?php
declare(strict_types=1);

namespace App\GIS\Domain;

use App\Core\Crs\DefaultProjectedCrs;
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
     *   srid: ?int,                // target CRS for distance/area (default 3123)
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
        $srid   = isset($params['srid']) && is_int($params['srid']) ? $params['srid'] : DefaultProjectedCrs::SRID;

        $geom = $params['geometry'] ?? null;

        // Extract the user id from session. Middleware sets app.user_id; controllers
        // also SET LOCAL app.current_user_id for the RLS read helpers. Accept either.
        $userId = (int) $this->pdo->query("
            SELECT COALESCE(
                NULLIF(current_setting('app.current_user_id', true), '')::int,
                NULLIF(current_setting('app.user_id', true), '')::int,
                0
            )
        ")->fetchColumn();
        $userId = $userId ?: 0;

        // Scope-predicate: user can view this layer's features (FR-014 / SR-03).
        $scopeWhere = $this->scopeSql($layerId, $userId);

        // Geometry filter for the operation
        $distanceM = isset($params['distance_m']) ? (float) $params['distance_m'] : 500;
        $bufferM   = isset($params['buffer_m']) ? (float) $params['buffer_m'] : 100;
        $geomFilter = $this->operationSql($operation, $geom, $srid, $distanceM, $bufferM);
        if ($geomFilter === null) {
            throw new ApiError('VALIDATION_FAILED', "Geometry required for operation: {$operation}", 400);
        }

        $where = ["f.layer_id = :lid", "f.deleted_at IS NULL", $scopeWhere, $geomFilter['sql']];
        $paramsList = [':lid' => $layerId, ':uid' => $userId];

        // Bind geometry params
        foreach ($geomFilter['params'] as $k => $v) {
            $paramsList[$k] = $v;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Count
        $countSql = "SELECT COUNT(*) FROM app.gis_features f {$whereSql}";
        $cntStmt = $this->pdo->prepare($countSql);
        foreach ($paramsList as $k => $v) {
            // Only bind params referenced by the count SQL (geometry/scope filters
            // like :gj, :srid are selected-only and unused in COUNT, which breaks
            // native prepared statements if bound).
            if (str_contains($whereSql, $k)) {
                $cntStmt->bindValue($k, $v);
            }
        }
        $cntStmt->execute();
        $total = (int) $cntStmt->fetchColumn();

        // Data
        $sel = 'f.id, f.status, f.psgc_barangay, f.provenance, f.attributes, ST_AsGeoJSON(f.geom)::json AS geometry';
        $hasDist = false;
        if ($operation === 'nearest' || $operation === 'within_distance' || $operation === 'buffer') {
            $sel .= ', ST_Distance(ST_Transform(f.geom, :srid::int), ST_Transform(ST_GeomFromGeoJSON(:gj_query)::geometry, :srid::int)) AS dist_m';
            $paramsList[':srid'] = $srid;
            $paramsList[':gj_query'] = $geom !== null ? json_encode($geom) : 'null';
            $hasDist = true;
        }
        $orderBy = $hasDist ? 'dist_m ASC' : 'f.created_at DESC';
        $dataSql = "SELECT {$sel} FROM app.gis_features f {$whereSql} ORDER BY {$orderBy} LIMIT :lim OFFSET :off";
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
                'geometry'   => json_decode($r['geometry'], true) ?? ['type' => 'Point', 'coordinates' => [0, 0]],
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
    private function operationSql(string $operation, ?array $geom, int $srid, float $distanceM = 500, float $bufferM = 100): ?array
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
            'within_distance' => $this->withinDistanceSql($gj, $srid, $distanceM),
            'buffer'       => $this->bufferSql($gj, $srid, $bufferM),
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
            'sql' => 'ST_Intersects(ST_Transform(f.geom, :srid::int), ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid::int))',
            'params' => [':gj' => $gj, ':srid' => $srid],
        ];
    }

    private function withinSql(?string $gj, int $srid): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        return [
            'sql' => 'ST_Within(ST_Transform(f.geom, :srid::int), ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid::int))',
            'params' => [':gj' => $gj, ':srid' => $srid],
        ];
    }

    private function containsSql(?string $gj, int $srid): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        return [
            'sql' => 'ST_Contains(ST_Transform(f.geom, :srid::int), ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid::int))',
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

    private function withinDistanceSql(?string $gj, int $srid, float $distanceM): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        return [
            'sql' => 'ST_DWithin(ST_Transform(f.geom, :srid::int), ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid::int), :dist::float8)',
            // ':gj' feeds ST_DWithin (WHERE); ':gj_query' feeds ST_Distance (SELECT).
            'params' => [':gj' => $gj, ':gj_query' => $gj, ':srid' => $srid, ':dist' => $distanceM],
        ];
    }

    private function bufferSql(?string $gj, int $srid, float $bufferM): array
    {
        if ($gj === null) return ['sql' => 'true', 'params' => []];
        return [
            'sql' => 'ST_Intersects(ST_Transform(f.geom, :srid::int), ST_Buffer(ST_Transform(ST_GeomFromGeoJSON(:gj)::geometry, :srid::int), :buffer::float8))',
            'params' => [':gj' => $gj, ':srid' => $srid, ':buffer' => $bufferM],
        ];
    }

    /**
     * Build the scope WHERE clause for the given layer + user.
     *
     * Layer-level capability is the authoritative gate (FR-014 / SR-03) and is
     * resolved exactly like FeatureScopeResolver: an explicit can_view grant in
     * app.layer_permissions via one of the user's roles. Row-level data scopes
     * (region/province/barangay) are enforced by the gis_features RLS policies,
     * not here.
     */
    private function scopeSql(int $layerId, int $userId): string
    {
        if ($userId <= 0) {
            return 'false';
        }
        return <<<'SQL'
EXISTS (
    SELECT 1
    FROM app.layer_permissions lp
    JOIN app.user_roles ur ON ur.role_id = lp.role_id
    WHERE lp.layer_id = :lid
      AND ur.user_id = :uid
      AND lp.can_view = true
)
SQL;
    }
}
