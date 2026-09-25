<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/**
 * TASK-115 — VR-45 cycle rejection at write time (architecture.md §18.2:
 * "a parcel can never become its own ancestor").
 *
 * The lineage migration installs a BEFORE INSERT trigger that walks the
 * candidate parent's own ancestor chain. These tests exercise it through raw
 * SQL because any write path (service, import, manual SQL) must be stopped,
 * not only the API.
 */
class LineageCycleTest extends TestCase
{
    private \PDO $pdo;
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();
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
            $pdo->query("SELECT id FROM app.parcels WHERE parcel_code LIKE 'CYC_%'")->fetchAll(\PDO::FETCH_ASSOC)
        );
        if ($ids !== []) {
            $list = implode(', ', array_map(static fn (string $i): string => "'$i'", $ids));
            $pdo->exec("DELETE FROM app.parcel_relationships WHERE parent_parcel_id IN ($list) OR child_parcel_id IN ($list)");
            $pdo->exec("DELETE FROM app.parcel_operations WHERE id IN (SELECT superseded_by_operation_id FROM app.parcels WHERE id IN ($list))");
            $pdo->exec("DELETE FROM app.parcels WHERE id IN ($list)");
        }
    }

    private function parcel(string $code): string
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (id, parcel_code, status, geometry_source, version)
            VALUES (gen_random_uuid(), :code, 'DRAFT', 'MANUAL_DRAWING', 1)
            RETURNING id
        ");
        $stmt->execute([':code' => $code]);
        $id = (string) $stmt->fetchColumn();
        $this->createdIds[] = $id;
        return $id;
    }

    private function insertEdge(string $parent, string $child): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcel_relationships (parent_parcel_id, child_parcel_id, relationship_type)
            VALUES (:p, :c, 'REPLACEMENT')
        ");
        $stmt->execute([':p' => $parent, ':c' => $child]);
    }

    public function testSelfEdgeIsRejected(): void
    {
        $p = $this->parcel('CYC_SELF_01');
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/VR-45/');
        $this->insertEdge($p, $p);
    }

    public function testDirectCycleIsRejected(): void
    {
        $p = $this->parcel('CYC_DIR_01');
        $c = $this->parcel('CYC_DIR_02');

        $this->insertEdge($p, $c); // parent → child: fine

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/VR-45/');
        $this->insertEdge($c, $p); // child → parent: closes a 2-cycle
    }

    public function testTransitiveCycleIsRejected(): void
    {
        $a = $this->parcel('CYC_TRN_01');
        $b = $this->parcel('CYC_TRN_02');
        $c = $this->parcel('CYC_TRN_03');

        $this->insertEdge($a, $b);
        $this->insertEdge($b, $c);

        // a→b→c exists; c→a would make c its own (transitive) ancestor.
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/VR-45/');
        $this->insertEdge($c, $a);
    }

    public function testValidChainStillInserts(): void
    {
        $a = $this->parcel('CYC_OK_01');
        $b = $this->parcel('CYC_OK_02');
        $c = $this->parcel('CYC_OK_03');

        $this->insertEdge($a, $b);
        $this->insertEdge($b, $c);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcel_relationships WHERE child_parcel_id IN ('{$b}', '{$c}') AND parent_parcel_id IN ('{$a}', '{$b}')")->fetchColumn();
        $this->assertSame(2, $count);
    }
}
