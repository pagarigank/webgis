<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;

class RlsTest extends TestCase
{
    private PDO $pdo;
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Connect as the default user (superuser in dev)
        $this->pdo = $this->getAppInstance()->getContainer()->get(PDO::class);
        
        // Setup test user
        $this->pdo->exec("DELETE FROM app.users WHERE email = 'rls_test_user@example.com'");
        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, full_name, password_hash, status) 
            VALUES ('rls_test_user', 'rls_test_user@example.com', 'RLS Test', 'hash', 'ACTIVE') RETURNING id
        ");
        $stmt->execute();
        $this->testUserId = (int) $stmt->fetchColumn();
        
        // Create a specific barangay scope for this user
        // Note: PSGC scope types are the geographic BARANGAY/MUNICIPALITY/PROVINCE/REGION types
        $this->pdo->exec("
            INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level)
            VALUES ({$this->testUserId}, 'BARANGAY', '133901001', 'VIEW')
        ");
        
        // Insert test parcels
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code IN ('RLS_TEST_PARCEL_1', 'RLS_TEST_PARCEL_2')");
        
        // Parcel 1 is in scope
        $this->pdo->exec("
            INSERT INTO app.parcels (id, parcel_code, psgc_barangay)
            VALUES ('33333333-3333-3333-3333-333333333333', 'RLS_TEST_PARCEL_1', '133901001')
        ");
        
        // Parcel 2 is out of scope
        $this->pdo->exec("
            INSERT INTO app.parcels (id, parcel_code, psgc_barangay)
            VALUES ('44444444-4444-4444-4444-444444444444', 'RLS_TEST_PARCEL_2', '041005001')
        ");
        
        // Setup a non-superuser role to test RLS
        $this->pdo->exec("
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'test_app_rw') THEN
                    CREATE ROLE test_app_rw;
                END IF;
            END
            \$\$;
            GRANT USAGE ON SCHEMA app TO test_app_rw;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA app TO test_app_rw;
        ");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("RESET ROLE");
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code IN ('RLS_TEST_PARCEL_1', 'RLS_TEST_PARCEL_2')");
        $this->pdo->exec("DELETE FROM app.data_scopes WHERE user_id = {$this->testUserId}");
        $this->pdo->exec("DELETE FROM app.users WHERE id = {$this->testUserId}");
        
        parent::tearDown();
    }

    public function testRlsRestrictsAccessForOutOfScopeRows(): void
    {
        // Switch to the unprivileged application role to enforce RLS
        $this->pdo->exec("SET ROLE test_app_rw");
        
        // 1. Set app.user_id to the test user
        $this->pdo->exec("SET app.user_id = '{$this->testUserId}'");

        // 2. Query parcels
        $stmt = $this->pdo->query("SELECT parcel_code FROM app.parcels WHERE parcel_code IN ('RLS_TEST_PARCEL_1', 'RLS_TEST_PARCEL_2')");
        $parcels = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Should only see PARCEL_1 (in scope)
        $this->assertCount(1, $parcels);
        $this->assertEquals('RLS_TEST_PARCEL_1', $parcels[0]);
    }
    
    public function testRlsReturnsZeroRowsWhenNoUserIdSet(): void
    {
        $this->pdo->exec("SET ROLE test_app_rw");
        // Ensure no user_id is set
        $this->pdo->exec("RESET app.user_id");
        
        $stmt = $this->pdo->query("SELECT parcel_code FROM app.parcels WHERE parcel_code IN ('RLS_TEST_PARCEL_1', 'RLS_TEST_PARCEL_2')");
        $parcels = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $parcels);
    }
}
