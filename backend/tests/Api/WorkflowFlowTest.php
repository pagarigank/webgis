<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-101 — Parcel transitions API: full workflow flow.
 *
 * ACs covered here:
 *  - A parcel with a valid computation walks DRAFT → SUBMITTED →
 *    UNDER_REVIEW → VERIFIED → APPROVED → PUBLISHED via the engine.
 *  - Approval is impossible while a blocking validation failure exists.
 *  - Every transition is audited with actor, reason, and comment.
 *  - A return cycle (SUBMITTED → RETURNED → SUBMITTED) works with a reason.
 *  - The validation guard re-evaluates at APPROVE time (state may have
 *    changed since SUBMIT).
 */
class WorkflowFlowTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private int $userId;
    private array $createdParcelIds = [];
    private array $createdControlPointIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'WF_FLOW_%'");
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'wf_creator'");
        $user = $this->createMockUser($this->pdo, [
            'parcel.view', 'parcel.create', 'parcel.update',
            'parcel.submit', 'parcel.review', 'parcel.verify', 'parcel.approve', 'parcel.publish', 'parcel.archive',
            'survey.view', 'survey.create', 'survey.update',
        ], ['GIS_MANAGER']);
        $this->token = $user['token'];
        $this->userId = $user['id'];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdParcelIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $this->createdParcelIds));
            $this->pdo->exec("DELETE FROM app.approval_actions WHERE instance_id IN (
                SELECT id FROM app.workflow_instances WHERE entity_type = 'PARCEL' AND entity_id IN ($ids)
            )");
            $this->pdo->exec("DELETE FROM app.workflow_instances WHERE entity_type = 'PARCEL' AND entity_id IN ($ids)");
            $this->pdo->exec("DELETE FROM app.notifications WHERE entity_type = 'PARCEL' AND entity_id IN ($ids)");
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
            $this->createdParcelIds = [];
        }
        if (!empty($this->createdControlPointIds)) {
            $cpIds = implode(', ', $this->createdControlPointIds);
            $this->pdo->exec("DELETE FROM app.survey_control_points WHERE id IN ($cpIds)");
        }
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'WF_FLOW_%'");
        parent::tearDown();
    }

    private function req(string $method, string $path, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
    }

    private function transition(string $parcelId, string $action, array $extra = []): array
    {
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/transitions", array_merge([
            'action' => $action,
        ], $extra)));
        return [
            'status' => $res->getStatusCode(),
            'body'   => json_decode((string) $res->getBody(), true),
        ];
    }

    /** Build a fully valid, computed, accepted parcel (ready for submission). */
    private function createReadyParcel(string $code): array
    {
        $cpStmt = $this->pdo->prepare(
            'INSERT INTO app.survey_control_points (point_name, status, geom)
             VALUES (:name, :status, ST_SetSRID(ST_MakePoint(121.0, 14.5), 4326))
             RETURNING id'
        );
        $cpStmt->execute([':name' => 'CP_' . uniqid(), ':status' => 'VERIFIED']);
        $cpId = (int) $cpStmt->fetchColumn();
        $this->createdControlPointIds[] = $cpId;

        $pRes = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => $code,
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 5000.0,
        ]));
        $this->assertContains($pRes->getStatusCode(), [200, 201], (string) $pRes->getBody());
        $parcel = json_decode((string) $pRes->getBody(), true)['data'];
        $parcelId = $parcel['id'];
        $this->createdParcelIds[] = $parcelId;

        // Set the creator to the test user so notifications can be asserted.
        $this->pdo->prepare('UPDATE app.parcels SET created_by = :uid WHERE id = :pid')
            ->execute([':uid' => $this->userId, ':pid' => $parcelId]);

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

        $courses = [
            ['from_corner' => '1', 'to_corner' => '2', 'bearing_raw' => 'DUE EAST',  'distance_raw' => 100.0],
            ['from_corner' => '2', 'to_corner' => '3', 'bearing_raw' => 'DUE NORTH', 'distance_raw' => 50.0],
            ['from_corner' => '3', 'to_corner' => '4', 'bearing_raw' => 'DUE WEST',  'distance_raw' => 100.0],
            ['from_corner' => '4', 'to_corner' => '1', 'bearing_raw' => 'DUE SOUTH', 'distance_raw' => 50.0],
        ];
        foreach ($courses as $c) {
            $this->handle($this->req('POST', "/api/v1/technical-descriptions/{$tdId}/courses", $c));
        }

        $this->handle($this->req('POST', "/api/v1/technical-descriptions/{$tdId}/confirm"));

        $calcRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/calculate", [
            'technical_description_id' => $tdId,
            'compute_crs'              => 'EPSG:3123',
        ]));
        $calc = json_decode((string) $calcRes->getBody(), true)['data'];

        $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/accept-computation", [
            'computation_id' => $calc['computation_id'],
            'reason'         => 'Test setup accept',
        ]));

        return ['parcel_id' => $parcelId, 'computation_id' => $calc['computation_id'], 'td_id' => $tdId];
    }

    public function testFullApprovalFlowWithValidComputation(): void
    {
        $setup = $this->createReadyParcel('WF_FLOW_FULL_01');
        $pid = $setup['parcel_id'];

        // DRAFT -> SUBMITTED (validation guard passes)
        $r = $this->transition($pid, 'SUBMIT', ['reason' => 'Ready for review']);
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('SUBMITTED', $r['body']['data']['to_state']);

        // SUBMITTED -> UNDER_REVIEW
        $r = $this->transition($pid, 'START_REVIEW');
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('UNDER_REVIEW', $r['body']['data']['to_state']);

        // UNDER_REVIEW -> VERIFIED
        $r = $this->transition($pid, 'VERIFY');
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('VERIFIED', $r['body']['data']['to_state']);

        // VERIFIED -> APPROVED (comment mandatory, FR-137)
        $r = $this->transition($pid, 'APPROVE', [
            'comment'                => 'Survey checks out',
            'accepted_computation_id'=> $setup['computation_id'],
        ]);
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('APPROVED', $r['body']['data']['to_state']);

        // APPROVED -> PUBLISHED
        $r = $this->transition($pid, 'PUBLISH');
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('PUBLISHED', $r['body']['data']['to_state']);

        // Parcel reflects the final status.
        $get = json_decode((string) $this->handle($this->req('GET', "/api/v1/parcels/{$pid}"))->getBody(), true);
        $this->assertSame('PUBLISHED', $get['data']['status']);

        // Full history recorded with actor + reason + comment.
        $h = json_decode((string) $this->handle($this->req('GET', "/api/v1/parcels/{$pid}/transitions/history"))->getBody(), true)['data']['history'];
        $this->assertCount(5, $h);
        $actions = array_column($h, 'action_code');
        $this->assertSame(['PUBLISH', 'APPROVE', 'VERIFY', 'START_REVIEW', 'SUBMIT'], $actions);

        $approveRow = $h[1];
        $this->assertSame('Survey checks out', $approveRow['comment']);
        $this->assertSame($setup['computation_id'], (int) $approveRow['accepted_computation_id']);
        $this->assertNotEmpty($approveRow['actor']);

        // APPROVE must record the accepted computation (FR-137).
        $this->assertSame($setup['computation_id'], (int) $approveRow['accepted_computation_id']);
    }

    public function testApprovalImpossibleWhileBlockingValidationFailureExists(): void
    {
        $setup = $this->createReadyParcel('WF_FLOW_BLOCKED_01');
        $pid = $setup['parcel_id'];

        // Walk to VERIFIED with a healthy computation.
        $this->assertSame(200, $this->transition($pid, 'SUBMIT', ['reason' => 'ok'])['status']);
        $this->assertSame(200, $this->transition($pid, 'START_REVIEW')['status']);
        $this->assertSame(200, $this->transition($pid, 'VERIFY')['status']);

        // Corrupt the computation AFTER submission: the APPROVE guard must
        // re-evaluate current state, not trust the earlier SUBMIT.
        $this->pdo->prepare("UPDATE app.parcel_computations SET closure_status = 'EXCEEDS_TOLERANCE', linear_error_m = 0.9 WHERE id = :cid")
            ->execute([':cid' => $setup['computation_id']]);

        $r = $this->transition($pid, 'APPROVE', ['comment' => 'Looks fine to me']);
        $this->assertSame(422, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('CLOSURE_EXCEEDS_TOLERANCE', $r['body']['error']['code']);

        // Parcel remains VERIFIED, not APPROVED.
        $get = json_decode((string) $this->handle($this->req('GET', "/api/v1/parcels/{$pid}"))->getBody(), true);
        $this->assertSame('VERIFIED', $get['data']['status']);
    }

    public function testReturnCycleRequiresReasonAndCarriesWarnings(): void
    {
        $setup = $this->createReadyParcel('WF_FLOW_RETURN_01');
        $pid = $setup['parcel_id'];

        $this->assertSame(200, $this->transition($pid, 'SUBMIT', ['reason' => 'ok'])['status']);

        // RETURN without a reason is rejected (FR-137).
        $r = $this->transition($pid, 'RETURN');
        $this->assertSame(422, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('VALIDATION_FAILED', $r['body']['error']['code']);

        // RETURN with a reason works.
        $r = $this->transition($pid, 'RETURN', ['reason' => 'Course 3 bearing unclear']);
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('RETURNED', $r['body']['data']['to_state']);

        // RETURNED -> SUBMITTED again (guard re-runs, still valid).
        $r = $this->transition($pid, 'SUBMIT', ['reason' => 'Fixed the bearing']);
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('SUBMITTED', $r['body']['data']['to_state']);

        $h = json_decode((string) $this->handle($this->req('GET', "/api/v1/parcels/{$pid}/transitions/history"))->getBody(), true)['data']['history'];
        $this->assertSame(['SUBMIT', 'RETURN', 'SUBMIT'], array_column($h, 'action_code'));
        $this->assertSame('Course 3 bearing unclear', $h[1]['reason']);
    }

    public function testTransitionInsertsNotificationForCreator(): void
    {
        $setup = $this->createReadyParcel('WF_FLOW_NOTIFY_01');
        $pid = $setup['parcel_id'];

        // The engine never notifies the actor about their own action, so the
        // creator must be a distinct user for a notification to be produced.
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version)
            VALUES ('wf_creator', 'wf_creator@example.com', 'dummy', 'Creator',
                    (SELECT id FROM app.organizations WHERE code = 'TESTORG'), 'ACTIVE', 1)");
        $creatorId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'wf_creator'")->fetchColumn();
        $this->pdo->prepare('UPDATE app.parcels SET created_by = :uid WHERE id = :pid')
            ->execute([':uid' => $creatorId, ':pid' => $pid]);

        $this->assertSame(200, $this->transition($pid, 'SUBMIT', ['reason' => 'ok'])['status']);

        $stmt = $this->pdo->prepare(
            "SELECT count(*) FROM app.notifications
             WHERE entity_type = 'PARCEL' AND entity_id = :pid AND type = 'WORKFLOW_SUBMIT' AND user_id = :uid"
        );
        $stmt->execute([':pid' => $pid, ':uid' => $creatorId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Creator receives a WORKFLOW_SUBMIT notification');
    }

    public function testArchiveRequiresReasonFromDraft(): void
    {
        $parcel = $this->createParcelLike('WF_FLOW_ARCH_01');

        // Without a reason -> rejected.
        $r = $this->transition($parcel['id'], 'ARCHIVE');
        $this->assertSame(422, $r['status'], (string) json_encode($r['body']));

        // With a reason -> archived (terminal).
        $r = $this->transition($parcel['id'], 'ARCHIVE', ['reason' => 'Duplicate record']);
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('ARCHIVED', $r['body']['data']['to_state']);

        // Terminal: nothing leaves ARCHIVED.
        $r = $this->transition($parcel['id'], 'SUBMIT', ['reason' => 'zombie']);
        $this->assertSame(422, $r['status']);
        $this->assertSame('INVALID_TRANSITION', $r['body']['error']['code']);
    }

    /** Minimal parcel without survey data (for archive tests). */
    private function createParcelLike(string $code): array
    {
        $res = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => $code,
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 1000.0,
        ]));
        $this->assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());
        $parcel = json_decode((string) $res->getBody(), true)['data'];
        $this->createdParcelIds[] = $parcel['id'];
        return $parcel;
    }
}
