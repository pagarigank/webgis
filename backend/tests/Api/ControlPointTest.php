<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-073 — Control point CRUD API acceptance.
 *
 * ACs covered:
 *  - Create accepts either a projected pair (easting/northing in a projected
 *    native CRS) or a geographic pair (latitude/longitude, WGS 84) and derives
 *    the other pair server-side.
 *  - Responses label which coordinate was ORIGINAL and which was DERIVED.
 *  - A point whose WGS 84 position falls outside the CRS area of use is
 *    rejected (400).
 *  - The native CRS must be a projected CRS.
 *  - Supplying both pairs, an incomplete pair, an out-of-range latitude, a
 *    non-numeric coordinate, an unknown CRS or a non-projected CRS is 400.
 *  - status is managed by the verify workflow and cannot be set on create.
 *  - Duplicate point_name + same CRS conflicts (409).
 *  - Update requires If-Match (428 missing / 409 stale) and bumps version;
 *    coordinate updates re-derive and recompute the stored geom.
 *  - Delete requires a reason, soft-deletes, and hides the row.
 *  - Permission-less users are denied (403).
 */
class ControlPointTest extends TestCase
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
        if (!empty($this->createdIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $this->createdIds));
            $this->pdo->exec("DELETE FROM app.survey_control_points WHERE id IN ($ids)");
            $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.survey_control_points' AND entity_id IN ($ids)");
            $this->createdIds = [];
        }
        $this->pdo->exec("DELETE FROM app.survey_control_points WHERE point_name LIKE 'CP073_%'");
        $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.survey_control_points' AND entity_id IN (SELECT id::varchar FROM app.survey_control_points WHERE point_name LIKE 'CP073_%')");
    }

    private function apiRequest(string $method, string $path, array $data = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
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

    private function expectValidationFailed(\Psr\Http\Message\ResponseInterface $response): array
    {
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $decoded['error']['code']);
        return $decoded;
    }

    public function testCreateProjectedOrignDerivesAndLabelsGeographicPair(): void
    {
        $point = $this->apiCreate('CP073_PROJ_ORIGIN');

        $this->assertSame('CP073_PROJ_ORIGIN', $point['point_name']);
        $this->assertSame('UNVERIFIED', $point['status']);
        $this->assertSame('PROJECTED', $point['coordinate_origin']);
        $this->assertSame(1, $point['version']);
        $this->assertEquals(512225.12, $point['easting']);
        $this->assertEquals(1678780.45, $point['northing']);

        // Original E/N kept, derived lat/long labelled derived.
        $this->assertFalse($point['derived']['easting']);
        $this->assertFalse($point['derived']['northing']);
        $this->assertTrue($point['derived']['latitude']);
        $this->assertTrue($point['derived']['longitude']);

        // The derived pair must fall inside the Philippines bounding box.
        $this->assertGreaterThan(4.0, $point['latitude']);
        $this->assertLessThan(22.0, $point['latitude']);
        $this->assertGreaterThan(116.0, $point['longitude']);
        $this->assertLessThan(128.0, $point['longitude']);

        $this->assertEquals(12.5, $point['elevation']);
        $this->assertSame('EPSG:3123', $point['native_crs']);
        $this->assertEquals(3123, $point['native_crs_srid']);

        // Geom persisted as a WGS 84 point.
        $this->assertSame('Point', $point['geom']['type']);
        $this->assertEquals($point['latitude'], $point['geom']['coordinates'][1], '', 0.000001);
        $this->assertEquals($point['longitude'], $point['geom']['coordinates'][0], '', 0.000001);
    }

    public function testCreateGeographicOriginDerivesProjectedPair(): void
    {
        $point = $this->apiCreate('CP073_GEO_ORIGIN', [
            'coordinate_origin' => 'GEOGRAPHIC',
            'latitude'          => 14.5995,
            'longitude'         => 120.9842,
            'easting'           => null,
            'northing'          => null,
            'elevation'         => null,
        ]);

        $this->assertSame('GEOGRAPHIC', $point['coordinate_origin']);
        $this->assertTrue($point['derived']['easting']);
        $this->assertTrue($point['derived']['northing']);
        $this->assertFalse($point['derived']['latitude']);
        $this->assertFalse($point['derived']['longitude']);

        $this->assertEquals(14.5995, $point['latitude']);
        $this->assertEquals(120.9842, $point['longitude']);

        // Derived E/N live inside the zone III reference frame (PH region).
        $this->assertGreaterThan(1_000_000, $point['northing']);
        $this->assertLessThan(2_500_000, $point['northing']);
        $this->assertGreaterThan(100_000, $point['easting']);
        $this->assertLessThan(1_000_000, $point['easting']);
    }

    public function testCreateRequiresNativeCrs(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_NO_CRS',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
        ]);
        $response = $this->handle($request);
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testCreateRejectsUnknownCrs(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_BAD_CRS',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:99999',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
        ]);
        $response = $this->handle($request);
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testCreateRejectsNonProjectedNativeCrs(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_GEOG_CRS',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'GEOGRAPHIC',
            'native_crs'        => 'EPSG:4326',
            'latitude'          => 14.5995,
            'longitude'         => 120.9842,
        ]);
        $response = $this->handle($request);
        $decoded = $this->expectValidationFailed($response);
        $this->assertArrayHasKey('native_crs', $decoded['error']['details']['fields']);
    }

    public function testCreateRejectsIncompletePair(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_INCOMPLETE',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:3123',
            'easting'           => 512225.12,
        ]);
        $response = $this->handle($request);
        $this->expectValidationFailed($response);
    }

    public function testCreateRejectsBothPairs(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_BOTH',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:3123',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
            'latitude'          => 14.5995,
            'longitude'         => 120.9842,
        ]);
        $response = $this->handle($request);
        $this->expectValidationFailed($response);
    }

    public function testCreateRejectsOutOfRangeLatitude(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_LAT95',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'GEOGRAPHIC',
            'native_crs'        => 'EPSG:3123',
            'latitude'          => 95,
            'longitude'         => 120.9842,
        ]);
        $response = $this->handle($request);
        $this->expectValidationFailed($response);
    }

    public function testCreateRejectsNonNumericCoordinate(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_NOTNUM',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:3123',
            'easting'           => 'abc',
            'northing'          => 1678780.45,
        ]);
        $response = $this->handle($request);
        $this->expectValidationFailed($response);
    }

    public function testCreateRejectsPointOutsideCrsAreaOfUse(): void
    {
        // EPSG:3123 covers the Philippines; (500000, 0) is the zone III origin
        // at the equator — far outside the PRS92 area of use.
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_OUT_OF_AREA',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:3123',
            'easting'           => 500000.0,
            'northing'          => 0.0,
        ]);
        $response = $this->handle($request);
        $decoded = $this->expectValidationFailed($response);
        $this->assertStringContainsString('area of use', strtolower($decoded['error']['message']));
    }

    public function testCreateRejectsExplicitStatusField(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_WITH_STATUS',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:3123',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
            'status'            => 'VERIFIED',
        ]);
        $response = $this->handle($request);
        $this->expectValidationFailed($response);
    }

    public function testCreateRejectsInvalidPointType(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_BADTYPE',
            'point_type'        => 'TOTALLY_MADE_UP',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:3123',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
        ]);
        $response = $this->handle($request);
        $this->expectValidationFailed($response);
    }

    public function testDuplicatePointNameAndCrsConflicts(): void
    {
        $this->apiCreate('CP073_DUP');

        $request = $this->apiRequest('POST', '/api/v1/control-points', [
            'point_name'        => 'CP073_DUP',
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:3123',
            'easting'           => 512225.12,
            'northing'          => 1678780.45,
        ]);
        $response = $this->handle($request);
        $this->assertEquals(409, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testGetReturnsCreatedRow(): void
    {
        $created = $this->apiCreate('CP073_GET');

        $request = $this->apiRequest('GET', '/api/v1/control-points/' . $created['id']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('CP073_GET', $decoded['data']['point_name']);
        $this->assertSame('UNVERIFIED', $decoded['data']['status']);
        $this->assertSame(1, $decoded['data']['version']);
    }

    public function testGetUnknownPointReturns404(): void
    {
        $request = $this->apiRequest('GET', '/api/v1/control-points/99999999');
        $response = $this->handle($request);
        $this->assertEquals(404, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testListFiltersAndPaginates(): void
    {
        $this->apiCreate('CP073_LIST_A');
        $this->apiCreate('CP073_LIST_B', ['point_type' => 'BLLM']);

        // status filter
        $request = $this->apiRequest('GET', '/api/v1/control-points?status=UNVERIFIED&q=CP073_LIST');
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertGreaterThanOrEqual(2, $decoded['data']['total']);
        foreach ($decoded['data']['data'] as $row) {
            $this->assertSame('UNVERIFIED', $row['status']);
            $this->assertStringContainsString('CP073_LIST', $row['point_name']);
        }

        // type filter
        $request = $this->apiRequest('GET', '/api/v1/control-points?type=BLLM&q=CP073_LIST');
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertGreaterThanOrEqual(1, $decoded['data']['total']);
        foreach ($decoded['data']['data'] as $row) {
            $this->assertSame('BLLM', $row['point_type']);
        }

        // pagination
        $request = $this->apiRequest('GET', '/api/v1/control-points?limit=1&offset=0&q=CP073_LIST');
        $response = $this->handle($request);
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, count($decoded['data']['data']));
        $this->assertGreaterThanOrEqual(2, $decoded['data']['total']);
    }

    public function testUpdateRequiresIfMatch(): void
    {
        $point = $this->apiCreate('CP073_IFMISSING');

        $request = $this->apiRequest('PUT', '/api/v1/control-points/' . $point['id'], [
            'point_type' => 'BLLM',
        ]);
        $response = $this->handle($request);
        $this->assertEquals(428, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testUpdateWithStaleIfMatchConflicts(): void
    {
        $point = $this->apiCreate('CP073_STALE');

        $request = $this->apiRequest('PUT', '/api/v1/control-points/' . $point['id'], [
            'point_type' => 'BLLM',
        ], ['If-Match' => '99']);
        $response = $this->handle($request);
        $this->assertEquals(409, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $decoded['error']['details']['current_version']);
    }

    public function testUpdateWithMatchingIfMatchBumpsVersion(): void
    {
        $point = $this->apiCreate('CP073_UPDA');

        $request = $this->apiRequest('PUT', '/api/v1/control-points/' . $point['id'], [
            'point_type' => 'BLLM',
        ], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('BLLM', $decoded['data']['point_type']);
        $this->assertSame(2, $decoded['data']['version']);
    }

    public function testCoordinateUpdateRecomputesDerivedPairAndGeom(): void
    {
        $point = $this->apiCreate('CP073_RECOMPUTE');

        $request = $this->apiRequest('PUT', '/api/v1/control-points/' . $point['id'], [
            'easting'  => 510000.0,
            'northing' => 1610000.0,
        ], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertEquals(510000.0, $decoded['data']['easting']);
        $this->assertEquals(1610000.0, $decoded['data']['northing']);

        // Original E/N, derived lat/long now re-deriven and inside the PH bbox.
        $this->assertFalse($decoded['data']['derived']['easting']);
        $this->assertTrue($decoded['data']['derived']['latitude']);
        $this->assertGreaterThan(4.0, $decoded['data']['latitude']);
        $this->assertLessThan(22.0, $decoded['data']['latitude']);

        // Version bumped and geom reflects the new position.
        $this->assertSame(2, $decoded['data']['version']);
        $this->assertEquals($decoded['data']['latitude'], $decoded['data']['geom']['coordinates'][1], '', 0.000001);
    }

    public function testDeleteRequiresReason(): void
    {
        $point = $this->apiCreate('CP073_NOREASON');

        $request = $this->apiRequest('DELETE', '/api/v1/control-points/' . $point['id']);
        $response = $this->handle($request);
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testDeleteSoftDeletesAndHidesRow(): void
    {
        $point = $this->apiCreate('CP073_DEL');

        $request = $this->apiRequest('DELETE', '/api/v1/control-points/' . $point['id'], [
            'reason' => 'Monument disturbed during construction',
        ], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertTrue($decoded['data']['deleted']);

        // Row is never physically removed.
        $stmt = $this->pdo->prepare("SELECT deleted_at, version FROM app.survey_control_points WHERE id = :id");
        $stmt->execute([':id' => $point['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame(2, (int) $row['version']);

        // A subsequent GET returns 404 (soft-deleted rows are hidden).
        $request = $this->apiRequest('GET', '/api/v1/control-points/' . $point['id']);
        $response = $this->handle($request);
        $this->assertEquals(404, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testRequiresControlPointViewPermission(): void
    {
        // Distinct user that holds NO control_point.* permission.
        $denied = $this->createDeniedUser();

        $request = $this->createJsonRequest('GET', '/api/v1/control-points')
            ->withHeader('Authorization', 'Bearer ' . $denied['token'])
            ->withHeader('Accept', 'application/json');
        $response = $this->handle($request);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    private function createDeniedUser(): array
    {
        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('TESTORG', 'Test Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT DO NOTHING");
        $orgId = (int) $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'TESTORG'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('cpdenied', 'cpdenied@example.com', 'dummy', 'Cp Denied', $orgId, 'ACTIVE', 1) ON CONFLICT DO NOTHING");
        $deniedId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'cpdenied'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('CP_DENIED_ROLE', 'Cp Denied Role', false) ON CONFLICT DO NOTHING");
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'CP_DENIED_ROLE'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($deniedId, $roleId) ON CONFLICT DO NOTHING");

        $token = \Firebase\JWT\JWT::encode([
            'sub' => (string) $deniedId, 'v' => 1, 'exp' => time() + 3600,
        ], getenv('JWT_SECRET') ?: 'dummy_secret', 'HS256');

        return ['id' => $deniedId, 'token' => $token];
    }
}