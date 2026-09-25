<?php
declare(strict_types=1);

namespace App\Survey\Http;

use App\Audit\AuditWriter;
use App\Core\Db\DbTransaction;
use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Survey Plans API (Phase 9 — TASK-077).
 *
 * Manages survey plans (Psd, Psu, Pcs, etc.), metadata, and their linkage to
 * parcels. Enforces uniqueness on plan_number, optimistic concurrency on updates
 * via If-Match, and audits all mutations.
 */
class SurveyPlanController
{
    public const PLAN_TYPES = [
        'Psd', 'Psu', 'Pcs', 'Csd', 'Ccs', 'Bsd', 'Vs', 'Fls', 'Rs', 'As', 'Swo', 'Msi', 'OTHER',
    ];

    public function __construct(private readonly PDO $pdo, private readonly AuditWriter $audit)
    {
    }

    /**
     * GET /survey-plans?limit=&offset=&sort=&dir=&q=&plan_type=&psgc_barangay=
     */
    public function list(Request $request, Response $response): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $q      = $request->getQueryParams();
        $limit  = min(max((int) ($q['limit'] ?? 50), 1), 1000);
        $offset = max((int) ($q['offset'] ?? 0), 0);
        $sort   = $q['sort'] ?? 'plan_number';
        $dir    = strtoupper($q['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $allowedSort = ['plan_number', 'plan_type', 'survey_date', 'approved_date', 'created_at', 'updated_at'];
        $sortCol     = in_array($sort, $allowedSort, true) ? $sort : 'plan_number';

        $where  = ['sp.deleted_at IS NULL'];
        $params = [];

        $planType = $q['plan_type'] ?? null;
        if ($planType !== null && $planType !== '') {
            if (!in_array($planType, self::PLAN_TYPES, true)) {
                throw new ApiError('VALIDATION_FAILED', 'Invalid plan_type value', 400);
            }
            $where[]   = 'sp.plan_type = :plan_type';
            $params[':plan_type'] = $planType;
        }

        $psgc = $q['psgc_barangay'] ?? null;
        if ($psgc !== null && $psgc !== '') {
            $where[]   = 'sp.psgc_barangay = :psgc';
            $params[':psgc'] = $psgc;
        }

        $search = $q['q'] ?? null;
        if ($search !== null && trim($search) !== '') {
            $where[] = '(sp.plan_number ILIKE :q OR sp.surveyor_name ILIKE :q OR sp.approving_agency ILIKE :q OR sp.control_reference ILIKE :q OR sp.remarks ILIKE :q)';
            $params[':q'] = '%' . trim($search) . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $from     = 'app.survey_plans sp LEFT JOIN ref.crs_registry c ON c.id = sp.crs_id';

        $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$from} {$whereSql}");
        $cntStmt->execute($params);
        $total = (int) $cntStmt->fetchColumn();

        $sql = 'SELECT ' . $this->planSelect() . " FROM {$from} {$whereSql} ORDER BY sp.{$sortCol} {$dir} LIMIT :lim OFFSET :off";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(fn ($r) => $this->formatPlan($r), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return Envelope::success($response, [
            'data'   => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
            'sort'   => $sortCol,
            'dir'    => $dir,
        ]);
    }

    /**
     * GET /survey-plans/{id}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $this->resolveUser($request);
        $id = $this->parseId($args);
        $plan = $this->getCurrent($id);

        return Envelope::success($response, $plan);
    }

    /**
     * POST /survey-plans
     */
    public function create(Request $request, Response $response): Response
    {
        $uid  = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $body = $this->readJsonBody($request);

        $planNumber = trim((string) ($body['plan_number'] ?? ''));
        if ($planNumber === '') {
            throw new ApiError('VALIDATION_FAILED', 'plan_number is required', 400);
        }
        if (mb_strlen($planNumber) > 80) {
            throw new ApiError('VALIDATION_FAILED', 'plan_number must be at most 80 characters', 400);
        }

        // Unique check
        $stmt = $this->pdo->prepare('SELECT id FROM app.survey_plans WHERE plan_number = :p AND deleted_at IS NULL');
        $stmt->execute([':p' => $planNumber]);
        if ($stmt->fetch()) {
            throw new ApiError('VALIDATION_FAILED', sprintf('Survey plan number "%s" already exists', $planNumber), 409);
        }

        $planType = trim((string) ($body['plan_type'] ?? ''));
        if ($planType === '' || !in_array($planType, self::PLAN_TYPES, true)) {
            throw new ApiError('VALIDATION_FAILED', 'Valid plan_type is required', 400);
        }

        $crsId = isset($body['crs_id']) && is_numeric($body['crs_id']) ? (int) $body['crs_id'] : null;
        if ($crsId !== null) {
            $chk = $this->pdo->prepare('SELECT id FROM ref.crs_registry WHERE id = :id');
            $chk->execute([':id' => $crsId]);
            if (!$chk->fetch()) {
                throw new ApiError('VALIDATION_FAILED', 'crs_id not found in CRS registry', 400);
            }
        }

        $areaSqm = isset($body['area_sqm']) && is_numeric($body['area_sqm']) ? (float) $body['area_sqm'] : null;
        $lotCount = isset($body['lot_count']) && is_numeric($body['lot_count']) ? (int) $body['lot_count'] : null;
        $surveyDate = $this->optionalDate($body, 'survey_date');
        $approvedDate = $this->optionalDate($body, 'approved_date');
        $approvingAgency = $this->optionalString($body, 'approving_agency', 120);
        $surveyorName = $this->optionalString($body, 'surveyor_name', 160);
        $surveyorLicense = $this->optionalString($body, 'surveyor_license', 60);
        $controlRef = $this->optionalString($body, 'control_reference', 160);
        $psgcBarangay = $this->validatePsgc($body['psgc_barangay'] ?? null);
        $sourceDocId = $this->optionalUuid($body, 'source_document_id');
        $remarks = $this->optionalText($body, 'remarks');

        $tx = DbTransaction::begin($this->pdo);
        try {
            $sql = 'INSERT INTO app.survey_plans (
                plan_number, plan_type, survey_date, approved_date, approving_agency,
                surveyor_name, surveyor_license, control_reference, crs_id,
                area_sqm, lot_count, psgc_barangay, source_document_id, remarks,
                version, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :plan_number, :plan_type, :survey_date, :approved_date, :approving_agency,
                :surveyor_name, :surveyor_license, :control_reference, :crs_id,
                :area_sqm, :lot_count, :psgc_barangay, :source_document_id, :remarks,
                1, :uid, :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            ) RETURNING id';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':plan_number'         => $planNumber,
                ':plan_type'           => $planType,
                ':survey_date'         => $surveyDate,
                ':approved_date'       => $approvedDate,
                ':approving_agency'    => $approvingAgency,
                ':surveyor_name'       => $surveyorName,
                ':surveyor_license'    => $surveyorLicense,
                ':control_reference'   => $controlRef,
                ':crs_id'              => $crsId,
                ':area_sqm'            => $areaSqm,
                ':lot_count'           => $lotCount,
                ':psgc_barangay'       => $psgcBarangay,
                ':source_document_id'  => $sourceDocId,
                ':remarks'             => $remarks,
                ':uid'                 => $uid,
            ]);

            $id = (int) $stmt->fetchColumn();
            $plan = $this->getCurrent($id);

            $this->audit->writeFromSession(
                'create',
                'app.survey_plans',
                (string) $id,
                null,
                array_merge($this->stripForAudit($plan), ['plan_number' => $planNumber]),
                null,
                'Created survey plan ' . $planNumber
            );

            DbTransaction::commit($this->pdo, $tx);
            return Envelope::success($response, $plan, 201);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }
    }

    /**
     * PUT /survey-plans/{id}
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $uid  = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $id   = $this->parseId($args);
        $body = $this->readJsonBody($request);

        $ifMatch = $this->parseIfMatchHeader($request);

        $tx = DbTransaction::begin($this->pdo);
        try {
            // FOR UPDATE concurrency check
            $lockStmt = $this->pdo->prepare(
                'SELECT * FROM app.survey_plans WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
            );
            $lockStmt->execute([':id' => $id]);
            $current = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if ($current === false) {
                throw new ApiError('NOT_FOUND', 'Survey plan not found', 404);
            }

            $currentVersion = (int) $current['version'];
            if ($ifMatch !== null && $ifMatch !== $currentVersion) {
                throw new ApiError('VERSION_CONFLICT', sprintf('Survey plan version conflict: current version is %d', $currentVersion), 409);
            }

            $planNumber = array_key_exists('plan_number', $body)
                ? trim((string) $body['plan_number'])
                : $current['plan_number'];

            if ($planNumber === '') {
                throw new ApiError('VALIDATION_FAILED', 'plan_number cannot be empty', 400);
            }

            if ($planNumber !== $current['plan_number']) {
                $chk = $this->pdo->prepare('SELECT id FROM app.survey_plans WHERE plan_number = :p AND id <> :id AND deleted_at IS NULL');
                $chk->execute([':p' => $planNumber, ':id' => $id]);
                if ($chk->fetch()) {
                    throw new ApiError('VALIDATION_FAILED', sprintf('Survey plan number "%s" already exists', $planNumber), 409);
                }
            }

            $planType = array_key_exists('plan_type', $body)
                ? trim((string) $body['plan_type'])
                : $current['plan_type'];
            if (!in_array($planType, self::PLAN_TYPES, true)) {
                throw new ApiError('VALIDATION_FAILED', 'Invalid plan_type value', 400);
            }

            $crsId = array_key_exists('crs_id', $body)
                ? ($body['crs_id'] !== null ? (int) $body['crs_id'] : null)
                : ($current['crs_id'] !== null ? (int) $current['crs_id'] : null);

            $areaSqm = array_key_exists('area_sqm', $body)
                ? ($body['area_sqm'] !== null ? (float) $body['area_sqm'] : null)
                : ($current['area_sqm'] !== null ? (float) $current['area_sqm'] : null);

            $lotCount = array_key_exists('lot_count', $body)
                ? ($body['lot_count'] !== null ? (int) $body['lot_count'] : null)
                : ($current['lot_count'] !== null ? (int) $current['lot_count'] : null);

            $surveyDate = array_key_exists('survey_date', $body)
                ? $this->optionalDate($body, 'survey_date')
                : $current['survey_date'];

            $approvedDate = array_key_exists('approved_date', $body)
                ? $this->optionalDate($body, 'approved_date')
                : $current['approved_date'];

            $approvingAgency = array_key_exists('approving_agency', $body)
                ? $this->optionalString($body, 'approving_agency', 120)
                : $current['approving_agency'];

            $surveyorName = array_key_exists('surveyor_name', $body)
                ? $this->optionalString($body, 'surveyor_name', 160)
                : $current['surveyor_name'];

            $surveyorLicense = array_key_exists('surveyor_license', $body)
                ? $this->optionalString($body, 'surveyor_license', 60)
                : $current['surveyor_license'];

            $controlRef = array_key_exists('control_reference', $body)
                ? $this->optionalString($body, 'control_reference', 160)
                : $current['control_reference'];

            $psgcBarangay = array_key_exists('psgc_barangay', $body)
                ? $this->validatePsgc($body['psgc_barangay'])
                : $current['psgc_barangay'];

            $sourceDocId = array_key_exists('source_document_id', $body)
                ? $this->optionalUuid($body, 'source_document_id')
                : $current['source_document_id'];

            $remarks = array_key_exists('remarks', $body)
                ? $this->optionalText($body, 'remarks')
                : $current['remarks'];

            $newVersion = $currentVersion + 1;

            $sql = 'UPDATE app.survey_plans SET
                plan_number = :plan_number,
                plan_type = :plan_type,
                survey_date = :survey_date,
                approved_date = :approved_date,
                approving_agency = :approving_agency,
                surveyor_name = :surveyor_name,
                surveyor_license = :surveyor_license,
                control_reference = :control_reference,
                crs_id = :crs_id,
                area_sqm = :area_sqm,
                lot_count = :lot_count,
                psgc_barangay = :psgc_barangay,
                source_document_id = :source_document_id,
                remarks = :remarks,
                version = :new_version,
                updated_by = :uid,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':plan_number'         => $planNumber,
                ':plan_type'           => $planType,
                ':survey_date'         => $surveyDate,
                ':approved_date'       => $approvedDate,
                ':approving_agency'    => $approvingAgency,
                ':surveyor_name'       => $surveyorName,
                ':surveyor_license'    => $surveyorLicense,
                ':control_reference'   => $controlRef,
                ':crs_id'              => $crsId,
                ':area_sqm'            => $areaSqm,
                ':lot_count'           => $lotCount,
                ':psgc_barangay'       => $psgcBarangay,
                ':source_document_id'  => $sourceDocId,
                ':remarks'             => $remarks,
                ':new_version'         => $newVersion,
                ':uid'                 => $uid,
                ':id'                  => $id,
            ]);

            $plan = $this->getCurrent($id);

            $this->audit->writeFromSession(
                'update',
                'app.survey_plans',
                (string) $id,
                $this->stripForAudit($current),
                array_merge($this->stripForAudit($plan), ['version' => $newVersion]),
                null,
                $body['change_reason'] ?? ('Updated survey plan ' . $planNumber)
            );

            DbTransaction::commit($this->pdo, $tx);
            return Envelope::success($response, $plan);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }
    }

    /**
     * DELETE /survey-plans/{id}
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $uid  = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $id   = $this->parseId($args);
        $body = $this->readJsonBody($request);

        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiError('VALIDATION_FAILED', 'reason is required to delete a survey plan', 400);
        }

        $tx = DbTransaction::begin($this->pdo);
        try {
            $current = $this->getCurrent($id);

            // Refuse delete if any active parcel references it
            $chk = $this->pdo->prepare('SELECT COUNT(*) FROM app.parcels WHERE survey_plan_id = :id AND deleted_at IS NULL');
            $chk->execute([':id' => $id]);
            $linkedCount = (int) $chk->fetchColumn();
            if ($linkedCount > 0) {
                throw new ApiError('VALIDATION_FAILED', sprintf('Cannot delete survey plan: %d active parcel(s) are linked to it', $linkedCount), 400);
            }

            $stmt = $this->pdo->prepare(
                'UPDATE app.survey_plans SET deleted_at = CURRENT_TIMESTAMP, updated_by = :uid, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $stmt->execute([':uid' => $uid, ':id' => $id]);

            $this->audit->writeFromSession(
                'delete',
                'app.survey_plans',
                (string) $id,
                array_merge($this->stripForAudit($current), ['deleted' => true]),
                null,
                null,
                $reason
            );

            DbTransaction::commit($this->pdo, $tx);
            return Envelope::success($response, ['deleted' => true, 'id' => $id]);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }
    }

    /**
     * GET /survey-plans/{id}/parcels
     *
     * List parcels linked to this survey plan (TASK-077 AC: linked parcels
     * listed from the plan).
     */
    public function parcels(Request $request, Response $response, array $args): Response
    {
        $this->resolveUser($request);
        $id = $this->parseId($args);

        // Ensure plan exists
        $this->getCurrent($id);

        $sql = 'SELECT p.id, p.parcel_code, p.lot_number, p.block_number, p.status, '
             . 'p.source_area_sqm, p.computed_area_sqm, p.verification_status, '
             . 'p.psgc_barangay, p.created_at '
             . 'FROM app.parcels p '
             . 'WHERE p.survey_plan_id = :id AND p.deleted_at IS NULL '
             . 'ORDER BY p.parcel_code ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $parcels = array_map(function (array $r) {
            return [
                'id'                  => (string) $r['id'],
                'parcel_code'         => $r['parcel_code'],
                'lot_number'          => $r['lot_number'],
                'block_number'        => $r['block_number'],
                'status'              => $r['status'],
                'source_area_sqm'     => $r['source_area_sqm'] !== null ? (float) $r['source_area_sqm'] : null,
                'computed_area_sqm'   => $r['computed_area_sqm'] !== null ? (float) $r['computed_area_sqm'] : null,
                'verification_status' => $r['verification_status'],
                'psgc_barangay'       => $r['psgc_barangay'],
                'created_at'          => $r['created_at'],
            ];
        }, $rows);

        return Envelope::success($response, [
            'survey_plan_id' => $id,
            'parcels'        => $parcels,
            'total'          => count($parcels),
        ]);
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
            throw new ApiError('VALIDATION_FAILED', 'Invalid survey plan id', 400);
        }
        return $id;
    }

    private function parseIfMatchHeader(Request $request): ?int
    {
        $header = $request->getHeaderLine('If-Match');
        if ($header === '') {
            return null;
        }
        $trimmed = trim($header, " \t\n\r\0\x0B\"");
        return is_numeric($trimmed) ? (int) $trimmed : null;
    }

    private function readJsonBody(Request $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function planSelect(): string
    {
        return 'sp.id, sp.plan_number, sp.plan_type, sp.survey_date, sp.approved_date, '
             . 'sp.approving_agency, sp.surveyor_name, sp.surveyor_license, sp.control_reference, '
             . 'sp.crs_id, sp.area_sqm, sp.lot_count, sp.psgc_barangay, sp.source_document_id, '
             . 'sp.remarks, sp.version, sp.created_by, sp.created_at, sp.updated_by, sp.updated_at, '
             . 'c.code AS crs_code, c.name AS crs_name';
    }

    private function getCurrent(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->planSelect() . ' FROM app.survey_plans sp LEFT JOIN ref.crs_registry c ON c.id = sp.crs_id WHERE sp.id = :id AND sp.deleted_at IS NULL'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Survey plan not found', 404);
        }
        return $this->formatPlan($row);
    }

    private function formatPlan(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'plan_number'         => $r['plan_number'],
            'plan_type'           => $r['plan_type'],
            'survey_date'         => $r['survey_date'],
            'approved_date'       => $r['approved_date'],
            'approving_agency'    => $r['approving_agency'],
            'surveyor_name'       => $r['surveyor_name'],
            'surveyor_license'    => $r['surveyor_license'],
            'control_reference'   => $r['control_reference'],
            'crs_id'              => $r['crs_id'] !== null ? (int) $r['crs_id'] : null,
            'crs_code'            => $r['crs_code'] ?? null,
            'crs_name'            => $r['crs_name'] ?? null,
            'area_sqm'            => $r['area_sqm'] !== null ? (float) $r['area_sqm'] : null,
            'lot_count'           => $r['lot_count'] !== null ? (int) $r['lot_count'] : null,
            'psgc_barangay'       => $r['psgc_barangay'],
            'source_document_id'  => $r['source_document_id'],
            'remarks'             => $r['remarks'],
            'version'             => (int) $r['version'],
            'created_by'          => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_at'          => $r['created_at'],
            'updated_by'          => $r['updated_by'] !== null ? (int) $r['updated_by'] : null,
            'updated_at'          => $r['updated_at'],
        ];
    }

    private function stripForAudit(array $plan): array
    {
        unset($plan['id'], $plan['created_by'], $plan['created_at'], $plan['updated_by'], $plan['updated_at']);
        return $plan;
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

    private function optionalDate(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
            return null;
        }
        $val = trim((string) $body[$key]);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            throw new ApiError('VALIDATION_FAILED', "{$key} must be in YYYY-MM-DD format", 400);
        }
        return $val;
    }

    private function optionalUuid(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
            return null;
        }
        $val = trim((string) $body[$key]);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
            throw new ApiError('VALIDATION_FAILED', "{$key} must be a valid UUID", 400);
        }
        return $val;
    }

    private function validatePsgc(mixed $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $code = trim((string) $code);
        if (!preg_match('/^\d{9,12}$/', $code)) {
            throw new ApiError('VALIDATION_FAILED', 'psgc_barangay must be a 9-12 digit PSGC code', 400);
        }
        return $code;
    }
}
