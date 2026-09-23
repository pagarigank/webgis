<?php
declare(strict_types=1);

namespace App\Survey\Http;

use App\Audit\AuditWriter;
use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\Survey\Domain\CoordinateDerivation;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Survey control point API (Phase 9 — TASK-073/074/075).
 *
 * A control point is stored with BOTH coordinate pairs: the original pair the
 * surveyor supplied and the pair derived from it (coordinate_origin records
 * which pair is original; responses label the derived pair explicitly).
 * Lat/long are always WGS 84 (EPSG:4326); easting/northing live in the
 * projected native CRS. Writes bump version and are audit-rowed inside the
 * same transaction; DELETE soft-deletes and requires a reason.
 *
 * Area of use (TASK-073 AC): a point whose WGS 84 position falls outside the
 * CRS area-of-use bounding box stored in ref.crs_registry is rejected with
 * 400 — a projection that cannot produce a finite in-range geographic
 * position is rejected as well.
 */
class ControlPointController
{
    /** Mirrors ck_cp_point_type on app.survey_control_points. */
    private const POINT_TYPES = [
        'BLLM', 'MBM', 'PBM', 'GCP', 'CONTROL_POINT', 'TIE_POINT', 'REFERENCE_POINT', 'OTHER',
    ];

    /** Mirrors ck_cp_status on app.survey_control_points. */
    private const STATUSES = ['UNVERIFIED', 'VERIFIED', 'DISPUTED', 'RETIRED'];

    public function __construct(private readonly PDO $pdo, private readonly AuditWriter $audit)
    {
    }

    // ── TASK-073: CRUD ─────────────────────────────────────────────────────

    /**
     * GET /control-points?limit=&offset=&sort=&dir=&status=&type=&q=&bbox=
     *
     * `q` fuzzy-matches point name / description / survey reference
     * (substring, accelerated by the trigram index on point_name); `bbox`
     * (w,s,e,n in EPSG:4326) restricts results to points intersecting the
     * envelope — used by the map's control point picker.
     */
    public function list(Request $request, Response $response): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $q      = $request->getQueryParams();
        $limit  = min(max((int) ($q['limit'] ?? 50), 1), 1000);
        $offset = max((int) ($q['offset'] ?? 0), 0);
        $sort   = $q['sort'] ?? 'point_name';
        $dir    = strtoupper($q['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $allowedSort = ['point_name', 'point_type', 'status', 'created_at', 'updated_at'];
        $sortCol     = in_array($sort, $allowedSort, true) ? $sort : 'point_name';

        $where  = ['cp.deleted_at IS NULL'];
        $params = [];

        $status = $q['status'] ?? null;
        if ($status !== null && $status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw new ApiError('VALIDATION_FAILED', 'Invalid status value', 400);
            }
            $where[]   = 'cp.status = :status';
            $params[':status'] = $status;
        }

        $type = $q['type'] ?? null;
        if ($type !== null && $type !== '') {
            if (!in_array($type, self::POINT_TYPES, true)) {
                throw new ApiError('VALIDATION_FAILED', 'Invalid type value', 400);
            }
            $where[]   = 'cp.point_type = :type';
            $params[':type'] = $type;
        }

        $search = $q['q'] ?? null;
        if ($search !== null && trim($search) !== '') {
            $where[] = '(cp.point_name ILIKE :q OR cp.description ILIKE :q OR cp.survey_reference ILIKE :q)';
            $params[':q'] = '%' . trim($search) . '%';
        }

        $bbox = $q['bbox'] ?? null;
        if ($bbox !== null && $bbox !== '') {
            $parts = is_array($bbox) ? $bbox : array_map('trim', explode(',', $bbox));
            $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));
            if (count($parts) !== 4) {
                throw new ApiError('VALIDATION_FAILED', 'bbox must be west,south,east,north', 400);
            }
            foreach ($parts as $part) {
                if (!is_numeric($part)) {
                    throw new ApiError('VALIDATION_FAILED', 'bbox must be numeric west,south,east,north', 400);
                }
            }
            $nums = array_map('floatval', $parts);
            if (!($nums[0] < $nums[2]) || !($nums[1] < $nums[3])) {
                throw new ApiError('VALIDATION_FAILED', 'bbox must satisfy west<south<east<north', 400);
            }
            $where[] = 'ST_Intersects(cp.geom, ST_MakeEnvelope(:w, :s, :e, :n, 4326))';
            $params[':w'] = $nums[0];
            $params[':s'] = $nums[1];
            $params[':e'] = $nums[2];
            $params[':n'] = $nums[3];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $from     = 'app.survey_control_points cp '
                  . 'LEFT JOIN ref.crs_registry c ON c.id = cp.native_crs_id';

        $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$from} {$whereSql}");
        $cntStmt->execute($params);
        $total = (int) $cntStmt->fetchColumn();

        $sql = 'SELECT ' . $this->pointSelect() . " FROM {$from} {$whereSql} ORDER BY cp.{$sortCol} {$dir} LIMIT :lim OFFSET :off";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(fn ($r) => $this->formatPoint($r), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return Envelope::success($response, [
            'data'   => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
            'sort'   => $sortCol,
            'dir'    => $dir,
        ]);
    }

    // ── TASK-075: nearest ─────────────────────────────────────────────────

    /**
     * GET /control-points/nearest?lat=&lon=&limit=&type=
     *
     * Nearest-N control points to a WGS 84 position, ordered by distance using
     * the GIST index (`cp.geom <-> :pt`, verified by EXPLAIN). Each row carries
     * a geodesic `distance_m` (computed on geography). Results respect record
     * data scopes exactly like the RLS policies: a point is returned only when
     * `app.fn_user_can_see` grants the caller access to its barangay, so the
     * map picker never leaks out-of-scope points.
     */
    public function nearest(Request $request, Response $response): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $q   = $request->getQueryParams();
        $raw = [$q['lat'] ?? null, $q['lon'] ?? null];
        foreach ($raw as $i => $value) {
            if (!is_numeric($value)) {
                throw new ApiError('VALIDATION_FAILED', $i === 0 ? 'lat must be a numeric coordinate' : 'lon must be a numeric coordinate', 400);
            }
            $raw[$i] = (float) $value;
        }
        [$lat, $lon] = $raw;
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new ApiError('VALIDATION_FAILED', 'lat/lon out of range', 400);
        }

        $limit = min(max((int) ($q['limit'] ?? 10), 1), 100);

        $where  = ['cp.deleted_at IS NULL'];
        $params = [];

        $type = $q['type'] ?? null;
        if ($type !== null && $type !== '') {
            if (!in_array($type, self::POINT_TYPES, true)) {
                throw new ApiError('VALIDATION_FAILED', 'Invalid type value', 400);
            }
            $where[]   = 'cp.point_type = :type';
            $params[':type'] = $type;
        }

        // Same predicate the parcels RLS policies use; resolves GLOBAL first,
        // then geographic / organisation data scopes. Positioned eagerly so a
        // point outside the caller's scope can never reach the picker.
        $where[] = 'app.fn_user_can_see(NULLIF(current_setting(\'app.user_id\', true), \'\')::bigint, cp.psgc_barangay, NULL)';

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $from     = 'app.survey_control_points cp '
                  . 'LEFT JOIN ref.crs_registry c ON c.id = cp.native_crs_id';

        // Named placeholders appear exactly once (native prepares reject
        // repetition), hence the lon1/lat1 (distance) vs lon2/lat2 (order).
        $refPoint = 'ST_SetSRID(ST_MakePoint(:lon1, :lat1), 4326)';

        $sql = 'SELECT ' . $this->pointSelect() . ', '
             . 'ROUND((ST_Distance(cp.geom::geography, ' . $refPoint . '::geography))::numeric, 2) AS distance_m '
             . "FROM {$from} {$whereSql} "
             . 'ORDER BY cp.geom <-> ST_SetSRID(ST_MakePoint(:lon2, :lat2), 4326) '
             . 'LIMIT :lim';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lon1', $lon);
        $stmt->bindValue(':lat1', $lat);
        $stmt->bindValue(':lon2', $lon);
        $stmt->bindValue(':lat2', $lat);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row                = $this->formatPoint($r);
            $row['distance_m']  = $r['distance_m'] !== null ? (float) $r['distance_m'] : null;
            $rows[] = $row;
        }

        return Envelope::success($response, [
            'data'  => $rows,
            'total' => count($rows),
            'limit' => $limit,
        ]);
    }

    /**
     * GET /control-points/{id}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $this->resolveUser($request);
        $id = $this->parseId($args);
        return Envelope::success($response, $this->getCurrent($id));
    }

    /**
     * POST /control-points
     *
     * Body: point_name, point_type, native_crs ("EPSG:3123"), coordinate_origin
     * (PROJECTED|GEOGRAPHIC), the original coordinate pair, optional
     * monument_type / elevation / source / survey_reference / accuracy_class /
     * accuracy_value_m / description / psgc_barangay. Status always starts
     * UNVERIFIED; the other pair is derived and labelled in the response.
     */
    public function create(Request $request, Response $response): Response
    {
        $uid  = $this->resolveUser($request);
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
        }

        $pointName = trim((string) ($body['point_name'] ?? ''));
        if ($pointName === '') {
            throw new ApiError('VALIDATION_FAILED', 'point_name is required', 400);
        }
        if (strlen($pointName) > 80) {
            throw new ApiError('VALIDATION_FAILED', 'point_name must be at most 80 characters', 400);
        }

        $pointType = trim((string) ($body['point_type'] ?? ''));
        if ($pointType === '') {
            throw new ApiError('VALIDATION_FAILED', 'point_type is required', 400);
        }
        if (!in_array($pointType, self::POINT_TYPES, true)) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid point_type value', 400);
        }

        if (array_key_exists('status', $body)) {
            throw new ApiError(
                'VALIDATION_FAILED',
                'status is managed by the verify workflow and cannot be set on create',
                400
            );
        }

        if (!array_key_exists('native_crs', $body) || $body['native_crs'] === null || $body['native_crs'] === '') {
            throw new ApiError('VALIDATION_FAILED', 'native_crs is required', 400);
        }
        $crs = $this->resolveCrs($body['native_crs']);
        $this->assertProjectedCrs($crs);

        $plan = CoordinateDerivation::plan($body);
        if (!$plan['valid']) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid coordinate input', 400, ['fields' => $plan['errors']]);
        }

        $coords = $this->deriveCoordinates($crs, $plan);

        $monumentType = $this->optionalString($body, 'monument_type', 60);
        $source       = $this->optionalString($body, 'source', 160);
        $surveyRef    = $this->optionalString($body, 'survey_reference', 160);
        $accuracyCls  = $this->optionalString($body, 'accuracy_class', 40);
        $description  = $this->optionalText($body, 'description');
        $remarks      = null;
        $psgcBarangay = $this->validatePsgc($body['psgc_barangay'] ?? null);

        $accuracyValue = $body['accuracy_value_m'] ?? null;
        if ($accuracyValue !== null && $accuracyValue !== '') {
            if (!is_numeric($accuracyValue) || (float) $accuracyValue < 0 || !is_finite((float) $accuracyValue)) {
                throw new ApiError('VALIDATION_FAILED', 'accuracy_value_m must be a non-negative number', 400);
            }
            $accuracyValue = round((float) $accuracyValue, 4);
        } else {
            $accuracyValue = null;
        }

        // AuthenticateMiddleware owns the outer transaction; audit rows are
        // atomic with the mutation and everything rolls back on error.
        $this->setUserInSession($uid);

        $sql = 'INSERT INTO app.survey_control_points '
            . '(point_name, point_type, monument_type, easting, northing, elevation, native_crs_id, '
            . ' latitude, longitude, coordinate_origin, datum, zone, source, survey_reference, '
            . ' accuracy_class, accuracy_value_m, description, status, psgc_barangay, created_by, version, geom) '
            . 'VALUES (:name, :type, :monument, :easting, :northing, :elevation, :crs_id, '
            . ' :latitude, :longitude, :origin, :datum, :zone, :source, :survey_ref, '
            . ' :accuracy_cls, :accuracy_val, :description, \'UNVERIFIED\', :psgc, :uid, 1, '
            . ' ST_SetSRID(ST_MakePoint(:geo_lon, :geo_lat), 4326)) '
            . 'RETURNING id';
        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute([
                ':name'        => $pointName,
                ':type'        => $pointType,
                ':monument'    => $monumentType,
                ':easting'     => $coords['easting'],
                ':northing'    => $coords['northing'],
                ':elevation'   => $plan['elevation'],
                ':crs_id'      => (int) $crs['id'],
                ':latitude'    => $coords['latitude'],
                ':longitude'   => $coords['longitude'],
                ':origin'      => $plan['origin'],
                ':datum'       => $crs['datum'],
                ':zone'        => $crs['zone'],
                ':source'      => $source,
                ':survey_ref'  => $surveyRef,
                ':accuracy_cls'=> $accuracyCls,
                ':accuracy_val'=> $accuracyValue,
                ':description' => $description,
                ':psgc'        => $psgcBarangay,
                ':uid'         => $uid,
                ':geo_lon'     => $coords['longitude'],
                ':geo_lat'     => $coords['latitude'],
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ApiError('CONFLICT', 'A control point with this point_name already exists for this CRS.', 409);
            }
            if ($e->getCode() === '23503') {
                throw new ApiError('VALIDATION_FAILED', 'Foreign key violation (unknown PSGC code or CRS).', 400);
            }
            throw $e;
        }
        $id = (int) $stmt->fetchColumn();

        $row = $this->getCurrent($id);
        $this->audit->writeFromSession(
            'INSERT',
            'app.survey_control_points',
            (string) $id,
            null,
            $this->stripForAudit($row),
            null,
            'Control point created via API'
        );

        return Envelope::success($response, $row, 201);
    }

    /**
     * PUT /control-points/{id}
     *
     * Partial update of attribute and/or coordinate fields. Coordinate
     * changes must supply a complete original pair (for the possibly changed
     * coordinate_origin / native_crs); the other pair is re-derived. Requires
     * If-Match against the current version.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $uid  = $this->resolveUser($request);
        $id   = $this->parseId($args);
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
        }

        $allowed = [
            'point_name', 'point_type', 'monument_type', 'native_crs', 'coordinate_origin',
            'easting', 'northing', 'latitude', 'longitude', 'elevation',
            'source', 'survey_reference', 'accuracy_class', 'accuracy_value_m',
            'description', 'psgc_barangay', 'change_reason',
        ];
        $unknown = array_diff_key($body, array_fill_keys($allowed, true));
        if (!empty($unknown)) {
            throw new ApiError('VALIDATION_FAILED', 'Unexpected fields in update payload', 400, [
                'fields' => array_keys($unknown),
            ]);
        }

        $coordinateKeys = ['easting', 'northing', 'latitude', 'longitude', 'coordinate_origin', 'native_crs'];
        $touchesCoordinates = (bool) array_intersect($coordinateKeys, array_keys($body));

        // Middleware owns the outer transaction; audit is atomic, rollback on error.
        $this->setUserInSession($uid);

        $lock = $this->pdo->prepare(
                'SELECT * FROM app.survey_control_points WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
            );
            $lock->execute([':id' => $id]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if ($current === false) {
                throw new ApiError('NOT_FOUND', 'Control point not found', 404);
            }
            $currentVersion = (int) $current['version'];

            $ifMatch = $request->getHeaderLine('If-Match');
            if ($ifMatch === '') {
                throw new ApiError('PRECONDITION_REQUIRED', 'An If-Match header with the current version is required for updates.', 428);
            }
            if ((int) $ifMatch !== $currentVersion) {
                throw new ApiError('VERSION_CONFLICT', 'This control point was modified by another user.', 409, [
                    'current_version' => $currentVersion,
                ]);
            }

            $pre = $this->formatPoint($this->hydrateCrs($current));

            $sets   = [];
            $params = [':id' => $id, ':uid' => $uid];

            if (array_key_exists('point_name', $body)) {
                $pointName = trim((string) $body['point_name']);
                if ($pointName === '') {
                    throw new ApiError('VALIDATION_FAILED', 'point_name must not be empty', 400);
                }
                if (strlen($pointName) > 80) {
                    throw new ApiError('VALIDATION_FAILED', 'point_name must be at most 80 characters', 400);
                }
                $sets[]            = 'point_name = :point_name';
                $params[':point_name'] = $pointName;
            }

            if (array_key_exists('point_type', $body)) {
                $pointType = trim((string) $body['point_type']);
                if (!in_array($pointType, self::POINT_TYPES, true)) {
                    throw new ApiError('VALIDATION_FAILED', 'Invalid point_type value', 400);
                }
                $sets[]            = 'point_type = :point_type';
                $params[':point_type'] = $pointType;
            }

            foreach (['monument_type', 'source', 'survey_reference', 'accuracy_class', 'description'] as $text) {
                if (array_key_exists($text, $body)) {
                    $max = in_array($text, ['monument_type'], true) ? 60 : 160;
                    if ($text === 'accuracy_class') {
                        $max = 40;
                    }
                    $value = $body[$text] === null ? null : mb_substr(trim((string) $body[$text]), 0, $max);
                    $sets[] = "{$text} = :{$text}";
                    $params[":{$text}"] = $value === '' ? null : $value;
                }
            }

            if (array_key_exists('accuracy_value_m', $body)) {
                $v = $body['accuracy_value_m'];
                if ($v !== null && $v !== '') {
                    if (!is_numeric($v) || (float) $v < 0 || !is_finite((float) $v)) {
                        throw new ApiError('VALIDATION_FAILED', 'accuracy_value_m must be a non-negative number', 400);
                    }
                    $v = round((float) $v, 4);
                } else {
                    $v = null;
                }
                $sets[]              = 'accuracy_value_m = :accuracy_value_m';
                $params[':accuracy_value_m'] = $v;
            }

            if (array_key_exists('elevation', $body) && !$touchesCoordinates) {
                // elevation alone (no coordinate pair) still goes through the plan.
                $plan = CoordinateDerivation::plan($body + [
                    'coordinate_origin' => (string) $current['coordinate_origin'],
                    'easting'           => $current['easting'],
                    'northing'          => $current['northing'],
                    'latitude'          => $current['latitude'],
                    'longitude'         => $current['longitude'],
                ]);
                if (!$plan['valid']) {
                    throw new ApiError('VALIDATION_FAILED', 'Invalid coordinate input', 400, ['fields' => $plan['errors']]);
                }
                $sets[]              = 'elevation = :elevation';
                $params[':elevation'] = $plan['elevation'];
            }

            if (array_key_exists('psgc_barangay', $body)) {
                $sets[]              = 'psgc_barangay = :psgc_barangay';
                $params[':psgc_barangay'] = $this->validatePsgc($body['psgc_barangay']);
            }

            $coordinatesChanged = false;
            if ($touchesCoordinates) {
                $input = $body;
                $input['coordinate_origin'] ??= (string) $current['coordinate_origin'];

                $plan = CoordinateDerivation::plan($input);
                if (!$plan['valid']) {
                    throw new ApiError('VALIDATION_FAILED', 'Invalid coordinate input', 400, ['fields' => $plan['errors']]);
                }

                if (array_key_exists('native_crs', $body)) {
                    if ($body['native_crs'] === null || $body['native_crs'] === '') {
                        throw new ApiError('VALIDATION_FAILED', 'native_crs must not be null when setting coordinates', 400);
                    }
                    $crs = $this->resolveCrs($body['native_crs']);
                } elseif ($current['native_crs_id'] !== null) {
                    $crs = $this->resolveCrs((int) $current['native_crs_id']);
                } else {
                    throw new ApiError('VALIDATION_FAILED', 'native_crs is required to set coordinates', 400);
                }
                $this->assertProjectedCrs($crs);

                $coords = $this->deriveCoordinates($crs, $plan);

                $oldCrsId = $current['native_crs_id'] !== null ? (int) $current['native_crs_id'] : null;
                $coordinatesChanged = $oldCrsId !== (int) $crs['id']
                    || (string) $current['coordinate_origin'] !== $plan['origin']
                    || abs((float) $current['easting'] - $coords['easting']) > 0.00005
                    || abs((float) $current['northing'] - $coords['northing']) > 0.00005
                    || abs((float) $current['latitude'] - $coords['latitude']) > 0.0000000005
                    || abs((float) $current['longitude'] - $coords['longitude']) > 0.0000000005;

                $sets[] = 'easting = :easting';
                $sets[] = 'northing = :northing';
                $sets[] = 'latitude = :latitude';
                $sets[] = 'longitude = :longitude';
                $sets[] = 'coordinate_origin = :origin';
                $sets[] = 'native_crs_id = :crs_id';
                $sets[] = 'datum = :datum';
                $sets[] = 'zone = :zone';
                $sets[] = 'geom = ST_SetSRID(ST_MakePoint(:geo_lon, :geo_lat), 4326)';
                $params[':easting']   = $coords['easting'];
                $params[':northing']  = $coords['northing'];
                $params[':latitude']  = $coords['latitude'];
                $params[':longitude'] = $coords['longitude'];
                $params[':origin']    = $plan['origin'];
                $params[':crs_id']    = (int) $crs['id'];
                $params[':datum']     = $crs['datum'];
                $params[':zone']      = $crs['zone'];
                $params[':geo_lon']   = $coords['longitude'];
                $params[':geo_lat']   = $coords['latitude'];

                if (array_key_exists('elevation', $body)) {
                    $sets[]              = 'elevation = :elevation';
                    $params[':elevation'] = $plan['elevation'];
                }
            }

            if ($sets === []) {
                return Envelope::success($response, $pre);
            }

            $sets[] = 'version = version + 1';
            $sets[] = 'updated_by = :uid';
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';

            $sql = 'UPDATE app.survey_control_points SET ' . implode(', ', $sets)
                 . ' WHERE id = :id AND deleted_at IS NULL RETURNING id';
            try {
                $this->pdo->prepare($sql)->execute($params);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23505') {
                    throw new ApiError('CONFLICT', 'A control point with this point_name already exists for this CRS.', 409);
                }
                if ($e->getCode() === '23503') {
                    throw new ApiError('VALIDATION_FAILED', 'Foreign key violation (unknown PSGC code or CRS).', 400);
                }
                throw $e;
            }

            $updated = $this->getCurrent($id);

            // TASK-074 / FR-081: a coordinate change puts every dependent
            // parcel (one whose computation used this point) up for review.
            // The flag lives on the parcel, which is exactly the surface the
            // parcel picker and review queues filter on. Computations are left
            // byte-identical: each already stores its own input_snapshot.
            $impact = [];
            if ($coordinatesChanged) {
                $impact = $this->findDependentParcels($id);
                if ($impact !== []) {
                    $this->flagParcelsForReview($impact, $uid);
                    // Re-read so the returned rows carry the fresh flag.
                    $impact = $this->findDependentParcels($id);
                }
            }
            $updated['impact'] = $impact;

            $reason = trim((string) ($body['change_reason'] ?? ''));
            $this->audit->writeFromSession(
                'UPDATE',
                'app.survey_control_points',
                (string) $id,
                $this->stripForAudit($pre),
                $this->stripForAudit($updated),
                null,
                $reason === '' ? null : $reason
            );

            return Envelope::success($response, $updated);
    }

    /**
     * POST /control-points/{id}/verify (control_point.verify)
     *
     * TASK-074 / FR-078. Records the verifier and the moment of verification
     * and moves the point to VERIFIED. Re-verification is idempotent in the
     * sense that it may be repeated; each call stamps verified_by/verified_at
     * and bumps the version (the state transition is an audited change).
     */
    public function verify(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $id  = $this->parseId($args);

        // Middleware owns the outer transaction; audit is atomic, rollback on error.
        $this->setUserInSession($uid);

        $lock = $this->pdo->prepare(
            'SELECT * FROM app.survey_control_points WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
        );
        $lock->execute([':id' => $id]);
        $current = $lock->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            throw new ApiError('NOT_FOUND', 'Control point not found', 404);
        }

        $pre = $this->formatPoint($this->hydrateCrs($current));

        $upd = $this->pdo->prepare(
            "UPDATE app.survey_control_points SET status = 'VERIFIED', verified_by = :uid, "
            . 'verified_at = CURRENT_TIMESTAMP, version = version + 1, updated_by = :uid, '
            . 'updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id AND deleted_at IS NULL RETURNING id'
        );
        $upd->execute([':id' => $id, ':uid' => $uid]);

        $row = $this->getCurrent($id);
        $this->audit->writeFromSession(
            'VERIFY',
            'app.survey_control_points',
            (string) $id,
            $this->stripForAudit($pre),
            $this->stripForAudit($row),
            null,
            'Control point verified'
        );

        return Envelope::success($response, $row);
    }

    /**
     * GET /control-points/{id}/dependents (control_point.view)
     *
     * TASK-074 / FR-081. Parcels whose computations used this control point:
     * a parcel depends on the point when the tie points of its current
     * technical description (or of its current computation's technical
     * description) reference it. These are the parcels flagged for review when
     * the point's coordinates are edited. Soft-deleted parcels are excluded.
     */
    public function dependents(Request $request, Response $response, array $args): Response
    {
        $this->resolveUser($request);
        $id = $this->parseId($args);

        $chk = $this->pdo->prepare(
            'SELECT 1 FROM app.survey_control_points WHERE id = :id AND deleted_at IS NULL'
        );
        $chk->execute([':id' => $id]);
        if ($chk->fetchColumn() === false) {
            throw new ApiError('NOT_FOUND', 'Control point not found', 404);
        }

        $parcels = $this->findDependentParcels($id);

        return Envelope::success($response, [
            'parcels' => $parcels,
            'total'   => count($parcels),
        ]);
    }

    /**
     * DELETE /control-points/{id}?reason=... | body { reason }
     * Soft delete only: sets deleted_at, bumps version. Reason is mandatory.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $id  = $this->parseId($args);

        $q    = $request->getQueryParams();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $reason = trim((string) ($q['reason'] ?? ($body['reason'] ?? ($body['change_reason'] ?? ''))));
        if ($reason === '') {
            throw new ApiError('VALIDATION_FAILED', 'A delete reason is required', 400);
        }

        // Middleware owns the outer transaction; audit is atomic, rollback on error.
        $this->setUserInSession($uid);

        $lock = $this->pdo->prepare(
            'SELECT * FROM app.survey_control_points WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
        );
        $lock->execute([':id' => $id]);
        $current = $lock->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            throw new ApiError('NOT_FOUND', 'Control point not found', 404);
        }
        $currentVersion = (int) $current['version'];

        $ifMatch = $request->getHeaderLine('If-Match');
        if ($ifMatch !== '' && (int) $ifMatch !== $currentVersion) {
            throw new ApiError('VERSION_CONFLICT', 'This control point was modified by another user.', 409, [
                'current_version' => $currentVersion,
            ]);
        }

        $pre = $this->formatPoint($this->hydrateCrs($current));

        $upd = $this->pdo->prepare(
            'UPDATE app.survey_control_points SET deleted_at = CURRENT_TIMESTAMP, version = version + 1, updated_by = :uid '
            . 'WHERE id = :id AND deleted_at IS NULL RETURNING id'
        );
        $upd->execute([':id' => $id, ':uid' => $uid]);
        if ($upd->fetchColumn() === false) {
            throw new ApiError('NOT_FOUND', 'Control point not found', 404);
        }

        $this->audit->writeFromSession(
            'DELETE',
            'app.survey_control_points',
            (string) $id,
            $this->stripForAudit($pre),
            null,
            null,
            $reason
        );

        return Envelope::success($response, ['id' => $id, 'deleted' => true]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

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
        $this->pdo->exec('SET LOCAL app.current_user_id = ' . (int) $uid);
    }

    private function parseId(array $args): int
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid control point id', 400);
        }
        return $id;
    }

    /**
     * Resolve a CRS reference — accepts an integer id, a numeric SRID, or an
     * "EPSG:3123"-style code — against ref.crs_registry.
     */
    private function resolveCrs(mixed $raw): array
    {
        if (is_int($raw)) {
            $stmt = $this->pdo->prepare('SELECT ' . $this->crsSelect() . ' FROM ref.crs_registry WHERE id = :v');
            $stmt->execute([':v' => $raw]);
        } elseif (is_string($raw)) {
            $value = trim($raw);
            if ($value === '') {
                throw new ApiError('VALIDATION_FAILED', 'native_crs is required', 400);
            }
            if (preg_match('/^(?:EPSG:)?(\d+)$/i', $value, $m)) {
                $stmt = $this->pdo->prepare('SELECT ' . $this->crsSelect() . ' FROM ref.crs_registry WHERE srid = :v');
                $stmt->execute([':v' => (int) $m[1]]);
            } else {
                $stmt = $this->pdo->prepare('SELECT ' . $this->crsSelect() . ' FROM ref.crs_registry WHERE upper(code) = upper(:c)');
                $stmt->execute([':c' => $value]);
            }
        } else {
            throw new ApiError('VALIDATION_FAILED', 'native_crs must be an EPSG code such as "EPSG:3123"', 400);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('VALIDATION_FAILED', 'native_crs not found in the CRS registry', 400);
        }
        return $this->castCrs($row);
    }

    private function crsSelect(): string
    {
        return 'id, srid, code, name, datum, zone, is_projected, '
             . 'area_south, area_west, area_north, area_east';
    }

    private function castCrs(array $row): array
    {
        $isProjected = $row['is_projected'];
        if (is_string($isProjected)) {
            $isProjected = in_array(strtolower($isProjected), ['1', 't', 'true', 'y', 'yes', 'on'], true);
        }
        $row['id']           = (int) $row['id'];
        $row['srid']         = (int) $row['srid'];
        $row['is_projected'] = (bool) $isProjected;
        foreach (['area_south', 'area_west', 'area_north', 'area_east'] as $bound) {
            $row[$bound] = $row[$bound] === null ? null : (float) $row[$bound];
        }
        return $row;
    }

    private function assertProjectedCrs(array $crs): void
    {
        if (!$crs['is_projected']) {
            throw new ApiError(
                'VALIDATION_FAILED',
                'native_crs must be a projected coordinate reference system (easting/northing are stored in a projected CRS)',
                400,
                ['fields' => ['native_crs' => 'must be a projected coordinate reference system']]
            );
        }
    }

    /**
     * Execute the derivation plan against PostGIS and enforce the CRS
     * area-of-use check (TASK-073 AC).
     *
     * The original pair is returned exactly as supplied (rounded to the column
     * scale); the derived pair comes from ST_Transform. The point is rejected
     * with 400 when the transform fails, yields a non-finite / out-of-range
     * geographic position, or falls outside the CRS area-of-use bounding box
     * recorded in ref.crs_registry (NULL bounds skip only the bounding-box
     * comparison, never the finite/range checks).
     *
     * @return array{easting: float, northing: float, latitude: float, longitude: float}
     */
    private function deriveCoordinates(array $crs, array $plan): array
    {
        $nativeSrid = (int) $crs['srid'];

        if ($plan['origin'] === CoordinateDerivation::ORIGIN_PROJECTED) {
            $inSrid   = $nativeSrid;
            $px       = (float) $plan['original']['easting'];
            $py       = (float) $plan['original']['northing'];
            $easting  = round($px, 4);
            $northing = round($py, 4);
        } else {
            $inSrid   = 4326;
            $px       = (float) $plan['original']['longitude'];
            $py       = (float) $plan['original']['latitude'];
            $latitude  = round($py, 9);
            $longitude = round($px, 9);
        }

        // Positional placeholders: PDO native prepares forbid repeating named
        // placeholders, so each coordinate is bound by position even though it
        // feeds both the geographic and the projected transform.
        try {
            $stmt = $this->pdo->prepare(
                'SELECT '
                . 'ST_Y(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), ?::int), 4326)) AS latitude, '
                . 'ST_X(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), ?::int), 4326)) AS longitude, '
                . 'ST_Y(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), ?::int), ?::int)) AS northing, '
                . 'ST_X(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), ?::int), ?::int)) AS easting'
            );
            $args = [
                $px, $py, $inSrid,
                $px, $py, $inSrid,
                $px, $py, $inSrid, $nativeSrid,
                $px, $py, $inSrid, $nativeSrid,
            ];
            $stmt->execute($args);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            throw $this->areaOfUseError($crs);
        }

        if ($row === false) {
            throw $this->areaOfUseError($crs);
        }

        $lat = $row['latitude']  === null ? NAN : (float) $row['latitude'];
        $lon = $row['longitude'] === null ? NAN : (float) $row['longitude'];
        $e   = $row['easting']   === null ? NAN : (float) $row['easting'];
        $n   = $row['northing']  === null ? NAN : (float) $row['northing'];

        // Area-of-use: a geographic position the projection cannot produce, or
        // one outside the CRS bounding box, is outside the CRS area of use.
        if (!is_finite($lat) || !is_finite($lon) || $lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            throw $this->areaOfUseError($crs);
        }
        if ($crs['area_south'] !== null && $crs['area_west'] !== null
            && $crs['area_north'] !== null && $crs['area_east'] !== null
            && ($lat < $crs['area_south'] || $lat > $crs['area_north']
                || $lon < $crs['area_west'] || $lon > $crs['area_east'])) {
            throw $this->areaOfUseError($crs);
        }
        if (!is_finite($e) || !is_finite($n)) {
            throw $this->areaOfUseError($crs);
        }

        if ($plan['origin'] === CoordinateDerivation::ORIGIN_PROJECTED) {
            return [
                'easting'   => $easting,
                'northing'  => $northing,
                'latitude'  => round($lat, 9),
                'longitude' => round($lon, 9),
            ];
        }

        return [
            'easting'   => round($e, 4),
            'northing'  => round($n, 4),
            'latitude'  => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function areaOfUseError(array $crs): ApiError
    {
        return new ApiError(
            'VALIDATION_FAILED',
            sprintf('Point lies outside the area of use of %s (%s)', $crs['code'], $crs['name']),
            400,
            ['fields' => ['easting' => 'outside CRS area of use', 'northing' => 'outside CRS area of use']]
        );
    }

    private function pointSelect(): string
    {
        return 'cp.id, cp.point_name, cp.point_type, cp.monument_type, cp.easting, cp.northing, '
             . 'cp.elevation, cp.native_crs_id, cp.latitude, cp.longitude, cp.coordinate_origin, '
             . 'cp.datum, cp.zone, cp.source, cp.survey_reference, cp.accuracy_class, cp.accuracy_value_m, '
             . 'cp.description, cp.status, cp.psgc_barangay, cp.verified_by, cp.verified_at, '
             . 'cp.version, cp.created_by, cp.created_at, cp.updated_by, cp.updated_at, '
             . 'c.code AS native_crs_code, c.name AS native_crs_name, c.srid AS native_crs_srid, '
             . 'ST_AsGeoJSON(cp.geom)::json AS geom';
    }

    private function pointFrom(): string
    {
        return 'app.survey_control_points cp LEFT JOIN ref.crs_registry c ON c.id = cp.native_crs_id';
    }

    private function getCurrent(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->pointSelect() . ' FROM ' . $this->pointFrom() . ' WHERE cp.id = :id AND cp.deleted_at IS NULL'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Control point not found', 404);
        }
        return $this->formatPoint($row);
    }

    /**
     * Merge the CRS columns into a raw cp.* row (from FOR UPDATE locks, which
     * cannot join).
     */
    private function hydrateCrs(array $row): array
    {
        if ($row['native_crs_id'] === null) {
            $row['native_crs_code'] = null;
            $row['native_crs_name'] = null;
            $row['native_crs_srid'] = null;
            return $row;
        }
        $stmt = $this->pdo->prepare('SELECT code, name, srid FROM ref.crs_registry WHERE id = :id');
        $stmt->execute([':id' => (int) $row['native_crs_id']]);
        $crs = $stmt->fetch(PDO::FETCH_ASSOC);
        $row['native_crs_code'] = $crs['code'] ?? null;
        $row['native_crs_name'] = $crs['name'] ?? null;
        $row['native_crs_srid'] = $crs['srid'] !== null ? (int) $crs['srid'] : null;
        return $row;
    }

    private function formatPoint(array $r): array
    {
        $geom = $r['geom'] ?? null;
        if (is_string($geom)) {
            $decoded = json_decode($geom, true);
            $geom = (is_array($decoded) && $decoded !== []) ? $decoded : null;
        }

        // The original pair is the one coordinate_origin names; the other pair
        // was derived from it (TASK-073 AC: label which coordinate was original).
        $origin = (string) $r['coordinate_origin'];
        $derived = $origin === CoordinateDerivation::ORIGIN_PROJECTED
            ? ['easting' => false, 'northing' => false, 'latitude' => true, 'longitude' => true]
            : ['easting' => true, 'northing' => true, 'latitude' => false, 'longitude' => false];

        return [
            'id'                 => (int) $r['id'],
            'point_name'         => $r['point_name'],
            'point_type'         => $r['point_type'],
            'monument_type'      => $r['monument_type'],
            'easting'            => $r['easting'] !== null ? (float) $r['easting'] : null,
            'northing'           => $r['northing'] !== null ? (float) $r['northing'] : null,
            'elevation'          => $r['elevation'] !== null ? (float) $r['elevation'] : null,
            'latitude'           => $r['latitude'] !== null ? (float) $r['latitude'] : null,
            'longitude'          => $r['longitude'] !== null ? (float) $r['longitude'] : null,
            'coordinate_origin'  => $origin,
            'derived'            => $derived,
            'native_crs_id'      => $r['native_crs_id'] !== null ? (int) $r['native_crs_id'] : null,
            'native_crs'         => $r['native_crs_code'] ?? null,
            'native_crs_name'    => $r['native_crs_name'] ?? null,
            'native_crs_srid'    => $r['native_crs_srid'] !== null ? (int) $r['native_crs_srid'] : null,
            'datum'              => $r['datum'],
            'zone'               => $r['zone'],
            'source'             => $r['source'],
            'survey_reference'   => $r['survey_reference'],
            'accuracy_class'     => $r['accuracy_class'],
            'accuracy_value_m'   => $r['accuracy_value_m'] !== null ? (float) $r['accuracy_value_m'] : null,
            'description'        => $r['description'],
            'status'             => $r['status'],
            'psgc_barangay'      => $r['psgc_barangay'],
            'verified_by'        => $r['verified_by'] !== null ? (int) $r['verified_by'] : null,
            'verified_at'        => $r['verified_at'],
            'version'            => (int) $r['version'],
            'created_by'         => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_at'         => $r['created_at'],
            'updated_by'         => $r['updated_by'] !== null ? (int) $r['updated_by'] : null,
            'updated_at'         => $r['updated_at'],
            'geom'               => $geom,
        ];
    }

    private function stripForAudit(array $point): array
    {
        unset($point['geom'], $point['id'], $point['created_by'], $point['created_at'], $point['updated_by'], $point['updated_at']);
        return $point;
    }

    /**
     * Parcels whose computations used this control point (TASK-074/FR-081).
     * A parcel depends on the point when a current technical description — or
     * the technical description behind the parcel's current computation —
     * carries a tie point referencing it. Soft-deleted parcels are excluded.
     */
    private function findDependentParcels(int $cpId): array
    {
        $sql = 'SELECT DISTINCT p.id, p.parcel_code, p.lot_number, p.block_number, '
             . 'p.status, p.verification_status, p.computed_area_sqm, '
             . 'p.control_review_pending, p.control_review_since '
             . 'FROM app.parcels p '
             . 'WHERE p.deleted_at IS NULL '
             . 'AND EXISTS ('
             . 'SELECT 1 FROM app.tie_points tp '
             . 'JOIN app.technical_descriptions td ON td.id = tp.technical_description_id '
             . 'WHERE tp.control_point_id = :cp '
             . 'AND ('
             . '(td.parcel_id = p.id AND td.is_current = true) '
             . 'OR EXISTS (SELECT 1 FROM app.parcel_computations pc '
             . 'WHERE pc.parcel_id = p.id AND pc.is_current = true '
             . 'AND pc.technical_description_id = td.id)'
             . ')'
             . ') '
             . 'ORDER BY p.parcel_code';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':cp' => $cpId]);
        return array_map(fn (array $r) => $this->formatDependentParcel($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Mark parcels as pending review after a control point coordinate change.
     * Deliberately does NOT bump the parcel version: recomputing a parcel later
     * must not fail an in-flight optimistically-locked write that only touched
     * the review flag.
     */
    private function flagParcelsForReview(array $parcels, int $uid): void
    {
        $ids = array_column($parcels, 'id');
        $ph  = implode(', ', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE app.parcels SET control_review_pending = true, '
             . 'control_review_since = COALESCE(control_review_since, CURRENT_TIMESTAMP), '
             . 'updated_at = CURRENT_TIMESTAMP '
             . "WHERE id IN ($ph)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);
    }

    private function formatDependentParcel(array $r): array
    {
        $pending = $r['control_review_pending'];
        if (is_string($pending)) {
            $pending = in_array(strtolower($pending), ['1', 't', 'true', 'y', 'yes', 'on'], true);
        }
        return [
            'id'                      => (string) $r['id'],
            'parcel_code'             => $r['parcel_code'],
            'lot_number'              => $r['lot_number'],
            'block_number'            => $r['block_number'],
            'status'                  => $r['status'],
            'verification_status'     => $r['verification_status'],
            'computed_area_sqm'       => $r['computed_area_sqm'] !== null ? (float) $r['computed_area_sqm'] : null,
            'control_review_pending'  => (bool) $pending,
            'control_review_since'    => $r['control_review_since'],
        ];
    }

    private function optionalString(array $body, string $key, int $max): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
            return null;
        }
        $value = trim((string) $body[$key]);
        if (mb_strlen($value) > $max) {
            throw new ApiError('VALIDATION_FAILED', "{$key} must be at most {$max} characters", 400);
        }
        return $value;
    }

    private function optionalText(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
            return null;
        }
        return trim((string) $body[$key]);
    }

    private function validatePsgc(mixed $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $code = trim((string) $code);
        if (!preg_match('/^\d{10,12}$/', $code)) {
            throw new ApiError('VALIDATION_FAILED', 'psgc_barangay must be a 10-12 digit PSGC code', 400);
        }
        return $code;
    }
}
