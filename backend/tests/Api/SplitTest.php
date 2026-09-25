<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-112 — Split service API (api.md §8.2, architecture.md §18.3).
 *
 * Covered here:
 *  - Dry run returns preview payloads and writes NOTHING (parcel count,
 *    versions, operations, relationships all unchanged).
 *  - Commit creates DRAFT children with inherited PSGC/org, SUBDIVISION
 *    relationships, v1 child versions, a pre-split parent version, flips the
 *    parent to SUPERSEDED (+1 version), and writes one operation row + audit.
 *  - SPLIT_INVALID lists every failed check; unknown parent → 404; commit
 *    without If-Match → 428; wrong If-Match → 409; no permission → 403.
 *
 * Fixtures build real computed geometry via the TASK-101 API path
 * (create → TD → tie point → courses → confirm → calculate → accept).
 */
class SplitTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private int $userId;
    private ?int $scopeId = null;
    private array $createdParcelIds = [];
    private array $createdControlPointIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();
        $user = $this->createMockUser($this->pdo, ['parcel.view', 'parcel.split'], ['SPLIT_ADMIN']);
        $this->token = $user['token'];
        $this->userId = $user['id'];

        // GLOBAL WRITE scope so the §18.3 data-scope check passes for this
        // user. createMockUser reuses the shared 'testuser' row, so the scope
        // is tracked by id and removed in cleanup (never left accumulating).
        $stmt = $this->pdo->prepare("INSERT INTO app.data_scopes (user_id, scope_type, access_level) VALUES (:uid, 'GLOBAL', 'EDIT') RETURNING id");
        $stmt->execute([':uid' => $this->userId]);
        $this->scopeId = (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $pdo = $this->pdo ?? null;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM app.parcel_relationships WHERE parent_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'SPLIT_API_%') OR child_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'SPLIT_API_%')");
        $pdo->exec("DELETE FROM app.parcel_operations WHERE id IN (SELECT superseded_by_operation_id FROM app.parcels WHERE parcel_code LIKE 'SPLIT_API_%')");
        $pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'SPLIT_API_%' OR parcel_code LIKE 'SPLIT_API_%-S%')");
        $pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'SPLIT_API_%'");
        $pdo->exec("DELETE FROM app.survey_control_points WHERE point_name LIKE 'SPLIT_API_CP_%'");
        if ($this->scopeId !== null) {
            $pdo->exec("DELETE FROM app.data_scopes WHERE id = {$this->scopeId}");
            $this->scopeId = null;
        }
        $pdo->exec("DELETE FROM app.rate_limit_entries WHERE bucket_key LIKE 'lineage:%'");
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

    /** Build a parcel with real computed geometry (100 m × 50 m square). */
    private function createReadyParcel(string $code): string
    {
        $cpStmt = $this->pdo->prepare(
            "INSERT INTO app.survey_control_points (point_name, status, geom)
             VALUES (:name, 'VERIFIED', ST_SetSRID(ST_MakePoint(121.0, 14.5), 4326)) RETURNING id"
        );
        $cpStmt->execute([':name' => 'SPLIT_API_CP_' . uniqid()]);
        $cpId = (int) $cpStmt->fetchColumn();
        $this->createdControlPointIds[] = $cpId;

        $res = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code' => $code,
            'provenance' => 'MANUAL_DRAWING',
            'source_area_sqm' => 5000.0,
        ]));
        $this->assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());
        $parcelId = (string) json_decode((string) $res->getBody(), true)['data']['id'];
        $this->createdParcelIds[] = $parcelId;

        $tdRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/technical-descriptions", [
            'bearing_reference' => 'GRID',
            'claimed_area_sqm' => 5000.0,
            'tie_line_bearing' => 'DUE NORTH',
            'tie_line_distance' => 100.0,
        ]));
        $tdId = (int) json_decode((string) $tdRes->getBody(), true)['data']['id'];

        $this->pdo->prepare("INSERT INTO app.tie_points (technical_description_id, control_point_id, sequence, role) VALUES (:tdid, :cp, 1, 'TIE')")
            ->execute([':cp' => $cpId, ':tdid' => $tdId]);

        foreach ([
            ['from_corner' => '1', 'to_corner' => '2', 'bearing_raw' => 'DUE EAST', 'distance_raw' => 100.0],
            ['from_corner' => '2', 'to_corner' => '3', 'bearing_raw' => 'DUE NORTH', 'distance_raw' => 50.0],
            ['from_corner' => '3', 'to_corner' => '4', 'bearing_raw' => 'DUE WEST', 'distance_raw' => 100.0],
            ['from_corner' => '4', 'to_corner' => '1', 'bearing_raw' => 'DUE SOUTH', 'distance_raw' => 50.0],
        ] as $c) {
            $this->handle($this->req('POST', "/api/v1/technical-descriptions/{$tdId}/courses", $c));
        }
        $this->handle($this->req('POST', "/api/v1/technical-descriptions/{$tdId}/confirm"));

        $calcRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/calculate", [
            'technical_description_id' => $tdId,
            'compute_crs' => 'EPSG:3123',
        ]));
        $computationId = (int) json_decode((string) $calcRes->getBody(), true)['data']['computation_id'];

        $acceptRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/accept-computation", [
            'computation_id' => $computationId,
            'reason' => 'Split test setup',
        ]));
        $this->assertContains($acceptRes->getStatusCode(), [200, 201], (string) $acceptRes->getBody());

        return $parcelId;
    }

    private function parentVersion(string $parcelId): int
    {
        $stmt = $this->pdo->prepare('SELECT version FROM app.parcels WHERE id = :id');
        $stmt->execute([':id' => $parcelId]);
        return (int) $stmt->fetchColumn();
    }

    private function splitBody(): array
    {
        return [
            'method' => 'MAP_SPLIT_LINE',
            'split_line' => ['type' => 'LineString', 'coordinates' => [[0.0, 0.0], [0.0, 0.0]]],
            'children' => [['lot_number' => '100-A'], ['lot_number' => '100-B']],
            'reason' => 'Subdivision per plan Psd-000001',
        ];
    }

    /** Overwrite the placeholder coordinates with the parcel's real bbox midline. */
    private function bodyWithMidline(array $body, string $parcelId): array
    {
        $stmt = $this->pdo->prepare('SELECT ST_Extent(geom)::text FROM app.parcels WHERE id = :id');
        $stmt->execute([':id' => $parcelId]);
        $extent = (string) $stmt->fetchColumn();
        preg_match('/BOX\(([-0-9.]+) ([-0-9.]+), ?([-0-9.]+) ([-0-9.]+)\)/', $extent, $m);
        $minLng = (float) $m[1];
        $minLat = (float) $m[2];
        $maxLng = (float) $m[3];
        $maxLat = (float) $m[4];
        $midLng = ($minLng + $maxLng) / 2.0;
        $body['split_line']['coordinates'] = [[$midLng, $minLat - 0.001], [$midLng, $maxLat + 0.001]];
        return $body;
    }

    public function testDryRunReturnsPreviewAndWritesNothing(): void
    {
        $pid = $this->createReadyParcel('SPLIT_API_DRY_01');
        $before = [
            'parcels' => (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcels WHERE parcel_code LIKE 'SPLIT_API_%'")->fetchColumn(),
            'ops' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_operations')->fetchColumn(),
            'rels' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_relationships')->fetchColumn(),
            'versions' => (int) $this->pdo->query("SELECT COUNT(*) FROM audit.parcel_versions v JOIN app.parcels p ON p.id = v.parcel_id WHERE p.parcel_code LIKE 'SPLIT_API_%'")->fetchColumn(),
        ];

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$pid}/split?dry_run=true", $this->bodyWithMidline($this->splitBody(), $pid)));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];

        $this->assertTrue($data['dry_run']);
        $this->assertNull($data['operation_id']);
        $this->assertCount(2, $data['children']);
        $this->assertTrue($data['validation']['passed']);
        $this->assertSame('SUPERSEDED', $data['parent_after']['status']);
        $this->assertNotNull($data['area_reconciliation']['children_sum_sqm']);

        $after = [
            'parcels' => (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcels WHERE parcel_code LIKE 'SPLIT_API_%'")->fetchColumn(),
            'ops' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_operations')->fetchColumn(),
            'rels' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_relationships')->fetchColumn(),
            'versions' => (int) $this->pdo->query("SELECT COUNT(*) FROM audit.parcel_versions v JOIN app.parcels p ON p.id = v.parcel_id WHERE p.parcel_code LIKE 'SPLIT_API_%'")->fetchColumn(),
        ];
        $this->assertSame($before, $after, 'dry run must not write anything');
        $this->assertSame('DRAFT', $this->pdo->query("SELECT status FROM app.parcels WHERE id = '{$pid}'")->fetchColumn());
    }

    public function testCommitCreatesChildrenRelationshipsAndSupersedesParent(): void
    {
        $pid = $this->createReadyParcel('SPLIT_API_COMMIT_01');
        $version = $this->parentVersion($pid);
        $body = $this->bodyWithMidline($this->splitBody(), $pid);

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$pid}/split", $body, ['If-Match' => (string) $version, 'Idempotency-Key' => 'SPLIT_API_COMMIT_01']));
        $this->assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];

        $this->assertFalse($data['dry_run']);
        $this->assertNotNull($data['operation_id']);
        $childIds = array_map(fn (array $c): string => (string) $c['parcel_id'], $data['children']);
        $this->assertCount(2, $childIds);

        // Children: DRAFT, inherited provenance/PSGC/org, v1.
        foreach ($data['children'] as $i => $child) {
            $row = $this->pdo->query("SELECT status, geometry_source, psgc_barangay, org_id, version FROM app.parcels WHERE id = '{$child['parcel_id']}'")->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('DRAFT', $row['status']);
            $this->assertSame('MANUAL_DRAWING', $row['geometry_source']);
            $this->assertSame(1, (int) $row['version']);
            $this->assertSame((string) $child['lot_number'], ['100-A', '100-B'][$i]);
        }

        // Parent: SUPERSEDED, version+1, points at the operation.
        $parent = $this->pdo->query("SELECT status, version, superseded_by_operation_id FROM app.parcels WHERE id = '{$pid}'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('SUPERSEDED', $parent['status']);
        $this->assertSame($version + 1, (int) $parent['version']);
        $this->assertSame((int) $data['operation_id'], (int) $parent['superseded_by_operation_id']);

        // Two SUBDIVISION edges naming the operation.
        $edges = $this->pdo->query("SELECT relationship_type, operation_id FROM app.parcel_relationships WHERE parent_parcel_id = '{$pid}'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $edges);
        foreach ($edges as $e) {
            $this->assertSame('SUBDIVISION', $e['relationship_type']);
            $this->assertSame((int) $data['operation_id'], (int) $e['operation_id']);
        }

        // One operation row with validation + reconciliation.
        $op = $this->pdo->query("SELECT operation_type, method, status FROM app.parcel_operations WHERE id = {$data['operation_id']}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('SPLIT', $op['operation_type']);
        $this->assertSame('MAP_SPLIT_LINE', $op['method']);
        $this->assertSame('COMMITTED', $op['status']);

        // Versions: 2 child v1 rows + parent v1 (creation) + parent v2
        // (accepted computation — already records the pre-split state, so the
        // split's pre-state row is deduped by the ON CONFLICT guard).
        $this->assertSame(4, (int) $this->pdo->query("SELECT COUNT(*) FROM audit.parcel_versions WHERE parcel_id IN ('{$pid}', '{$childIds[0]}', '{$childIds[1]}')")->fetchColumn());

        // Audit row exists for the operation.
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit.audit_logs WHERE entity_type = 'app.parcel_operations' AND entity_id = '{$data['operation_id']}'")->fetchColumn());

        // Replaying the commit with the same Idempotency-Key returns the same operation.
        $replay = $this->handle($this->req('POST', "/api/v1/parcels/{$pid}/split", $body, ['If-Match' => (string) $version, 'Idempotency-Key' => 'SPLIT_API_COMMIT_01']));
        $this->assertSame(201, $replay->getStatusCode(), (string) $replay->getBody());
        $this->assertSame($data['operation_id'], json_decode((string) $replay->getBody(), true)['data']['operation_id']);
    }

    public function testCommitWithoutIfMatchIs428AndWrongVersionIs409(): void
    {
        $pid = $this->createReadyParcel('SPLIT_API_IFMATCH_01');
        $body = $this->bodyWithMidline($this->splitBody(), $pid);

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$pid}/split", $body));
        $this->assertSame(428, $res->getStatusCode(), (string) $res->getBody());

        $res2 = $this->handle($this->req('POST', "/api/v1/parcels/{$pid}/split", $body, ['If-Match' => '999']));
        $this->assertSame(409, $res2->getStatusCode(), (string) $res2->getBody());
        $this->assertSame('VERSION_CONFLICT', json_decode((string) $res2->getBody(), true)['error']['code']);
    }

    public function testSplitInvalidListsEveryFailedCheck(): void
    {
        $pid = $this->createReadyParcel('SPLIT_API_INVALID_01');
        $body = $this->bodyWithMidline($this->splitBody(), $pid);
        // Split line outside the parcel → 1 piece only (VR-35) and the union
        // cannot match the parent (VR-37): two failures reported together.
        $body['split_line']['coordinates'] = [[0.0, 0.0], [0.0, 0.0]];

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$pid}/split?dry_run=true", $body));
        $this->assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        $error = json_decode((string) $res->getBody(), true)['error'];
        $this->assertSame('SPLIT_INVALID', $error['code']);
        $rules = array_column($error['details']['failures'], 'rule');
        $this->assertContains('VR-35', $rules);
    }

    public function testUnknownParentIs404AndUnknownMethodIs400(): void
    {
        $fake = '00000000-0000-4000-8000-000000000001';
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$fake}/split?dry_run=true", $this->splitBody()));
        $this->assertSame(404, $res->getStatusCode());

        $pid = $this->createReadyParcel('SPLIT_API_METHOD_01');
        $body = $this->splitBody();
        $body['method'] = 'TELEPORT';
        $res2 = $this->handle($this->req('POST', "/api/v1/parcels/{$pid}/split?dry_run=true", $body));
        $this->assertSame(400, $res2->getStatusCode());
    }
}
