<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Audit\AuditWriter;
use App\Parcels\Application\SplitService;
use App\Parcels\Domain\SplitValidator;
use Tests\TestCase;

/**
 * TASK-112 — Rollback proof (todo.md: "a mid-operation failure leaves the
 * database untouched").
 *
 * A test subclass injects a failure AFTER the children INSERT but BEFORE the
 * operation row. The whole ambient transaction must roll back: no children,
 * no relationships, no versions, no operation, no audit, parent untouched —
 * and the idempotency key must remain unused.
 *
 * The service is invoked directly inside a test-managed transaction that
 * mirrors the middleware (BEGIN + SET LOCAL app.user_id), so the rollback of
 * exactly the service's writes is observable.
 */
class SplitRollbackTest extends TestCase
{
    private \PDO $pdo;
    private int $userId;
    private ?int $scopeId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();
        $user = $this->createMockUser($this->pdo, ['parcel.view', 'parcel.split'], ['SPLLRB_ADMIN']);
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
        $pdo->exec("DELETE FROM app.parcel_relationships WHERE parent_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'SPLLRB_%') OR child_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'SPLLRB_%')");
        $pdo->exec("DELETE FROM app.parcel_operations WHERE id IN (SELECT superseded_by_operation_id FROM app.parcels WHERE parcel_code LIKE 'SPLLRB_%')");
        $pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'SPLLRB_%')");
        $pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.parcel_operations' AND new_values::text LIKE '%SPLLRB_%'");
        $pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'SPLLRB_%'");
        if ($this->scopeId !== null) {
            $pdo->exec("DELETE FROM app.data_scopes WHERE id = {$this->scopeId}");
            $this->scopeId = null;
        }
    }

    private function service(): SplitService
    {
        return new class(
            $this->pdo,
            new SplitValidator($this->pdo),
            new AuditWriter($this->pdo),
        ) extends SplitService {
            protected function failAfterChildren(): void
            {
                throw new \RuntimeException('injected failure between children and operation');
            }
        };
    }

    private function createParent(string $code): string
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (id, parcel_code, lot_number, status, geometry_source, version, created_by, geom)
            VALUES (gen_random_uuid(), :code, 'LOT-100', 'DRAFT', 'MANUAL_DRAWING', 1, :uid,
                    ST_Multi(ST_SetSRID(ST_GeomFromText('POLYGON((121.0000 14.6000, 121.0010 14.6000, 121.0010 14.6090, 121.0000 14.6090, 121.0000 14.6000))'), 4326)))
            RETURNING id
        ");
        $stmt->execute([':code' => $code, ':uid' => $this->userId]);
        return (string) $stmt->fetchColumn();
    }

    private function splitBody(): array
    {
        return [
            'method' => 'SURVEY_GEOMETRY',
            'children' => [
                ['lot_number' => '100-A', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[121.0, 14.6], [121.0005, 14.6], [121.0005, 14.609], [121.0, 14.609], [121.0, 14.6]]]]],
                ['lot_number' => '100-B', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[121.0005, 14.6], [121.001, 14.6], [121.001, 14.609], [121.0005, 14.609], [121.0005, 14.6]]]]],
            ],
            'reason' => 'Rollback test subdivision',
        ];
    }

    public function testInjectedFailureAfterChildrenRollsBackEverything(): void
    {
        $parentId = $this->createParent('SPLLRB_MAIN_01');

        $snap = fn (): array => [
            'parcels' => (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcels WHERE parcel_code LIKE 'SPLLRB_%'")->fetchColumn(),
            'children' => (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcels WHERE parcel_code LIKE 'SPLLRB_MAIN_01-S%'")->fetchColumn(),
            'rels' => (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcel_relationships WHERE parent_parcel_id = '{$parentId}'")->fetchColumn(),
            'versions' => (int) $this->pdo->query("SELECT COUNT(*) FROM audit.parcel_versions WHERE parcel_id = '{$parentId}'")->fetchColumn(),
            'ops' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_operations')->fetchColumn(),
            'audit' => (int) $this->pdo->query("SELECT COUNT(*) FROM audit.audit_logs WHERE entity_type = 'app.parcel_operations'")->fetchColumn(),
        ];
        $before = $snap();

        $this->pdo->beginTransaction();
        $this->pdo->exec("SET LOCAL app.user_id = '{$this->userId}'");
        try {
            $service = $this->service();
            $service->setIfMatchVersion(1);
            $service->split($parentId, $this->userId, $this->splitBody(), false, 'test-req-rid', 'SPLLRB_KEY_01');
            $this->fail('The injected failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected failure between children and operation', $e->getMessage());
        } finally {
            $this->pdo->rollBack();
        }

        $this->assertSame($before, $snap(), 'mid-operation failure must leave the database untouched');

        $parent = $this->pdo->query("SELECT status, version, superseded_by_operation_id FROM app.parcels WHERE id = '{$parentId}'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('DRAFT', $parent['status']);
        $this->assertSame(1, (int) $parent['version']);
        $this->assertNull($parent['superseded_by_operation_id']);
    }

    /** The rolled-back attempt must not consume the Idempotency-Key. */
    public function testIdempotencyKeyStillUsableAfterRollback(): void
    {
        $parentId = $this->createParent('SPLLRB_IDEM_01');

        $this->pdo->beginTransaction();
        $this->pdo->exec("SET LOCAL app.user_id = '{$this->userId}'");
        try {
            $service = $this->service();
            $service->setIfMatchVersion(1);
            $service->split($parentId, $this->userId, $this->splitBody(), false, 'rid', 'SPLLRB_IDEM_KEY');
            $this->fail('injected failure must surface');
        } catch (\RuntimeException) {
        } finally {
            $this->pdo->rollBack();
        }

        // A healthy service now commits with the same key.
        $healthy = new SplitService($this->pdo, new SplitValidator($this->pdo), new AuditWriter($this->pdo));
        $this->pdo->beginTransaction();
        $this->pdo->exec("SET LOCAL app.user_id = '{$this->userId}'");
        try {
            $healthy->setIfMatchVersion(1);
            $result = $healthy->split($parentId, $this->userId, $this->splitBody(), false, 'rid', 'SPLLRB_IDEM_KEY');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->fail('Commit after rolled-back attempt failed: ' . $e->getMessage());
        }

        $this->assertNotNull($result['operation_id']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcels WHERE parcel_code LIKE 'SPLLRB_IDEM_01-S%'")->fetchColumn());
        $this->assertSame('SUPERSEDED', $this->pdo->query("SELECT status FROM app.parcels WHERE id = '{$parentId}'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcel_operations WHERE idempotency_key = 'SPLLRB_IDEM_KEY'")->fetchColumn());
    }
}
