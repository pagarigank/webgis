<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;
use PDOException;

class AuditPartitionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->getAppInstance()->getContainer()->get(PDO::class);
    }

    public function testAuditLogInsertsIntoPartition(): void
    {
        // Insert a log within the partition range (2020 to 2030)
        $entityId = uniqid('TEST_');
        $this->pdo->exec("
            INSERT INTO audit.audit_logs (occurred_at, action, entity_type, entity_id) 
            VALUES ('2026-06-01 12:00:00Z', 'CREATE', 'TEST', '{$entityId}')
        ");
        
        $count = $this->pdo->query("SELECT count(*) FROM audit.audit_logs_y2020_to_y2030 WHERE entity_type = 'TEST' AND entity_id = '{$entityId}'")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testAuditLogFailsWhenNoPartitionExists(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/no partition of relation "audit_logs" found for row/');

        // Insert a log outside the partition range
        $this->pdo->exec("
            INSERT INTO audit.audit_logs (occurred_at, action, entity_type, entity_id) 
            VALUES ('2035-06-01 12:00:00Z', 'CREATE', 'TEST', '123')
        ");
    }
}
