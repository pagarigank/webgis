<?php
declare(strict_types=1);

namespace App\Parcels\Http;

use App\Core\Crs\DefaultProjectedCrs;
use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\Audit\AuditWriter;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Parcel CRUD API (TASK-068).
 *
 * A parcel may exist without geometry (geometries arrive via later phases —
 * manual drawing, survey-derived). provenance (geometry_source) is mandatory,
 * defaulting to MANUAL_DRAWING. Scope enforcement is delegated to the
 * app.parcels RLS policies: the caller's user id is set into the session and
 * Postgres only exposes rows inside the user's data scopes. Writes bump
 * version and are audit-rowed; DELETE never removes the row — it soft-deletes
 * and requires a reason.
 */
class ParcelController
{
    private PDO $pdo;
    private AuditWriter $audit;
    private ?\App\Survey\Application\SurveyValidationService $validationService;
    private ?\App\Parcels\Domain\OverlapDetector $overlapDetector;
    private \App\Parcels\Domain\VersionDiffService $versionDiff;
    private ?\App\Parcels\Workflow\WorkflowEngine $workflowEngine;
    private ?\App\Parcels\Domain\ParcelLocator $parcelLocator = null;

    /**
     * FR-199 / TASK-072 — provenance values that assert the geometry came from
     * official survey data. Relabelling a parcel to any of these requires survey
     * data attached (survey_plan_id) and a recorded justification (change_reason).
     */
    /** Default sliver threshold (m²) for GET /parcels/{id}/overlaps (TASK-097). */
    private const DEFAULT_SLIVER_THRESHOLD = 0.05;

    private const SURVEY_DERIVED_SOURCES = [
        'SURVEY_COORDINATES',
        'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
        'TRANSFORMED_FROM_HISTORICAL_SURVEY',
    ];

    public function __construct(
        PDO $pdo,
        AuditWriter $audit,
        ?\App\Survey\Application\SurveyValidationService $validationService = null,
        ?\App\Parcels\Domain\OverlapDetector $overlapDetector = null,
        ?\App\Parcels\Domain\VersionDiffService $versionDiff = null,
        ?\App\Parcels\Workflow\WorkflowEngine $workflowEngine = null
    ) {
        $this->pdo = $pdo;
        $this->audit = $audit;
        $this->validationService = $validationService;
        $this->overlapDetector = $overlapDetector;
        $this->versionDiff = $versionDiff ?? new \App\Parcels\Domain\VersionDiffService();
        $this->workflowEngine = $workflowEngine;
    }

    private function resolveUser(Request $request): int
    {
        $userId = (int) ($request->getAttribute('user_id') ?: 0);
        if ($userId <= 0) {
            throw new ApiError('UNAUTHORIZED', 'Not authenticated', 401);
        }
        return $userId;
    }

    private function setUserInSession(int $uid): void
    {
        $this->pdo->exec("SET LOCAL app.current_user_id = " . (int) $uid);
    }

    private function parseUuid(array $args, string $key): string
    {
        $val = $args[$key] ?? '';
        if (!is_string($val) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid parcel id', 400);
        }
        return strtolower($val);
    }

    private function validatePsgc(?string $code, string $field): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $code = trim($code);
        if (!preg_match('/^\d{9,12}$/', $code)) {
            throw new ApiError('VALIDATION_FAILED', "{$field} must be a 9-12 digit PSGC code", 400);
        }
        return $code;
    }

    /**
     * GET /parcels/locate?lng=&lat=&srid=&tolerance_m=&limit=
     *
     * TASK-104b — resolve a map click to real parcels. The generic GIS identify
     * tool answers against app.gis_features, which is a separate feature store
     * with no relationship to app.parcels, so a parcel can never be identified
     * by it. This endpoint queries app.parcels directly (RLS-scoped by the
     * session user) and returns the parcels containing the point first, then
     * the nearest ones within the tolerance, so the map can offer open / split /
     * consolidate / lineage / history actions on a clicked parcel.
     */
    public function locate(Request $request, Response $response): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $q = $request->getQueryParams();

        $lng = isset($q['lng']) ? (float) $q['lng'] : null;
        $lat = isset($q['lat']) ? (float) $q['lat'] : null;
        if ($lng === null || $lat === null) {
            throw new ApiError('VALIDATION_FAILED', 'lng and lat are required', 400);
        }

        $srid       = isset($q['srid']) ? (int) $q['srid'] : DefaultProjectedCrs::SRID;
        $toleranceM = isset($q['tolerance_m']) && $q['tolerance_m'] !== '' ? (float) $q['tolerance_m'] : null;
        $limit      = isset($q['limit']) ? (int) $q['limit'] : 10;

        $result = $this->locator()->atPoint($lng, $lat, $srid, $toleranceM, $limit);

        return Envelope::success($response, $result);
    }

    /**
     * GET /parcels/overlay?bbox=w,s,e,n&limit=
     *
     * TASK-104b — GeoJSON for the parcels intersecting a viewport, used to draw
     * the parcel overlay that the locate action menu hangs off. Kept separate
     * from GET /parcels so the map never has to pay for the full list payload.
     */
    public function overlay(Request $request, Response $response): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $q = $request->getQueryParams();
        $bbox = $q['bbox'] ?? null;
        if (!is_string($bbox) || trim($bbox) === '') {
            throw new ApiError('VALIDATION_FAILED', 'bbox=w,s,e,n is required', 400);
        }

        $parts = array_map('trim', explode(',', $bbox));
        if (count($parts) !== 4) {
            throw new ApiError('VALIDATION_FAILED', 'bbox must be west,south,east,north', 400);
        }
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                throw new ApiError('VALIDATION_FAILED', 'bbox must contain numeric coordinates', 400);
            }
        }

        $west  = (float) $parts[0];
        $south = (float) $parts[1];
        $east  = (float) $parts[2];
        $north = (float) $parts[3];

        $limit = isset($q['limit']) ? (int) $q['limit'] : 500;
        $geojson = $this->locator()->inBbox($west, $south, $east, $north, $limit);

        return Envelope::success($response, $geojson);
    }

    private function locator(): \App\Parcels\Domain\ParcelLocator
    {
        if ($this->parcelLocator === null) {
            $this->parcelLocator = new \App\Parcels\Domain\ParcelLocator($this->pdo);
        }
        return $this->parcelLocator;
    }

    /**
     * GET /parcels?limit=&offset=&sort=&dir=&status=&psgc_barangay=&q=&include_historical=&bbox=
     *
     * TASK-070 — list with filters, keyword search, and map preview support.
     *  - `q`               keyword search across lot / block / survey plan / title /
     *                      tax declaration / parcel code / location / barangay name.
     *  - `include_historical=false` (default): SUPERSEDED parcels are hidden. Per the
     *                      TASK-070 AC, `include_historical=true` is the ONLY way to
     *                      see SUPERSEDED parcels.
     *  - `bbox=w,s,e,n`    (EPSG:4326) restrict the result set to parcels intersecting
     *                      the envelope — used by the map preview on the parcels page.
     */
    public function list(Request $request, Response $response): Response
    {
        $uid  = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $q      = $request->getQueryParams();
        $limit  = min(max((int) ($q['limit'] ?? 50), 1), 1000);
        $offset = max((int) ($q['offset'] ?? 0), 0);
        $sort   = $q['sort'] ?? 'created_at';
        $dir    = strtoupper($q['dir'] ?? 'DESC') === 'DESC' ? 'DESC' : 'ASC';
        $status = $q['status'] ?? null;
        $psgc   = $this->validatePsgc($q['psgc_barangay'] ?? null, 'psgc_barangay');
        $search = $q['q'] ?? null;
        $includeHistorical = filter_var($q['include_historical'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $bbox   = $q['bbox'] ?? null;

        $allowedSort = ['id', 'parcel_code', 'lot_number', 'block_number', 'tax_declaration_no', 'source_area_sqm', 'psgc_barangay', 'status', 'created_at', 'updated_at'];
        $sortCol     = in_array($sort, $allowedSort, true) ? $sort : 'created_at';

        // Joins give the search access to the barangay name (ref.psgc_areas) and the
        // survey plan number (app.survey_plans) so "plan" and "barangay" filters work.
        $from   = 'app.parcels p LEFT JOIN ref.psgc_areas pa ON pa.code = p.psgc_barangay '
                . 'LEFT JOIN app.survey_plans sp ON sp.id = p.survey_plan_id';
        $where  = ['p.deleted_at IS NULL'];
        $params = [];

        if ($status !== null && $status !== '') {
            $validStatuses = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'RETURNED', 'VERIFIED', 'APPROVED', 'PUBLISHED', 'ARCHIVED', 'SUPERSEDED'];
            if (!in_array($status, $validStatuses, true)) {
                throw new ApiError('VALIDATION_FAILED', 'Invalid status value', 400);
            }
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }

        // TASK-070/TASK-119 AC: only include_historical=true exposes
        // historical parcels (SUPERSEDED and ARCHIVED). Default views never
        // show them; every historical view is explicitly opted into.
        if (!$includeHistorical) {
            $where[] = "p.status NOT IN ('SUPERSEDED', 'ARCHIVED')";
        }

        if ($psgc !== null) {
            $where[] = 'p.psgc_barangay = :psgc';
            $params[':psgc'] = $psgc;
        }

        if ($search !== null && trim($search) !== '') {
            $where[] = "(p.parcel_code ILIKE :q OR p.lot_number ILIKE :q OR p.block_number ILIKE :q "
                . "OR p.title_number_ref ILIKE :q OR p.tax_declaration_no ILIKE :q "
                . "OR p.location_description ILIKE :q OR sp.plan_number ILIKE :q OR pa.name ILIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }

        if ($bbox !== null && $bbox !== '') {
            // PHP's parse_str turns `bbox=w,s,e,n` into an array; normalize so a
            // single comma-joined string and an auto-parsed array behave identically.
            $parts = is_array($bbox) ? $bbox : array_map('trim', explode(',', $bbox));
            $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));
            if (count($parts) !== 4) {
                throw new ApiError('VALIDATION_FAILED', 'bbox must be west,south,east,north', 400);
            }
            $nums = array_map('floatval', $parts);
            foreach ($parts as $part) {
                if (!is_numeric($part)) {
                    throw new ApiError('VALIDATION_FAILED', 'bbox must be valid west<south<east<north coordinates', 400);
                }
            }
            if (!($nums[0] < $nums[2]) || !($nums[1] < $nums[3])) {
                throw new ApiError('VALIDATION_FAILED', 'bbox must be valid west<south<east<north coordinates', 400);
            }
            $where[] = 'ST_Intersects(p.geom, ST_MakeEnvelope(:w, :s, :e, :n, 4326))';
            $params[':w'] = $nums[0];
            $params[':s'] = $nums[1];
            $params[':e'] = $nums[2];
            $params[':n'] = $nums[3];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM {$from} {$whereSql}";
        $cntStmt = $this->pdo->prepare($countSql);
        $cntStmt->execute($params);
        $total = (int) $cntStmt->fetchColumn();

        $select = "p.id, p.parcel_code, p.lot_number, p.block_number, p.title_number_ref, p.tax_declaration_no, "
            . "p.source_area_sqm, p.source_area_unit, p.computed_area_sqm, p.psgc_barangay, p.psgc_municipality, "
            . "p.psgc_province, p.location_description, p.status, p.geometry_source, p.verification_status, "
            . "p.org_id, p.remarks, p.version, p.created_by, p.created_at, p.updated_by, p.updated_at, "
            . "p.survey_plan_id, pa.name AS psgc_barangay_name, sp.plan_number AS survey_plan_number, "
            . "ST_AsGeoJSON(p.geom)::json AS geometry";
        $sql = "SELECT {$select} FROM {$from} {$whereSql} ORDER BY p.{$sortCol} {$dir} LIMIT :lim OFFSET :off";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(fn ($r) => $this->formatParcel($r), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return Envelope::success($response, [
            'data'     => $rows,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'sort'     => $sortCol,
            'dir'      => $dir,
            'include_historical' => $includeHistorical,
        ]);
    }

    /**
     * GET /parcels/{id}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $uid  = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $pid = $this->parseUuid($args, 'id');

        $select = $this->parcelSelect();
        $stmt = $this->pdo->prepare("SELECT {$select} FROM {$this->parcelFrom()} WHERE p.id = :pid AND p.deleted_at IS NULL");
        $stmt->execute([':pid' => $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }

        return Envelope::success($response, $this->formatParcel($row));
    }

    /**
     * POST /parcels
     * Body: { parcel_code, provenance, lot_number?, status?, psgc_barangay?, ... }
     * provenance (geometry_source) is mandatory. A parcel may exist without geometry.
     */
    public function create(Request $request, Response $response): Response
    {
        $uid  = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
        }

        $parcelCode = trim((string) ($body['parcel_code'] ?? ''));
        if ($parcelCode === '') {
            throw new ApiError('VALIDATION_FAILED', 'parcel_code is required', 400);
        }
        if (strlen($parcelCode) > 80) {
            throw new ApiError('VALIDATION_FAILED', 'parcel_code must be at most 80 characters', 400);
        }

        // TASK-068 AC: provenance is mandatory.
        $geometrySource = trim((string) ($body['provenance'] ?? $body['geometry_source'] ?? ''));
        if ($geometrySource === '') {
            throw new ApiError('VALIDATION_FAILED', 'provenance (geometry_source) is required', 400);
        }
        $this->assertGeometrySource($geometrySource);

        // FR-199: a parcel created with survey-derived provenance must attach a
        // survey plan and record the justification (TASK-072).
        $surveyPlanId = $this->resolveSurveyPlanId($body['survey_plan_id'] ?? null);
        $justification = $this->readCreateJustification($body);
        $this->assertSurveyDerivedProvenance($geometrySource, $surveyPlanId, $justification);

        $status = (string) ($body['status'] ?? 'DRAFT');
        $this->assertStatus($status);

        $geom = $body['geometry'] ?? null;
        if ($geom !== null) {
            $this->assertGeoJsonObject($geom);
        }

        $psgcBarangay = $this->validatePsgc($body['psgc_barangay'] ?? null, 'psgc_barangay');
        $psgcMunicipality = $this->validatePsgc($body['psgc_municipality'] ?? null, 'psgc_municipality');
        $psgcProvince = $this->validatePsgc($body['psgc_province'] ?? null, 'psgc_province');

        $sourceAreaSqm = $body['source_area_sqm'] ?? null;
        if ($sourceAreaSqm !== null) {
            if (!is_numeric($sourceAreaSqm) || (float) $sourceAreaSqm < 0) {
                throw new ApiError('VALIDATION_FAILED', 'source_area_sqm must be a non-negative number', 400);
            }
            $sourceAreaSqm = round((float) $sourceAreaSqm, 4);
        }

        $orgId = $body['org_id'] ?? null;
        if ($orgId !== null) {
            $orgId = (int) $orgId;
        }

        $geomSql = 'NULL';
        $geomParams = [];
        if ($geom !== null) {
            $geomJson = json_encode($geom);
            // Optional server-side validity check when geometry is provided.
            $checkStmt = $this->pdo->prepare("SELECT ST_IsValid(ST_GeomFromGeoJSON(:gj)) AS ok");
            $checkStmt->execute([':gj' => $geomJson]);
            if (!(bool) $checkStmt->fetchColumn()) {
                throw new ApiError('GEOMETRY_INVALID', 'geometry is not valid per ST_IsValid', 400);
            }
            $geomSql = 'ST_Multi(ST_Transform(ST_GeomFromGeoJSON(:gj), 4326))';
            $geomParams[':gj'] = $geomJson;
        }

        $sql = "INSERT INTO app.parcels "
            . "(id, parcel_code, lot_number, block_number, title_number_ref, tax_declaration_no, "
            . " source_area_sqm, source_area_unit, psgc_barangay, psgc_municipality, psgc_province, "
            . " location_description, status, geometry_source, remarks, org_id, source_document_id, survey_plan_id, created_by, geom, version) "
            . "VALUES (gen_random_uuid(), :code, :lot, :block, :title, :td, "
            . " :area_sqm, :area_unit, :psgc_b, :psgc_m, :psgc_p, "
            . " :loc_desc, :status, :geom_src, :remarks, :org_id, :src_doc, :survey_plan_id, :uid, {$geomSql}, 1) "
            . "RETURNING id";
        $params = $geomParams + [
            ':code'      => $parcelCode,
            ':lot'       => $body['lot_number'] ?? null,
            ':block'     => $body['block_number'] ?? null,
            ':title'     => $body['title_number_ref'] ?? null,
            ':td'        => $body['tax_declaration_no'] ?? null,
            ':area_sqm'  => $sourceAreaSqm,
            ':area_unit' => $body['source_area_unit'] ?? 'sqm',
            ':psgc_b'    => $psgcBarangay,
            ':psgc_m'    => $psgcMunicipality,
            ':psgc_p'    => $psgcProvince,
            ':loc_desc'  => $body['location_description'] ?? null,
            ':status'    => $status,
            ':geom_src'  => $geometrySource,
            ':remarks'   => $body['remarks'] ?? null,
            ':org_id'    => $orgId,
            ':src_doc'   => $body['source_document_id'] ?? null,
            ':survey_plan_id' => $surveyPlanId,
            ':uid'       => $uid,
        ];
        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($params);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ApiError('CONFLICT', 'A parcel with this parcel_code already exists.', 409);
            }
            if ($e->getCode() === '23503') {
                throw new ApiError('VALIDATION_FAILED', 'Foreign key violation (unknown PSGC code, org, or document).', 400);
            }
            throw $e;
        }
        $pid = (string) $stmt->fetchColumn();

        // Read back + audit
        $select = $this->parcelSelect();
        $fetch = $this->pdo->prepare("SELECT {$select} FROM {$this->parcelFrom()} WHERE p.id = :pid");
        $fetch->execute([':pid' => $pid]);
        $row = $fetch->fetch(PDO::FETCH_ASSOC);
        $parcel = $this->formatParcel($row);

        $this->audit->writeFromSession('INSERT', 'app.parcels', $pid, null, $this->stripForAudit($parcel), null, 'Parcel created via API');

        // TASK-069: brand-new parcel is version 1 — record the baseline version row.
        $this->writeVersion($pid, 1, $this->snapshotForVersion($parcel), $parcel['status'], $parcel['provenance'], 'Parcel created', $justification ?? 'Parcel created via API', $parcel['geometry']);

        return Envelope::success($response, $parcel, 201);
    }

    /**
     * PATCH /parcels/{id}
     * Partial update. Body: { ..., change_reason } — reason is optional for
     * attribute edits (TASK-069 records version rows); geometry/status changes
     * are version-numbered by the caller next.
     *
     * TASK-102 / FR-141 — editing an APPROVED parcel first runs the REOPEN
     * workflow transition: the edit is refused without change_reason, requires
     * the REOPEN permission (seeded parcel.approve), returns the parcel to the
     * configured state (WORKFLOW_APPROVED_EDIT_TARGET_STATE, default DRAFT),
     * and the previously approved version stays intact. Because the reopen
     * bumps the version, the caller's If-Match must be the approved version.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $uid  = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $pid = $this->parseUuid($args, 'id');

        // Verify existence + lock + read version + pre-change provenance (FR-199).
        $lock = $this->pdo->prepare("SELECT id, version, parcel_code, status, geometry_source, survey_plan_id FROM app.parcels WHERE id = :pid AND deleted_at IS NULL FOR UPDATE");
        $lock->execute([':pid' => $pid]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
        $currentVersion = (int) $row['version'];

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
        }

        // Optimistic concurrency against the version the caller saw (on an
        // APPROVED parcel that is the approved version — the reopen consumes
        // it below, before the edit applies).
        $ifMatch = $request->getHeaderLine('If-Match');
        if ($ifMatch === '') {
            throw new ApiError('PRECONDITION_REQUIRED', 'An If-Match header with the current version is required for updates.', 428);
        }
        if ((int) $ifMatch !== $currentVersion) {
            throw new ApiError('VERSION_CONFLICT', 'This parcel was modified by another user.', 409, ['current_version' => $currentVersion]);
        }

        // TASK-102 / FR-141 — an APPROVED record must be reopened through the
        // workflow engine before any edit applies. Everything the reopen needs
        // is already in hand (status, version, reason); it runs inside this
        // request transaction, before the attribute UPDATE below.
        if ($row['status'] === 'APPROVED') {
            // The target state is configuration, never a request field (FR-136):
            // reject explicit status changes before the reopen consumes the
            // caller's If-Match.
            if (array_key_exists('status', $body)) {
                throw new ApiError(
                    'INVALID_STATE',
                    'Status transitions are workflow-controlled; the approved-edit target state is configured, not requested (FR-141).',
                    400
                );
            }
            if ($this->workflowEngine === null) {
                throw new ApiError('INTERNAL_ERROR', 'Workflow engine is not available for the approved-edit cycle.', 500);
            }
            $reason = trim((string) ($body['change_reason'] ?? ($body['reason'] ?? '')));

            // The workflow engine bumps versions without writing version rows,
            // so the approved state would otherwise never be retrievable.
            // Capture it as an append-only row BEFORE the reopen flips status;
            // later edits never rewrite this row (FR-141 AC).
            $approvedState = $this->getCurrentParcel($pid);
            $this->writeVersion(
                $pid,
                $currentVersion,
                $this->snapshotForVersion($approvedState),
                'APPROVED',
                $approvedState['provenance'],
                'Approved record reopened for editing; approved state preserved',
                $reason,
                $approvedState['geometry']
            );

            $reopen = $this->workflowEngine->reopenApprovedRecord($pid, $uid, $reason);
        }

        $allowed = ['lot_number', 'block_number', 'title_number_ref', 'tax_declaration_no', 'source_area_sqm', 'source_area_unit', 'psgc_barangay', 'psgc_municipality', 'psgc_province', 'location_description', 'status', 'provenance', 'geometry_source', 'remarks', 'geometry', 'org_id', 'source_document_id', 'survey_plan_id', 'change_reason'];
        $unknown = array_diff_key($body, array_fill_keys($allowed, true));
        if (!empty($unknown)) {
            throw new ApiError('VALIDATION_FAILED', 'Unexpected fields in update payload', 400);
        }

        $sets = [];
        $params = [];

        if (array_key_exists('provenance', $body) || array_key_exists('geometry_source', $body)) {
            $gs = $body['provenance'] ?? $body['geometry_source'];
            $this->assertGeometrySource((string) $gs);
            $sets[] = 'geometry_source = :geom_src';
            $params[':geom_src'] = (string) $gs;
        }

        if (array_key_exists('survey_plan_id', $body)) {
            $surveyPlanId = $this->resolveSurveyPlanId($body['survey_plan_id']);
            $sets[] = 'survey_plan_id = :survey_plan_id';
            $params[':survey_plan_id'] = $surveyPlanId;
        }

        // FR-199: changing provenance to a survey-derived value requires survey
        // data attached (survey_plan_id present on the record after this update)
        // and a recorded justification (change_reason).
        if (array_key_exists('provenance', $body) || array_key_exists('geometry_source', $body)) {
            $newSource = (string) ($body['provenance'] ?? $body['geometry_source']);
            if (in_array($newSource, self::SURVEY_DERIVED_SOURCES, true)) {
                $resultingPlanId = array_key_exists('survey_plan_id', $body)
                    ? $params[':survey_plan_id']
                    : ($row['survey_plan_id'] !== null ? (int) $row['survey_plan_id'] : null);
                $reason = trim((string) ($body['change_reason'] ?? ($body['reason'] ?? '')));
                if ($resultingPlanId === null) {
                    throw new ApiError('VALIDATION_FAILED', 'Survey-derived provenance requires survey data: attach a survey_plan_id.', 400);
                }
                if ($reason === '') {
                    throw new ApiError('VALIDATION_FAILED', 'Switching to survey-derived provenance requires a recorded justification (change_reason).', 400);
                }
            }
        }

        foreach (['lot_number', 'block_number', 'title_number_ref', 'tax_declaration_no', 'location_description', 'remarks', 'source_area_unit'] as $text) {
            if (array_key_exists($text, $body)) {
                $sets[] = "{$text} = :{$text}";
                $params[":{$text}"] = $body[$text];
            }
        }

        if (array_key_exists('source_area_sqm', $body)) {
            $v = $body['source_area_sqm'];
            if ($v !== null && (!is_numeric($v) || (float) $v < 0)) {
                throw new ApiError('VALIDATION_FAILED', 'source_area_sqm must be a non-negative number', 400);
            }
            $sets[] = 'source_area_sqm = :area_sqm';
            $params[':area_sqm'] = $v === null ? null : round((float) $v, 4);
        }

        if (array_key_exists('status', $body)) {
            $this->assertStatus((string) $body['status']);
            // Interim workflow guard (FR-135a / FR-136; audit G-1). Until the
            // TASK-100 state machine routes every transition, PATCH /parcels/{id}
            // must not become a second, unguarded submit/approve path: only the
            // DRAFT <-> RETURNED attribute-edit loop is writable here. DRAFT ->
            // SUBMITTED goes through the validated POST /parcels/{id}/submit
            // (ValidationController::submit); SUPERSEDED is set exclusively by a
            // committed split/consolidation (FR-135a).
            if ((string) $body['status'] !== $row['status']) {
                $from = (string) $row['status'];
                $to = (string) $body['status'];
                $editable = ['DRAFT', 'RETURNED'];
                if (!in_array($from, $editable, true) || !in_array($to, $editable, true)) {
                    throw new ApiError(
                        'INVALID_STATE',
                        sprintf('Status transitions are workflow-controlled; use the workflow endpoints (cannot PATCH %s -> %s).', $from, $to),
                        400
                    );
                }
            }
            $sets[] = 'status = :status';
            $params[':status'] = (string) $body['status'];
        }

        foreach (['psgc_barangay', 'psgc_municipality', 'psgc_province'] as $psgc) {
            if (array_key_exists($psgc, $body)) {
                $sets[] = "{$psgc} = :{$psgc}";
                $params[":{$psgc}"] = $this->validatePsgc($body[$psgc], $psgc);
            }
        }

        if (array_key_exists('org_id', $body)) {
            $sets[] = 'org_id = :org_id';
            $params[':org_id'] = $body['org_id'] === null ? null : (int) $body['org_id'];
        }
        if (array_key_exists('source_document_id', $body)) {
            $sets[] = 'source_document_id = :src_doc';
            $params[':src_doc'] = $body['source_document_id'];
        }

        if (array_key_exists('geometry', $body)) {
            $geom = $body['geometry'];
            if ($geom === null) {
                $sets[] = 'geom = NULL';
            } else {
                $this->assertGeoJsonObject($geom);
                $geomJson = json_encode($geom);
                $vStmt = $this->pdo->prepare("SELECT ST_IsValid(ST_GeomFromGeoJSON(:gj)) AS ok");
                $vStmt->execute([':gj' => $geomJson]);
                if (!(bool) $vStmt->fetchColumn()) {
                    throw new ApiError('GEOMETRY_INVALID', 'geometry is not valid per ST_IsValid', 400);
                }
                $sets[] = 'geom = ST_Multi(ST_Transform(ST_GeomFromGeoJSON(:gj), 4326))';
                $params[':gj'] = $geomJson;
            }
        }

        if (empty($sets)) {
            return Envelope::success($response, $this->getCurrentParcel($pid));
        }

        $sets[] = 'version = version + 1';
        $sets[] = 'updated_by = :uid';
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[':uid'] = $uid;
        $params[':pid'] = $pid;

        // Capture true pre-change state BEFORE the UPDATE so summaries/audits
        // reflect what actually changed (TASK-069).
        $preChange = $this->getCurrentParcel($pid);

        $sql = "UPDATE app.parcels SET " . implode(', ', $sets) . " WHERE id = :pid AND deleted_at IS NULL RETURNING version";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $newVersion = (int) $stmt->fetchColumn();

        $updated = $this->getCurrentParcel($pid);

        // Audit (TASK-057-style): pre-change snapshot + new state.
        $reason = $body['change_reason'] ?? null;
        $this->audit->writeFromSession('UPDATE', 'app.parcels', $pid, $preChange, $this->stripForAudit($updated), null, $reason);

        // TASK-069: record a new version row; never rewrite history.
        $summary = $this->summarizeChanges($preChange, $updated);
        $this->writeVersion($pid, $newVersion, $this->snapshotForVersion($updated), $updated['status'], $updated['provenance'], $summary, $reason, $updated['geometry']);

        return Envelope::success($response, $updated);
    }

    /**
     * POST /parcels/{id}/accept-computation
     * TASK-092 — Accept computation -> parcel geometry.
     * Sets geometry, geometry_source = 'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
     * current_computation_id, bumps version, records version row, and audits.
     * AC: nothing writes to parcels.geom before this call; the version records
     * which computation was accepted.
     */
    public function acceptComputation(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $pid = $this->parseUuid($args, 'id');

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object.', 400);
        }

        $compId = (int) ($body['computation_id'] ?? 0);
        if ($compId <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'Field computation_id is required.', 400);
        }
        $reason = trim((string) ($body['reason'] ?? ($body['change_reason'] ?? "Accepted computation #{$compId}")));

        // Lock parcel row
        $lock = $this->pdo->prepare("SELECT id, version, geom FROM app.parcels WHERE id = :pid AND deleted_at IS NULL FOR UPDATE");
        $lock->execute([':pid' => $pid]);
        $parcelRow = $lock->fetch(PDO::FETCH_ASSOC);
        if ($parcelRow === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }

        // Verify computation exists, belongs to this parcel, and has geometry
        $cStmt = $this->pdo->prepare("SELECT id, parcel_id, computed_area_sqm, geom FROM app.parcel_computations WHERE id = :id");
        $cStmt->execute([':id' => $compId]);
        $compRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($compRow === false) {
            throw new ApiError('NOT_FOUND', 'Computation not found', 404);
        }
        if ((string) $compRow['parcel_id'] !== $pid) {
            throw new ApiError('VALIDATION_FAILED', 'Computation does not belong to this parcel.', 400);
        }
        if ($compRow['geom'] === null) {
            throw new ApiError('VALIDATION_FAILED', 'Computation does not contain valid polygon geometry.', 422);
        }

        $preChange = $this->getCurrentParcel($pid);

        // Update parcels table: set geom, geometry_source, current_computation_id, computed_area_sqm, version
        $upd = $this->pdo->prepare(
            "UPDATE app.parcels SET "
            . "geom = ST_Multi(c.geom), "
            . "geometry_source = 'COMPUTED_FROM_TECHNICAL_DESCRIPTION', "
            . "current_computation_id = c.id, "
            . "computed_area_sqm = c.computed_area_sqm, "
            . "version = app.parcels.version + 1, "
            . "updated_by = :uid, "
            . "updated_at = CURRENT_TIMESTAMP "
            . "FROM app.parcel_computations c "
            . "WHERE app.parcels.id = :pid AND c.id = :cid "
            . "RETURNING app.parcels.version"
        );
        $upd->execute([':pid' => $pid, ':cid' => $compId, ':uid' => $uid]);
        $newVersion = (int) $upd->fetchColumn();

        // Update parcel_computations is_current
        $this->pdo->prepare("UPDATE app.parcel_computations SET is_current = false WHERE parcel_id = :pid")->execute([':pid' => $pid]);
        $this->pdo->prepare("UPDATE app.parcel_computations SET is_current = true WHERE id = :cid")->execute([':cid' => $compId]);

        $updated = $this->getCurrentParcel($pid);

        // Audit & Version History
        $summary = "Accepted computation #{$compId} -> geometry updated from technical description";
        $snapshot = $this->snapshotForVersion($updated);
        $snapshot['accepted_computation_id'] = $compId;

        $this->writeVersion(
            $pid,
            $newVersion,
            $snapshot,
            $updated['status'],
            'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
            $summary,
            $reason,
            $updated['geometry']
        );

        $this->audit->writeFromSession(
            'UPDATE',
            'app.parcels',
            $pid,
            $preChange,
            $this->stripForAudit($updated),
            null,
            $reason
        );

        return Envelope::success($response, $updated, 200);
    }

    /**
     * DELETE /parcels/{id}?reason=...  | body { reason, change_reason }
     * Soft delete only: sets deleted_at, bumps version. Reason is mandatory.
     */
    /**
     * GET /parcels/{id}/overlaps
     *
     * TASK-097 — neighbour/overlap detection for a single parcel (FR-061):
     * GIST-indexed ST_Intersects against non-archived/non-superseded parcels,
     * geodesic overlap area in m², sliver classification, self excluded.
     * Optional ?sliver_threshold_sqm= overrides the default 0.05 m².
     */
    public function overlaps(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $pid = $this->parseUuid($args, 'id');

        if ($this->overlapDetector === null) {
            throw new ApiError('INTERNAL_ERROR', 'Overlap detector is not available.', 500);
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM app.parcels WHERE id = :pid AND deleted_at IS NULL');
        $exists->execute([':pid' => $pid]);
        if ($exists->fetchColumn() === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }

        $qp = $request->getQueryParams();
        $threshold = self::DEFAULT_SLIVER_THRESHOLD;
        if (isset($qp['sliver_threshold_sqm']) && $qp['sliver_threshold_sqm'] !== '') {
            if (!is_numeric($qp['sliver_threshold_sqm']) || (float) $qp['sliver_threshold_sqm'] < 0) {
                throw new ApiError('VALIDATION_FAILED', 'sliver_threshold_sqm must be a non-negative number', 400);
            }
            $threshold = (float) $qp['sliver_threshold_sqm'];
        }

        $result = $this->overlapDetector->detectOverlaps($pid, null, $threshold);

        return Envelope::success($response, $result, 200);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $uid  = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $pid = $this->parseUuid($args, 'id');

        $q = $request->getQueryParams();
        $body = $request->getParsedBody();
        $reason = trim((string) ($q['reason'] ?? ($body['reason'] ?? $body['change_reason'] ?? '')));
        if ($reason === '') {
            throw new ApiError('VALIDATION_FAILED', 'A delete reason is required', 400);
        }

        // lock + version
        $lock = $this->pdo->prepare("SELECT id, version, parcel_code FROM app.parcels WHERE id = :pid AND deleted_at IS NULL FOR UPDATE");
        $lock->execute([':pid' => $pid]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
        $currentVersion = (int) $row['version'];

        $ifMatch = $request->getHeaderLine('If-Match');
        if ($ifMatch !== '') {
            if ((int) $ifMatch !== $currentVersion) {
                throw new ApiError('VERSION_CONFLICT', 'This parcel was modified by another user.', 409, ['current_version' => $currentVersion]);
            }
        }

        $oldSnapshot = $this->buildPreChangeSnapshot($pid, $row['parcel_code'], $currentVersion);

        $sql = "UPDATE app.parcels SET deleted_at = CURRENT_TIMESTAMP, version = version + 1, updated_by = :uid WHERE id = :pid AND deleted_at IS NULL RETURNING id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pid' => $pid, ':uid' => $uid]);
        if ($stmt->fetchColumn() === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }

        $this->audit->writeFromSession('DELETE', 'app.parcels', $pid, $oldSnapshot, null, null, $reason);

        // TASK-069: record the tombstone as the final version row.
        $final = $this->getCurrentParcel($pid);
        $this->writeVersion($pid, (int) $final['version'], $this->snapshotForVersion($final), $final['status'], $final['provenance'], 'Parcel deleted', $reason, $final['geometry']);

        return Envelope::success($response, ['id' => $pid, 'deleted' => true]);
    }

    /**
     * GET /parcels/{id}/versions
     * Paginated, newest-first index of version rows (append-only lineage).
     */
    public function versions(Request $request, Response $response, array $args): Response
    {
        $this->resolveUser($request);
        $pid = $this->parseUuid($args, 'id');

        $this->assertParcelKeyExists($pid);

        $query = $request->getQueryParams();
        $page  = max(1, (int) ($query['page'] ?? 1));
        if ($page > 1000) {
            $page = 1000;
        }
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM audit.parcel_versions WHERE parcel_id = :pid');
        $count->execute([':pid' => $pid]);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT id, version, status, geometry_source, change_summary, change_reason, changed_by, changed_at, '
            . 'CASE WHEN geom IS NOT NULL THEN true ELSE false END AS has_geometry, request_id '
            . 'FROM audit.parcel_versions WHERE parcel_id = :pid ORDER BY version DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':pid', $pid);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map(fn (array $r) => [
            'id'              => (int) $r['id'],
            'version'         => (int) $r['version'],
            'status'          => $r['status'],
            'provenance'      => $r['geometry_source'],
            'change_summary'  => $r['change_summary'],
            'change_reason'   => $r['change_reason'],
            'changed_by'      => $r['changed_by'] === null ? null : (int) $r['changed_by'],
            'changed_at'      => $r['changed_at'],
            'has_geometry'    => (bool) $r['has_geometry'],
            'request_id'      => $r['request_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return Envelope::success($response, [
            'data'       => $items,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    /**
     * GET /parcels/{id}/versions/{v}
     * Full historical version: snapshot + geometry.
     */
    public function version(Request $request, Response $response, array $args): Response
    {
        $this->resolveUser($request);
        $pid        = $this->parseUuid($args, 'id');
        $versionNum = (int) ($args['v'] ?? 0);
        if ($versionNum < 1) {
            throw new ApiError('VALIDATION_FAILED', 'Version must be a positive integer', 400);
        }

        $this->assertParcelKeyExists($pid);

        $stmt = $this->pdo->prepare(
            'SELECT id, version, snapshot, status, geometry_source, change_summary, change_reason, changed_by, '
            . 'changed_at, ST_AsGeoJSON(geom) AS geometry, request_id '
            . 'FROM audit.parcel_versions WHERE parcel_id = :pid AND version = :v'
        );
        $stmt->execute([':pid' => $pid, ':v' => $versionNum]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Parcel version not found', 404);
        }

        $snapshot = json_decode($row['snapshot'], true);
        $geometry = $row['geometry'];
        if (is_string($geometry)) {
            $decoded = json_decode($geometry, true);
            $geometry = (is_array($decoded) && $decoded !== []) ? $decoded : null;
        }

        return Envelope::success($response, [
            'id'             => (int) $row['id'],
            'version'        => (int) $row['version'],
            'parcel_id'      => $pid,
            'snapshot'       => $snapshot,
            'geometry'       => $geometry,
            'status'         => $row['status'],
            'provenance'     => $row['geometry_source'],
            'change_summary' => $row['change_summary'],
            'change_reason'  => $row['change_reason'],
            'changed_by'     => $row['changed_by'] === null ? null : (int) $row['changed_by'],
            'changed_at'     => $row['changed_at'],
            'request_id'     => $row['request_id'],
        ]);
    }

    /**
     * POST /parcels/{id}/versions/{v}/restore
     * Restores a historical snapshot as a NEW version (history is never rewritten).
     * Requires If-Match against the current parcel version.
     */
    public function restore(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $pid        = $this->parseUuid($args, 'id');
        $versionNum = (int) ($args['v'] ?? 0);
        if ($versionNum < 1) {
            throw new ApiError('VALIDATION_FAILED', 'Version must be a positive integer', 400);
        }

        // Current parcel must exist and not be deleted.
        $lock = $this->pdo->prepare('SELECT id, version, parcel_code FROM app.parcels WHERE id = :pid AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([':pid' => $pid]);
        $current = $lock->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
        $currentVersion = (int) $current['version'];

        $ifMatch = $request->getHeaderLine('If-Match');
        if ($ifMatch === '') {
            throw new ApiError('PRECONDITION_REQUIRED', 'If-Match header is required for restore', 428);
        }
        if ((int) $ifMatch !== $currentVersion) {
            throw new ApiError('VERSION_CONFLICT', 'This parcel was modified by another user.', 409, ['current_version' => $currentVersion]);
        }

        // Load the historical version.
        $stmt = $this->pdo->prepare(
            'SELECT id, version, snapshot, status, geometry_source, ST_AsGeoJSON(geom) AS geometry '
            . 'FROM audit.parcel_versions WHERE parcel_id = :pid AND version = :v'
        );
        $stmt->execute([':pid' => $pid, ':v' => $versionNum]);
        $hist = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($hist === false) {
            throw new ApiError('NOT_FOUND', 'Parcel version not found', 404);
        }
        $snapshot = json_decode($hist['snapshot'], true);
        if (!is_array($snapshot)) {
            throw new ApiError('INTERNAL', 'Stored version snapshot is corrupt', 500);
        }

        // Apply the restored key attributes onto the live row, bump version.
        $sets = ['version = version + 1', 'updated_by = :uid', 'updated_at = CURRENT_TIMESTAMP'];
        $params = [':pid' => $pid, ':uid' => $uid];

        $map = [
            'lot_number' => 'lot_number', 'block_number' => 'block_number', 'title_number_ref' => 'title_number_ref',
            'tax_declaration_no' => 'tax_declaration_no', 'source_area_sqm' => 'source_area_sqm',
            'source_area_unit' => 'source_area_unit', 'psgc_barangay' => 'psgc_barangay',
            'psgc_municipality' => 'psgc_municipality', 'psgc_province' => 'psgc_province',
            'location_description' => 'location_description', 'status' => 'status',
            'provenance' => 'geometry_source', 'remarks' => 'remarks', 'org_id' => 'org_id',
            'source_document_id' => 'source_document_id', 'survey_plan_id' => 'survey_plan_id',
        ];
        foreach ($map as $snapKey => $col) {
            if (array_key_exists($snapKey, $snapshot)) {
                $val = $snapshot[$snapKey];
                if ($col === 'status') {
                    $this->assertStatus((string) $val);
                }
                if ($col === 'geometry_source') {
                    $this->assertGeometrySource((string) $val);
                }
                if (in_array($col, ['org_id', 'survey_plan_id'], true)) {
                    $sets[] = "{$col} = :{$col}";
                    $params[":{$col}"] = $val === null ? null : (int) $val;
                    continue;
                }
                $sets[] = "{$col} = :{$col}";
                $params[":{$col}"] = $val;
            }
        }

        $histGeom = $hist['geometry'];
        if (is_string($histGeom)) {
            $decoded = json_decode($histGeom, true);
            $histGeom = (is_array($decoded) && $decoded !== []) ? $decoded : null;
        }
        if ($histGeom !== null) {
            $sets[] = 'geom = ST_Multi(ST_Transform(ST_GeomFromGeoJSON(:gj), 4326))';
            $params[':gj'] = json_encode($histGeom);
        } else {
            $sets[] = 'geom = NULL';
        }

        $sql = 'UPDATE app.parcels SET ' . implode(', ', $sets) . ' WHERE id = :pid AND deleted_at IS NULL RETURNING version';
        $upd = $this->pdo->prepare($sql);
        $upd->execute($params);
        $newVersion = (int) $upd->fetchColumn();

        $restored = $this->getCurrentParcel($pid);
        $reason = $this->readChangeReason($request);
        $this->audit->writeFromSession('UPDATE', 'app.parcels', $pid, null, $this->stripForAudit($restored), null, $reason ?: "Restored from version {$versionNum}");

        $summary = "Restored from version {$versionNum}";
        $this->writeVersion($pid, $newVersion, $this->snapshotForVersion($restored), $restored['status'], $restored['provenance'], $summary, $reason, $restored['geometry']);

        return Envelope::success($response, $restored);
    }

    /**
     * GET /parcels/{id}/versions/{v}/compare?against={v2}
     *
     * TASK-105 — field-level diff and geometry diff between two versions.
     * Defaults to comparing version v against the current version.
     */
    public function compare(Request $request, Response $response, array $args): Response
    {
        $this->resolveUser($request);
        $pid = $this->parseUuid($args, 'id');
        $vA = (int) ($args['v'] ?? 0);
        $vB = (int) ($request->getQueryParams()['against'] ?? 0);

        if ($vA < 1) {
            throw new ApiError('VALIDATION_FAILED', 'Version must be a positive integer', 400);
        }

        $this->assertParcelKeyExists($pid);

        if ($vB < 1) {
            $cur = $this->pdo->prepare('SELECT version FROM app.parcels WHERE id = :pid');
            $cur->execute([':pid' => $pid]);
            $vB = (int) $cur->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            'SELECT version, snapshot, ST_AsGeoJSON(geom) AS geometry FROM audit.parcel_versions WHERE parcel_id = :pid AND version IN (:a, :b)'
        );
        $stmt->execute([':pid' => $pid, ':a' => min($vA, $vB), ':b' => max($vA, $vB)]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(int) $r['version']] = $r;
        }
        if (!isset($rows[$vA]) || !isset($rows[$vB])) {
            throw new ApiError('NOT_FOUND', 'One or both parcel versions were not found', 404);
        }

        $old = json_decode((string) $rows[min($vA, $vB)]['snapshot'], true) ?: [];
        $new = json_decode((string) $rows[max($vA, $vB)]['snapshot'], true) ?: [];

        $oldGeom = $this->extractRing($rows[min($vA, $vB)]['geometry']);
        $newGeom = $this->extractRing($rows[max($vA, $vB)]['geometry']);

        $result = $this->versionDiff->diff($old, $new, $oldGeom, $newGeom);

        return Envelope::success($response, [
            'parcel_id'      => $pid,
            'from_version'   => min($vA, $vB),
            'to_version'     => max($vA, $vB),
            'field_changes'  => $result['fields'],
            'geometry_diff'  => $result['geometry'],
        ], 200);
    }

    /** @return array<int, array{0:float,1:float}>|null */
    private function extractRing(?string $geojson): ?array
    {
        if ($geojson === null || $geojson === '') {
            return null;
        }
        $decoded = json_decode($geojson, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $this->versionDiff->exteriorRing($decoded);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Snapshot used for audit.parcel_versions: all editable parcel state except
     * the surrogate/audit columns and the geometry (which is stored separately
     * in the version's geom column).
     */
    private function snapshotForVersion(array $parcel): array
    {
        $snap = $parcel;
        unset($snap['id'], $snap['version'], $snap['created_by'], $snap['created_at'], $snap['updated_by'], $snap['updated_at'], $snap['geometry'], $snap['computed_area_sqm'], $snap['verification_status']);
        return $snap;
    }

    private function sessionRequestId(): ?string
    {
        $row = $this->pdo->query("SELECT NULLIF(current_setting('app.request_id', true), '') AS rid")->fetch();
        return isset($row['rid']) && trim((string) $row['rid']) !== '' ? (string) $row['rid'] : null;
    }

    /**
     * Append a row to audit.parcel_versions. Version rows are append-only:
     * monotonically increasing per parcel, never renumbered, never deleted.
     */
    private function writeVersion(string $pid, int $version, array $snapshot, string $status, string $geometrySource, ?string $summary, ?string $reason, array|string|null $geometry): void
    {
        $geomSql = 'NULL';
        $params = [
            ':pid'    => $pid,
            ':ver'    => $version,
            ':snap'   => json_encode($snapshot, JSON_THROW_ON_ERROR),
            ':status' => $status,
            ':gsrc'   => $geometrySource,
            ':summ'   => $summary,
            ':reason' => $reason,
            ':rid'    => $this->sessionRequestId(),
        ];

        if ($geometry !== null) {
            $geomSql = 'ST_Multi(ST_GeomFromGeoJSON(:gj))';
            $params[':gj'] = is_array($geometry) ? json_encode($geometry) : $geometry;
        }

        $sql = "INSERT INTO audit.parcel_versions "
            . "(parcel_id, version, snapshot, status, geometry_source, change_summary, change_reason, changed_by, request_id, geom) "
            . "VALUES (:pid, :ver, :snap::jsonb, :status, :gsrc, :summ, :reason, NULLIF(current_setting('app.user_id', true), '')::bigint, :rid, {$geomSql})";
        $this->pdo->prepare($sql)->execute($params);
    }

    /** Build a compact human-readable change summary from two snapshots. */
    private function summarizeChanges(array $old, array $new): string
    {
        $fields = ['status', 'provenance', 'lot_number', 'block_number', 'title_number_ref', 'tax_declaration_no', 'source_area_sqm', 'psgc_barangay', 'psgc_municipality', 'psgc_province', 'location_description'];
        $parts = [];

        $oldGeom = json_encode($old['geometry'] ?? null);
        $newGeom = json_encode($new['geometry'] ?? null);
        if ($oldGeom !== $newGeom) {
            $parts[] = $new['geometry'] === null ? 'geometry removed' : ($old['geometry'] === null ? 'geometry added' : 'geometry updated');
        }

        foreach ($fields as $f) {
            $ov = $old[$f] ?? null;
            $nv = $new[$f] ?? null;
            if ($ov !== $nv) {
                $parts[] = $f . ': ' . $this->shortenValue($ov) . ' -> ' . $this->shortenValue($nv);
            }
        }

        return implode('; ', $parts);
    }

    private function shortenValue(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        $s = is_scalar($v) ? (string) $v : json_encode($v);
        return strlen($s) > 40 ? substr($s, 0, 37) . '...' : $s;
    }

    private function parcelSelect(): string
    {
        return "p.id, p.parcel_code, p.lot_number, p.block_number, p.title_number_ref, p.tax_declaration_no, "
            . "p.source_area_sqm, p.source_area_unit, p.computed_area_sqm, p.psgc_barangay, p.psgc_municipality, "
            . "p.psgc_province, p.location_description, p.status, p.geometry_source, p.verification_status, "
            . "p.org_id, p.remarks, p.version, p.created_by, p.created_at, p.updated_by, p.updated_at, "
            . "p.survey_plan_id, sp.plan_number AS survey_plan_number, pa.name AS psgc_barangay_name, "
            . "ST_AsGeoJSON(p.geom)::json AS geometry";
    }

    private function parcelFrom(): string
    {
        return 'app.parcels p '
            . 'LEFT JOIN app.survey_plans sp ON sp.id = p.survey_plan_id '
            . 'LEFT JOIN ref.psgc_areas pa ON pa.code = p.psgc_barangay';
    }

    private function getCurrentParcel(string $pid): array
    {
        $select = $this->parcelSelect();
        $stmt = $this->pdo->prepare("SELECT {$select} FROM {$this->parcelFrom()} WHERE p.id = :pid");
        $stmt->execute([':pid' => $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
        return $this->formatParcel($row);
    }

    private function buildPreChangeSnapshot(string $pid, string $parcelCode, int $currentVersion): array
    {
        $select = $this->parcelSelect();
        $stmt = $this->pdo->prepare("SELECT {$select} FROM {$this->parcelFrom()} WHERE p.id = :pid");
        $stmt->execute([':pid' => $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['id' => $pid, 'parcel_code' => $parcelCode, 'version' => $currentVersion];
        }
        $snap = $this->formatParcel($row);
        $snap['version'] = $currentVersion;
        return $snap;
    }

    private function formatParcel(array $r): array
    {
        $geometry = $r['geometry'] ?? null;
        if (is_string($geometry)) {
            $decoded = json_decode($geometry, true);
            $geometry = (is_array($decoded) && $decoded !== []) ? $decoded : null;
        }
        return [
            'id'                 => $r['id'],
            'parcel_code'        => $r['parcel_code'],
            'lot_number'         => $r['lot_number'],
            'block_number'       => $r['block_number'],
            'title_number_ref'   => $r['title_number_ref'],
            'tax_declaration_no' => $r['tax_declaration_no'],
            'source_area_sqm'    => $r['source_area_sqm'] !== null ? (float) $r['source_area_sqm'] : null,
            'source_area_unit'   => $r['source_area_unit'],
            'computed_area_sqm'  => $r['computed_area_sqm'] !== null ? (float) $r['computed_area_sqm'] : null,
            'psgc_barangay'      => $r['psgc_barangay'],
            'psgc_barangay_name' => $r['psgc_barangay_name'] ?? null,
            'psgc_municipality'  => $r['psgc_municipality'],
            'psgc_province'      => $r['psgc_province'],
            'survey_plan_id'     => $r['survey_plan_id'] !== null ? (int) $r['survey_plan_id'] : null,
            'survey_plan_number' => $r['survey_plan_number'] ?? null,
            'location_description' => $r['location_description'],
            'status'             => $r['status'],
            'provenance'         => $r['geometry_source'],
            'geometry_source'    => $r['geometry_source'],
            'verification_status' => $r['verification_status'],
            'org_id'             => $r['org_id'] !== null ? (int) $r['org_id'] : null,
            'remarks'            => $r['remarks'],
            'version'            => (int) $r['version'],
            'created_by'         => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_at'         => $r['created_at'],
            'updated_by'         => $r['updated_by'] !== null ? (int) $r['updated_by'] : null,
            'updated_at'         => $r['updated_at'],
            'geometry'           => $geometry,
        ];
    }

    private function stripForAudit(array $parcel): array
    {
        unset($parcel['geometry'], $parcel['id'], $parcel['created_by'], $parcel['created_at'], $parcel['updated_by'], $parcel['updated_at']);
        return $parcel;
    }

    private function assertParcelKeyExists(string $pid): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM app.parcels WHERE id = :pid');
        $stmt->execute([':pid' => $pid]);
        if ($stmt->fetchColumn() === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
    }

    private function readChangeReason(Request $request): ?string
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return null;
        }
        $reason = trim((string) ($body['change_reason'] ?? ($body['reason'] ?? '')));
        return $reason === '' ? null : $reason;
    }

    /**
     * FR-199 — resolve and validate survey_plan_id. Returns null when the body
     * omits it; throws when a value is present but not a positive integer or does
     * not reference an existing survey plan.
     */
    private function resolveSurveyPlanId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/^\d{1,10}$/', trim($value))) {
            $id = (int) trim($value);
        } else {
            throw new ApiError('VALIDATION_FAILED', 'survey_plan_id must be a positive integer', 400);
        }
        if ($id <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'survey_plan_id must be a positive integer', 400);
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM app.survey_plans WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $id]);
        if ($stmt->fetchColumn() === false) {
            throw new ApiError('VALIDATION_FAILED', 'survey_plan_id does not reference an existing survey plan', 400);
        }
        return $id;
    }

    /**
     * FR-199 — the justification recorded against a parcel that is created with a
     * survey-derived provenance. Mirrors readChangeReason() so the create endpoint
     * accepts the same change_reason field used by updates.
     */
    private function readCreateJustification(array $body): ?string
    {
        $reason = trim((string) ($body['change_reason'] ?? ($body['justification'] ?? '')));
        return $reason === '' ? null : $reason;
    }

    /**
     * FR-199 — survey-derived provenance (SURVEY_COORDINATES,
     * COMPUTED_FROM_TECHNICAL_DESCRIPTION, TRANSFORMED_FROM_HISTORICAL_SURVEY)
     * requires survey data attached (survey_plan_id) and a recorded justification.
     * Throws VALIDATION_FAILED when either prerequisite is missing.
     */
    private function assertSurveyDerivedProvenance(string $source, ?int $surveyPlanId, ?string $justification): void
    {
        if (!in_array($source, self::SURVEY_DERIVED_SOURCES, true)) {
            return;
        }
        if ($surveyPlanId === null) {
            throw new ApiError('VALIDATION_FAILED', 'Survey-derived provenance requires survey data: attach a survey_plan_id.', 400);
        }
        if ($justification === null) {
            throw new ApiError('VALIDATION_FAILED', 'Switching to survey-derived provenance requires a recorded justification (change_reason).', 400);
        }
    }

    private function assertStatus(string $status): void
    {
        $validStatuses = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'RETURNED', 'VERIFIED', 'APPROVED', 'PUBLISHED', 'ARCHIVED', 'SUPERSEDED'];
        if (!in_array($status, $validStatuses, true)) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid status value', 400);
        }
    }

    private function assertGeometrySource(string $source): void
    {
        $valid = ['SURVEY_COORDINATES', 'COMPUTED_FROM_TECHNICAL_DESCRIPTION', 'TRANSFORMED_FROM_HISTORICAL_SURVEY', 'IMPORTED_GIS', 'CAD_IMPORT', 'DIGITIZED_FROM_IMAGERY', 'MANUAL_DRAWING', 'APPROXIMATE'];
        if (!in_array($source, $valid, true)) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid provenance (geometry_source) value', 400);
        }
    }

    private function assertGeoJsonObject(mixed $geom): void
    {
        if (!is_array($geom) || !isset($geom['type']) || !is_string($geom['type'])) {
            throw new ApiError('VALIDATION_FAILED', 'geometry must be a GeoJSON object', 400);
        }
        $allowedTypes = ['Polygon', 'MultiPolygon'];
        if (!in_array($geom['type'], $allowedTypes, true)) {
            throw new ApiError('VALIDATION_FAILED', 'Unsupported geometry type: ' . $geom['type'], 400);
        }
    }
}
