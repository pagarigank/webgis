<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

class RoleAdminTest extends TestCase
{
    private const SECRET = 'dummy_secret_for_testing_long_enough_for_hs256_0123456789';

    private PDO $pdo;
    private int $adminUserId;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            'pgsql:host=' . (getenv('DB_HOST') ?: 'postgres') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . (getenv('DB_NAME') ?: 'webgis'),
            getenv('DB_USER') ?: 'postgres',
            getenv('DB_PASS') ?: 'postgres',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $this->cleanup();

        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('ROLE_RADM', 'Role Admin', false)");
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'ROLE_RADM'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) SELECT $roleId, p.id FROM app.permissions p WHERE p.code = 'role.manage'");

        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, status, version) VALUES ('radmtest', 'radm@example.com', 'dummy', 'Role Admin', 'ACTIVE', 1)");
        $this->adminUserId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'radmtest'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($this->adminUserId, $roleId)");

        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode(['sub' => (string) $this->adminUserId, 'v' => 1, 'exp' => time() + 3600]));
        $signature = hash_hmac('sha256', "$header.$payload", self::SECRET, true);
        $this->adminToken = "$header.$payload." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        putenv('JWT_SECRET=' . self::SECRET);
        $_SERVER['JWT_SECRET'] = self::SECRET;
        $_ENV['JWT_SECRET'] = self::SECRET;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'radmtest'");
        $this->pdo->exec("DELETE FROM app.roles WHERE code IN ('ROLE_RADM', 'ROLE_NEW')");
        $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.roles'");
    }

    /** @param array<string,mixed> $data */
    private function json(ServerRequestInterface $request, array $data): ServerRequestInterface
    {
        $request->getBody()->write(json_encode($data));
        return $request->withHeader('Content-Type', 'application/json');
    }

    public function testListPermissionsCatalogue(): void
    {
        $app = $this->getAppInstance();
        $response = $app->handle($this->createRequest('GET', '/api/v1/permissions?per_page=500')->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['success']);
        $codes = array_column($data['data']['data'], 'code');
        $this->assertContains('user.manage', $codes);
        $this->assertContains('role.manage', $codes);
        $this->assertContains('scope.manage', $codes);
        $this->assertContains('system.config', $codes);
        $this->assertGreaterThanOrEqual(61, $data['data']['meta']['total']);
    }

    public function testCreateAndGetRole(): void
    {
        $app = $this->getAppInstance();
        $response = $app->handle($this->json($this->createRequest('POST', '/api/v1/roles'), [
            'code' => 'ROLE_NEW', 'name' => 'New Role', 'description' => 'created in test',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertFalse($data['data']['is_system']);
        $this->assertSame([], $data['data']['permissions']);

        $id = $data['data']['id'];
        $response = $app->handle($this->createRequest('GET', "/api/v1/roles/$id")->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSetPermissionsRequiresReason(): void
    {
        $app = $this->getAppInstance();
        $created = json_decode((string) $app->handle($this->json($this->createRequest('POST', '/api/v1/roles'), [
            'code' => 'ROLE_NEW', 'name' => 'New Role',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken))->getBody(), true);
        $id = $created['data']['id'];

        $noReason = $this->json($this->createRequest('PUT', "/api/v1/roles/$id/permissions"), ['permissions' => ['user.manage']])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $response = $app->handle($noReason);
        $this->assertEquals(422, $response->getStatusCode());

        $withReason = $this->json($this->createRequest('PUT', "/api/v1/roles/$id/permissions"), [
            'permissions' => ['user.manage'], 'reason' => 'Delegate user administration (TASK-034)',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $response = $app->handle($withReason);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertContains('user.manage', $data['data']['permissions']);
    }

    public function testSetPermissionsRejectsUnknownCode(): void
    {
        $app = $this->getAppInstance();
        $created = json_decode((string) $app->handle($this->json($this->createRequest('POST', '/api/v1/roles'), [
            'code' => 'ROLE_NEW', 'name' => 'New Role',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken))->getBody(), true);
        $id = $created['data']['id'];

        $response = $app->handle($this->json($this->createRequest('PUT', "/api/v1/roles/$id/permissions"), [
            'permissions' => ['does.not.exist'], 'reason' => 'test',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testCannotDeleteSystemRole(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM app.roles WHERE code = 'SYS_ADMIN'");
        $id = (int) $stmt->fetchColumn();

        $app = $this->getAppInstance();
        $response = $app->handle($this->json($this->createRequest('DELETE', "/api/v1/roles/$id"), ['reason' => 'attempt'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $data['error']['code']);
    }

    public function testCannotDeleteRoleInUse(): void
    {
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('ROLE_NEW', 'New Role', false)");
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'ROLE_NEW'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($this->adminUserId, $roleId)");

        $app = $this->getAppInstance();
        $response = $app->handle($this->json($this->createRequest('DELETE', "/api/v1/roles/$roleId"), ['reason' => 'attempt'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testUpdateRole(): void
    {
        $app = $this->getAppInstance();
        $created = json_decode((string) $app->handle($this->json($this->createRequest('POST', '/api/v1/roles'), [
            'code' => 'ROLE_NEW', 'name' => 'New Role',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken))->getBody(), true);
        $id = $created['data']['id'];

        $response = $app->handle($this->json($this->createRequest('PUT', "/api/v1/roles/$id"), ['description' => 'updated'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('updated', $data['data']['description']);
    }
}