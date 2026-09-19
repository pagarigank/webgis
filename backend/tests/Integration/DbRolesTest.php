<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;
use PDOException;

class DbRolesTest extends TestCase
{
    public function testAppRwCannotUpdateAuditLogs(): void
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);

        // We assume the PDO connection uses the app_rw role (or equivalent) in testing.
        // We will attempt to UPDATE an audit log row and expect a permission error.
        
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/permission denied for table audit_logs/');

        $pdo->exec("UPDATE audit_logs SET action = 'tampered' WHERE id = 1");
    }
}
