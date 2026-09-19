<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;

class RlsTest extends TestCase
{
    public function testUnprivilegedQueryReturnsZeroRowsOnRlsTable(): void
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);

        // Clear any role bindings just in case
        $pdo->exec("SET LOCAL app.user_id = ''");

        // The audit_logs table has RLS. It should return 0 rows regardless of contents
        // when app.user_id is not set.
        $stmt = $pdo->query("SELECT count(*) FROM audit_logs");
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(0, $count, 'RLS should block access and return 0 rows for unprivileged query');
    }
}
