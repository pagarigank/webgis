<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;
use PDOException;

class SchemaIdentityTest extends TestCase
{
    public function testDataScopeRequiresRefCodeOrGeom(): void
    {
        $app = $this->getAppInstance();
        $pdo = $app->getContainer()->get(PDO::class);

        // First, create a dummy org, role, user to satisfy FKs
        $pdo->exec("
            INSERT INTO app.organizations (code, name, org_type) 
            VALUES ('TEST_ORG', 'Test Org', 'OFFICE')
            ON CONFLICT DO NOTHING
        ");
        $stmt = $pdo->query("SELECT id FROM app.organizations WHERE code = 'TEST_ORG'");
        $orgId = $stmt->fetchColumn();

        $pdo->exec("
            INSERT INTO app.users (username, email, password_hash, full_name, org_id) 
            VALUES ('scopetest', 'scopetest@test.com', 'hash', 'Scope Test', $orgId)
            ON CONFLICT DO NOTHING
        ");
        $stmt = $pdo->query("SELECT id FROM app.users WHERE username = 'scopetest'");
        $userId = $stmt->fetchColumn();

        // Attempt to insert data_scope without both scope_ref_code and geom
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/ck_scope_target/');

        $pdo->exec("
            INSERT INTO app.data_scopes (user_id, scope_type, access_level, scope_ref_code, geom)
            VALUES ($userId, 'ORGANIZATION', 'VIEW', NULL, NULL)
        ");
    }
}
