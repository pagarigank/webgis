<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-074 — Control point verification, dependents, and coordinate-edit
 * impact (FR-078 / FR-081).
 *
 * ACs covered:
 *  - `verify` records the verifier and the moment of verification and moves
 *    the point to VERIFIED; it requires control_point.verify.
 *  - `dependents` lists the parcels whose computations used this point
 *    (current TD tie points / current computation's TD tie points).
 *  - Editing a control point's coordinates flags the dependent parcels for
 *    review (control_review_pending) and returns them in data.impact while
 *    leaving every existing computation byte-identical.
 *  - A non-coordinate edit does not flag dependents.
 */
class ControlPointImpactTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    /** @var array<int,string> Control point ids created during the test, cleaned in tearDown. */
    private array $createdIds = [];

    private string $adminToken;

    private int $adminId;

    private const CP_PERMS = [
        'control_point.view', 'control_point.create', 'control_point.update', 'control_point.verify',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();

        $this->cleanup();

        $user = $this->createMockUser($this->pdo, self::CP_PERMS, ['SYS_ADMIN']);
        $this->adminId = (int) $user['id'];
        $this->adminToken = $user['token'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        // FK order: computations -> tie_points -> technical_descriptions -> parcels.
        $this->pdo->exec("DELETE FROM app.parcel_computations WHERE parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'CP074_%')");
        $this->pdo->exec("DELETE FROM app.tie_points WHERE technical_description_id IN (SELECT id FROM app.technical_descriptions WHERE parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'CP074_%'))");
        $this->pdo->exec("DELETE FROM app.technical_descriptions WHERE parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'CP074_%')");
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'CP074_%'");

        if (!empty($this->createdIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $this->createdIds));
            $this->pdo->exec("DELETE FROM app.survey_control_points WHERE id IN ($ids)");
            $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.survey_control_points' AND entity_id IN ($ids)");
            $this->createdIds = [];
        }
        $this->pdo->exec("DELETE FROM app.survey_control_points WHERE point_name LIKE 'CP074_%'");
        $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.survey_control_points' AND entity_id IN (SELECT id::varchar FROM app.survey_control_points WHERE point_name LIKE 'CP074_%')");
    }

    private function apiRequest(string $method, string $path, array $data = [], array $headers = [], ?string $token = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . ($token ?? $this->adminToken))
            ->withHeader('Accept', 'application/json');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /** Create a control point through the API and return the decoded data row. */
    private function apiCreate(string $pointName, array $overrides = []): array
    {
        $body = array_merge([
            'point_name'        => $pointName,
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:3123',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
            'elevation'         => 12.5,
        ], $overrides);

        $request  = $this->apiRequest('POST', '/api/v1/control-points', $body);
        $response = $this->handle($request);
        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->createdIds[] = $decoded['data']['id'];
        return $decoded['data'];
    }

    /**
     * Seed a parcel with a current TD, a tie point referencing $cpId, and a
     * current computation. Returns the seeded ids.
     *
     * @return array{parcel_id: string, td_id: int, comp_id: int, tie_id: int}
     */
    private function seedDependentParcel(int $cpId, string $parcelCode, float $easting = 512225.12, float $northing = 1678780.45): array
    {
        $pdo = $this->pdo;
        $crsId = (int) $pdo->query("SELECT id FROM ref.crs_registry WHERE srid = 3123")->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO app.parcels (id, parcel_code, lot_number, block_number) VALUES (gen_random_uuid(), :code, 'LOT-1', 'BLK-1') RETURNING id");
        $stmt->execute([':code' => $parcelCode]);
        $parcelId = (string) $stmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO app.technical_descriptions (parcel_id, revision, source_type, parser_status, status, is_current) VALUES (:pid, 1, 'MANUALLY_ENTERED', 'NOT_PARSED', 'DRAFT', true) RETURNING id");
        $stmt->execute([':pid' => $parcelId]);
        $tdId = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO app.tie_points (technical_description_id, control_point_id, role, as_used_easting, as_used_northing, as_used_crs_id, as_used_status, sequence) VALUES (:td, :cp, 'TIE', :e, :n, :crs, 'UNVERIFIED', 1) RETURNING id");
        $stmt->execute([':td' => $tdId, ':cp' => $cpId, ':e' => $easting, ':n' => $northing, ':crs' => $crsId]);
        $tieId = (int) $stmt->fetchColumn();

        $snapshot = json_encode([
            'tie_points' => [['point' => 'CP', 'easting' => $easting, 'northing' => $northing]],
            'input_pair' => [$easting, $northing],
        ], JSON_THROW_ON_ERROR);

        $stmt = $pdo->prepare(
            "INSERT INTO app.parcel_computations (parcel_id, technical_description_id, compute_crs_id, method, "
            . "closure_status, input_snapshot, tolerances, engine_version, is_current, computed_by) "
            . "VALUES (:pid, :td, :crs, 'TRAVERSE_PLANE', 'WITHIN_TOLERANCE', :snap::jsonb, '{}'::jsonb, 'test-1', true, :uid) RETURNING id"
        );
        $stmt->execute([':pid' => $parcelId, ':td' => $tdId, ':crs' => $crsId, ':snap' => $snapshot, ':uid' => $this->adminId]);
        $compId = (int) $stmt->fetchColumn();

        return ['parcel_id' => $parcelId, 'td_id' => $tdId, 'comp_id' => $compId, 'tie_id' => $tieId];
    }

    private function createDeniedUser(): array
    {
        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('TESTORG', 'Test Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT DO NOTHING");
        $orgId = (int) $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'TESTORG'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('cp074denied', 'cp074denied@example.com', 'dummy', 'Cp074 Denied', $orgId, 'ACTIVE', 1) ON CONFLICT DO NOTHING");
        $deniedId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'cp074denied'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('CP074_DENIED_ROLE', 'Cp074 Denied Role', false) ON CONFLICT DO NOTHING");
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'CP074_DENIED_ROLE'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($deniedId, $roleId) ON CONFLICT DO NOTHING");

        $token = \Firebase\JWT\JWT::encode([
            'sub' => (string) $deniedId, 'v' => 1, 'exp' => time() + 3600,
        ], getenv('JWT_SECRET') ?: 'dummy_secret', 'HS256');

        return ['id' => $deniedId, 'token' => $token];
    }

    // ── verify ────────────────────────────────────────────────────────────

    public function testVerifyRecordsVerifierAndTime(): void
    {
        $point = $this->apiCreate('CP074_VERIFY');

        $request  = $this->apiRequest('POST', '/api/v1/control-points/' . $point['id'] . '/verify');
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('VERIFIED', $decoded['data']['status']);
        $this->assertSame($this->adminId, $decoded['data']['verified_by']);
        $this->assertNotNull($decoded['data']['verified_at']);
        $this->assertSame(2, $decoded['data']['version']);

        // Persisted.
        $stmt = $this->pdo->prepare("SELECT status, verified_by, verified_at, version FROM app.survey_control_points WHERE id = :id");
        $stmt->execute([':id' => $point['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('VERIFIED', $row['status']);
        $this->assertSame((string) $this->adminId, (string) $row['verified_by']);
        $this->assertNotNull($row['verified_at']);
        $this->assertSame('2', (string) $row['version']);
    }

    public function testVerifyRequiresPermission(): void
    {
        $point  = $this->apiCreate('CP074_VERIFY_DENIED');
        $denied = $this->createDeniedUser();

        $request  = $this->apiRequest('POST', '/api/v1/control-points/' . $point['id'] . '/verify', [], [], $denied['token']);
        $response = $this->handle($request);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testVerifyNotFound(): void
    {
        $request  = $this->apiRequest('POST', '/api/v1/control-points/999999/verify');
        $response = $this->handle($request);
        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
    }

    // ── dependents ────────────────────────────────────────────────────────

    public function testDependentsListsParcelsUsingPoint(): void
    {
        $point = $this->apiCreate('CP074_DEP');
        $this->seedDependentParcel($point['id'], 'CP074_DEP_PARCEL');

        $request  = $this->apiRequest('GET', '/api/v1/control-points/' . $point['id'] . '/dependents');
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $decoded['data']['total']);
        $this->assertSame('CP074_DEP_PARCEL', $decoded['data']['parcels'][0]['parcel_code']);
        $this->assertFalse($decoded['data']['parcels'][0]['control_review_pending']);
        $this->assertNull($decoded['data']['parcels'][0]['control_review_since']);
    }

    public function testDependentsEmptyWhenPointUnused(): void
    {
        $point = $this->apiCreate('CP074_DEP_EMPTY');

        $request  = $this->apiRequest('GET', '/api/v1/control-points/' . $point['id'] . '/dependents');
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(0, $decoded['data']['total']);
        $this->assertSame([], $decoded['data']['parcels']);
    }

    public function testDependentsExcludesSoftDeletedParcel(): void
    {
        $point  = $this->apiCreate('CP074_DEP_DELETED');
        $parcel = $this->seedDependentParcel($point['id'], 'CP074_DEP_DELETED_PARCEL');

        $this->pdo->exec("UPDATE app.parcels SET deleted_at = CURRENT_TIMESTAMP WHERE id = '{$parcel['parcel_id']}'");

        $request  = $this->apiRequest('GET', '/api/v1/control-points/' . $point['id'] . '/dependents');
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(0, $decoded['data']['total']);
    }

    public function testDependentsNotFound(): void
    {
        $request  = $this->apiRequest('GET', '/api/v1/control-points/999999/dependents');
        $response = $this->handle($request);
        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testDependentsRequiresPermission(): void
    {
        $point  = $this->apiCreate('CP074_DEP_PERM');
        $denied = $this->createDeniedUser();

        $request  = $this->apiRequest('GET', '/api/v1/control-points/' . $point['id'] . '/dependents', [], [], $denied['token']);
        $response = $this->handle($request);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    // ── coordinate-edit impact (FR-081) ───────────────────────────────────

    public function testCoordinateEditFlagsDependentsAndKeepsComputationsByteIdentical(): void
    {
        $point  = $this->apiCreate('CP074_IMPACT');
        $parcel = $this->seedDependentParcel($point['id'], 'CP074_IMPACT_PARCEL');

        // Snapshot the computation before the edit to prove byte-identity later.
        $before = $this->computationSnapshot($parcel['comp_id']);

        $request = $this->apiRequest('PUT', '/api/v1/control-points/' . $point['id'], [
            'easting'  => 510000.0,
            'northing' => 1610000.0,
        ], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(2, $decoded['data']['version']);

        // data.impact lists the dependent parcel, now flagged for review.
        $this->assertArrayHasKey('impact', $decoded['data']);
        $this->assertCount(1, $decoded['data']['impact']);
        $this->assertSame('CP074_IMPACT_PARCEL', $decoded['data']['impact'][0]['parcel_code']);
        $this->assertTrue($decoded['data']['impact'][0]['control_review_pending']);
        $this->assertNotNull($decoded['data']['impact'][0]['control_review_since']);

        // Flag persisted on the parcel.
        $stmt = $this->pdo->prepare("SELECT control_review_pending, control_review_since FROM app.parcels WHERE id = :id");
        $stmt->execute([':id' => $parcel['parcel_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue((bool) $row['control_review_pending']);
        $this->assertNotNull($row['control_review_since']);

        // AC: every existing computation is byte-identical.
        $after = $this->computationSnapshot($parcel['comp_id']);
        $this->assertSame($before, $after);

        // The tie point's as-used snapshot is also untouched.
        $stmt = $this->pdo->prepare("SELECT as_used_easting, as_used_northing FROM app.tie_points WHERE id = :id");
        $stmt->execute([':id' => $parcel['tie_id']]);
        $tie = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(512225.12, (float) $tie['as_used_easting']);
        $this->assertEquals(1678780.45, (float) $tie['as_used_northing']);
    }

    public function testNonCoordinateEditDoesNotFlagDependents(): void
    {
        $point  = $this->apiCreate('CP074_IMPACT_NONE');
        $parcel = $this->seedDependentParcel($point['id'], 'CP074_IMPACT_NONE_PARCEL');

        $request = $this->apiRequest('PUT', '/api/v1/control-points/' . $point['id'], [
            'point_type' => 'BLLM',
        ], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame([], $decoded['data']['impact']);

        $stmt = $this->pdo->prepare("SELECT control_review_pending FROM app.parcels WHERE id = :id");
        $stmt->execute([':id' => $parcel['parcel_id']]);
        $this->assertFalse((bool) $stmt->fetchColumn());
    }

    public function testCoordinateEditWithNoDependentsReturnsEmptyImpact(): void
    {
        $point = $this->apiCreate('CP074_IMPACT_EMPTY');

        $request = $this->apiRequest('PUT', '/api/v1/control-points/' . $point['id'], [
            'easting'  => 510000.0,
            'northing' => 1610000.0,
        ], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('impact', $decoded['data']);
        $this->assertSame([], $decoded['data']['impact']);
    }

    private function computationSnapshot(int $compId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT input_snapshot::text, tolerances::text, computed_area_sqm, start_easting, start_northing, engine_version '
            . 'FROM app.parcel_computations WHERE id = :id'
        );
        $stmt->execute([':id' => $compId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, 'computation row must exist');
        return json_encode($row, JSON_THROW_ON_ERROR);
    }
}