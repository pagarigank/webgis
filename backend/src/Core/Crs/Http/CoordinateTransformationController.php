<?php
declare(strict_types=1);

namespace App\Core\Crs\Http;

use App\Core\Crs\CoordinateTransformationService;
use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller for explicit coordinate transformations (TASK-095).
 */
class CoordinateTransformationController
{
    private CoordinateTransformationService $service;

    public function __construct(CoordinateTransformationService $service)
    {
        $this->service = $service;
    }

    /**
     * POST /crs/transform
     *
     * Request body:
     * {
     *   "coordinates": [ {"x": 512225.12, "y": 1678780.45}, ... ],
     *   "source_crs": "EPSG:3123",
     *   "target_crs": "EPSG:4326",
     *   "entity_type": "PARCEL",      (optional)
     *   "entity_id": "uuid-...",      (optional)
     *   "notes": "Transformation"     (optional)
     * }
     */
    public function transform(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object.', 400);
        }

        $coords = $body['coordinates'] ?? null;
        if (!is_array($coords) || empty($coords)) {
            throw new ApiError('VALIDATION_FAILED', 'Field coordinates must be a non-empty array of points.', 400);
        }

        $sourceCrs = $body['source_crs'] ?? null;
        $targetCrs = $body['target_crs'] ?? null;

        if ($sourceCrs === null || $sourceCrs === '') {
            throw new ApiError('CRS_REQUIRED', 'Field source_crs is required.', 422);
        }
        if ($targetCrs === null || $targetCrs === '') {
            throw new ApiError('CRS_REQUIRED', 'Field target_crs is required.', 422);
        }

        $userId = $request->getAttribute('user_id');

        $options = [
            'entity_type'      => isset($body['entity_type']) ? (string) $body['entity_type'] : null,
            'entity_id'        => isset($body['entity_id']) ? (string) $body['entity_id'] : null,
            'method'           => (string) ($body['method'] ?? 'POSTGIS_ST_TRANSFORM'),
            'parameters'       => is_array($body['parameters'] ?? null) ? $body['parameters'] : null,
            'parameter_source' => isset($body['parameter_source']) ? (string) $body['parameter_source'] : 'PostGIS / PROJ',
            'accuracy_m'       => isset($body['accuracy_m']) ? (float) $body['accuracy_m'] : 0.05,
            'performed_by'     => $userId !== null ? (int) $userId : null,
            'notes'            => isset($body['notes']) ? (string) $body['notes'] : null,
            'persist_log'      => (bool) ($body['persist_log'] ?? true),
        ];

        $result = $this->service->transform($coords, $sourceCrs, $targetCrs, $options);

        return Envelope::success($response, $result, 200);
    }
}
