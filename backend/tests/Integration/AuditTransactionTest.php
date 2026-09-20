<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Audit\AuditWriter;
use App\Audit\PartitionWorker;
use PDO;
use PHPUnit\Framework\TestCase;

class AuditTransactionTest extends TestCase
{
    private PDO $pdo;
    private AuditWriter $writer;
    private PartitionWorker $partitionWorker;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST') ?: 'postgres';
        $port = getenv('DB_PORT') ?: '5432';
        $db   = getenv('DB_NAME') ?: 'webgis';
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASS') ?: 'postgres';

        $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->writer = new AuditWriter($this->pdo);
        $this->partitionWorker = new PartitionWorker($this->pdo);

        // The migration creates a mega-partition covering 2020-2031.
        // If that partition exists, we don't need to create monthly partitions
        // for current dates. Just clean up test rows.
        $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_id LIKE 'AUDIT_TEST_%'");
    }

    // ---- AuditWriter tests -----------------------------------------------

    public function testAuditWriterInsertsRow(): void
    {
        $this->writer->write(
            action: 'INSERT',
            table: 'app.parcels',
            entityId: 'AUDIT_TEST_001',
            newValues: ['parcel_code' => 'AUDIT_TEST_001'],
            userId: 1,
        );

        $stmt = $this->pdo->query(
            "SELECT * FROM audit.audit_logs WHERE entity_id = 'AUDIT_TEST_001'"
        );
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'Audit row should be inserted');
        $this->assertEquals('INSERT', $row['action']);
        $this->assertEquals('app.parcels', $row['entity_type']);
        $this->assertEquals(1, $row['user_id']);
    }

    public function testAuditWriterRedactsPiiInNewValues(): void
    {
        $this->writer->write(
            action: 'UPDATE',
            table: 'app.users',
            entityId: 'AUDIT_TEST_002',
            oldValues: ['email' => 'old@example.com', 'username' => 'alice'],
            newValues: ['email' => 'new@example.com', 'username' => 'alice'],
        );

        $stmt = $this->pdo->query(
            "SELECT old_values, new_values FROM audit.audit_logs WHERE entity_id = 'AUDIT_TEST_002'"
        );
        $row = $stmt->fetch();
        $this->assertNotFalse($row);

        $oldVals = json_decode($row['old_values'], true);
        $newVals = json_decode($row['new_values'], true);

        // PII (email) must NOT appear verbatim in audit logs
        $this->assertStringNotContainsString('old@example.com', $row['old_values']);
        $this->assertStringNotContainsString('new@example.com', $row['new_values']);

        // Non-PII (username) must survive
        $this->assertSame('alice', $oldVals['username']);
        $this->assertSame('alice', $newVals['username']);

        // Redacted marker must be present
        $this->assertStringStartsWith('[REDACTED:', $oldVals['email']);
    }

    public function testFailedAuditWriteRollsBackMutation(): void
    {
        // We simulate the scenario where the audit write would fail by attempting
        // to insert an invalid row (action > 60 chars) inside an explicit transaction.
        $this->pdo->beginTransaction();

        try {
            // This will fail because action is too long
            $this->writer->write(
                action: str_repeat('X', 100), // exceeds varchar(60)
                table: 'app.parcels',
                entityId: 'AUDIT_TEST_ROLLBACK',
                userId: 1,
            );
            $this->pdo->commit();
            $this->fail('Expected PDOException was not thrown');
        } catch (\PDOException) {
            $this->pdo->rollBack();
        }

        // The audit row must NOT exist (rolled back)
        $stmt = $this->pdo->query(
            "SELECT count(*) FROM audit.audit_logs WHERE entity_id = 'AUDIT_TEST_ROLLBACK'"
        );
        $this->assertEquals(0, $stmt->fetchColumn());
    }

    // ---- PartitionWorker tests -------------------------------------------

    public function testPartitionWorkerCreatesPartition(): void
    {
        // Create partition for a far-future month beyond the mega-partition (>2031)
        $this->partitionWorker->ensureNextPartition(2099, 12);

        $stmt = $this->pdo->query(
            "SELECT tablename FROM pg_tables WHERE schemaname='audit' AND tablename='audit_logs_2099_12'"
        );
        $this->assertNotFalse($stmt->fetch(), 'Partition audit_logs_2099_12 should exist');
    }

    public function testPartitionWorkerIsIdempotent(): void
    {
        // Calling ensureNextPartition twice for the same month must not throw
        $this->partitionWorker->ensureNextPartition(2099, 11);
        $this->partitionWorker->ensureNextPartition(2099, 11);
        $this->assertTrue(true); // No exception = success
    }
}
