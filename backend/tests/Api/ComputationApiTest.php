<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-090, 091, 092, 094, 095 — Full Survey Computation Engine Integration Tests.
 */
class ComputationApiTest extends TestCase
{
    private \PDO $pdo;
    private string $adminToken;
    private array $createdParcelIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $user = $this->createMockUser($this->pdo, [
            'parcel.view', 'parcel.create', 'parcel.update', 'parcel.delete',
            'survey.view', 'survey.create', 'survey.update',
        ], ['SYS_ADMIN']);
        $this->adminToken = $user['token'];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdParcelIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $this->createdParcelIds));
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
            $this->createdParcelIds = [];
        }
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'COMP_TEST_%'");
        parent::tearDown();
    }

    private function apiRequest(string $method, string $path, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('Accept', 'application/json');
    }

    public function testCompleteComputationAndAcceptFlow(): void
    {
        // 1. Create a test parcel without geometry
        $pRes = $this->handle($this->apiRequest('POST', '/api/v1/parcels', [
            'parcel_code'     => 'COMP_TEST_PARCEL_001',
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 5000.0,
        ]));
        $this->assertSame(201, $pRes->getStatusCode(), (string) $pRes->getBody());
        $parcel = json_decode((string) $pRes->getBody(), true)['data'];
        $parcelId = $parcel['id'];
        $this->createdParcelIds[] = $parcelId;

        // 2. Create technical description
        $tdRes = $this->handle($this->apiRequest('POST', "/api/v1/parcels/{$parcelId}/technical-descriptions", [
            'bearing_reference' => 'GRID',
            'claimed_area_sqm'  => 5000.0,
            'tie_line_bearing'  => 'DUE NORTH',
            'tie_line_distance' => 100.0,
        ]));
        $this->assertTrue(in_array($tdRes->getStatusCode(), [200, 201], true), (string) $tdRes->getBody());
        $td = json_decode((string) $tdRes->getBody(), true)['data'];
        $tdId = $td['id'];

        // 3. Add 4 boundary courses (100m x 50m rectangle)
        $courses = [
            ['from_corner' => '1', 'to_corner' => '2', 'bearing_raw' => 'DUE EAST',  'distance_raw' => 100.0],
            ['from_corner' => '2', 'to_corner' => '3', 'bearing_raw' => 'DUE NORTH', 'distance_raw' => 50.0],
            ['from_corner' => '3', 'to_corner' => '4', 'bearing_raw' => 'DUE WEST',  'distance_raw' => 100.0],
            ['from_corner' => '4', 'to_corner' => '1', 'bearing_raw' => 'DUE SOUTH', 'distance_raw' => 50.0],
        ];

        foreach ($courses as $c) {
            $cRes = $this->handle($this->apiRequest('POST', "/api/v1/technical-descriptions/{$tdId}/courses", $c));
            $this->assertTrue(in_array($cRes->getStatusCode(), [200, 201], true), (string) $cRes->getBody());
        }

        // 4. Confirm technical description
        $confRes = $this->handle($this->apiRequest('POST', "/api/v1/technical-descriptions/{$tdId}/confirm"));
        $this->assertSame(200, $confRes->getStatusCode(), (string) $confRes->getBody());

        // 5. Run Computation: POST /parcels/{id}/calculate (TASK-090)
        $calcRes = $this->handle($this->apiRequest('POST', "/api/v1/parcels/{$parcelId}/calculate", [
            'technical_description_id' => $tdId,
            'compute_crs'              => 'EPSG:3123',
        ]));
        $this->assertSame(201, $calcRes->getStatusCode(), (string) $calcRes->getBody());
        $calcData = json_decode((string) $calcRes->getBody(), true)['data'];

        $this->assertArrayHasKey('computation_id', $calcData);
        $compId = $calcData['computation_id'];
        $this->assertSame('EPSG:3123', $calcData['compute_crs']);
        $this->assertEqualsWithDelta(0.000, $calcData['closure']['linear_error_m'], 0.001);
        $this->assertSame('1:INF', $calcData['closure']['relative_precision']);
        $this->assertSame('WITHIN_TOLERANCE', $calcData['closure']['status']);
        $this->assertEqualsWithDelta(5000.0, $calcData['area']['computed_sqm'], 0.01);
        $this->assertEqualsWithDelta(5000.0, $calcData['area']['postgis_sqm'], 0.01);
        $this->assertCount(4, $calcData['vertices']);
        $this->assertFalse($calcData['persisted_to_parcel']);

        // Verify parcel.geom is STILL NULL before accept! (TASK-092 AC)
        $pCheck = $this->pdo->query("SELECT geom, current_computation_id FROM app.parcels WHERE id = '{$parcelId}'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($pCheck['geom']);
        $this->assertNull($pCheck['current_computation_id']);

        // 6. Test Replay Determinism (TASK-090 AC)
        $replayRes = $this->handle($this->apiRequest('POST', "/api/v1/computations/{$compId}/replay"));
        $this->assertSame(200, $replayRes->getStatusCode(), (string) $replayRes->getBody());
        $replayData = json_decode((string) $replayRes->getBody(), true)['data'];
        $this->assertTrue($replayData['replayed']);
        $this->assertTrue($replayData['matches_original']);

        // 7. Test Traverse Adjustment (TASK-094)
        $adjRes = $this->handle($this->apiRequest('POST', "/api/v1/computations/{$compId}/adjust", [
            'method' => 'COMPASS',
            'params' => ['note' => 'Test Compass adjustment'],
        ]));
        $this->assertSame(201, $adjRes->getStatusCode(), (string) $adjRes->getBody());
        $adjData = json_decode((string) $adjRes->getBody(), true)['data'];
        $this->assertSame($compId, $adjData['base_computation_id']);
        $this->assertSame('COMPASS', $adjData['adjustment_method']);
        $this->assertSame('WITHIN_TOLERANCE', $adjData['closure']['status']);

        // 8. Test Accept Computation -> parcel geometry (TASK-092)
        $acceptRes = $this->handle($this->apiRequest('POST', "/api/v1/parcels/{$parcelId}/accept-computation", [
            'computation_id' => $compId,
            'reason'         => 'Survey technical review approved',
        ]));
        $this->assertSame(200, $acceptRes->getStatusCode(), (string) $acceptRes->getBody());
        $acceptedParcel = json_decode((string) $acceptRes->getBody(), true)['data'];

        $this->assertSame('COMPUTED_FROM_TECHNICAL_DESCRIPTION', $acceptedParcel['provenance']);
        $this->assertNotNull($acceptedParcel['geometry']);
        $this->assertSame('MultiPolygon', $acceptedParcel['geometry']['type']);
        $this->assertSame(2, $acceptedParcel['version']); // BUMPED VERSION

        // Verify DB row
        $pDb = $this->pdo->query("SELECT geometry_source, current_computation_id, version FROM app.parcels WHERE id = '{$parcelId}'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('COMPUTED_FROM_TECHNICAL_DESCRIPTION', $pDb['geometry_source']);
        $this->assertSame($compId, (int) $pDb['current_computation_id']);
        $this->assertSame(2, (int) $pDb['version']);

        // Verify version history row in audit.parcel_versions
        $vDb = $this->pdo->query("SELECT snapshot, geometry_source, change_reason FROM audit.parcel_versions WHERE parcel_id = '{$parcelId}' AND version = 2")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($vDb);
        $this->assertSame('COMPUTED_FROM_TECHNICAL_DESCRIPTION', $vDb['geometry_source']);
        $snap = json_decode((string) $vDb['snapshot'], true);
        $this->assertSame($compId, $snap['accepted_computation_id']);
    }

    public function testCrsSuggestEndpoint(): void
    {
        $res = $this->handle($this->apiRequest('GET', '/api/v1/crs/suggest?lng=121.05'));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertSame(3123, $data['srid']);
        $this->assertSame('PTM Zone III', $data['zone']);
    }

    public function testCrsTransformEndpoint(): void
    {
        $res = $this->handle($this->apiRequest('POST', '/api/v1/crs/transform', [
            'coordinates' => [['x' => 500000.0, 'y' => 1600000.0]],
            'source_crs'  => 'EPSG:3123',
            'target_crs'  => 'EPSG:4326',
        ]));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertSame('EPSG:3123', $data['source_crs']['code']);
        $this->assertSame('EPSG:4326', $data['target_crs']['code']);
        $this->assertEqualsWithDelta(121.0, $data['transformed'][0]['x'], 0.01);
    }
}
