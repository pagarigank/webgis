<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-096 — Survey Validation Service Checklist API Tests.
 *
 * Verifies the 12-point FR-125 checklist:
 *  - TD parsed & confirmed (VR-TD-CONFIRMED)
 *  - Tie point found (VR-TIE-FOUND)
 *  - Tie point verified (VR-19)
 *  - Minimum vertices >= 3 (VR-10)
 *  - Valid bearings / GRID reference (VR-01..03, 07..09)
 *  - Valid distances (VR-04..06)
 *  - Compute CRS identified & area of use (VR-20)
 *  - Closed polygon & closure tolerance (VR-11, 12)
 *  - Valid geometry & no self-intersection (VR-13, 14)
 *  - Area plausibility (VR-15)
 *  - Area vs source (VR-16, 17) with mandatory validation aid note
 *  - Cadastral overlap detection (VR-18)
 */
class ValidationTest extends TestCase
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
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'VAL_TEST_%'");
        parent::tearDown();
    }

    private function req(string $method, string $path, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
    }

    /** Helper to create a fully computed parcel */
    private function createComputedParcel(string $code, float $claimedArea = 5000.0, bool $verifyTiePoint = true): array
    {
        // 1. Control Point
        $cpStmt = $this->pdo->prepare(
            'INSERT INTO app.survey_control_points (point_name, status, geom) '
            . 'VALUES (:name, :status, ST_SetSRID(ST_MakePoint(121.0, 14.5), 4326)) '
            . 'RETURNING id'
        );
        $cpStmt->execute([
            ':name'   => 'BBM_' . uniqid(),
            ':status' => $verifyTiePoint ? 'VERIFIED' : 'UNVERIFIED',
        ]);
        $cpId = (int) $cpStmt->fetchColumn();
        $this->createdControlPointIds[] = $cpId;

        // 2. Parcel
        $pRes = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => $code,
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => $claimedArea,
        ]));
        $parcel = json_decode((string) $pRes->getBody(), true)['data'];
        $parcelId = $parcel['id'];
        $this->createdParcelIds[] = $parcelId;

        // 3. Technical Description
        $tdRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/technical-descriptions", [
            'bearing_reference' => 'GRID',
            'claimed_area_sqm'  => $claimedArea,
            'tie_line_bearing'  => 'DUE NORTH',
            'tie_line_distance' => 100.0,
        ]));
        $td = json_decode((string) $tdRes->getBody(), true)['data'];
        $tdId = (int) $td['id'];

        // Associate control point to tie point
        $this->pdo->prepare('INSERT INTO app.tie_points (technical_description_id, control_point_id, sequence, role) VALUES (:tdid, :cp, 1, \'TIE\')')
            ->execute([':cp' => $cpId, ':tdid' => $tdId]);

        // 4. 4 boundary courses (100m x 50m = 5000 sqm)
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
            'reason'         => 'Test setup',
        ]));

        return [
            'parcel_id'      => $parcelId,
            'td_id'          => $tdId,
            'computation_id' => $calc['computation_id'],
        ];
    }

    public function testValidateReturnsFull12PointChecklist(): void
    {
        $setup = $this->createComputedParcel('VAL_TEST_FULL_001', 5000.0, true);
        $parcelId = $setup['parcel_id'];

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/validate"));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $body = json_decode((string) $res->getBody(), true);
        $this->assertTrue($body['success']);
        $data = $body['data'];

        $this->assertSame($parcelId, $data['parcel_id']);
        $this->assertTrue($data['passed'], json_encode($data['blocking_failures']));
        $this->assertTrue($data['can_submit']);
        $this->assertSame(0, $data['blocking_count']);

        // FR-127 validation aid note
        $this->assertSame(
            'Area comparison is a validation aid, not a determination of correctness.',
            $data['area_comparison']['note']
        );

        // Verify all 12 check IDs are present
        $checkIds = array_column($data['checks'], 'id');
        $expectedChecks = [
            'technical_description',
            'tie_point_found',
            'tie_point_verified',
            'course_count',
            'bearing_reference',
            'course_syntax',
            'crs_area_of_use',
            'traverse_closure',
            'geometry_validity',
            'area_plausibility',
            'area_reconciliation',
            'parcel_overlap',
        ];
        foreach ($expectedChecks as $exp) {
            $this->assertContains($exp, $checkIds, "Missing check: {$exp}");
        }
    }

    public function testValidateDetectsUnconfirmedTd(): void
    {
        // Parcel with unconfirmed TD
        $pRes = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => 'VAL_TEST_UNCONF_001',
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 1000.0,
        ]));
        $parcel = json_decode((string) $pRes->getBody(), true)['data'];
        $parcelId = $parcel['id'];
        $this->createdParcelIds[] = $parcelId;

        $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/technical-descriptions", [
            'bearing_reference' => 'GRID',
            'claimed_area_sqm'  => 1000.0,
        ]));

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/validate"));
        $this->assertSame(200, $res->getStatusCode());

        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertFalse($data['passed']);
        $this->assertFalse($data['can_submit']);
        $this->assertGreaterThan(0, $data['blocking_count']);

        $tdCheck = null;
        foreach ($data['checks'] as $c) {
            if ($c['id'] === 'technical_description') {
                $tdCheck = $c;
                break;
            }
        }
        $this->assertNotNull($tdCheck);
        $this->assertSame('FAIL', $tdCheck['status']);
        $this->assertSame('VR-TD-CONFIRMED', $tdCheck['rule']);
        $this->assertSame('blocking', $tdCheck['severity']);
    }

    public function testValidateDetectsUnverifiedTiePointWarning(): void
    {
        $setup = $this->createComputedParcel('VAL_TEST_UNVER_TIE', 5000.0, false);
        $parcelId = $setup['parcel_id'];

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/validate"));
        $this->assertSame(200, $res->getStatusCode());

        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertTrue($data['can_submit']); // warnings do not block submission!
        $this->assertGreaterThanOrEqual(1, $data['warning_count']);

        $tieCheck = null;
        foreach ($data['checks'] as $c) {
            if ($c['id'] === 'tie_point_verified') {
                $tieCheck = $c;
                break;
            }
        }
        $this->assertNotNull($tieCheck);
        $this->assertSame('WARN', $tieCheck['status']);
        $this->assertSame('VR-19', $tieCheck['rule']);
        $this->assertSame('warning', $tieCheck['severity']);
    }

    public function testValidateDetectsAreaDiscrepancyWarning(): void
    {
        // Parcel with source area 6000 sqm but computed area 5000 sqm (20% diff -> VR-16 warning)
        $setup = $this->createComputedParcel('VAL_TEST_AREA_DIFF', 6000.0, true);
        $parcelId = $setup['parcel_id'];

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/validate"));
        $this->assertSame(200, $res->getStatusCode());

        $data = json_decode((string) $res->getBody(), true)['data'];
        $areaCheck = null;
        foreach ($data['checks'] as $c) {
            if ($c['id'] === 'area_reconciliation') {
                $areaCheck = $c;
                break;
            }
        }
        $this->assertNotNull($areaCheck);
        $this->assertSame('WARN', $areaCheck['status']);
        $this->assertSame('VR-16', $areaCheck['rule']);
    }

    public function testGetLatestValidationReturnsCachedResult(): void
    {
        $setup = $this->createComputedParcel('VAL_TEST_CACHED_001', 5000.0, true);
        $parcelId = $setup['parcel_id'];

        // Validate first
        $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/validate"));

        // GET latest
        $getRes = $this->handle($this->req('GET', "/api/v1/parcels/{$parcelId}/validation"));
        $this->assertSame(200, $getRes->getStatusCode());

        $data = json_decode((string) $getRes->getBody(), true)['data'];
        $this->assertSame($parcelId, $data['parcel_id']);
        $this->assertTrue($data['passed']);
        $this->assertNotEmpty($data['checks']);
    }
}
