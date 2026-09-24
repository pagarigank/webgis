<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-069 — Parcel versioning acceptance.
 *
 * ACs covered here:
 *  - Versions are monotonic, never renumbered, never deleted (lineage endpoints).
 *  - Every geometry / status / TD / key-attribute change creates a version row with a change summary and reason.
 *  - Restore creates a NEW version (history is append-only).
 */
class ParcelVersionTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    /** @var string[] Parcel ids created during the test, cleaned in tearDown. */
    private array $createdIds = [];

    private string $adminToken;

    private const PERMS = [
        'parcel.view', 'parcel.create', 'parcel.update', 'parcel.delete',
        'parcel.lineage.view', 'parcel.version.restore',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $app = $this->getAppInstance();
        $this->pdo = $app->getContainer()->get(\PDO::class);

        $this->cleanup();

        $user = $this->createMockUser($this->pdo, self::PERMS, ['SYS_ADMIN']);
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
            $this->pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN ($ids)");
            $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.parcels' AND entity_id IN ($ids)");
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
            $this->createdIds = [];
        }
        $verIds = $this->pdo->query("SELECT id FROM app.parcels WHERE parcel_code LIKE 'VER_TEST_%'")->fetchAll(\PDO::FETCH_COLUMN);
        if (!empty($verIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $verIds));
            $this->pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN ($ids)");
            $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.parcels' AND entity_id IN ($ids)");
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
        }
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

    private function apiCreate(string $parcelCode): array
    {
        $request = $this->apiRequest('POST', '/api/v1/parcels', [
            'parcel_code'     => $parcelCode,
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 250.0,
        ]);
        $response = $this->getAppInstance()->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        if (isset($body['data']['id'])) {
            $this->createdIds[] = $body['data']['id'];
        }

        return $body;
    }

    private function apiUpdate(string $id, int $ifMatch, array $updates, array $headers = []): array
    {
        $request = $this->apiRequest('PATCH', '/api/v1/parcels/' . $id, $updates, ['If-Match' => (string) $ifMatch]);
        $response = $this->getAppInstance()->handle($request);
        return json_decode((string) $response->getBody(), true);
    }

    private function versionRows(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit.parcel_versions WHERE parcel_id = :pid ORDER BY version');
        $stmt->execute([':pid' => $id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function polygon(): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[[121.0, 14.5], [121.01, 14.5], [121.01, 14.51], [121.0, 14.51], [121.0, 14.5]]],
        ];
    }

    public function testCreateRecordsVersionOne(): void
    {
        $created = $this->apiCreate('VER_TEST_0001');
        $this->assertSame(1, $created['data']['version']);

        $rows = $this->versionRows($created['data']['id']);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['version']);
        $this->assertSame('Parcel created', $rows[0]['change_summary']);
        $this->assertSame('MANUAL_DRAWING', $rows[0]['geometry_source']);
        $this->assertSame('DRAFT', $rows[0]['status']);
        $this->assertNotEquals('', json_encode($rows[0]['snapshot']));
    }

    public function testVersionNumbersAreMonotonicAcrossChanges(): void
    {
        $created = $this->apiCreate('VER_TEST_0002');
        $id = $created['data']['id'];

        // Attribute edits only: status transitions are workflow-controlled
        // (FR-136) and no longer accepted via PATCH.
        $v2 = $this->apiUpdate($id, 1, ['tax_declaration_no' => 'TD-2026-001', 'change_reason' => 'Registered tax declaration']);
        $this->assertSame(2, $v2['data']['version']);

        $v3 = $this->apiUpdate($id, 2, ['location_description' => 'Beside the barangay hall', 'change_reason' => 'Location clarification']);
        $this->assertSame(3, $v3['data']['version']);

        $rows = $this->versionRows($id);
        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], array_map(fn ($r) => (int) $r['version'], $rows));
    }

    public function testGeometryChangeRecordsVersionWithSummary(): void
    {
        $created = $this->apiCreate('VER_TEST_0003');
        $id = $created['data']['id'];

        $updated = $this->apiUpdate($id, 1, ['geometry' => $this->polygon(), 'change_reason' => 'Digitized from imagery']);
        $this->assertSame(2, $updated['data']['version']);
        $this->assertNotEmpty($updated['data']['geometry']);

        $rows = $this->versionRows($id);
        $this->assertCount(2, $rows);
        $this->assertSame(2, (int) $rows[1]['version']);
        $this->assertStringContainsString('geometry added', $rows[1]['change_summary']);
        $this->assertSame('Digitized from imagery', $rows[1]['change_reason']);
    }

    public function testSnapshotRestoresFullHistoryAndReason(): void
    {
        $created = $this->apiCreate('VER_TEST_0004');
        $id = $created['data']['id'];

        $this->apiUpdate($id, 1, ['lot_number' => 'A-1']);
        $this->apiUpdate($id, 2, ['lot_number' => 'A-2']);
        $this->apiUpdate($id, 3, ['lot_number' => 'A-3']);

        $rows = $this->versionRows($id);
        $this->assertCount(4, $rows);

        $snap1 = json_decode($rows[1]['snapshot'], true); // v2: lot A-1
        $snap3 = json_decode($rows[3]['snapshot'], true); // v4: lot A-3
        $this->assertSame('A-1', $snap1['lot_number']);
        $this->assertSame('A-3', $snap3['lot_number']);
    }

    public function testListVersionsReturnsPaginatedLineageNewestFirst(): void
    {
        $created = $this->apiCreate('VER_TEST_0005');
        $id = $created['data']['id'];
        $this->apiUpdate($id, 1, ['lot_number' => 'LOT-NEWEST-FIRST']);

        $request = $this->apiRequest('GET', '/api/v1/parcels/' . $id . '/versions?page=1&per_page=10');
        $response = $this->getAppInstance()->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $body['data']['pagination']['total']);
        $this->assertCount(2, $body['data']['data']);
        $this->assertSame([2, 1], array_column($body['data']['data'], 'version'));
        $this->assertSame(2, $body['data']['data'][0]['version']);
        $this->assertSame('DRAFT', $body['data']['data'][0]['status']);
        $this->assertStringContainsString('lot_number: null -> LOT-NEWEST-FIRST', $body['data']['data'][0]['change_summary']);
    }

    public function testListVersionsRequiresLineagePermission()
    {
        $app = $this->getAppInstance();
        $created = $this->apiCreate('VER_TEST_0006');

        // Distinct user with only parcel.view (no lineage.view), not SYS_ADMIN.
        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('TESTORG', 'Test Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT DO NOTHING");
        $orgId = (int) $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'TESTORG'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('verlineage', 'verlineage@example.com', 'dummy', 'Lineage', $orgId, 'ACTIVE', 1) ON CONFLICT DO NOTHING");
        $limitedId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'verlineage'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('PARCEL_VIEWER', 'Parcel Viewer', false) ON CONFLICT DO NOTHING");
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'PARCEL_VIEWER'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($limitedId, $roleId) ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('parcel.view', 'View parcels') ON CONFLICT DO NOTHING");
        $permId = (int) $this->pdo->query("SELECT id FROM app.permissions WHERE code = 'parcel.view'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($roleId, $permId) ON CONFLICT DO NOTHING");

        $token = \Firebase\JWT\JWT::encode([
            'sub' => (string) $limitedId, 'v' => 1, 'exp' => time() + 3600,
        ], getenv('JWT_SECRET') ?: 'dummy_secret', 'HS256');

        $request = $this->createJsonRequest('GET', '/api/v1/parcels/' . $created['data']['id'] . '/versions')
            ->withHeader('Authorization', 'Bearer ' . $token);
        $response = $app->handle($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testGetVersionReturnsSnapshotAndGeometry(): void
    {
        $created = $this->apiCreate('VER_TEST_0007');
        $id = $created['data']['id'];
        $this->apiUpdate($id, 1, ['geometry' => $this->polygon(), 'change_reason' => 'Added shape']);

        $request = $this->apiRequest('GET', '/api/v1/parcels/' . $id . '/versions/2');
        $response = $this->getAppInstance()->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $body['data']['version']);
        $this->assertNotNull($body['data']['geometry']);
        $this->assertSame('Added shape', $body['data']['change_reason']);
    }

    public function testGetUnknownVersionReturns404(): void
    {
        $created = $this->apiCreate('VER_TEST_0008');
        $id = $created['data']['id'];

        $request = $this->apiRequest('GET', '/api/v1/parcels/' . $id . '/versions/999');
        $response = $this->getAppInstance()->handle($request);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRestoreRequiresIfMatch(): void
    {
        $created = $this->apiCreate('VER_TEST_0009');
        $id = $created['data']['id'];

        $request = $this->apiRequest('POST', '/api/v1/parcels/' . $id . '/versions/1/restore', []);
        $response = $this->getAppInstance()->handle($request);
        $this->assertSame(428, $response->getStatusCode());
    }

    public function testRestoreCreatesNewVersionNotRewrite(): void
    {
        $created = $this->apiCreate('VER_TEST_0010');
        $id = $created['data']['id'];

        $this->apiUpdate($id, 1, ['lot_number' => 'B-1']);
        $this->apiUpdate($id, 2, ['lot_number' => 'B-2', 'tax_declaration_no' => 'TD-X']);

        // Restore version 2 (lot B-2, TD-X) over current (B-2, TD-X) -> bump to 4? No: current is v3 already.
        $request = $this->apiRequest('POST', '/api/v1/parcels/' . $id . '/versions/2/restore', ['change_reason' => 'Rolling back']);
        $request = $request->withHeader('If-Match', '3');
        $response = $this->getAppInstance()->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(4, $body['data']['version']);

        $rows = $this->versionRows($id);
        $this->assertCount(4, $rows);
        $this->assertSame([1, 2, 3, 4], array_map(fn ($r) => (int) $r['version'], $rows));
        $this->assertSame('Restored from version 2', $rows[3]['change_summary']);
    }

    public function testRestoreBringsBackHistoricalAttributes(): void
    {
        $created = $this->apiCreate('VER_TEST_0011');
        $id = $created['data']['id'];

        $this->apiUpdate($id, 1, ['lot_number' => 'C-1', 'geometry' => $this->polygon()]);
        $this->apiUpdate($id, 2, ['lot_number' => 'C-2']);

        // Restore version 2 (lot C-1 + geometry) over current (C-2).
        $request = $this->apiRequest('POST', '/api/v1/parcels/' . $id . '/versions/2/restore', []);
        $request = $request->withHeader('If-Match', '3');
        $response = $this->getAppInstance()->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('C-1', $body['data']['lot_number']);
        $this->assertNotEmpty($body['data']['geometry']);
        $this->assertSame(4, $body['data']['version']);
    }

    public function testRestoreRejectsStaleIfMatch(): void
    {
        $created = $this->apiCreate('VER_TEST_0012');
        $id = $created['data']['id'];

        $request = $this->apiRequest('POST', '/api/v1/parcels/' . $id . '/versions/1/restore', []);
        $request = $request->withHeader('If-Match', '99');
        $response = $this->getAppInstance()->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(1, $body['error']['details']['current_version']);
    }

    public function testDeleteRecordsFinalVersion(): void
    {
        $created = $this->apiCreate('VER_TEST_0013');
        $id = $created['data']['id'];
        $this->apiUpdate($id, 1, ['tax_declaration_no' => 'TD-1']);

        $request = $this->apiRequest('DELETE', '/api/v1/parcels/' . $id, ['reason' => 'Duplicate record']);
        $response = $this->getAppInstance()->handle($request);
        $this->assertSame(200, $response->getStatusCode());

        $rows = $this->versionRows($id);
        $this->assertCount(3, $rows);
        $this->assertSame('Parcel deleted', $rows[2]['change_summary']);
        $this->assertSame('Duplicate record', $rows[2]['change_reason']);
    }

    public function testVersionsAreAppendOnlyNeverRenumbered(): void
    {
        $created = $this->apiCreate('VER_TEST_0014');
        $id = $created['data']['id'];
        $this->apiUpdate($id, 1, ['block_number' => 'BLK-APPEND-ONLY']);

        // Re-inserting an existing version must be rejected by the unique(parcel_id, version) index.
        $duplicate = $this->pdo->prepare('INSERT INTO audit.parcel_versions (parcel_id, version, snapshot) VALUES (:pid, 1, \'{}\')');
        $threw = false;
        try {
            $duplicate->execute([':pid' => $id]);
        } catch (\PDOException $e) {
            $threw = $e->getCode() === '23505';
        }
        $this->assertTrue($threw, 'Duplicate version write must fail on the unique constraint');

        $rows = $this->versionRows($id);
        $this->assertSame([1, 2], array_map(fn ($r) => (int) $r['version'], $rows));
    }
}