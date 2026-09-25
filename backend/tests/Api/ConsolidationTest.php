<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-114 — Consolidation API (api.md §8.3, architecture.md §18.4).
 *
 * Two touching squares consolidate into one DRAFT parcel; both parents end
 * SUPERSEDED with CONSOLIDATION edges to the new parcel. Dry run writes
 * nothing; CONSOLIDATION_INVALID enumerates blocking failures; commits
 * verify per-parent versions.
 */
class ConsolidationTest extends TestCase
{
    private const A = 'POLYGON((121.0000 14.6000, 121.0010 14.6000, 121.0010 14.6090, 121.0000 14.6090, 121.0000 14.6000))';
    private const B = 'POLYGON((121.0010 14.6000, 121.0020 14.6000, 121.0020 14.6090, 121.0010 14.6090, 121.0010 14.6000))';
    private const B_OVERLAP = 'POLYGON((121.0005 14.6000, 121.0015 14.6000, 121.0015 14.6090, 121.0005 14.6090, 121.0005 14.6000))';

    private \PDO $pdo;
    private string $token;
    private int $userId;
    private ?int $scopeId = null;
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();
        $user = $this->createMockUser($this->pdo, ['parcel.view', 'parcel.consolidate'], ['CONSOL_ADMIN']);
        $this->token = $user['token'];
        $this->userId = $user['id'];
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
        $ids = array_merge($this->createdIds, array_map(
            static fn (array $r): string => (string) $r['id'],
            $pdo->query("SELECT id FROM app.parcels WHERE parcel_code LIKE 'CONSOL_API_%'")->fetchAll(\PDO::FETCH_ASSOC)
        ));
        if ($ids !== []) {
            $list = implode(', ', array_map(static fn (string $i): string => "'$i'", $ids));
            $pdo->exec("DELETE FROM app.parcel_relationships WHERE parent_parcel_id IN ($list) OR child_parcel_id IN ($list)");
            $pdo->exec("DELETE FROM app.parcel_operations WHERE id IN (SELECT superseded_by_operation_id FROM app.parcels WHERE id IN ($list))");
            $pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN ($list)");
            $pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.parcel_operations' AND new_values::text LIKE '%CONSOL_API_%'");
            $pdo->exec("DELETE FROM app.parcels WHERE id IN ($list)");
        }
        if ($this->scopeId !== null) {
            $pdo->exec("DELETE FROM app.data_scopes WHERE id = {$this->scopeId}");
            $this->scopeId = null;
        }
        $pdo->exec("DELETE FROM app.rate_limit_entries WHERE bucket_key LIKE 'lineage:%'");
        $this->createdIds = [];
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

    /** Direct-insert parents (status DRAFT) with real adjacent geometry. */
    private function createParent(string $code, string $wkt): string
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (id, parcel_code, lot_number, status, geometry_source, version, created_by, geom)
            VALUES (gen_random_uuid(), :code, 'LOT-1', 'DRAFT', 'MANUAL_DRAWING', 1, :uid,
                    ST_Multi(ST_SetSRID(ST_GeomFromText(:wkt), 4326)))
            RETURNING id
        ");
        $stmt->execute([':code' => $code, ':uid' => $this->userId, ':wkt' => $wkt]);
        $id = (string) $stmt->fetchColumn();
        $this->createdIds[] = $id;
        return $id;
    }

    private function versions(array $ids): array
    {
        $map = [];
        $list = implode(', ', array_map(static fn (string $i): string => "'$i'", $ids));
        foreach ($this->pdo->query("SELECT id, version FROM app.parcels WHERE id IN ($list)")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['id']] = (int) $row['version'];
        }
        return $map;
    }

    public function testDryRunPreviewsUnionAndWritesNothing(): void
    {
        $a = $this->createParent('CONSOL_API_A_01', self::A);
        $b = $this->createParent('CONSOL_API_B_01', self::B);
        $before = [
            'parcels' => (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcels WHERE parcel_code LIKE 'CONSOL_API_%'")->fetchColumn(),
            'ops' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_operations')->fetchColumn(),
            'rels' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_relationships')->fetchColumn(),
        ];

        $res = $this->handle($this->req('POST', '/api/v1/parcels/consolidate?dry_run=true', [
            'parent_parcel_ids' => [$a, $b],
            'new_parcel' => ['lot_number' => '200'],
            'reason' => 'Consolidation per Ccs-000001',
        ]));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];

        $this->assertTrue($data['dry_run']);
        $this->assertNull($data['operation_id']);
        $this->assertNotNull($data['result']['geometry']);
        $this->assertSame('Polygon', $data['result']['geometry']['type']);
        $this->assertGreaterThan(20000.0, (float) $data['result']['area_sqm']);
        $this->assertTrue($data['validation']['passed']);

        $after = [
            'parcels' => (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcels WHERE parcel_code LIKE 'CONSOL_API_%'")->fetchColumn(),
            'ops' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_operations')->fetchColumn(),
            'rels' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_relationships')->fetchColumn(),
        ];
        $this->assertSame($before, $after, 'dry run must write nothing');
    }

    public function testCommitCreatesParcelSupersedesParentsAndIsIdempotent(): void
    {
        $a = $this->createParent('CONSOL_API_A_02', self::A);
        $b = $this->createParent('CONSOL_API_B_02', self::B);
        $versions = $this->versions([$a, $b]);
        $body = [
            'parent_parcel_ids' => [$a, $b],
            'parent_versions' => $versions,
            'new_parcel' => ['lot_number' => '201'],
            'reason' => 'Consolidation per Ccs-000002',
        ];

        $res = $this->handle($this->req('POST', '/api/v1/parcels/consolidate', $body, ['Idempotency-Key' => 'CONSOL_API_KEY_02']));
        $this->assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];

        $newId = (string) $data['result']['parcel_id'];
        $this->createdIds[] = $newId;
        $this->assertNotNull($data['operation_id']);

        $new = $this->pdo->query("SELECT status, version, parcel_code, ST_GeometryType(geom) AS gt, ST_NumGeometries(geom) AS np FROM app.parcels WHERE id = '{$newId}'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('DRAFT', $new['status']);
        $this->assertSame('CONSOL_API_A_02-CONS', $new['parcel_code']);
        $this->assertSame('ST_MultiPolygon', $new['gt']);
        $this->assertSame(1, (int) $new['np']);

        foreach ([$a, $b] as $pid) {
            $row = $this->pdo->query("SELECT status, version, superseded_by_operation_id FROM app.parcels WHERE id = '{$pid}'")->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('SUPERSEDED', $row['status']);
            $this->assertSame($versions[$pid] + 1, (int) $row['version']);
            $this->assertSame((int) $data['operation_id'], (int) $row['superseded_by_operation_id']);
        }

        $edges = $this->pdo->query("SELECT COUNT(*) FROM app.parcel_relationships WHERE child_parcel_id = '{$newId}' AND relationship_type = 'CONSOLIDATION'")->fetchColumn();
        $this->assertSame(2, (int) $edges);

        // Replay with the same key → same operation, nothing new written.
        $replay = $this->handle($this->req('POST', '/api/v1/parcels/consolidate', $body, ['Idempotency-Key' => 'CONSOL_API_KEY_02']));
        $this->assertSame(201, $replay->getStatusCode(), (string) $replay->getBody());
        $this->assertSame($data['operation_id'], json_decode((string) $replay->getBody(), true)['data']['operation_id']);
        $this->assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcels WHERE parcel_code LIKE 'CONSOL_API_%' OR parcel_code = 'CONSOL_API_A_02-CONS'")->fetchColumn());
    }

    public function testOverlappingParentsBlockWithConsolidationInvalid(): void
    {
        $a = $this->createParent('CONSOL_API_A_03', self::A);
        $b = $this->createParent('CONSOL_API_B_03', self::B_OVERLAP);

        $res = $this->handle($this->req('POST', '/api/v1/parcels/consolidate?dry_run=true', [
            'parent_parcel_ids' => [$a, $b],
            'reason' => 'Should fail',
        ]));
        $this->assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        $error = json_decode((string) $res->getBody(), true)['error'];
        $this->assertSame('CONSOLIDATION_INVALID', $error['code']);
        $rules = array_column($error['details']['failures'], 'rule');
        $this->assertContains('VR-41', $rules);
    }

    public function testCommitWithoutParentVersionsIs428AndStaleVersionIs409(): void
    {
        $a = $this->createParent('CONSOL_API_A_04', self::A);
        $b = $this->createParent('CONSOL_API_B_04', self::B);

        $noVersions = $this->handle($this->req('POST', '/api/v1/parcels/consolidate', [
            'parent_parcel_ids' => [$a, $b],
            'reason' => 'No versions given',
        ]));
        $this->assertSame(428, $noVersions->getStatusCode(), (string) $noVersions->getBody());

        $stale = $this->handle($this->req('POST', '/api/v1/parcels/consolidate', [
            'parent_parcel_ids' => [$a, $b],
            'parent_versions' => [$a => 999, $b => 1],
            'reason' => 'Stale version',
        ]));
        $this->assertSame(409, $stale->getStatusCode(), (string) $stale->getBody());
        $this->assertSame('VERSION_CONFLICT', json_decode((string) $stale->getBody(), true)['error']['code']);
    }

    public function testUnknownParentIs404AndSingleParentIsRejected(): void
    {
        $fake = '00000000-0000-4000-8000-000000000002';
        $res = $this->handle($this->req('POST', '/api/v1/parcels/consolidate?dry_run=true', [
            'parent_parcel_ids' => [$fake, $fake],
            'reason' => 'Unknown parents',
        ]));
        $this->assertSame(404, $res->getStatusCode(), (string) $res->getBody());

        $a = $this->createParent('CONSOL_API_A_05', self::A);
        $res2 = $this->handle($this->req('POST', '/api/v1/parcels/consolidate?dry_run=true', [
            'parent_parcel_ids' => [$a],
            'reason' => 'One parent',
        ]));
        $this->assertSame(422, $res2->getStatusCode(), (string) $res2->getBody());
    }
}
