<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-097 — GET /parcels/{id}/overlaps acceptance (audit G-5).
 *
 * ACs covered here:
 *  - Returns the overlapping parcel list with area and percentage.
 *  - Excludes ARCHIVED / SUPERSEDED parcels from the result.
 *  - A parcel without geometry reports has_overlap=false.
 *  - Unknown parcel -> 404; invalid threshold -> 400; no permission -> 403.
 */
class ParcelOverlapsTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'OVL_API_%'");
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'ovl_no_perm_user'");
        $user = $this->createMockUser($this->pdo, ['parcel.view'], ['SURVEY_OFFICER']);
        $this->token = $user['token'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'OVL_API_%'");
        parent::tearDown();
    }

    private function req(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
    }

    private function insertParcel(string $code, string $wkt, string $status = 'APPROVED'): string
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (
                id, parcel_code, lot_number, status, geometry_source,
                geom, created_by
            ) VALUES (
                gen_random_uuid(), :code, 'Lot-1', :status, 'MANUAL_DRAWING',
                ST_Multi(ST_SetSRID(ST_GeomFromText(:wkt), 4326)), 1
            ) RETURNING id
        ");
        $stmt->execute([':code' => $code, ':status' => $status, ':wkt' => $wkt]);
        $id = (string) $stmt->fetchColumn();
        $this->createdIds[] = $id;
        return $id;
    }

    public function testReturnsOverlappingParcelsWithArea(): void
    {
        $subject = $this->insertParcel(
            'OVL_API_SUBJECT',
            'POLYGON((121.000 14.600, 121.001 14.600, 121.001 14.601, 121.000 14.601, 121.000 14.600))'
        );
        $this->insertParcel(
            'OVL_API_NEIGHBOUR',
            'POLYGON((121.0005 14.6005, 121.0015 14.6005, 121.0015 14.6015, 121.0005 14.6015, 121.0005 14.6005))'
        );

        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$subject}/overlaps"));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $body = json_decode((string) $res->getBody(), true);
        $this->assertTrue($body['success']);
        $data = $body['data'];

        $this->assertTrue($data['has_overlap']);
        $this->assertGreaterThan(0.0, $data['total_overlap_area_sqm']);
        $this->assertNotEmpty($data['overlapping_parcels']);

        $first = $data['overlapping_parcels'][0];
        $this->assertSame('OVL_API_NEIGHBOUR', $first['parcel_code']);
        $this->assertArrayHasKey('overlap_area_sqm', $first);
        $this->assertArrayHasKey('overlap_pct', $first);
        $this->assertArrayHasKey('is_sliver', $first);
    }

    public function testExcludesArchivedAndSupersededParcels(): void
    {
        $subject = $this->insertParcel(
            'OVL_API_HIST_SUBJECT',
            'POLYGON((121.100 14.700, 121.101 14.700, 121.101 14.701, 121.100 14.701, 121.100 14.700))'
        );
        $this->insertParcel(
            'OVL_API_SUPERSEDED',
            'POLYGON((121.100 14.700, 121.101 14.700, 121.101 14.701, 121.100 14.701, 121.100 14.700))',
            'SUPERSEDED'
        );
        $this->insertParcel(
            'OVL_API_ARCHIVED',
            'POLYGON((121.100 14.700, 121.101 14.700, 121.101 14.701, 121.100 14.701, 121.100 14.700))',
            'ARCHIVED'
        );

        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$subject}/overlaps"));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertFalse($data['has_overlap']);
        $this->assertEmpty($data['overlapping_parcels']);
    }

    public function testParcelWithoutGeometryHasNoOverlap(): void
    {
        $subject = $this->insertParcel('OVL_API_NOGEOM', 'POLYGON((121.200 14.800, 121.201 14.800, 121.201 14.801, 121.200 14.801, 121.200 14.800))');
        // Strip geometry to exercise the no-geometry branch.
        $this->pdo->prepare('UPDATE app.parcels SET geom = NULL WHERE id = :id')
            ->execute([':id' => $subject]);

        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$subject}/overlaps"));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertFalse($data['has_overlap']);
        $this->assertSame(0, $data['total_overlap_area_sqm']);
    }

    public function testUnknownParcelReturns404(): void
    {
        $res = $this->handle($this->req('GET', '/api/v1/parcels/00000000-0000-0000-0000-000000000000/overlaps'));
        $this->assertSame(404, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame('NOT_FOUND', $body['error']['code']);
    }

    public function testInvalidThresholdReturns400(): void
    {
        $subject = $this->insertParcel(
            'OVL_API_BADTHRESH',
            'POLYGON((121.300 14.900, 121.301 14.900, 121.301 14.901, 121.300 14.901, 121.300 14.900))'
        );

        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$subject}/overlaps?sliver_threshold_sqm=-1"));
        $this->assertSame(400, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
    }

    public function testRequiresParcelViewPermission(): void
    {
        $subject = $this->insertParcel(
            'OVL_API_NOPERM',
            'POLYGON((121.400 15.000, 121.401 15.000, 121.401 15.001, 121.400 15.001, 121.400 15.000))'
        );

        // Distinct role/user: createMockUser reuses shared fixture rows, so a
        // role granted parcel.view in an earlier test would leak here.
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('OVL_NO_PERM_ROLE', 'Overlap No Perm', false) ON CONFLICT DO NOTHING");
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'OVL_NO_PERM_ROLE'")->fetchColumn();

        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('OVL_NO_PERM_ORG', 'Overlap No Perm Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT DO NOTHING");
        $orgId = (int) $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'OVL_NO_PERM_ORG'")->fetchColumn();

        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('ovl_no_perm_user', 'ovl_no_perm@example.com', 'dummy', 'No Perm', $orgId, 'ACTIVE', 1)");
        $userId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'ovl_no_perm_user'")->fetchColumn();

        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($userId, $roleId)");

        $token = \Firebase\JWT\JWT::encode([
            'sub' => (string) $userId, 'v' => 1, 'exp' => time() + 3600,
        ], getenv('JWT_SECRET') ?: 'dummy_secret', 'HS256');

        $request = $this->createJsonRequest('GET', "/api/v1/parcels/{$subject}/overlaps")
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Accept', 'application/json');
        $res = $this->handle($request);
        $this->assertSame(403, $res->getStatusCode(), (string) $res->getBody());
    }
}
