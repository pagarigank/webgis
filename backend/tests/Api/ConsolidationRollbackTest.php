<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Audit\AuditWriter;
use App\Parcels\Application\ConsolidationService;
use App\Parcels\Domain\ConsolidationValidator;
use Tests\TestCase;

/**
 * TASK-114 — Rollback proof: a failure injected after the new-parcel INSERT
 * must leave every parent intact (not superseded), with no edges, versions,
 * operation row, or audit residue — and the Idempotency-Key unused.
 */
class ConsolidationRollbackTest extends TestCase
{
    private const A = 'POLYGON((121.0000 14.6000, 121.0010 14.6000, 121.0010 14.6090, 121.0000 14.6090, 121.0000 14.6000))';
    private const B = 'POLYGON((121.0010 14.6000, 121.0020 14.6000, 121.0020 14.6090, 121.0010 14.6090, 121.0010 14.6000))';

    private \PDO $pdo;
    private int $userId;
    private ?int $scopeId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();
        $user = $this->createMockUser($this->pdo, ['parcel.view', 'parcel.consolidate'], ['CONSRB_ADMIN']);
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
        $pdo->exec("DELETE FROM app.parcel_relationships WHERE parent_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'CONSRB_%') OR child_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'CONSRB_%')");
        $pdo->exec("DELETE FROM app.parcel_operations WHERE id IN (SELECT superseded_by_operation_id FROM app.parcels WHERE parcel_code LIKE 'CONSRB_%')");
        $pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'CONSRB_%')");
        $pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.parcel_operations' AND new_values::text LIKE '%CONSRB_%'");
        $pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'CONSRB_%'");
        if ($this->scopeId !== null) {
            $pdo->exec("DELETE FROM app.data_scopes WHERE id = {$this->scopeId}");
            $this->scopeId = null;
        }
    }

    private function service(): ConsolidationService
    {
        return new class(
            $this->pdo,
            new ConsolidationValidator($this->pdo),
            new AuditWriter($this->pdo),
        ) extends ConsolidationService {
            protected function failAfterNewParcel(): void
            {
                throw new \RuntimeException('injected failure after new parcel insert');
            }
        };
    }

    private function createParent(string $code, string $wkt): string
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (id, parcel_code, lot_number, status, geometry_source, version, created_by, geom)
            VALUES (gen_random_uuid(), :code, 'LOT-1', 'DRAFT', 'MANUAL_DRAWING', 1, :uid,
                    ST_Multi(ST_SetSRID(ST_GeomFromText(:wkt), 4326)))
            RETURNING id
        ");
        $stmt->execute([':code' => $code, ':uid' => $this->userId, ':wkt' => $wkt]);
        return (string) $stmt->fetchColumn();
    }

    private function body(array $ids): array
    {
        return [
            'parent_parcel_ids' => $ids,
            'new_parcel' => ['lot_number' => '300'],
            'reason' => 'Rollback test consolidation',
        ];
    }

    public function testInjectedFailureAfterNewParcelRollsBackEverything(): void
    {
        $a = $this->createParent('CONSRB_A_01', self::A);
        $b = $this->createParent('CONSRB_B_01', self::B);

        $snap = fn (): array => [
            'parcels' => (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcels WHERE parcel_code LIKE 'CONSRB_%'")->fetchColumn(),
            'rels' => (int) $this->pdo->query('SELECT COUNT(*) FROM app.parcel_relationships')->fetchColumn(),
            'versions' => (int) $this->pdo->query("SELECT COUNT(*) FROM audit.parcel_versions WHERE parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'CONSRB_%')")->fetchColumn(),
            'ops' => (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcel_operations WHERE inputs::text LIKE '%CONSRB_%'")->fetchColumn(),
        ];
        $before = $snap();

        $this->pdo->beginTransaction();
        $this->pdo->exec("SET LOCAL app.user_id = '{$this->userId}'");
        try {
            $service = $this->service();
            $service->setIfMatchVersions([$a => 1, $b => 1]);
            $service->consolidate([$a, $b], $this->userId, $this->body([$a, $b]), false, 'rid', 'CONSRB_KEY');
            $this->fail('The injected failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected failure after new parcel insert', $e->getMessage());
        } finally {
            $this->pdo->rollBack();
        }

        $this->assertSame($before, $snap(), 'mid-operation failure must leave the database untouched');

        foreach ([$a, $b] as $pid) {
            $row = $this->pdo->query("SELECT status, version, superseded_by_operation_id FROM app.parcels WHERE id = '{$pid}'")->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('DRAFT', $row['status']);
            $this->assertSame(1, (int) $row['version']);
            $this->assertNull($row['superseded_by_operation_id']);
        }
    }

    public function testIdempotencyKeyStillUsableAfterRollback(): void
    {
        $a = $this->createParent('CONSRB_A_02', self::A);
        $b = $this->createParent('CONSRB_B_02', self::B);

        $this->pdo->beginTransaction();
        $this->pdo->exec("SET LOCAL app.user_id = '{$this->userId}'");
        try {
            $service = $this->service();
            $service->setIfMatchVersions([$a => 1, $b => 1]);
            $service->consolidate([$a, $b], $this->userId, $this->body([$a, $b]), false, 'rid', 'CONSRB_IDEM_KEY');
            $this->fail('injected failure must surface');
        } catch (\RuntimeException) {
        } finally {
            $this->pdo->rollBack();
        }

        $healthy = new ConsolidationService($this->pdo, new ConsolidationValidator($this->pdo), new AuditWriter($this->pdo));
        $this->pdo->beginTransaction();
        $this->pdo->exec("SET LOCAL app.user_id = '{$this->userId}'");
        try {
            $healthy->setIfMatchVersions([$a => 1, $b => 1]);
            $result = $healthy->consolidate([$a, $b], $this->userId, $this->body([$a, $b]), false, 'rid', 'CONSRB_IDEM_KEY');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->fail('Commit after rolled-back attempt failed: ' . $e->getMessage());
        }

        $this->assertNotNull($result['operation_id']);
        $this->assertSame('SUPERSEDED', $this->pdo->query("SELECT status FROM app.parcels WHERE id = '{$a}'")->fetchColumn());
        $this->assertSame('SUPERSEDED', $this->pdo->query("SELECT status FROM app.parcels WHERE id = '{$b}'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcel_operations WHERE idempotency_key = 'CONSRB_IDEM_KEY'")->fetchColumn());
    }
}
