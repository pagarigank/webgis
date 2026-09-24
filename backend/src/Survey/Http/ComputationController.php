<?php
declare(strict_types=1);

namespace App\Survey\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\Survey\Application\SurveyComputationService;
use App\Survey\Domain\ComputeCrsGuard;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller for parcel computations and survey math workflows (TASK-090, 091, 094).
 */
class ComputationController
{
    private PDO $pdo;
    private SurveyComputationService $service;
    private ComputeCrsGuard $crsGuard;

    public function __construct(
        PDO $pdo,
        SurveyComputationService $service,
        ?ComputeCrsGuard $crsGuard = null
    ) {
        $this->pdo = $pdo;
        $this->service = $service;
        $this->crsGuard = $crsGuard ?? new ComputeCrsGuard();
    }

    private function resolveUserId(Request $request): int
    {
        $uid = (int) ($request->getAttribute('user_id') ?: 0);
        if ($uid <= 0) {
            throw new ApiError('UNAUTHORIZED', 'Not authenticated', 401);
        }
        return $uid;
    }

    /**
     * POST /parcels/{id}/calculate
     *
     * Request body:
     * {
     *   "technical_description_id": 412,
     *   "compute_crs": "EPSG:3123",
     *   "tolerances": { "linear_closure_m": 0.10, "relative_precision_min": 5000 }
     * }
     */
    public function calculate(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUserId($request);
        $parcelId = $args['id'] ?? '';

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object.', 400);
        }

        $tdId = (int) ($body['technical_description_id'] ?? 0);
        if ($tdId <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'Field technical_description_id is required.', 400);
        }

        $computeCrs = $body['compute_crs'] ?? null;
        if ($computeCrs === null || $computeCrs === '') {
            throw new ApiError('CRS_REQUIRED', 'Field compute_crs is required.', 422);
        }

        $tolerances = is_array($body['tolerances'] ?? null) ? $body['tolerances'] : [];

        $result = $this->service->calculateForParcel(
            $parcelId,
            $tdId,
            $computeCrs,
            $tolerances,
            $uid
        );

        return Envelope::success($response, $result, 201);
    }

    /**
     * GET /parcels/{id}/computations
     */
    public function listForParcel(Request $request, Response $response, array $args): Response
    {
        $this->resolveUserId($request);
        $parcelId = $args['id'] ?? '';

        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.parcel_id, c.technical_description_id, c.compute_crs_id, crs.code AS compute_crs_code, '
            . 'c.method, c.adjustment_method, c.base_computation_id, '
            . 'c.linear_error_m, c.relative_precision_denominator, c.computed_area_sqm, c.postgis_area_sqm, '
            . 'c.source_area_sqm, c.closure_status, c.is_current, c.engine_version, c.computed_at, '
            . 'u.full_name AS computed_by_name '
            . 'FROM app.parcel_computations c '
            . 'LEFT JOIN ref.crs_registry crs ON crs.id = c.compute_crs_id '
            . 'LEFT JOIN app.users u ON u.id = c.computed_by '
            . 'WHERE c.parcel_id = :pid '
            . 'ORDER BY c.computed_at DESC, c.id DESC'
        );
        $stmt->execute([':pid' => $parcelId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = array_map(function ($r) {
            $prec = $r['relative_precision_denominator'] !== null
                ? sprintf('1:%d', (int) round((float) $r['relative_precision_denominator']))
                : '1:INF';
            return [
                'id'                         => (int) $r['id'],
                'parcel_id'                  => (string) $r['parcel_id'],
                'technical_description_id'   => (int) $r['technical_description_id'],
                'compute_crs'                => (string) $r['compute_crs_code'],
                'method'                     => (string) $r['method'],
                'adjustment_method'          => $r['adjustment_method'],
                'base_computation_id'        => $r['base_computation_id'] !== null ? (int) $r['base_computation_id'] : null,
                'linear_error_m'             => (float) $r['linear_error_m'],
                'relative_precision'         => $prec,
                'computed_area_sqm'          => (float) $r['computed_area_sqm'],
                'postgis_area_sqm'           => $r['postgis_area_sqm'] !== null ? (float) $r['postgis_area_sqm'] : null,
                'source_area_sqm'            => $r['source_area_sqm'] !== null ? (float) $r['source_area_sqm'] : null,
                'closure_status'             => (string) $r['closure_status'],
                'is_current'                 => (bool) $r['is_current'],
                'engine_version'             => (string) $r['engine_version'],
                'computed_at'                => (string) $r['computed_at'],
                'computed_by_name'           => (string) ($r['computed_by_name'] ?? 'System'),
            ];
        }, $rows);

        return Envelope::success($response, $data, 200);
    }

    /**
     * GET /computations/{id}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $this->resolveUserId($request);
        $id = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            'SELECT c.*, crs.code AS compute_crs_code, ST_AsGeoJSON(c.geom) AS geom_geojson, '
            . 'u.full_name AS computed_by_name '
            . 'FROM app.parcel_computations c '
            . 'LEFT JOIN ref.crs_registry crs ON crs.id = c.compute_crs_id '
            . 'LEFT JOIN app.users u ON u.id = c.computed_by '
            . 'WHERE c.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Computation not found', 404);
        }

        // Fetch vertices
        $vStmt = $this->pdo->prepare('SELECT * FROM app.parcel_vertices WHERE computation_id = :cid ORDER BY seq ASC');
        $vStmt->execute([':cid' => $id]);
        $rawVertices = $vStmt->fetchAll(PDO::FETCH_ASSOC);

        $vertices = array_map(fn ($v) => [
            'seq'       => (int) $v['seq'],
            'label'     => (string) ($v['point_label'] ?? $v['seq']),
            'easting'   => (float) $v['easting'],
            'northing'  => (float) $v['northing'],
            'latitude'  => $v['latitude'] !== null ? (float) $v['latitude'] : null,
            'longitude' => $v['longitude'] !== null ? (float) $v['longitude'] : null,
        ], $rawVertices);

        $prec = $row['relative_precision_denominator'] !== null
            ? sprintf('1:%d', (int) round((float) $row['relative_precision_denominator']))
            : '1:INF';

        $data = [
            'id'                       => (int) $row['id'],
            'parcel_id'                => (string) $row['parcel_id'],
            'technical_description_id' => (int) $row['technical_description_id'],
            'compute_crs'              => (string) $row['compute_crs_code'],
            'method'                   => (string) $row['method'],
            'adjustment_method'        => $row['adjustment_method'],
            'base_computation_id'      => $row['base_computation_id'] !== null ? (int) $row['base_computation_id'] : null,
            'closure'                  => [
                'delta_e'                        => (float) $row['closure_de'],
                'delta_n'                        => (float) $row['closure_dn'],
                'linear_error_m'                 => (float) $row['linear_error_m'],
                'error_azimuth_dd'               => $row['error_azimuth_dd'] !== null ? (float) $row['error_azimuth_dd'] : null,
                'perimeter_m'                    => (float) $row['perimeter_m'],
                'relative_precision_denominator' => $row['relative_precision_denominator'] !== null ? (float) $row['relative_precision_denominator'] : null,
                'relative_precision'             => $prec,
                'status'                         => (string) $row['closure_status'],
            ],
            'area' => [
                'computed_sqm'   => (float) $row['computed_area_sqm'],
                'postgis_sqm'    => $row['postgis_area_sqm'] !== null ? (float) $row['postgis_area_sqm'] : null,
                'source_sqm'     => $row['source_area_sqm'] !== null ? (float) $row['source_area_sqm'] : null,
                'difference_sqm' => $row['area_diff_sqm'] !== null ? (float) $row['area_diff_sqm'] : null,
                'difference_pct' => $row['area_diff_pct'] !== null ? (float) $row['area_diff_pct'] : null,
                'note'           => \App\Survey\Domain\AreaCalculator::VALIDATION_AID_NOTE,
            ],
            'vertices'            => $vertices,
            'geometry_preview'    => $row['geom_geojson'] !== null ? json_decode((string) $row['geom_geojson'], true) : null,
            'validation_result'   => $row['validation_result'] !== null ? json_decode((string) $row['validation_result'], true) : null,
            'is_current'          => (bool) $row['is_current'],
            'engine_version'      => (string) $row['engine_version'],
            'computed_at'         => (string) $row['computed_at'],
            'computed_by_name'    => (string) ($row['computed_by_name'] ?? 'System'),
        ];

        return Envelope::success($response, $data, 200);
    }

    /**
     * GET /computations/{id}/snapshot
     */
    public function getSnapshot(Request $request, Response $response, array $args): Response
    {
        $this->resolveUserId($request);
        $id = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare('SELECT input_snapshot, engine_version, computed_at FROM app.parcel_computations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Computation not found', 404);
        }

        $snapshot = json_decode((string) $row['input_snapshot'], true);
        return Envelope::success($response, [
            'computation_id' => $id,
            'engine_version' => $row['engine_version'],
            'computed_at'    => $row['computed_at'],
            'snapshot'       => $snapshot,
        ], 200);
    }

    /**
     * POST /computations/{id}/replay
     */
    public function replay(Request $request, Response $response, array $args): Response
    {
        $this->resolveUserId($request);
        $id = (int) ($args['id'] ?? 0);

        $result = $this->service->replay($id);
        return Envelope::success($response, $result, 200);
    }

    /**
     * POST /computations/{id}/adjust
     */
    public function adjust(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUserId($request);
        $id = (int) ($args['id'] ?? 0);

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object.', 400);
        }

        $method = (string) ($body['method'] ?? 'COMPASS');
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];

        $result = $this->service->adjust($id, $method, $params, $uid);
        return Envelope::success($response, $result, 201);
    }

    /**
     * GET /crs/suggest?lng=120.98&lat=14.59
     */
    public function suggestZone(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        if (!isset($q['lng']) || !is_numeric($q['lng'])) {
            throw new ApiError('VALIDATION_FAILED', 'Query parameter lng is required and must be numeric.', 400);
        }

        $lng = (float) $q['lng'];
        $suggested = $this->crsGuard->suggestPtmZone($lng);

        return Envelope::success($response, $suggested, 200);
    }
}
