<?php
declare(strict_types=1);

namespace App\Survey\Http;

use App\Audit\AuditWriter;
use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\Survey\Application\SurveyValidationService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ValidationController
 *
 * Implements validation and submission guards (TASK-096, TASK-098):
 *  - POST /parcels/{id}/validate: Evaluates 12-point survey checklist.
 *  - GET /parcels/{id}/validation: Returns latest validation result.
 *  - POST /parcels/{id}/submit: Validates and transitions parcel to SUBMITTED.
 */
class ValidationController
{
    public function __construct(
        private PDO $pdo,
        private SurveyValidationService $validationService,
        private AuditWriter $audit
    ) {
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

    /**
     * POST /parcels/{id}/validate
     *
     * Evaluates survey validation rules (VR-01 through VR-20, TD confirmation,
     * tie point, overlap, closure) and returns a complete checklist report.
     */
    public function validate(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $parcelId = $this->parseUuid($args, 'id');

        $body = $request->getParsedBody();
        $options = is_array($body) ? $body : [];

        $result = $this->validationService->validateParcel($parcelId, $options);

        return Envelope::success($response, $result, 200);
    }

    /**
     * GET /parcels/{id}/validation
     *
     * Returns the latest persisted validation result from the current computation,
     * or computes it if not yet cached.
     */
    public function getLatest(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $parcelId = $this->parseUuid($args, 'id');

        $queryParams = $request->getQueryParams();
        $forceRevalidate = filter_var($queryParams['revalidate'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$forceRevalidate) {
            $stmt = $this->pdo->prepare(
                'SELECT c.validation_result '
                . 'FROM app.parcels p '
                . 'JOIN app.parcel_computations c ON c.id = p.current_computation_id '
                . 'WHERE p.id = :pid AND p.deleted_at IS NULL'
            );
            $stmt->execute([':pid' => $parcelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && !empty($row['validation_result'])) {
                $cached = is_string($row['validation_result'])
                    ? json_decode($row['validation_result'], true)
                    : $row['validation_result'];

                if (is_array($cached)) {
                    return Envelope::success($response, $cached, 200);
                }
            }
        }

        $result = $this->validationService->validateParcel($parcelId);

        return Envelope::success($response, $result, 200);
    }

    /**
     * POST /parcels/{id}/submit
     *
     * Submission guard (TASK-098):
     * Runs survey validation checklist. If any blocking checks fail:
     *  - If closure tolerance exceeded: returns 422 CLOSURE_EXCEEDS_TOLERANCE.
     *  - If other blocking failures: returns 422 VALIDATION_FAILED.
     * If valid:
     *  - Transitions status to SUBMITTED.
     *  - Carries forward warnings to reviewers in the audit / version snapshot.
     */
    public function submit(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);
        $parcelId = $this->parseUuid($args, 'id');

        $body = $request->getParsedBody();
        $reason = is_array($body) ? trim((string) ($body['change_reason'] ?? ($body['reason'] ?? 'Submitted parcel for review'))) : 'Submitted parcel for review';
        if ($reason === '') {
            $reason = 'Submitted parcel for review';
        }

        // Lock parcel row
        $lock = $this->pdo->prepare('SELECT * FROM app.parcels WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([':id' => $parcelId]);
        $parcel = $lock->fetch(PDO::FETCH_ASSOC);
        if ($parcel === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found.', 404);
        }

        $currentStatus = $parcel['status'];
        if (in_array($currentStatus, ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'PUBLISHED', 'ARCHIVED', 'SUPERSEDED'], true)) {
            throw new ApiError('INVALID_STATE', sprintf('Cannot submit parcel with status %s.', $currentStatus), 400);
        }

        // Run validation
        $valResult = $this->validationService->validateParcel($parcelId);

        if (!$valResult['can_submit']) {
            $hasClosure = false;
            $closureMessage = '';

            foreach ($valResult['blocking_failures'] as $bf) {
                if (in_array($bf['rule'] ?? '', ['VR-11', 'VR-12'], true) || ($bf['id'] ?? '') === 'traverse_closure') {
                    $hasClosure = true;
                    $closureMessage = $bf['message'];
                    break;
                }
            }

            if ($hasClosure) {
                throw new ApiError('CLOSURE_EXCEEDS_TOLERANCE', $closureMessage, 422, [
                    'rule'              => 'VR-11',
                    'blocking_failures' => $valResult['blocking_failures'],
                    'warnings'          => $valResult['warnings'],
                ]);
            }

            $firstErr = $valResult['blocking_failures'][0] ?? null;
            $errMsg = $firstErr ? sprintf('Submission blocked by %s: %s', $firstErr['rule'] ?? 'validation', $firstErr['message'] ?? 'Validation failure') : 'Submission blocked by validation failures.';

            throw new ApiError('VALIDATION_FAILED', $errMsg, 422, [
                'rule'              => $firstErr['rule'] ?? 'VALIDATION',
                'blocking_failures' => $valResult['blocking_failures'],
                'warnings'          => $valResult['warnings'],
            ]);
        }

        $preChange = $parcel;
        unset($preChange['geom']);

        // Update status to SUBMITTED
        $upd = $this->pdo->prepare(
            'UPDATE app.parcels SET '
            . 'status = \'SUBMITTED\', '
            . 'version = version + 1, '
            . 'updated_by = :uid, '
            . 'updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id '
            . 'RETURNING version'
        );
        $upd->execute([':uid' => $uid, ':id' => $parcelId]);
        $newVersion = (int) $upd->fetchColumn();

        // Fetch updated parcel
        $getStmt = $this->pdo->prepare(
            'SELECT p.*, sp.plan_number AS survey_plan_number, pa.name AS psgc_barangay_name, '
            . 'ST_AsGeoJSON(p.geom)::json AS geometry '
            . 'FROM app.parcels p '
            . 'LEFT JOIN app.survey_plans sp ON sp.id = p.survey_plan_id '
            . 'LEFT JOIN ref.psgc_areas pa ON pa.code = p.psgc_barangay '
            . 'WHERE p.id = :id'
        );
        $getStmt->execute([':id' => $parcelId]);
        $updated = $getStmt->fetch(PDO::FETCH_ASSOC);

        // Record version snapshot with carried forward warnings
        $snapshot = $updated;
        unset($snapshot['id'], $snapshot['version'], $snapshot['created_by'], $snapshot['created_at'], $snapshot['updated_by'], $snapshot['updated_at'], $snapshot['geometry']);
        $snapshot['validation_warnings'] = $valResult['warnings'];

        $geomJson = is_array($updated['geometry']) ? json_encode($updated['geometry']) : $updated['geometry'];

        $vStmt = $this->pdo->prepare(
            'INSERT INTO audit.parcel_versions '
            . '(parcel_id, version, snapshot, status, geometry_source, change_summary, change_reason, changed_by, geom) '
            . 'VALUES (:pid, :ver, :snap::jsonb, \'SUBMITTED\', :gsrc, :summary, :reason, :uid, '
            . ($geomJson ? 'ST_Multi(ST_GeomFromGeoJSON(:gj))' : 'NULL') . ')'
        );
        $vParams = [
            ':pid'     => $parcelId,
            ':ver'     => $newVersion,
            ':snap'    => json_encode($snapshot, JSON_THROW_ON_ERROR),
            ':gsrc'    => $updated['geometry_source'] ?? 'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
            ':summary' => sprintf('Parcel submitted for review with %d warning(s)', count($valResult['warnings'])),
            ':reason'  => $reason,
            ':uid'     => $uid,
        ];
        if ($geomJson) {
            $vParams[':gj'] = $geomJson;
        }
        $vStmt->execute($vParams);

        // Audit log
        $this->audit->writeFromSession('UPDATE', 'app.parcels', $parcelId, $preChange, $snapshot, null, $reason);

        return Envelope::success($response, [
            'parcel'     => $updated,
            'validation' => $valResult,
        ], 200);
    }
}
