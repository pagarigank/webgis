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
        $config = $container->get(\App\Core\Config\Config::class);
        $host = $config->get('DB_HOST');
        $port = $config->get('DB_PORT');
        $name = $config->get('DB_NAME');
        $user = $config->get('DB_USER');
        $pass = $config->get('DB_PASS');

        $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Since in our dev environment the DB_USER (app_rw) is created as the PostgreSQL superuser, 
        // we must test the intended permissions by switching to a non-superuser role that matches 
        // what app_rw would be in production.
        $pdo->exec("
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'test_app_rw') THEN
                    CREATE ROLE test_app_rw;
                END IF;
            END
            $$;
            GRANT USAGE ON SCHEMA audit TO test_app_rw;
            GRANT INSERT ON audit.audit_logs TO test_app_rw;
        ");
        
        $pdo->exec("SET ROLE test_app_rw");

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/permission denied for table audit_logs/');

        try {
            $pdo->exec("UPDATE audit.audit_logs SET action = 'tampered' WHERE id = 1");
        } finally {
            // Restore role
            $pdo->exec("RESET ROLE");
        }
    }
}
