<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use App\RBAC\LayerCapabilityResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class LayerPermissionTest extends TestCase
{
    private PDO $pdo;
    private LayerCapabilityResolver $resolver;
    private ArrayAdapter $cache;
    private int $testUserId;
    private int $roleAId;
    private int $roleBId;
    private int $testLayerId;

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

        // Ensure app.layer_permissions table exists in test DB (fallback if migration hasn't run)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS app.layer_permissions (
                layer_id bigint NOT NULL,
                role_id bigint NOT NULL,
                can_view boolean NOT NULL DEFAULT false,
                can_create boolean NOT NULL DEFAULT false,
                can_update boolean NOT NULL DEFAULT false,
                can_delete boolean NOT NULL DEFAULT false,
                can_approve boolean NOT NULL DEFAULT false,
                PRIMARY KEY (layer_id, role_id)
            )
        ");

        // Clean up from previous run if aborted
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'layertestuser'");
        $this->pdo->exec("DELETE FROM app.roles WHERE code IN ('ROLE_A', 'ROLE_B')");
        $this->pdo->exec("DELETE FROM app.gis_layers WHERE code = 'TEST_LAYER'");

        // 1. Create a dummy layer
        $this->pdo->exec("
            INSERT INTO app.gis_layers (code, name, geometry_type) 
            VALUES ('TEST_LAYER', 'Test Layer', 'POINT')
        ");
        $stmt = $this->pdo->query("SELECT id FROM app.gis_layers WHERE code = 'TEST_LAYER'");
        $this->testLayerId = (int)$stmt->fetchColumn();

        // 2. Create roles
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('ROLE_A', 'Role A', false)");
        $stmt = $this->pdo->query("SELECT id FROM app.roles WHERE code = 'ROLE_A'");
        $this->roleAId = (int)$stmt->fetchColumn();

        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('ROLE_B', 'Role B', false)");
        $stmt = $this->pdo->query("SELECT id FROM app.roles WHERE code = 'ROLE_B'");
        $this->roleBId = (int)$stmt->fetchColumn();

        // 3. Assign layer permissions to roles
        // Role A can view and update
        $this->pdo->exec("
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_update)
            VALUES ({$this->testLayerId}, {$this->roleAId}, true, true)
        ");
        
        // Role B can view and delete
        $this->pdo->exec("
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_delete)
            VALUES ({$this->testLayerId}, {$this->roleBId}, true, true)
        ");

        // 4. Create a user
        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, password_hash, full_name, status, version) 
            VALUES ('layertestuser', 'layer@example.com', 'dummy', 'Layer Test User', 'ACTIVE', 1) 
            RETURNING id
        ");
        $stmt->execute();
        $this->testUserId = (int)$stmt->fetchColumn();

        // Set up resolver with a fast in-memory array cache
        $this->cache = new ArrayAdapter();
        $psr16Cache = new Psr16Cache($this->cache);
        
        $this->resolver = new LayerCapabilityResolver($this->pdo, $psr16Cache);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM app.users WHERE id = {$this->testUserId}");
        $this->pdo->exec("DELETE FROM app.roles WHERE id IN ({$this->roleAId}, {$this->roleBId})");
        $this->pdo->exec("DELETE FROM app.gis_layers WHERE id = {$this->testLayerId}");
    }

    public function testAggregateCapabilitiesForSingleRole(): void
    {
        // Assign only Role A
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ({$this->testUserId}, {$this->roleAId})");

        $caps = $this->resolver->getLayerCapabilities($this->testUserId, 1, $this->testLayerId);
        
        $this->assertTrue($caps['can_view']);
        $this->assertFalse($caps['can_create']);
        $this->assertTrue($caps['can_update']);
        $this->assertFalse($caps['can_delete']);
    }

    public function testAggregateCapabilitiesForMultipleRoles(): void
    {
        // Assign Role A and Role B
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ({$this->testUserId}, {$this->roleAId})");
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ({$this->testUserId}, {$this->roleBId})");

        // The user should have the union of both roles' permissions
        // view=true(A,B), update=true(A), delete=true(B), create=false
        $caps = $this->resolver->getLayerCapabilities($this->testUserId, 1, $this->testLayerId);
        
        $this->assertTrue($caps['can_view']);
        $this->assertFalse($caps['can_create']);
        $this->assertTrue($caps['can_update']);
        $this->assertTrue($caps['can_delete']);
    }

    public function testUnassignedLayerReturnsAllFalse(): void
    {
        $caps = $this->resolver->getLayerCapabilities($this->testUserId, 1, 99999); // Non-existent layer
        
        $this->assertFalse($caps['can_view']);
        $this->assertFalse($caps['can_update']);
        $this->assertFalse($caps['can_delete']);
    }

    public function testCapabilitiesAreCachedAndInvalidated(): void
    {
        // 1. Assign Role A, compute caps (version 1)
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ({$this->testUserId}, {$this->roleAId})");
        $caps1 = $this->resolver->getLayerCapabilities($this->testUserId, 1, $this->testLayerId);
        $this->assertFalse($caps1['can_delete']); // Role A cannot delete

        // 2. Assign Role B directly in DB
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ({$this->testUserId}, {$this->roleBId})");

        // 3. Fetch again with version 1 (should hit cache and NOT see Role B)
        $caps2 = $this->resolver->getLayerCapabilities($this->testUserId, 1, $this->testLayerId);
        $this->assertFalse($caps2['can_delete']); 

        // 4. Fetch with version 2 (simulating admin increment) - should read fresh from DB
        $caps3 = $this->resolver->getLayerCapabilities($this->testUserId, 2, $this->testLayerId);
        $this->assertTrue($caps3['can_delete']); 
    }
}
