<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-102 / FR-141 — Editing approved records.
 *
 * ACs covered here:
 *  - Editing an APPROVED parcel creates a new version and returns the parcel
 *    to the configured workflow state (default DRAFT).
 *  - The previously approved version remains retrievable and unchanged.
 *  - A change reason is mandatory for an approved-record edit (FR-137 family).
 *  - The reopen runs through the workflow engine: it is permission-gated
 *    (parcel.approve), audited in approval_actions, and notifies the creator.
 *  - PATCH cannot set the post-reopen status directly — the target state is
 *    workflow-controlled (FR-136).
 *  - WORKFLOW_APPROVED_EDIT_TARGET_STATE reconfigures the target state
 *    (e.g. UNDER_REVIEW) and the cycle honors it.
 */
class ApprovedEditTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private int $userId;
    private array $createdParcelIds = [];
    private array $createdControlPointIds = [];
    private bool $createdReopenTransition = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'WF_AE_%'");
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'wf_ae_creator'");
        $user = $this->createMockUser($this->pdo, [
            'parcel.view', 'parcel.create', 'parcel.update',
            'parcel.submit', 'parcel.review', 'parcel.verify', 'parcel.approve',
            'survey.view', 'survey.create',
        ], ['GIS_MANAGER']);
        $this->token = $user['token'];
        $this->userId = $user['id'];

        $this->createdReopenTransition = $this->ensureReopenTransition();
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
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'WF_AE_%'");
        $this->pdo->exec("DELETE FROM app.users WHERE username IN ('wf_ae_creator', 'wf_ae_encoder')");
        $this->pdo->exec("DELETE FROM app.roles WHERE code = 'AE_ENCODER'");
        $this->pdo->exec("DELETE FROM app.system_settings WHERE key = 'WORKFLOW_APPROVED_EDIT_TARGET_STATE'");
        if ($this->createdReopenTransition) {
            $this->pdo->exec("DELETE FROM app.workflow_transitions WHERE action_code = 'REOPEN'");
        }
        parent::tearDown();
    }

    /**
     * The REOPEN transition is data: make sure the row this task relies on
     * exists (mirrors SystemSeeder), and remember whether WE inserted it so
     * the environment is left as we found it.
     */
    private function ensureReopenTransition(): bool
    {
        $exists = $this->pdo->query(
            "SELECT count(*) FROM app.workflow_transitions t
             JOIN app.workflow_states s1 ON s1.id = t.from_state_id AND s1.code = 'APPROVED'
             JOIN app.workflow_states s2 ON s2.id = t.to_state_id AND s2.code = 'DRAFT'
             WHERE t.action_code = 'REOPEN'"
        )->fetchColumn();
        if ((int) $exists > 0) {
            return false;
        }
        $this->pdo->exec(
            "INSERT INTO app.workflow_transitions
                (definition_id, from_state_id, to_state_id, action_code, required_permission, requires_reason, requires_comment, guard_expression)
             SELECT d.id, s1.id, s2.id, 'REOPEN', 'parcel.approve', true, false, NULL
             FROM app.workflow_definitions d
             JOIN app.workflow_states s1 ON s1.definition_id = d.id AND s1.code = 'APPROVED'
             JOIN app.workflow_states s2 ON s2.definition_id = d.id AND s2.code = 'DRAFT'
             WHERE d.code = 'PARCEL_APPROVAL'"
        );
        return true;
    }

    private function req(string $method, string $path, array $data = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
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

    private function patch(string $parcelId, array $data, int $ifMatchVersion): array
    {
        $res = $this->handle($this->req('PATCH', "/api/v1/parcels/{$parcelId}", $data, [
            'If-Match' => (string) $ifMatchVersion,
        ]));
        return [
            'status' => $res->getStatusCode(),
            'body'   => json_decode((string) $res->getBody(), true),
        ];
    }

    private function getParcel(string $parcelId): array
    {
        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$parcelId}"));
        return json_decode((string) $res->getBody(), true)['data'];
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

        // Distinct creator so the reopen notification (FR-140) can be asserted.
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version)
            VALUES ('wf_ae_creator', 'wf_ae_creator@example.com', 'dummy', 'Creator',
                    (SELECT id FROM app.organizations WHERE code = 'TESTORG'), 'ACTIVE', 1)");
        $creatorId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'wf_ae_creator'")->fetchColumn();
        $this->pdo->prepare('UPDATE app.parcels SET created_by = :uid WHERE id = :pid')
            ->execute([':uid' => $creatorId, ':pid' => $parcelId]);

        $tdRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/technical-descriptions", [
            'bearing_reference' => 'GRID',
            'claimed_area_sqm'  => 5000.0,
            'tie_line_bearing'  => 'DUE NORTH',
            'tie_line_distance' => 100.0,
        ]));
        $td = json_decode((string) $tdRes->getBody(), true)['data'];
        $tdId = (int) $td['id'];

        $this->pdo->prepare("INSERT INTO app.tie_points (technical_description_id, control_point_id, sequence, role) VALUES (:tdid, :cp, 1, 'TIE')")
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

    /** Walk DRAFT → SUBMITTED → UNDER_REVIEW → VERIFIED → APPROVED. */
    private function walkToApproved(string $pid, int $computationId): void
    {
        $this->assertSame(200, $this->transition($pid, 'SUBMIT', ['reason' => 'ok'])['status']);
        $this->assertSame(200, $this->transition($pid, 'START_REVIEW')['status']);
        $this->assertSame(200, $this->transition($pid, 'VERIFY')['status']);
        $r = $this->transition($pid, 'APPROVE', [
            'comment'                => 'Survey checks out',
            'accepted_computation_id'=> $computationId,
        ]);
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
    }

    public function testApprovedEditCreatesNewVersionAndReturnsToDraft(): void
    {
        $setup = $this->createReadyParcel('WF_AE_FULL_01');
        $pid = $setup['parcel_id'];
        $this->walkToApproved($pid, $setup['computation_id']);

        $approved = $this->getParcel($pid);
        $this->assertSame('APPROVED', $approved['status']);
        $approvedVersion = (int) $approved['version'];
        $approvedLot = $approved['lot_number'];

        // Edit the approved record with a reason.
        $r = $this->patch($pid, [
            'lot_number'    => 'LOT-AE-EDITED',
            'change_reason' => 'Correction requested by the assessor',
        ], $approvedVersion);
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('DRAFT', $r['body']['data']['status'], 'FR-141: the parcel returns to the configured state');
        $this->assertSame($approvedVersion + 2, (int) $r['body']['data']['version'], 'a new version was created (reopen + edit)');
        $this->assertSame('LOT-AE-EDITED', $r['body']['data']['lot_number'], 'the edit itself was applied');

        // 1. The previously approved version is preserved as its own row:
        //    status APPROVED and the PRE-edit attribute values and geometry.
        $fetchApprovedRow = fn (): array => json_decode(
            (string) $this->handle($this->req('GET', "/api/v1/parcels/{$pid}/versions/{$approvedVersion}"))->getBody(),
            true
        )['data'];
        $approvedRowAfter = $fetchApprovedRow();
        $this->assertSame('APPROVED', $approvedRowAfter['status'], 'the preserved row keeps the approved status');
        $this->assertSame($approvedVersion, (int) $approvedRowAfter['version']);
        $this->assertSame($approvedLot, $approvedRowAfter['snapshot']['lot_number'] ?? null, 'the approved attribute values are preserved');
        $this->assertSame($approved['geometry'], $approvedRowAfter['geometry'], 'the approved geometry is preserved');

        // 2. The appended version rows are exactly: the approved-state capture
        //    and the reopened+edited state; history is append-only.
        $versions = json_decode(
            (string) $this->handle($this->req('GET', "/api/v1/parcels/{$pid}/versions"))->getBody(),
            true
        )['data']['data'];
        $this->assertCount(4, $versions, 'created + accepted + approved capture + reopened+edited');
        $this->assertSame($approvedVersion, (int) $versions[1]['version']);
        $this->assertSame('APPROVED', $versions[1]['status']);
        $this->assertSame($approvedVersion + 2, (int) $versions[0]['version']);
        $this->assertSame('DRAFT', $versions[0]['status']);

        // 3. The reopen is recorded in the workflow history with its reason.
        $h = json_decode(
            (string) $this->handle($this->req('GET', "/api/v1/parcels/{$pid}/transitions/history"))->getBody(),
            true
        )['data']['history'];
        $this->assertSame('REOPEN', $h[0]['action_code'], 'the approved-edit cycle is workflow-audited');
        $this->assertSame('APPROVED', $h[0]['from_state']);
        $this->assertSame('DRAFT', $h[0]['to_state']);
        $this->assertSame('Correction requested by the assessor', $h[0]['reason']);

        // 4. The creator is notified of the reopen (FR-140).
        $creatorId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'wf_ae_creator'")->fetchColumn();
        $stmt = $this->pdo->prepare(
            "SELECT count(*) FROM app.notifications
             WHERE entity_type = 'PARCEL' AND entity_id = :pid AND type = 'WORKFLOW_REOPEN' AND user_id = :uid"
        );
        $stmt->execute([':pid' => $pid, ':uid' => $creatorId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'creator receives a WORKFLOW_REOPEN notification');

        // 5. Re-fetch the approved row after all later mutations: still byte-
        //    identical — history rows are append-only, never rewritten.
        $this->assertSame($approvedRowAfter, $fetchApprovedRow(), 'the approved row is never rewritten');
    }

    public function testApprovedEditRequiresChangeReason(): void
    {
        $setup = $this->createReadyParcel('WF_AE_NOREASON_01');
        $pid = $setup['parcel_id'];
        $this->walkToApproved($pid, $setup['computation_id']);
        $approvedVersion = (int) $this->getParcel($pid)['version'];

        $r = $this->patch($pid, ['lot_number' => 'LOT-X'], $approvedVersion);
        $this->assertSame(422, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('VALIDATION_FAILED', $r['body']['error']['code']);

        // Nothing changed: the parcel is still APPROVED at the same version.
        $after = $this->getParcel($pid);
        $this->assertSame('APPROVED', $after['status']);
        $this->assertSame($approvedVersion, (int) $after['version']);
    }

    public function testApprovedEditIsPermissionGated(): void
    {
        $setup = $this->createReadyParcel('WF_AE_PERM_01');
        $pid = $setup['parcel_id'];
        $this->walkToApproved($pid, $setup['computation_id']);
        $approvedVersion = (int) $this->getParcel($pid)['version'];

        // A user with parcel.update but WITHOUT parcel.approve (the REOPEN
        // permission) must be refused — the reopen is a workflow action.
        // createMockUser reuses the shared 'testuser' fixture, which has
        // accumulated grants in earlier tests, so build a dedicated user.
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version)
            VALUES ('wf_ae_encoder', 'wf_ae_encoder@example.com', 'dummy', 'Encoder',
                    (SELECT id FROM app.organizations WHERE code = 'TESTORG'), 'ACTIVE', 1)");
        $encoderId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'wf_ae_encoder'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('AE_ENCODER', 'AE Encoder', false) ON CONFLICT DO NOTHING");
        $encoderRoleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'AE_ENCODER'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($encoderId, $encoderRoleId) ON CONFLICT DO NOTHING");
        foreach (['parcel.view', 'parcel.update'] as $perm) {
            $this->pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('$perm', '$perm') ON CONFLICT DO NOTHING");
            $permId = (int) $this->pdo->query("SELECT id FROM app.permissions WHERE code = '$perm'")->fetchColumn();
            $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($encoderRoleId, $permId) ON CONFLICT DO NOTHING");
        }
        $encoderToken = \Firebase\JWT\JWT::encode(
            ['sub' => (string) $encoderId, 'v' => 1, 'exp' => time() + 3600],
            getenv('JWT_SECRET') ?: 'dummy_secret',
            'HS256'
        );

        $res = $this->handle(
            $this->createJsonRequest('PATCH', "/api/v1/parcels/{$pid}", [
                'lot_number'    => 'SNEAKY',
                'change_reason' => 'bypass attempt',
            ])
                ->withHeader('Authorization', 'Bearer ' . $encoderToken)
                ->withHeader('If-Match', (string) $approvedVersion)
                ->withHeader('Accept', 'application/json')
        );
        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('PERMISSION_DENIED', $body['error']['code']);
        $this->assertStringContainsString('parcel.approve', (string) $body['error']['message'], 'the error names the missing permission');

        // The record is untouched.
        $after = $this->getParcel($pid);
        $this->assertSame('APPROVED', $after['status']);
        $this->assertSame($approvedVersion, (int) $after['version']);
        $this->assertNotSame('SNEAKY', $after['lot_number']);
    }

    public function testPatchCannotSetStatusDirectlyOnAnApprovedParcel(): void
    {
        $setup = $this->createReadyParcel('WF_AE_STATUS_01');
        $pid = $setup['parcel_id'];
        $this->walkToApproved($pid, $setup['computation_id']);
        $approvedVersion = (int) $this->getParcel($pid)['version'];

        // Explicit status changes stay workflow-controlled (FR-136): even the
        // reopen's target state cannot be requested through PATCH.
        $r = $this->patch($pid, [
            'status'        => 'DRAFT',
            'change_reason' => 'wants draft',
        ], $approvedVersion);
        $this->assertSame(400, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('INVALID_STATE', $r['body']['error']['code']);

        $after = $this->getParcel($pid);
        $this->assertSame('APPROVED', $after['status']);
    }

    public function testConfiguredTargetStateIsHonored(): void
    {
        $this->pdo->exec(
            "INSERT INTO app.system_settings (key, value, description)
             VALUES ('WORKFLOW_APPROVED_EDIT_TARGET_STATE', '{\"value\": \"UNDER_REVIEW\"}', 'FR-141 approved-edit target state')
             ON CONFLICT (key) DO UPDATE SET value = '{\"value\": \"UNDER_REVIEW\"}'"
        );

        $setup = $this->createReadyParcel('WF_AE_CFG_01');
        $pid = $setup['parcel_id'];
        $this->walkToApproved($pid, $setup['computation_id']);
        $approvedVersion = (int) $this->getParcel($pid)['version'];

        $r = $this->patch($pid, [
            'remarks'       => 'reopened straight to review',
            'change_reason' => 'minor correction, reviewer asked for it',
        ], $approvedVersion);
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('UNDER_REVIEW', $r['body']['data']['status'], 'the configured target state is honored');
        $this->assertSame($approvedVersion + 2, (int) $r['body']['data']['version']);

        // The reopen row records the configured target.
        $h = json_decode(
            (string) $this->handle($this->req('GET', "/api/v1/parcels/{$pid}/transitions/history"))->getBody(),
            true
        )['data']['history'];
        $this->assertSame('REOPEN', $h[0]['action_code']);
        $this->assertSame('UNDER_REVIEW', $h[0]['to_state']);
    }
}
