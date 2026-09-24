<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-098 — Submission Guards Integration Tests.
 *
 * Requirements:
 *  - Block submission on any blocking failure with specific reason.
 *  - AC: a blocking error returns CLOSURE_EXCEEDS_TOLERANCE (422) or VALIDATION_FAILED (422) naming the rule.
 *  - Warnings persist to approval (carried forward to reviewers).
 *  - Guard works both via POST /parcels/{id}/submit and PATCH /parcels/{id} (status: 'SUBMITTED').
 */
class SubmitGuardTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private array $createdParcelIds = [];
    private array $createdControlPointIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $user = $this->createMockUser($this->pdo, [
            'parcel.view', 'parcel.create', 'parcel.update', 'parcel.delete',
            'survey.view', 'survey.create', 'survey.update',
        ], ['SURVEY_OFFICER']);
        $this->token = $user['token'];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdParcelIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $this->createdParcelIds));
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
            $this->createdParcelIds = [];
        }
        if (!empty($this->createdControlPointIds)) {
            $cpIds = implode(', ', $this->createdControlPointIds);
            $this->pdo->exec("DELETE FROM app.survey_control_points WHERE id IN ($cpIds)");
            $this->createdControlPointIds = [];
        }
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'SUBMIT_GUARD_%'");
        parent::tearDown();
    }

    private function req(string $method, string $path, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
    }

    /** Helper to create a valid computed parcel */
    private function createReadyParcel(string $code, bool $verifiedTiePoint = true): array
    {
        // 1. Control Point
        $cpStmt = $this->pdo->prepare(
            'INSERT INTO app.survey_control_points (point_name, status, geom) '
            . 'VALUES (:name, :status, ST_SetSRID(ST_MakePoint(121.0, 14.5), 4326)) '
            . 'RETURNING id'
        );
        $cpStmt->execute([
            ':name'   => 'CP_' . uniqid(),
            ':status' => $verifiedTiePoint ? 'VERIFIED' : 'UNVERIFIED',
        ]);
        $cpId = (int) $cpStmt->fetchColumn();
        $this->createdControlPointIds[] = $cpId;

        // 2. Parcel
        $pRes = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => $code,
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 5000.0,
        ]));
        $parcel = json_decode((string) $pRes->getBody(), true)['data'];
        $parcelId = $parcel['id'];
        $this->createdParcelIds[] = $parcelId;

        // 3. Technical Description
        $tdRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/technical-descriptions", [
            'bearing_reference' => 'GRID',
            'claimed_area_sqm'  => 5000.0,
            'tie_line_bearing'  => 'DUE NORTH',
            'tie_line_distance' => 100.0,
        ]));
        $td = json_decode((string) $tdRes->getBody(), true)['data'];
        $tdId = (int) $td['id'];

        $this->pdo->prepare('INSERT INTO app.tie_points (technical_description_id, control_point_id, sequence, role) VALUES (:tdid, :cp, 1, \'TIE\')')
            ->execute([':cp' => $cpId, ':tdid' => $tdId]);

        // 4. Courses
        $courses = [
            ['from_corner' => '1', 'to_corner' => '2', 'bearing_raw' => 'DUE EAST',  'distance_raw' => 100.0],
            ['from_corner' => '2', 'to_corner' => '3', 'bearing_raw' => 'DUE NORTH', 'distance_raw' => 50.0],
            ['from_corner' => '3', 'to_corner' => '4', 'bearing_raw' => 'DUE WEST',  'distance_raw' => 100.0],
            ['from_corner' => '4', 'to_corner' => '1', 'bearing_raw' => 'DUE SOUTH', 'distance_raw' => 50.0],
        ];
        foreach ($courses as $c) {
            $this->handle($this->req('POST', "/api/v1/technical-descriptions/{$tdId}/courses", $c));
        }

        // 5. Confirm TD
        $this->handle($this->req('POST', "/api/v1/technical-descriptions/{$tdId}/confirm"));

        // 6. Calculate
        $calcRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/calculate", [
            'technical_description_id' => $tdId,
            'compute_crs'              => 'EPSG:3123',
        ]));
        $calc = json_decode((string) $calcRes->getBody(), true)['data'];

        // 7. Accept computation
        $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/accept-computation", [
            'computation_id' => $calc['computation_id'],
            'reason'         => 'Test setup accept',
        ]));

        return [
            'parcel_id'      => $parcelId,
            'computation_id' => $calc['computation_id'],
            'version'        => 2,
        ];
    }

    public function testSubmitBlockedWhenNoComputationExists(): void
    {
        // Brand new parcel without TD or computation
        $pRes = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => 'SUBMIT_GUARD_EMPTY_01',
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 1000.0,
        ]));
        $parcel = json_decode((string) $pRes->getBody(), true)['data'];
        $parcelId = $parcel['id'];
        $this->createdParcelIds[] = $parcelId;

        // Try POST /parcels/{id}/submit
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/submit"));
        $this->assertSame(422, $res->getStatusCode(), (string) $res->getBody());

        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertNotEmpty($body['error']['details']['blocking_failures']);

        // Check parcel is still DRAFT
        $checkRes = $this->handle($this->req('GET', "/api/v1/parcels/{$parcelId}"));
        $p = json_decode((string) $checkRes->getBody(), true)['data'];
        $this->assertSame('DRAFT', $p['status']);
    }

    public function testSubmitBlockedWhenClosureExceedsTolerance(): void
    {
        $setup = $this->createReadyParcel('SUBMIT_GUARD_CLOSURE_01', true);
        $parcelId = $setup['parcel_id'];
        $compId = $setup['computation_id'];

        // Force computation closure status to EXCEEDS_TOLERANCE
        $this->pdo->prepare("UPDATE app.parcel_computations SET closure_status = 'EXCEEDS_TOLERANCE', linear_error_m = 0.8500 WHERE id = :cid")
            ->execute([':cid' => $compId]);

        // Attempt submit -> Expect 422 CLOSURE_EXCEEDS_TOLERANCE
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/submit"));
        $this->assertSame(422, $res->getStatusCode(), (string) $res->getBody());

        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame('CLOSURE_EXCEEDS_TOLERANCE', $body['error']['code']);
        $this->assertStringContainsString('0.85', $body['error']['message']);
    }

    public function testSubmitBlockedWhenStatusAlreadySubmittedOrInvalidState(): void
    {
        $setup = $this->createReadyParcel('SUBMIT_GUARD_STATE_01', false);
        $parcelId = $setup['parcel_id'];

        // Submit once -> 200
        $res1 = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/submit"));
        $this->assertSame(200, $res1->getStatusCode());

        // Submit again when already SUBMITTED -> 400 INVALID_STATE
        $res2 = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/submit"));
        $this->assertSame(400, $res2->getStatusCode());
        $body = json_decode((string) $res2->getBody(), true);
        $this->assertSame('INVALID_STATE', $body['error']['code']);
    }

    public function testSubmitSucceedsWithWarningsCarriedForward(): void
    {
        // Parcel with unverified tie point (VR-19 warning)
        $setup = $this->createReadyParcel('SUBMIT_GUARD_SUCCESS_01', false);
        $parcelId = $setup['parcel_id'];

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/submit", [
            'change_reason' => 'Submitting verified survey for cadastral approval',
        ]));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $body = json_decode((string) $res->getBody(), true);
        $this->assertTrue($body['success']);
        $data = $body['data'];

        $this->assertSame('SUBMITTED', $data['parcel']['status']);
        $this->assertGreaterThan(1, $data['parcel']['version']);
        $this->assertTrue($data['validation']['can_submit']);
        $this->assertGreaterThanOrEqual(1, $data['validation']['warning_count']);

        // Verify version history carries forward warnings
        $vRes = $this->handle($this->req('GET', "/api/v1/parcels/{$parcelId}/versions"));
        $versionsData = json_decode((string) $vRes->getBody(), true)['data'];
        $versions = $versionsData['data'] ?? $versionsData;

        $latestVer = $versions[0];
        $this->assertSame('SUBMITTED', $latestVer['status']);
        $this->assertStringContainsString('warning(s)', $latestVer['change_summary']);
    }
}
