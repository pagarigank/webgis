<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-068 — Parcel CRUD API acceptance.
 *
 * ACs covered here:
 *  - A parcel can exist without geometry.
 *  - provenance (geometry_source) is mandatory.
 *  - Update requires If-Match (428 missing / 409 stale) and bumps the version.
 *  - Delete requires a reason and never removes the row (soft delete).
 */
class ParcelCrudTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    /** @var string[] Parcel ids created during the test, cleaned in tearDown. */
    private array $createdIds = [];

    private string $adminToken;

    private const PARCEL_PERMS = ['parcel.view', 'parcel.create', 'parcel.update', 'parcel.delete'];

    protected function setUp(): void
    {
        parent::setUp();
        $app = $this->getAppInstance();
        $this->pdo = $app->getContainer()->get(\PDO::class);

        $this->cleanup();

        $user = $this->createMockUser($this->pdo, self::PARCEL_PERMS, ['SYS_ADMIN']);
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
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
            $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.parcels' AND entity_id IN ($ids)");
            $this->createdIds = [];
        }
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'CRUD_TEST_%'");
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

    /** Create a parcel through the API and return the decoded body. */
    private function apiCreate(string $parcelCode, array $overrides = []): array
    {
        $body = array_merge([
            'parcel_code'    => $parcelCode,
            'provenance'     => 'MANUAL_DRAWING',
            'source_area_sqm' => 250.0,
        ], $overrides);

        $request = $this->apiRequest('POST', '/api/v1/parcels', $body);
        $response = $this->handle($request);
        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->createdIds[] = $decoded['data']['id'];
        return $decoded['data'];
    }

    public function testCreateParcelWithoutGeometrySucceeds(): void
    {
        $parcel = $this->apiCreate('CRUD_TEST_PARCEL_001');

        $this->assertArrayHasKey('id', $parcel);
        $this->assertEquals('CRUD_TEST_PARCEL_001', $parcel['parcel_code']);
        $this->assertEquals('MANUAL_DRAWING', $parcel['provenance']);
        $this->assertEquals('DRAFT', $parcel['status']);
        $this->assertEquals(1, $parcel['version']);
        $this->assertNull($parcel['geometry']);

        // Row is truly persisted, not geometry-bearing.
        $stmt = $this->pdo->prepare("SELECT geometry_source, version FROM app.parcels WHERE id = :id");
        $stmt->execute([':id' => $parcel['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('MANUAL_DRAWING', $row['geometry_source']);
        $this->assertEquals(1, (int) $row['version']);
    }

    public function testCreateRequiresProvenance(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/parcels', [
            'parcel_code' => 'CRUD_TEST_PARCEL_NOPROV',
        ]);
        $response = $this->handle($request);
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $decoded['error']['code']);
    }

    public function testCreateRejectsUnknownProvenance(): void
    {
        $request = $this->apiRequest('POST', '/api/v1/parcels', [
            'parcel_code' => 'CRUD_TEST_PARCEL_BADPROV',
            'provenance'  => 'MADE_UP_SOURCE',
        ]);
        $response = $this->handle($request);
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testDuplicateParcelCodeConflicts(): void
    {
        $this->apiCreate('CRUD_TEST_PARCEL_DUP');

        $request = $this->apiRequest('POST', '/api/v1/parcels', [
            'parcel_code' => 'CRUD_TEST_PARCEL_DUP',
            'provenance'  => 'MANUAL_DRAWING',
        ]);
        $response = $this->handle($request);
        $this->assertEquals(409, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testGetParcelReturnsCreatedRow(): void
    {
        $parcel = $this->apiCreate('CRUD_TEST_PARCEL_GET');

        $request = $this->apiRequest('GET', '/api/v1/parcels/' . $parcel['id']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertEquals('CRUD_TEST_PARCEL_GET', $decoded['data']['parcel_code']);
        $this->assertEquals('DRAFT', $decoded['data']['status']);
        $this->assertEquals(1, $decoded['data']['version']);
    }

    public function testGetUnknownParcelReturns404(): void
    {
        $request = $this->apiRequest('GET', '/api/v1/parcels/99999999-9999-9999-9999-999999999999');
        $response = $this->handle($request);
        $this->assertEquals(404, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testListParcelsFiltersAndPaginates(): void
    {
        $this->apiCreate('CRUD_TEST_PARCEL_LIST_A', ['status' => 'DRAFT']);
        $this->apiCreate('CRUD_TEST_PARCEL_LIST_B', ['status' => 'SUBMITTED']);

        // status filter
        $request = $this->apiRequest('GET', '/api/v1/parcels?status=SUBMITTED');
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertGreaterThanOrEqual(1, $decoded['data']['total']);
        foreach ($decoded['data']['data'] as $row) {
            $this->assertEquals('SUBMITTED', $row['status']);
        }

        // keyword search across lot/block/title/tax declaration fields
        $this->pdo->exec("UPDATE app.parcels SET tax_declaration_no = 'CRUD-TD-42' WHERE parcel_code = 'CRUD_TEST_PARCEL_LIST_A'");
        $request = $this->apiRequest('GET', '/api/v1/parcels?q=CRUD-TD-42');
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertGreaterThanOrEqual(1, $decoded['data']['total']);
        $this->assertEquals('CRUD_TEST_PARCEL_LIST_A', $decoded['data']['data'][0]['parcel_code']);

        // pagination
        $request = $this->apiRequest('GET', '/api/v1/parcels?limit=1&offset=0');
        $response = $this->handle($request);
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertEquals(1, count($decoded['data']['data']));
        $this->assertGreaterThanOrEqual(1, $decoded['data']['total']);
    }

    public function testUpdateRequiresIfMatch(): void
    {
        $parcel = $this->apiCreate('CRUD_TEST_PARCEL_IFMISSING');

        $request = $this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'lot_number' => 'LOT-1',
        ]);
        $response = $this->handle($request);
        $this->assertEquals(428, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testUpdateWithStaleIfMatchConflicts(): void
    {
        $parcel = $this->apiCreate('CRUD_TEST_PARCEL_STALE');

        $request = $this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'lot_number' => 'LOT-1',
        ], ['If-Match' => '99']);
        $response = $this->handle($request);
        $this->assertEquals(409, $response->getStatusCode(), (string) $response->getBody());

        // Version must be unchanged
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $decoded['error']['details']['current_version']);
    }

    public function testUpdateWithMatchingIfMatchBumpsVersion(): void
    {
        $parcel = $this->apiCreate('CRUD_TEST_PARCEL_UPD');

        $request = $this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'lot_number'      => 'LOT-7',
            'tax_declaration_no' => 'TD-777',
        ], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertEquals('LOT-7', $decoded['data']['lot_number']);
        $this->assertEquals('TD-777', $decoded['data']['tax_declaration_no']);
        $this->assertEquals(2, $decoded['data']['version']);
    }

    public function testUpdateCanAddPolygonGeometry(): void
    {
        $parcel = $this->apiCreate('CRUD_TEST_PARCEL_GEOM');

        $request = $this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'geometry' => [
                'type'        => 'Polygon',
                'coordinates' => [[
                    [125.0, 7.0],
                    [125.01, 7.0],
                    [125.01, 7.01],
                    [125.0, 7.01],
                    [125.0, 7.0],
                ]],
            ],
        ], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertNotNull($decoded['data']['geometry']);
        $this->assertEquals('MultiPolygon', $decoded['data']['geometry']['type']);
        $this->assertEquals(2, $decoded['data']['version']);
    }

    public function testDeleteRequiresReason(): void
    {
        $parcel = $this->apiCreate('CRUD_TEST_PARCEL_NOREASON');

        $request = $this->apiRequest('DELETE', '/api/v1/parcels/' . $parcel['id']);
        $response = $this->handle($request);
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testDeleteSoftDeletesAndRetainsRow(): void
    {
        $parcel = $this->apiCreate('CRUD_TEST_PARCEL_DEL');

        $request = $this->apiRequest('DELETE', '/api/v1/parcels/' . $parcel['id'], ['reason' => 'Vacated for consolidation'], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertTrue($decoded['data']['deleted']);

        // Row is never physically removed.
        $stmt = $this->pdo->prepare("SELECT deleted_at FROM app.parcels WHERE id = :id");
        $stmt->execute([':id' => $parcel['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['deleted_at']);

        // A subsequent GET returns 404 (soft-deleted rows are hidden).
        $request = $this->apiRequest('GET', '/api/v1/parcels/' . $parcel['id']);
        $response = $this->handle($request);
        $this->assertEquals(404, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testDeleteWithUnknownIdReturns404(): void
    {
        $request = $this->apiRequest('DELETE', '/api/v1/parcels/99999999-9999-9999-9999-999999999999', ['reason' => 'Test reason'], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(404, $response->getStatusCode(), (string) $response->getBody());
    }
}