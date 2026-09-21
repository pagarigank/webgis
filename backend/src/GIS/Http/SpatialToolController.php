<?php
declare(strict_types=1);

namespace App\GIS\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\GIS\Domain\IdentifyPopup;
use App\GIS\Domain\SpatialMeasure;
use App\RBAC\FeatureScopeResolver;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SpatialToolController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SpatialMeasure $measure,
        private readonly IdentifyPopup $identifyPopup,
    ) {
    }

    /**
     * POST /spatial/measure
     * Body: { type: 'distance'|'area', geometry: GeoJSON, srid?: int }
     *
     * Distance: computes length of a LineString/MultiLineString in a projected
     * CRS (default EPSG:32651, UTM 51N for Metro Manila).
     * Area: computes area of a Polygon/MultiPolygon in the same CRS.
     */
    public function measure(Request $request, Response $response, array $args): Response
    {
        $this->ensureAuth($request);

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
        }

        $type = $body['type'] ?? '';
        if (!in_array($type, ['distance', 'area'], true)) {
            throw new ApiError('VALIDATION_FAILED', 'type must be "distance" or "area"', 400);
        }

        $geom = $body['geometry'] ?? null;
        if (!is_array($geom)) {
            throw new ApiError('VALIDATION_FAILED', 'geometry is required', 400);
        }

        $srid = isset($body['srid']) && is_int($body['srid']) ? $body['srid'] : null;

        $result = $type === 'distance'
            ? $this->measure->length($geom, $srid)
            : $this->measure->area($geom, $srid);

        return Envelope::success($response, $result);
    }

    /**
     * GET /spatial/identify?lng=121.0&lat=14.5&layer_id=1&srid=32651
     *
     * Identify features at a point, returning nearby features sorted by
     * distance in the target CRS.
     */
    public function identify(Request $request, Response $response, array $args): Response
    {
        $this->ensureAuth($request);

        $q = $request->getQueryParams();
        $lng = isset($q['lng']) ? (float) $q['lng'] : null;
        $lat = isset($q['lat']) ? (float) $q['lat'] : null;
        $layerId = isset($q['layer_id']) ? (int) $q['layer_id'] : null;
        $srid = isset($q['srid']) && is_int($q['srid']) ? $q['srid'] : 32651;

        if ($lng === null || $lat === null) {
            throw new ApiError('VALIDATION_FAILED', 'lng and lat are required', 400);
        }

        if ($layerId <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'layer_id is required', 400);
        }

        // Scope check: user must have access to this layer
        $capsule = $request->getAttribute('feature_scope_resolver');
        if (!$capsule instanceof FeatureScopeResolver) {
            throw new ApiError('INTERNAL_ERROR', 'Feature scope resolver missing', 500);
        }
        $caps = $capsule->getLayerCapabilities((int) $request->getAttribute('user_id'), $layerId);
        if (!$caps['can_view']) {
            throw new ApiError('FORBIDDEN', 'No permission to view features in this layer', 403);
        }

        $request->getAttribute('pdo'); // not used here; pdo is in measure/identifyPopup
        // We need to set the session user for the identify query inside SpatialMeasure
        $this->setUserInSession((int) $request->getAttribute('user_id'));

        $result = $this->identifyPopup->forPoint(
            $q['feature_id'] ?? '',
            $layerId,
            ['lng' => $lng, 'lat' => $lat],
            $srid,
        );

        return Envelope::success($response, $result);
    }

    /**
     * Identify all features near a point across the user's accessible layers.
     */
    public function identifyNearby(Request $request, Response $response, array $args): Response
    {
        $this->ensureAuth($request);

        $q = $request->getQueryParams();
        $lng = isset($q['lng']) ? (float) $q['lng'] : null;
        $lat = isset($q['lat']) ? (float) $q['lat'] : null;
        $srid = isset($q['srid']) && is_int($q['srid']) ? $q['srid'] : 32651;

        if ($lng === null || $lat === null) {
            throw new ApiError('VALIDATION_FAILED', 'lng and lat are required', 400);
        }

        $this->setUserInSession((int) $request->getAttribute('user_id'));

        $result = $this->measure->identify($lng, $lat, $srid);

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
