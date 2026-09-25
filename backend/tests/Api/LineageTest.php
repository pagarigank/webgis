<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-115 — Lineage API (api.md §8.4).
 *
 * Fixture: a twice-transformed parcel graph — grandparent → two children,
 * child A → grandchild — built with real relationship edges backed by
 * operation rows. The API must return the full graph in both directions,
 * every edge must name its operation, and depth truncation must be reported
 * explicitly rather than silently.
 */
class LineageTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private int $userId;
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();
        $user = $this->createMockUser($this->pdo, ['parcel.view', 'parcel.lineage.view'], ['LIN_ADMIN']);
        $this->token = $user['token'];
        $this->userId = $user['id'];
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
        $ids = array_map(
            static fn (array $r): string => (string) $r['id'],
            $pdo->query("SELECT id FROM app.parcels WHERE parcel_code LIKE 'LIN\\_%'")->fetchAll(\PDO::FETCH_ASSOC)
        );
        if ($ids !== []) {
            $list = implode(', ', array_map(static fn (string $i): string => "'$i'", $ids));
            $pdo->exec("DELETE FROM app.parcel_relationships WHERE parent_parcel_id IN ($list) OR child_parcel_id IN ($list)");
            $pdo->exec("DELETE FROM app.parcel_operations WHERE id IN (SELECT superseded_by_operation_id FROM app.parcels WHERE id IN ($list))");
            $pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN ($list)");
            $pdo->exec("DELETE FROM app.parcels WHERE id IN ($list)");
        }
    }

    private function req(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
    }

    private function parcel(string $code, string $wkt): string
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

    /** Insert a relationship edge backed by a fresh operation row (FR-230). */
    private function edge(string $parent, string $child, string $type): int
    {
        $operationId = (int) $this->pdo->query(
            "INSERT INTO app.parcel_operations (operation_type, method, status, performed_by)
             VALUES ('SPLIT', 'MAP_SPLIT_LINE', 'COMMITTED', {$this->userId}) RETURNING id"
        )->fetchColumn();
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcel_relationships (parent_parcel_id, child_parcel_id, relationship_type, operation_id)
            VALUES (:p, :c, :t, :op)
        ");
        $stmt->execute([':p' => $parent, ':c' => $child, ':t' => $type, ':op' => $operationId]);
        return $operationId;
    }

    private static function square(float $minLng): string
    {
        $maxLng = $minLng + 0.001;
        return sprintf('POLYGON((%.4f 14.6, %.4f 14.6, %.4f 14.61, %.4f 14.61, %.4f 14.6))', $minLng, $maxLng, $maxLng, $minLng, $minLng);
    }

    public function testTwoGenerationsReturnedBothWaysWithOperationEdges(): void
    {
        $grandparent = $this->parcel('LIN_GP_01', self::square(121.0));
        $childA = $this->parcel('LIN_CA_01', self::square(121.0));
        $childB = $this->parcel('LIN_CB_01', self::square(121.001));
        $grandchild = $this->parcel('LIN_GC_01', self::square(121.0));

        $this->edge($grandparent, $childA, 'SUBDIVISION');
        $this->edge($grandparent, $childB, 'SUBDIVISION');
        $this->edge($childA, $grandchild, 'SUBDIVISION');

        // From the middle node: both directions must return the full graph.
        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$childA}/lineage?direction=both&depth=5"));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];

        $codes = array_column($data['nodes'], 'parcel_code');
        $this->assertCount(4, $data['nodes']);
        $this->assertContains('LIN_GP_01', $codes);
        $this->assertContains('LIN_CA_01', $codes);
        $this->assertContains('LIN_CB_01', $codes);
        $this->assertContains('LIN_GC_01', $codes);

        $this->assertCount(3, $data['edges']);
        foreach ($data['edges'] as $edge) {
            $this->assertSame('SUBDIVISION', $edge['type']);
            $this->assertNotNull($edge['operation_id']);
            $this->assertGreaterThan(0, (int) $edge['operation_id']);
        }
        $this->assertFalse($data['truncated']);

        // Root depth 0; one hop in either direction is depth 1.
        $byCode = array_column($data['nodes'], null, 'parcel_code');
        $this->assertSame(0, (int) $byCode['LIN_CA_01']['depth']);
        $this->assertSame(1, (int) $byCode['LIN_GP_01']['depth']);
        $this->assertSame(1, (int) $byCode['LIN_CB_01']['depth']);
        $this->assertSame(1, (int) $byCode['LIN_GC_01']['depth']);
    }

    public function testDepthCapTruncationIsExplicit(): void
    {
        $gp = $this->parcel('LIN_T_GP', self::square(121.0));
        $c = $this->parcel('LIN_T_C', self::square(121.0));
        $gc = $this->parcel('LIN_T_GC', self::square(121.0));

        $this->edge($gp, $c, 'SUBDIVISION');
        $this->edge($c, $gc, 'SUBDIVISION');

        // depth=1 upward from the grandchild: parent visible, grandparent cut.
        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$gc}/lineage?direction=ancestors&depth=1"));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];

        $codes = array_column($data['nodes'], 'parcel_code');
        $this->assertContains('LIN_T_C', $codes);
        $this->assertNotContains('LIN_T_GP', $codes);
        $this->assertTrue($data['truncated']);
        $this->assertTrue($data['truncated_ancestors']);
        $this->assertFalse($data['truncated_descendants']);

        // depth=2 reaches everything → not truncated.
        $full = json_decode((string) $this->handle($this->req('GET', "/api/v1/parcels/{$gc}/lineage?direction=ancestors&depth=2"))->getBody(), true)['data'];
        $this->assertContains('LIN_T_GP', array_column($full['nodes'], 'parcel_code'));
        $this->assertFalse($full['truncated']);
    }

    public function testDirectionFiltersAndValidation(): void
    {
        $p = $this->parcel('LIN_D_P', self::square(121.0));
        $c = $this->parcel('LIN_D_C', self::square(121.0));
        $this->edge($p, $c, 'SUBDIVISION');

        // Ancestors of the child: root + parent, no descendants.
        $anc = json_decode((string) $this->handle($this->req('GET', "/api/v1/parcels/{$c}/lineage?direction=ancestors"))->getBody(), true)['data'];
        $codesAnc = array_column($anc['nodes'], 'parcel_code');
        $this->assertCount(2, $anc['nodes']);
        $this->assertContains('LIN_D_P', $codesAnc);
        $this->assertContains('LIN_D_C', $codesAnc);

        // Descendants of the parent: root + child, no ancestors.
        $des = json_decode((string) $this->handle($this->req('GET', "/api/v1/parcels/{$p}/lineage?direction=descendants"))->getBody(), true)['data'];
        $codesDes = array_column($des['nodes'], 'parcel_code');
        $this->assertCount(2, $des['nodes']);
        $this->assertContains('LIN_D_C', $codesDes);
        $this->assertContains('LIN_D_P', $codesDes);

        // Invalid depth/direction → 400.
        $this->assertSame(400, $this->handle($this->req('GET', "/api/v1/parcels/{$c}/lineage?depth=0"))->getStatusCode());
        $this->assertSame(400, $this->handle($this->req('GET', "/api/v1/parcels/{$c}/lineage?depth=99"))->getStatusCode());
        $this->assertSame(400, $this->handle($this->req('GET', "/api/v1/parcels/{$c}/lineage?direction=sideways"))->getStatusCode());
    }

    public function testUnknownParcelIs404(): void
    {
        $fake = '00000000-0000-4000-8000-000000000003';
        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$fake}/lineage"));
        $this->assertSame(404, $res->getStatusCode());
    }
}
