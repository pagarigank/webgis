<?php
declare(strict_types=1);

namespace App\Survey\Http;

use App\Audit\AuditWriter;
use App\Core\Errors\ApiError;
use App\Survey\Domain\Bearing;
use App\Survey\Domain\CourseValidator;
use App\Survey\Domain\Distance;
use App\Survey\Domain\Parser\TechnicalDescriptionParser;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller for Technical Descriptions, Courses, Parsing, Validation, and Confirmation (Phase 10: TASK-080, 081, 082, 083, 086).
 */
class TechnicalDescriptionController
{
    public const SOURCE_TYPES = [
        'MANUALLY_ENTERED', 'OCR_EXTRACTED', 'AI_EXTRACTED', 'IMPORTED', 'PASTED_TEXT',
    ];

    public const BEARING_REFERENCES = ['GRID', 'GEODETIC', 'MAGNETIC', 'ASSUMED'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditWriter $audit,
        private readonly TechnicalDescriptionParser $parser = new TechnicalDescriptionParser(),
        private readonly CourseValidator $validator = new CourseValidator()
    ) {
    }

    /**
     * GET /parcels/{id}/technical-descriptions
     * List all revisions for a parcel.
     */
    public function listForParcel(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $parcelId = $args['id'] ?? '';
        if ($parcelId === '') {
            throw new ApiError('VALIDATION_FAILED', 'parcel id is required', 400);
        }

        $stmt = $this->pdo->prepare(
            'SELECT td.*, u.username AS confirmed_by_username '
            . 'FROM app.technical_descriptions td '
            . 'LEFT JOIN app.users u ON td.confirmed_by = u.id '
            . 'WHERE td.parcel_id = :pid '
            . 'ORDER BY td.revision DESC'
        );
        $stmt->execute([':pid' => $parcelId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = array_map(fn (array $r) => $this->formatTdSummary($r), $rows);
        return $this->json($response, ['data' => $data]);
    }

    /**
     * POST /parcels/{id}/technical-descriptions
     * Creates next revision for a parcel.
     */
    public function createForParcel(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $parcelId = $args['id'] ?? '';
        if ($parcelId === '') {
            throw new ApiError('VALIDATION_FAILED', 'parcel id is required', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $sourceType = $body['source_type'] ?? 'PASTED_TEXT';
        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            $sourceType = 'MANUALLY_ENTERED';
        }

        $bearingRef = $body['bearing_reference'] ?? 'GRID';
        if (!in_array($bearingRef, self::BEARING_REFERENCES, true)) {
            $bearingRef = 'GRID';
        }

        $distanceUnit = $body['distance_unit'] ?? 'm';
        $originalText = isset($body['original_text']) ? (string) $body['original_text'] : null;
        $planId = !empty($body['survey_plan_id']) ? (int) $body['survey_plan_id'] : null;
        $pobLabel = isset($body['point_of_beginning_label']) ? (string) $body['point_of_beginning_label'] : '1';
        $surveyRef = isset($body['survey_reference']) ? (string) $body['survey_reference'] : null;
        $makeCurrent = (bool) ($body['is_current'] ?? true);
        $startedTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            // Calculate next revision
            $revStmt = $this->pdo->prepare('SELECT COALESCE(MAX(revision), 0) + 1 FROM app.technical_descriptions WHERE parcel_id = :pid');
            $revStmt->execute([':pid' => $parcelId]);
            $nextRevision = (int) $revStmt->fetchColumn();

            if ($makeCurrent) {
                $unsetStmt = $this->pdo->prepare('UPDATE app.technical_descriptions SET is_current = false WHERE parcel_id = :pid');
                $unsetStmt->execute([':pid' => $parcelId]);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO app.technical_descriptions ('
                . 'parcel_id, revision, survey_plan_id, original_text, source_type, parser_status, '
                . 'bearing_reference, distance_unit, point_of_beginning_label, survey_reference, '
                . 'is_current, status, version, created_by, updated_by'
                . ') VALUES ('
                . ':pid, :rev, :plan_id, :orig_text, :src_type, :p_status, '
                . ':b_ref, :d_unit, :pob, :s_ref, '
                . ':current, :status, 1, :uid, :uid'
                . ') RETURNING id'
            );

            $stmt->execute([
                ':pid' => $parcelId,
                ':rev' => $nextRevision,
                ':plan_id' => $planId,
                ':orig_text' => $originalText,
                ':src_type' => $sourceType,
                ':p_status' => 'NOT_PARSED',
                ':b_ref' => $bearingRef,
                ':d_unit' => $distanceUnit,
                ':pob' => $pobLabel,
                ':s_ref' => $surveyRef,
                ':current' => $makeCurrent ? 'true' : 'false',
                ':status' => 'DRAFT',
                ':uid' => $uid,
            ]);

            $tdId = (int) $stmt->fetchColumn();

            // If original_text is provided, automatically parse and stage courses & tie line
            if ($originalText !== null && trim($originalText) !== '') {
                $parseResult = $this->parser->parse($originalText, $sourceType, $distanceUnit);

                // Update parser status
                $upStmt = $this->pdo->prepare('UPDATE app.technical_descriptions SET parser_status = :ps WHERE id = :id');
                $upStmt->execute([':ps' => $parseResult['parser_status'], ':id' => $tdId]);

                // Insert tie point & tie line if found
                if ($parseResult['tie_point_name'] !== null && !empty($parseResult['tie_lines'])) {
                    $this->insertParsedTieLine($tdId, $parseResult['tie_point_name'], $parseResult['tie_lines'][0], $uid);
                }

                // Insert courses
                foreach ($parseResult['courses'] as $c) {
                    $this->insertCourseRow($tdId, $c);
                }
            }

            $this->audit->writeFromSession('INSERT', 'app.technical_descriptions', (string) $tdId, null, [
                'parcel_id' => $parcelId,
                'revision' => $nextRevision,
                'source_type' => $sourceType,
            ]);

            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $this->get($request, $response, ['id' => (string) $tdId]);
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * GET /technical-descriptions/{id}
     * Retrieves full technical description detail including courses and tie points.
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $id = (int) ($args['id'] ?? 0);
        $td = $this->loadTdRow($id);

        $coursesStmt = $this->pdo->prepare('SELECT * FROM app.technical_description_courses WHERE technical_description_id = :id ORDER BY seq ASC');
        $coursesStmt->execute([':id' => $id]);
        $courses = array_map(fn (array $c) => $this->formatCourseRow($c), $coursesStmt->fetchAll(PDO::FETCH_ASSOC));

        $tiePointsStmt = $this->pdo->prepare('SELECT * FROM app.tie_points WHERE technical_description_id = :id ORDER BY sequence ASC');
        $tiePointsStmt->execute([':id' => $id]);
        $tiePoints = $tiePointsStmt->fetchAll(PDO::FETCH_ASSOC);

        $tieLinesStmt = $this->pdo->prepare('SELECT * FROM app.tie_lines WHERE technical_description_id = :id ORDER BY seq ASC');
        $tieLinesStmt->execute([':id' => $id]);
        $tieLines = array_map(fn (array $tl) => $this->formatTieLineRow($tl), $tieLinesStmt->fetchAll(PDO::FETCH_ASSOC));

        $payload = $this->formatTdSummary($td);
        $payload['courses'] = $courses;
        $payload['tie_points'] = $tiePoints;
        $payload['tie_lines'] = $tieLines;

        $res = $response->withHeader('ETag', '"' . $td['version'] . '"');
        return $this->json($res, ['data' => $payload]);
    }

    /**
     * PUT /technical-descriptions/{id}
     * Updates technical description header with optimistic concurrency.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $id = (int) ($args['id'] ?? 0);
        $td = $this->loadTdRow($id);

        if ($td['parser_status'] === 'CONFIRMED') {
            throw new ApiError('CONFLICT', 'Confirmed technical descriptions cannot be modified in-place; create a new revision instead.', 409);
        }

        $this->verifyIfMatch($request, (int) $td['version']);
        $body = (array) ($request->getParsedBody() ?? []);

        $bRef = $body['bearing_reference'] ?? $td['bearing_reference'];
        $dUnit = $body['distance_unit'] ?? $td['distance_unit'];
        $pob = $body['point_of_beginning_label'] ?? $td['point_of_beginning_label'];
        $sRef = $body['survey_reference'] ?? $td['survey_reference'];
        $planId = array_key_exists('survey_plan_id', $body) ? (!empty($body['survey_plan_id']) ? (int) $body['survey_plan_id'] : null) : $td['survey_plan_id'];

        $stmt = $this->pdo->prepare(
            'UPDATE app.technical_descriptions SET '
            . 'bearing_reference = :b_ref, distance_unit = :d_unit, point_of_beginning_label = :pob, '
            . 'survey_reference = :s_ref, survey_plan_id = :plan_id, version = version + 1, '
            . 'updated_by = :uid, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id RETURNING version'
        );
        $stmt->execute([
            ':b_ref' => $bRef,
            ':d_unit' => $dUnit,
            ':pob' => $pob,
            ':s_ref' => $sRef,
            ':plan_id' => $planId,
            ':uid' => $uid,
            ':id' => $id,
        ]);

        $this->audit->writeFromSession('UPDATE', 'app.technical_descriptions', (string) $id, null, [
            'bearing_reference' => $bRef,
            'distance_unit' => $dUnit,
        ]);

        return $this->get($request, $response, $args);
    }

    /**
     * POST /technical-descriptions/{id}/courses
     * Adds a course to technical description.
     */
    public function addCourse(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $id = (int) ($args['id'] ?? 0);
        $td = $this->loadTdRow($id);

        if ($td['parser_status'] === 'CONFIRMED') {
            throw new ApiError('CONFLICT', 'Cannot add course to confirmed technical description', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        // Resolve next sequence number
        $seqStmt = $this->pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM app.technical_description_courses WHERE technical_description_id = :id');
        $seqStmt->execute([':id' => $id]);
        $nextSeq = (int) $seqStmt->fetchColumn();

        $cData = [
            'seq' => $body['seq'] ?? $nextSeq,
            'from_point_label' => $body['from_point_label'] ?? $body['from_corner'] ?? $body['from'] ?? (string) $nextSeq,
            'to_point_label' => $body['to_point_label'] ?? $body['to_corner'] ?? $body['to'] ?? (string) ($nextSeq + 1),
            'bearing' => $body['bearing'] ?? $body['bearing_raw'] ?? null,
            'quadrant' => $body['quadrant'] ?? null,
            'deg' => $body['deg'] ?? null,
            'min' => $body['min'] ?? null,
            'sec' => $body['sec'] ?? null,
            'azimuth_dd' => $body['azimuth_dd'] ?? null,
            'distance' => $body['distance'] ?? $body['distance_raw'] ?? $body['distance_m'] ?? null,
            'unit' => $body['unit'] ?? $td['distance_unit'] ?? 'm',
            'remarks' => $body['remarks'] ?? null,
        ];

        $courseId = $this->insertCourseRow($id, $cData);

        $this->audit->writeFromSession('INSERT', 'app.technical_description_courses', (string) $courseId, null, [
            'technical_description_id' => $id,
            'seq' => $cData['seq'],
        ]);

        return $this->get($request, $response, ['id' => (string) $id]);
    }

    /**
     * PUT /technical-descriptions/{id}/courses/{courseId}
     * Updates an individual course.
     */
    public function updateCourse(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $tdId = (int) ($args['id'] ?? 0);
        $courseId = (int) ($args['courseId'] ?? 0);

        $td = $this->loadTdRow($tdId);
        if ($td['parser_status'] === 'CONFIRMED') {
            throw new ApiError('CONFLICT', 'Cannot edit course on confirmed technical description', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        // Resolve normalized values
        $bearingObj = null;
        if (!empty($body['bearing'])) {
            try {
                $bearingObj = Bearing::parse((string) $body['bearing']);
            } catch (InvalidArgumentException $e) {
                throw new ApiError('VALIDATION_FAILED', $e->getMessage(), 400);
            }
        } elseif (!empty($body['quadrant']) && isset($body['deg'])) {
            try {
                $bearingObj = Bearing::fromQuadrant((string) $body['quadrant'], (int) $body['deg'], (int) ($body['min'] ?? 0), (float) ($body['sec'] ?? 0));
            } catch (InvalidArgumentException $e) {
                throw new ApiError('VALIDATION_FAILED', $e->getMessage(), 400);
            }
        }

        $distanceObj = null;
        if (isset($body['distance']) || isset($body['distance_m'])) {
            $distVal = (float) ($body['distance'] ?? $body['distance_m']);
            $unit = (string) ($body['unit'] ?? $td['distance_unit'] ?? 'm');
            try {
                $distanceObj = Distance::fromUnit($distVal, $unit);
            } catch (InvalidArgumentException $e) {
                throw new ApiError('VALIDATION_FAILED', $e->getMessage(), 400);
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE app.technical_description_courses SET '
            . 'from_point_label = COALESCE(:from_p, from_point_label), '
            . 'to_point_label = COALESCE(:to_p, to_point_label), '
            . 'original_bearing = COALESCE(:orig_b, original_bearing), '
            . 'bearing_quadrant = COALESCE(:quad, bearing_quadrant), '
            . 'deg = COALESCE(:deg, deg), min = COALESCE(:min, min), sec = COALESCE(:sec, sec), '
            . 'bearing_type = COALESCE(:b_type, bearing_type), '
            . 'normalized_bearing = COALESCE(:norm_b, normalized_bearing), '
            . 'azimuth_dd = COALESCE(:az, azimuth_dd), '
            . 'original_distance = COALESCE(:orig_d, original_distance), '
            . 'original_unit = COALESCE(:orig_u, original_unit), '
            . 'distance_m = COALESCE(:dist_m, distance_m), '
            . 'remarks = COALESCE(:remarks, remarks) '
            . 'WHERE id = :cid AND technical_description_id = :td_id'
        );

        $stmt->execute([
            ':from_p' => $body['from_point_label'] ?? null,
            ':to_p' => $body['to_point_label'] ?? null,
            ':orig_b' => $body['bearing'] ?? null,
            ':quad' => $bearingObj?->getQuadrant(),
            ':deg' => $bearingObj?->getDegrees(),
            ':min' => $bearingObj?->getMinutes(),
            ':sec' => $bearingObj?->getSeconds(),
            ':b_type' => $bearingObj?->getType(),
            ':norm_b' => $bearingObj?->toNormalizedString(),
            ':az' => $bearingObj?->toAzimuth()->toDecimalDegrees(),
            ':orig_d' => $distanceObj?->getOriginalValue(),
            ':orig_u' => $distanceObj?->getOriginalUnit(),
            ':dist_m' => $distanceObj?->toMeters(),
            ':remarks' => $body['remarks'] ?? null,
            ':cid' => $courseId,
            ':td_id' => $tdId,
        ]);

        $this->audit->writeFromSession('UPDATE', 'app.technical_description_courses', (string) $courseId, null, [
            'technical_description_id' => $tdId,
        ]);

        return $this->get($request, $response, ['id' => (string) $tdId]);
    }

    /**
     * DELETE /technical-descriptions/{id}/courses/{courseId}
     * Deletes a course and sequentially renumbers remaining courses.
     */
    public function deleteCourse(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $tdId = (int) ($args['id'] ?? 0);
        $courseId = (int) ($args['courseId'] ?? 0);

        $td = $this->loadTdRow($tdId);
        if ($td['parser_status'] === 'CONFIRMED') {
            throw new ApiError('CONFLICT', 'Cannot delete course from confirmed technical description', 409);
        }

        $startedTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $delStmt = $this->pdo->prepare('DELETE FROM app.technical_description_courses WHERE id = :cid AND technical_description_id = :td_id');
            $delStmt->execute([':cid' => $courseId, ':td_id' => $tdId]);

            // Renumber remaining courses sequentially 1..N
            $courses = $this->pdo->prepare('SELECT id FROM app.technical_description_courses WHERE technical_description_id = :td_id ORDER BY seq ASC');
            $courses->execute([':td_id' => $tdId]);
            $ids = $courses->fetchAll(PDO::FETCH_COLUMN);

            $updateSeq = $this->pdo->prepare('UPDATE app.technical_description_courses SET seq = :seq WHERE id = :id');
            foreach ($ids as $idx => $cId) {
                $updateSeq->execute([':seq' => $idx + 1, ':id' => $cId]);
            }

            $this->audit->writeFromSession('DELETE', 'app.technical_description_courses', (string) $courseId, [
                'technical_description_id' => $tdId,
            ], null);

            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return $this->get($request, $response, ['id' => (string) $tdId]);
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * PUT /technical-descriptions/{id}/courses/order
     * Reorders courses and audits action.
     */
    public function reorderCourses(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $tdId = (int) ($args['id'] ?? 0);
        $td = $this->loadTdRow($tdId);
        if ($td['parser_status'] === 'CONFIRMED') {
            throw new ApiError('CONFLICT', 'Cannot reorder courses on confirmed technical description', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $courseIds = $body['course_ids'] ?? [];
        if (!is_array($courseIds) || empty($courseIds)) {
            throw new ApiError('VALIDATION_FAILED', 'course_ids array is required', 400);
        }

        $startedTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            // Use temporary negative sequence numbers to avoid unique constraint clash
            $tempStmt = $this->pdo->prepare('UPDATE app.technical_description_courses SET seq = :neg_seq WHERE id = :id AND technical_description_id = :td_id');
            foreach ($courseIds as $idx => $cId) {
                $tempStmt->execute([':neg_seq' => -($idx + 1), ':id' => (int) $cId, ':td_id' => $tdId]);
            }

            $finalStmt = $this->pdo->prepare('UPDATE app.technical_description_courses SET seq = :seq WHERE id = :id AND technical_description_id = :td_id');
            foreach ($courseIds as $idx => $cId) {
                $finalStmt->execute([':seq' => $idx + 1, ':id' => (int) $cId, ':td_id' => $tdId]);
            }

            $this->audit->writeFromSession('UPDATE', 'app.technical_descriptions', (string) $tdId, null, [
                'new_order' => $courseIds,
            ]);

            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return $this->get($request, $response, ['id' => (string) $tdId]);
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * POST /technical-descriptions/{id}/validate
     * Course syntax and rule check without computing (TASK-081).
     */
    public function validateCourses(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        // Fetch courses for this TD
        $stmt = $this->pdo->prepare('SELECT * FROM app.technical_description_courses WHERE technical_description_id = :id ORDER BY seq ASC');
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $courses = array_map(function (array $r) {
            return [
                'seq' => (int) $r['seq'],
                'from_point_label' => $r['from_point_label'],
                'to_point_label' => $r['to_point_label'],
                'quadrant' => $r['bearing_quadrant'],
                'deg' => $r['deg'] !== null ? (int) $r['deg'] : null,
                'min' => $r['min'] !== null ? (int) $r['min'] : null,
                'sec' => $r['sec'] !== null ? (float) $r['sec'] : null,
                'bearing' => $r['normalized_bearing'] ?? $r['original_bearing'],
                'azimuth_dd' => $r['azimuth_dd'] !== null ? (float) $r['azimuth_dd'] : null,
                'distance' => $r['distance_m'] !== null ? (float) $r['distance_m'] : (float) $r['original_distance'],
                'unit' => $r['original_unit'] ?? 'm',
            ];
        }, $rows);

        $result = $this->validator->validate($courses);
        return $this->json($response, ['data' => $result]);
    }

    /**
     * POST /survey/parse
     * Free text to staged courses (TASK-082).
     */
    public function parseText(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $text = (string) ($body['text'] ?? '');
        if (trim($text) === '') {
            throw new ApiError('VALIDATION_FAILED', 'text is required', 400);
        }

        $sourceType = (string) ($body['source_type'] ?? 'PASTED_TEXT');
        $unitHint = (string) ($body['distance_unit_hint'] ?? 'm');

        $result = $this->parser->parse($text, $sourceType, $unitHint);
        return $this->json($response, ['data' => $result]);
    }

    /**
     * POST /technical-descriptions/{id}/confirm
     * Staged -> Confirmed workflow action (TASK-083).
     */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $id = (int) ($args['id'] ?? 0);
        $td = $this->loadTdRow($id);

        if ($td['parser_status'] === 'CONFIRMED') {
            return $this->get($request, $response, $args);
        }

        // Validate courses
        $coursesStmt = $this->pdo->prepare('SELECT * FROM app.technical_description_courses WHERE technical_description_id = :id ORDER BY seq ASC');
        $coursesStmt->execute([':id' => $id]);
        $rows = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            throw new ApiError('PARSE_UNRESOLVED', 'Cannot confirm a technical description without courses', 422, [
                'courses' => [],
            ]);
        }

        $coursesForValidation = array_map(function (array $r) {
            return [
                'seq' => (int) $r['seq'],
                'quadrant' => $r['bearing_quadrant'],
                'deg' => $r['deg'] !== null ? (int) $r['deg'] : null,
                'min' => $r['min'] !== null ? (int) $r['min'] : null,
                'sec' => $r['sec'] !== null ? (float) $r['sec'] : null,
                'bearing' => $r['normalized_bearing'] ?? $r['original_bearing'],
                'azimuth_dd' => $r['azimuth_dd'] !== null ? (float) $r['azimuth_dd'] : null,
                'distance' => $r['distance_m'] !== null ? (float) $r['distance_m'] : (float) $r['original_distance'],
                'unit' => $r['original_unit'] ?? 'm',
            ];
        }, $rows);

        $valResult = $this->validator->validate($coursesForValidation);
        if (!$valResult['valid']) {
            throw new ApiError('PARSE_UNRESOLVED', 'Cannot confirm: unresolved course errors detected', 422, [
                'errors' => $valResult['errors'],
            ]);
        }

        $startedTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $upStmt = $this->pdo->prepare(
                'UPDATE app.technical_descriptions SET '
                . 'parser_status = :status, status = :status, confirmed_by = :uid, confirmed_at = CURRENT_TIMESTAMP, '
                . 'version = version + 1, updated_by = :uid, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = :id'
            );
            $upStmt->execute([':status' => 'CONFIRMED', ':uid' => $uid, ':id' => $id]);

            $confCourses = $this->pdo->prepare('UPDATE app.technical_description_courses SET is_confirmed = true WHERE technical_description_id = :id');
            $confCourses->execute([':id' => $id]);

            $this->audit->writeFromSession('CONFIRM', 'app.technical_descriptions', (string) $id, null, [
                'confirmed_courses_count' => count($rows),
            ]);

            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return $this->get($request, $response, $args);
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * POST /technical-descriptions/{id}/ocr
     * OCR assist staging (TASK-086).
     */
    public function ocrAssist(Request $request, Response $response, array $args): Response
    {
        $uid = $this->resolveUser($request);
        $this->setUserInSession($uid);

        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $ocrText = (string) ($body['text'] ?? '');

        if (trim($ocrText) === '') {
            throw new ApiError('VALIDATION_FAILED', 'text extracted from scan is required', 400);
        }

        $parseResult = $this->parser->parse($ocrText, 'OCR_EXTRACTED', 'm');

        $startedTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $upStmt = $this->pdo->prepare(
                'UPDATE app.technical_descriptions SET '
                . 'original_text = :text, source_type = :src, parser_status = :ps, '
                . 'version = version + 1, updated_by = :uid, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = :id'
            );
            $upStmt->execute([
                ':text' => $ocrText,
                ':src' => 'OCR_EXTRACTED',
                ':ps' => $parseResult['parser_status'],
                ':uid' => $uid,
                ':id' => $id,
            ]);

            // Clear old courses if re-staging
            $delStmt = $this->pdo->prepare('DELETE FROM app.technical_description_courses WHERE technical_description_id = :id');
            $delStmt->execute([':id' => $id]);

            foreach ($parseResult['courses'] as $c) {
                $this->insertCourseRow($id, $c);
            }

            $this->audit->writeFromSession('OCR_STAGING', 'app.technical_descriptions', (string) $id, null, [
                'courses_count' => count($parseResult['courses']),
                'status' => $parseResult['parser_status'],
            ]);

            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return $this->get($request, $response, $args);
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ── Internal Helpers ──────────────────────────────────────────────────

    private function loadTdRow(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM app.technical_descriptions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $td = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$td) {
            throw new ApiError('NOT_FOUND', "Technical description {$id} not found", 404);
        }
        return $td;
    }

    private function insertCourseRow(int $tdId, array $c): int
    {
        $bearingObj = null;
        if (!empty($c['bearing']['original'])) {
            try {
                $bearingObj = Bearing::parse((string) $c['bearing']['original']);
            } catch (\Throwable) {}
        } elseif (!empty($c['bearing']) && is_string($c['bearing'])) {
            try {
                $bearingObj = Bearing::parse((string) $c['bearing']);
            } catch (\Throwable) {}
        } elseif (!empty($c['quadrant']) && isset($c['deg'])) {
            try {
                $bearingObj = Bearing::fromQuadrant((string) $c['quadrant'], (int) $c['deg'], (int) ($c['min'] ?? 0), (float) ($c['sec'] ?? 0));
            } catch (\Throwable) {}
        }

        $distanceObj = null;
        if (!empty($c['distance']['original'])) {
            try {
                $distanceObj = Distance::parse((string) $c['distance']['original']);
            } catch (\Throwable) {}
        } elseif (!empty($c['distance'])) {
            $val = is_array($c['distance']) ? (float) ($c['distance']['value'] ?? 0) : (float) $c['distance'];
            $u = is_array($c['distance']) ? (string) ($c['distance']['unit'] ?? 'm') : (string) ($c['unit'] ?? 'm');
            try {
                $distanceObj = Distance::fromUnit($val, $u);
            } catch (\Throwable) {}
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO app.technical_description_courses ('
            . 'technical_description_id, seq, course_type, from_point_label, to_point_label, '
            . 'original_bearing, bearing_quadrant, deg, min, sec, bearing_type, normalized_bearing, azimuth_dd, '
            . 'original_distance, original_unit, distance_m, extraction_method, confidence, source_text_span, is_confirmed, remarks'
            . ') VALUES ('
            . ':td_id, :seq, :c_type, :from_p, :to_p, '
            . ':orig_b, :quad, :deg, :min, :sec, :b_type, :norm_b, :az, '
            . ':orig_d, :orig_u, :dist_m, :ext_m, :conf, :span, false, :rem'
            . ') RETURNING id'
        );

        $stmt->execute([
            ':td_id' => $tdId,
            ':seq' => (int) $c['seq'],
            ':c_type' => 'LINE',
            ':from_p' => (string) ($c['from_point_label'] ?? (string) $c['seq']),
            ':to_p' => (string) ($c['to_point_label'] ?? (string) ((int) $c['seq'] + 1)),
            ':orig_b' => $c['bearing']['original'] ?? (is_string($c['bearing'] ?? null) ? $c['bearing'] : null),
            ':quad' => $bearingObj?->getQuadrant() ?? ($c['bearing']['quadrant'] ?? null),
            ':deg' => $bearingObj?->getDegrees() ?? ($c['bearing']['deg'] ?? null),
            ':min' => $bearingObj?->getMinutes() ?? ($c['bearing']['min'] ?? null),
            ':sec' => $bearingObj?->getSeconds() ?? ($c['bearing']['sec'] ?? null),
            ':b_type' => $bearingObj?->getType() ?? 'QUADRANT',
            ':norm_b' => $bearingObj?->toNormalizedString() ?? null,
            ':az' => $bearingObj?->toAzimuth()->toDecimalDegrees() ?? ($c['bearing']['azimuth_dd'] ?? null),
            ':orig_d' => $distanceObj?->getOriginalValue() ?? ($c['distance']['value'] ?? null),
            ':orig_u' => $distanceObj?->getOriginalUnit() ?? ($c['distance']['unit'] ?? 'm'),
            ':dist_m' => $distanceObj?->toMeters() ?? ($c['distance']['meters'] ?? null),
            ':ext_m' => $c['extraction_method'] ?? 'MANUALLY_ENTERED',
            ':conf' => $c['confidence'] ?? null,
            ':span' => isset($c['source_span']) ? json_encode($c['source_span']) : null,
            ':rem' => $c['remarks'] ?? null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function insertParsedTieLine(int $tdId, string $tiePointName, array $tl, int $uid): void
    {
        // 1. Look up or insert tie point
        $cpStmt = $this->pdo->prepare('SELECT id FROM app.survey_control_points WHERE point_name ILIKE :name LIMIT 1');
        $cpStmt->execute([':name' => $tiePointName]);
        $cpId = $cpStmt->fetchColumn();

        $tpStmt = $this->pdo->prepare(
            'INSERT INTO app.tie_points ('
            . 'technical_description_id, control_point_id, adhoc_name, role, '
            . 'as_used_easting, as_used_northing, as_used_crs_id, as_used_status, created_by'
            . ') VALUES ('
            . ':td_id, :cp_id, :adhoc, :role, 0, 0, 1, :st, :uid'
            . ') RETURNING id'
        );
        $tpStmt->execute([
            ':td_id' => $tdId,
            ':cp_id' => $cpId ? (int) $cpId : null,
            ':adhoc' => $cpId ? null : $tiePointName,
            ':role' => 'TIE',
            ':st' => $cpId ? 'VERIFIED' : 'UNVERIFIED',
            ':uid' => $uid,
        ]);
        $tiePointId = (int) $tpStmt->fetchColumn();

        // 2. Insert tie line
        $b = $tl['bearing'] ?? [];
        $d = $tl['distance'] ?? [];

        $tlStmt = $this->pdo->prepare(
            'INSERT INTO app.tie_lines ('
            . 'technical_description_id, tie_point_id, seq, to_point_label, '
            . 'original_bearing, bearing_quadrant, deg, min, sec, azimuth_dd, normalized_bearing, '
            . 'original_distance, original_unit, distance_m, extraction_method'
            . ') VALUES ('
            . ':td_id, :tp_id, 1, :to_p, '
            . ':orig_b, :quad, :deg, :min, :sec, :az, :norm_b, '
            . ':orig_d, :orig_u, :dist_m, :ext_m'
            . ')'
        );
        $tlStmt->execute([
            ':td_id' => $tdId,
            ':tp_id' => $tiePointId,
            ':to_p' => $tl['to_point'] ?? '1',
            ':orig_b' => $b['original'] ?? null,
            ':quad' => $b['quadrant'] ?? null,
            ':deg' => $b['deg'] ?? null,
            ':min' => $b['min'] ?? null,
            ':sec' => $b['sec'] ?? null,
            ':az' => $b['azimuth_dd'] ?? null,
            ':norm_b' => isset($b['quadrant'], $b['deg'], $b['min']) ? sprintf('%s %d°%02d\'%05.2f" %s', $b['quadrant'][0], $b['deg'], $b['min'], $b['sec'] ?? 0, $b['quadrant'][1]) : null,
            ':orig_d' => $d['value'] ?? null,
            ':orig_u' => $d['unit'] ?? 'm',
            ':dist_m' => $d['meters'] ?? null,
            ':ext_m' => 'PASTED_TEXT',
        ]);
    }

    private function formatTdSummary(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'parcel_id' => (string) $r['parcel_id'],
            'revision' => (int) $r['revision'],
            'survey_plan_id' => $r['survey_plan_id'] !== null ? (int) $r['survey_plan_id'] : null,
            'original_text' => $r['original_text'],
            'source_type' => $r['source_type'],
            'parser_status' => $r['parser_status'],
            'status' => $r['status'],
            'is_current' => (bool) $r['is_current'],
            'confirmed_by' => $r['confirmed_by'] !== null ? (int) $r['confirmed_by'] : null,
            'confirmed_at' => $r['confirmed_at'],
            'bearing_reference' => $r['bearing_reference'],
            'distance_unit' => $r['distance_unit'],
            'point_of_beginning_label' => $r['point_of_beginning_label'],
            'survey_reference' => $r['survey_reference'],
            'version' => (int) $r['version'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }

    private function formatCourseRow(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'technical_description_id' => (int) $c['technical_description_id'],
            'seq' => (int) $c['seq'],
            'from_point_label' => $c['from_point_label'],
            'to_point_label' => $c['to_point_label'],
            'bearing' => [
                'quadrant' => $c['bearing_quadrant'],
                'deg' => $c['deg'] !== null ? (int) $c['deg'] : null,
                'min' => $c['min'] !== null ? (int) $c['min'] : null,
                'sec' => $c['sec'] !== null ? (float) $c['sec'] : null,
                'bearing_type' => $c['bearing_type'],
                'normalized' => $c['normalized_bearing'],
                'azimuth_dd' => $c['azimuth_dd'] !== null ? (float) $c['azimuth_dd'] : null,
                'original' => $c['original_bearing'],
            ],
            'distance' => [
                'value' => $c['original_distance'] !== null ? (float) $c['original_distance'] : null,
                'unit' => $c['original_unit'],
                'meters' => $c['distance_m'] !== null ? (float) $c['distance_m'] : null,
            ],
            'extraction_method' => $c['extraction_method'],
            'confidence' => $c['confidence'] !== null ? (float) $c['confidence'] : null,
            'source_span' => !empty($c['source_text_span']) ? json_decode((string) $c['source_text_span'], true) : null,
            'is_confirmed' => (bool) $c['is_confirmed'],
            'remarks' => $c['remarks'],
        ];
    }

    private function formatTieLineRow(array $tl): array
    {
        return [
            'id' => (int) $tl['id'],
            'tie_point_id' => (int) $tl['tie_point_id'],
            'seq' => (int) $tl['seq'],
            'to_point_label' => $tl['to_point_label'],
            'bearing' => [
                'quadrant' => $tl['bearing_quadrant'],
                'deg' => $tl['deg'] !== null ? (int) $tl['deg'] : null,
                'min' => $tl['min'] !== null ? (int) $tl['min'] : null,
                'sec' => $tl['sec'] !== null ? (float) $tl['sec'] : null,
                'azimuth_dd' => $tl['azimuth_dd'] !== null ? (float) $tl['azimuth_dd'] : null,
                'normalized' => $tl['normalized_bearing'],
                'original' => $tl['original_bearing'],
            ],
            'distance' => [
                'value' => $tl['original_distance'] !== null ? (float) $tl['original_distance'] : null,
                'unit' => $tl['original_unit'],
                'meters' => $tl['distance_m'] !== null ? (float) $tl['distance_m'] : null,
            ],
        ];
    }

    private function verifyIfMatch(Request $request, int $currentVersion): void
    {
        $ifMatch = $request->getHeaderLine('If-Match');
        if ($ifMatch === '') {
            throw new ApiError('IF_MATCH_REQUIRED', 'If-Match header is required', 428);
        }
        $expected = trim($ifMatch, '" ');
        if ($expected !== (string) $currentVersion) {
            throw new ApiError('VERSION_CONFLICT', "Version conflict: expected {$expected}, current {$currentVersion}", 409, [
                'current_version' => $currentVersion,
            ]);
        }
    }

    private function resolveUser(Request $request): int
    {
        $actor = $request->getAttribute('user') ?? $request->getAttribute('actor');
        if (is_array($actor) && isset($actor['id'])) {
            return (int) $actor['id'];
        }
        if (is_object($actor) && isset($actor->id)) {
            return (int) $actor->id;
        }
        return 1;
    }

    private function setUserInSession(int $userId): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT set_config(:key, :val, true)');
            $stmt->execute([':key' => 'app.current_user_id', ':val' => (string) $userId]);
        } catch (\Throwable) {}
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
