<?php
declare(strict_types=1);

namespace App\GIS\Http;

use App\Core\Crs\DefaultProjectedCrs;
use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\GIS\Domain\SpatialQuery;
use App\RBAC\FeatureScopeResolver;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SpatialQueryController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SpatialQuery $query,
    ) {
    }

    /**
     * POST /spatial/query
     *
     * Execute a spatial query operation against accessible layers.
     *
     * Body:
     * {
     *   "operation": "bbox"|"intersects"|"within"|"contains"|"nearest"|"within_distance"|"buffer",
     *   "layer_id": 1,                        // optional; if omitted, searches all accessible layers
     *   "geometry": { type, coordinates },   // GeoJSON geometry (or [w,s,e,n] for bbox)
     *   "srid": 3123,                        // target CRS for distance (default 3123, PRS92 zone III)
     *   "limit": 100,                         // default 100, max 500
     *   "offset": 0,
     *   "distance_m": 500,                    // for within_distance
     *   "buffer_m": 100                       // for buffer
     * }
     */
    public function query(Request $request, Response $response, array $args): Response
    {
        $this->ensureAuth($request);

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
        }

        $operation = $body['operation'] ?? '';
        if (!in_array($operation, ['bbox', 'intersects', 'within', 'contains', 'nearest', 'within_distance', 'buffer'], true)) {
            throw new ApiError('VALIDATION_FAILED', 'operation must be one of: bbox, intersects, within, contains, nearest, within_distance, buffer', 400);
        }

        $layerId = isset($body['layer_id']) && is_numeric($body['layer_id']) ? (int) $body['layer_id'] : null;

        // If layer_id supplied, scope-check it
        if ($layerId !== null && $layerId > 0) {
            $capsule = $request->getAttribute('feature_scope_resolver');
            if (!$capsule instanceof FeatureScopeResolver) {
                throw new ApiError('INTERNAL_ERROR', 'Feature scope resolver missing', 500);
            }
            $caps = $capsule->getLayerCapabilities((int) $request->getAttribute('user_id'), $layerId);
            if (!$caps['can_view']) {
                throw new ApiError('FORBIDDEN', 'No permission to view features in this layer', 403);
            }
        }

        // Set session user for the query engine
        $this->setUserInSession((int) $request->getAttribute('user_id'));

        $geometry = $body['geometry'] ?? null;
        if ($geometry === null || !is_array($geometry)) {
            throw new ApiError('VALIDATION_FAILED', 'geometry is required', 400);
        }

        // For within_distance and buffer, read extra params
        $extra = [];
        if ($operation === 'within_distance') {
            $extra['distance_m'] = isset($body['distance_m']) ? (float) $body['distance_m'] : 500;
        }
        if ($operation === 'buffer') {
            $extra['buffer_m'] = isset($body['buffer_m']) ? (float) $body['buffer_m'] : 100;
        }

        $srid = isset($body['srid']) && is_int($body['srid']) ? $body['srid'] : DefaultProjectedCrs::SRID;

        // If layer_id is null, we search across all accessible layers — pass 0 as placeholder
        $effectiveLayerId = $layerId ?? 0;

        $result = $this->query->execute([
            'operation' => $operation,
            'layer_id' => $effectiveLayerId,
            'geometry' => $geometry,
            'srid' => $srid,
            'limit' => isset($body['limit']) ? (int) $body['limit'] : 100,
            'offset' => isset($body['offset']) ? (int) $body['offset'] : 0,
            ...$extra,
        ]);

        return Envelope::success($response, $result);
    }

    /**
     * GET /spatial/query/bbox?layer_id=1&west=121&south=14&east=121.1&north=14.1&srid=3123
     *
     * Convenience GET endpoint for bbox queries (no POST needed for simple bbox).
     */
    public function bbox(Request $request, Response $response, array $args): Response
    {
        $this->ensureAuth($request);

        $q = $request->getQueryParams();
        $layerId = isset($q['layer_id']) ? (int) $q['layer_id'] : null;
        if ($layerId === null || $layerId <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'layer_id is required', 400);
        }

        $capsule = $request->getAttribute('feature_scope_resolver');
        if (!$capsule instanceof FeatureScopeResolver) {
            throw new ApiError('INTERNAL_ERROR', 'Feature scope resolver missing', 500);
        }
        $caps = $capsule->getLayerCapabilities((int) $request->getAttribute('user_id'), $layerId);
        if (!$caps['can_view']) {
            throw new ApiError('FORBIDDEN', 'No permission to view features in this layer', 403);
        }

        $west = (float) ($q['west'] ?? 0);
        $south = (float) ($q['south'] ?? 0);
        $east = (float) ($q['east'] ?? 0);
        $north = (float) ($q['north'] ?? 0);
        if ($west >= $east || $south >= $north) {
            throw new ApiError('VALIDATION_FAILED', 'bbox must have west < east and south < north', 400);
        }

        $this->setUserInSession((int) $request->getAttribute('user_id'));

        $result = $this->query->execute([
            'operation' => 'bbox',
            'layer_id' => $layerId,
            'geometry' => [$west, $south, $east, $north],
            'srid' => isset($q['srid']) && is_numeric($q['srid']) ? (int) $q['srid'] : DefaultProjectedCrs::SRID,
            'limit' => isset($q['limit']) ? (int) $q['limit'] : 100,
            'offset' => isset($q['offset']) ? (int) $q['offset'] : 0,
        ]);

        return Envelope::success($response, $result);
    }

    private function ensureAuth(Request $request): void
    {
        $uid = (int) ($request->getAttribute('user_id') ?: 0);
        if ($uid <= 0) {
            throw new ApiError('UNAUTHORIZED', 'Not authenticated', 401);
        }
    }

    private function setUserInSession(int $uid): void
    {
        $this->pdo->exec("SET LOCAL app.current_user_id = " . (int) $uid);
    }
}
