<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

class OrganizationAdminTest extends TestCase
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

        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('ROLE_OADM', 'Org Admin', false)");
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'ROLE_OADM'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) SELECT $roleId, p.id FROM app.permissions p WHERE p.code = 'system.config'");

        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, status, version) VALUES ('oadmtest', 'oadm@example.com', 'dummy', 'Org Admin', 'ACTIVE', 1)");
        $this->adminUserId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'oadmtest'")->fetchColumn();
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
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'oadmtest'");
        $this->pdo->exec("DELETE FROM app.roles WHERE code = 'ROLE_OADM'");
        $this->pdo->exec("UPDATE app.organizations SET parent_id = NULL WHERE code IN ('OADMMAIN', 'OADMCHILD', 'OADMTOGO')");
        $this->pdo->exec("DELETE FROM app.users WHERE org_id IN (SELECT id FROM app.organizations WHERE code IN ('OADMMAIN', 'OADMCHILD', 'OADMTOGO'))");
        $this->pdo->exec("DELETE FROM app.organizations WHERE code IN ('OADMMAIN', 'OADMCHILD', 'OADMTOGO')");
        $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.organizations'");
    }

    /** @param array<string,mixed> $data */
    private function json(ServerRequestInterface $request, array $data): ServerRequestInterface
    {
        $request->getBody()->write(json_encode($data));
        return $request->withHeader('Content-Type', 'application/json');
    }

    private function createMain(): int
    {
        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('OADMMAIN', 'Main Office', 'OFFICE', 'ACTIVE')");
        return (int) $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'OADMMAIN'")->fetchColumn();
    }

    public function testCreateGetUpdateOrganisation(): void
    {
        $app = $this->getAppInstance();
        $response = $app->handle($this->json($this->createRequest('POST', '/api/v1/organizations'), [
            'code' => 'OADMMAIN', 'name' => 'Main Office', 'org_type' => 'OFFICE',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('ACTIVE', $data['data']['status']);
        $this->assertEquals(1, $data['data']['version']);
        $id = $data['data']['id'];

        $response = $app->handle($this->createRequest('GET', "/api/v1/organizations/$id")->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(200, $response->getStatusCode());

        $response = $app->handle($this->json($this->createRequest('PUT', "/api/v1/organizations/$id"), ['name' => 'Renamed Office'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('If-Match', '1'));
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('Renamed Office', $data['data']['name']);
        $this->assertEquals(2, $data['data']['version']);
    }

    public function testUpdateRequiresIfMatch(): void
    {
        $orgId = $this->createMain();
        $app = $this->getAppInstance();

        $response = $app->handle($this->json($this->createRequest('PUT', "/api/v1/organizations/$orgId"), ['name' => 'X'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(428, $response->getStatusCode());

        $response = $app->handle($this->json($this->createRequest('PUT', "/api/v1/organizations/$orgId"), ['name' => 'X'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('If-Match', '99'));
        $this->assertEquals(409, $response->getStatusCode());
    }

    public function testCreateRejectsInvalidOrgType(): void
    {
        $app = $this->getAppInstance();
        $response = $app->handle($this->json($this->createRequest('POST', '/api/v1/organizations'), [
            'code' => 'OADMMAIN', 'name' => 'Main Office', 'org_type' => 'MEGACORP',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $data['error']['code']);
    }

    public function testCannotDeactivateWhileUsersAssigned(): void
    {
        $orgId = $this->createMain();
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('oadmstaff', 'oadmstaff@example.com', 'dummy', 'Staff', $orgId, 'ACTIVE', 1)");

        $app = $this->getAppInstance();
        $response = $app->handle($this->json($this->createRequest('POST', "/api/v1/organizations/$orgId/deactivate"), ['reason' => 'closure'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testDeactivateOrganisation(): void
    {
        $orgId = $this->createMain();
        $app = $this->getAppInstance();

        $response = $app->handle($this->json($this->createRequest('POST', "/api/v1/organizations/$orgId/deactivate"), ['reason' => 'Consolidated under regional office'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(200, $response->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT status FROM app.organizations WHERE id = :id');
        $stmt->execute([':id' => $orgId]);
        $this->assertSame('INACTIVE', $stmt->fetchColumn());
    }
}