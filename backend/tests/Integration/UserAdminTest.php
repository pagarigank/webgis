<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    private const SECRET = 'dummy_secret_for_testing_long_enough_for_hs256_0123456789';
    private const BRGY  = '037105001';
    private const MUN   = '037105000';
    private const PROV  = '037100000';

    private PDO $pdo;
    private int $orgId;
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

        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('UADMORG', 'Admin Org', 'GOVERNMENT', 'ACTIVE')");
        $this->orgId = (int) $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'UADMORG'")->fetchColumn();

        $this->pdo->exec("INSERT INTO ref.psgc_areas (code, level, name) VALUES ('" . self::BRGY . "', 'BARANGAY', 'Test Barangay'), ('" . self::MUN . "', 'MUNICIPALITY', 'Test Municipal'), ('" . self::PROV . "', 'PROVINCE', 'Test Province')");

        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('ROLE_UADM', 'User Admin', false)");
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'ROLE_UADM'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) SELECT $roleId, p.id FROM app.permissions p WHERE p.code IN ('user.manage', 'scope.manage')");

        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('uadmtest', 'uadm@example.com', 'dummy', 'Admin', $this->orgId, 'ACTIVE', 1)");
        $this->adminUserId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'uadmtest'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($this->adminUserId, $roleId)");

        $this->adminToken = $this->signToken($this->adminUserId);
        $this->setupJwtSecret();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code = 'PAR-UADM-1'");
        $this->pdo->exec("DELETE FROM app.data_scopes WHERE user_id IN (SELECT id FROM app.users WHERE username IN ('uadmtest', 'plaintest', 'newuser', 'scopetest')) OR granted_by IN (SELECT id FROM app.users WHERE username IN ('uadmtest', 'plaintest', 'newuser', 'scopetest'))");
        $this->pdo->exec("DELETE FROM app.users WHERE username IN ('uadmtest', 'plaintest', 'newuser', 'scopetest')");
        $this->pdo->exec("DELETE FROM app.roles WHERE code IN ('ROLE_UADM', 'ROLE_NEW')");
        $this->pdo->exec("DELETE FROM app.organizations WHERE code = 'UADMORG'");
        $this->pdo->exec("DELETE FROM ref.psgc_areas WHERE code IN ('" . self::BRGY . "', '" . self::MUN . "', '" . self::PROV . "')");
        $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type IN ('app.users', 'app.user_roles', 'app.data_scopes')");
    }

    private function setupJwtSecret(): void
    {
        putenv('JWT_SECRET=' . self::SECRET);
        $_SERVER['JWT_SECRET'] = self::SECRET;
        $_ENV['JWT_SECRET'] = self::SECRET;
    }

    private function signToken(int $userId, int $version = 1): string
    {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode(['sub' => (string) $userId, 'v' => $version, 'exp' => time() + 3600]));
        $signature = hash_hmac('sha256', "$header.$payload", self::SECRET, true);
        return "$header.$payload." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    }

    /** @param array<string,mixed> $data */
    private function json(ServerRequestInterface $request, array $data): ServerRequestInterface
    {
        $request->getBody()->write(json_encode($data));
        return $request->withHeader('Content-Type', 'application/json');
    }

    public function testUsersRouteRequiresAuthentication(): void
    {
        $app = $this->getAppInstance();
        $response = $app->handle($this->createRequest('GET', '/api/v1/users'));
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testUsersRouteForbidsUserWithoutPermission(): void
    {
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('plaintest', 'plain@example.com', 'dummy', 'Plain', $this->orgId, 'ACTIVE', 1)");
        $plainUserId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'plaintest'")->fetchColumn();
        $plainToken = $this->signToken($plainUserId);

        $app = $this->getAppInstance();
        $response = $app->handle($this->createRequest('GET', '/api/v1/users')->withHeader('Authorization', 'Bearer ' . $plainToken));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testCreateAndReadUser(): void
    {
        $app = $this->getAppInstance();
        $request = $this->json($this->createRequest('POST', '/api/v1/users'), [
            'username' => 'newuser',
            'email' => 'newuser@example.com',
            'password' => 'Str0ng!Pass123',
            'full_name' => 'New User',
            'org_id' => $this->orgId,
            'roles' => [],
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);

        $response = $app->handle($request);
        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('newuser', $data['data']['username']);
        $this->assertEquals(1, $data['data']['version']);
        $this->assertEquals('ACTIVE', $data['data']['status']);

        $id = $data['data']['id'];
        $response = $app->handle($this->createRequest('GET', "/api/v1/users/$id")->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testCreateUserRejectsWeakPassword(): void
    {
        $app = $this->getAppInstance();
        $request = $this->json($this->createRequest('POST', '/api/v1/users'), [
            'username' => 'newuser',
            'email' => 'newuser@example.com',
            'password' => 'short',
            'full_name' => 'New User',
            'org_id' => $this->orgId,
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);

        $response = $app->handle($request);
        $this->assertEquals(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('VALIDATION_FAILED', $data['error']['code']);
        $this->assertNotEmpty($data['error']['details']['fields']);
    }

    public function testUpdateUserVersionSemantics(): void
    {
        $app = $this->getAppInstance();
        $create = $this->json($this->createRequest('POST', '/api/v1/users'), [
            'username' => 'newuser', 'email' => 'newuser@example.com',
            'password' => 'Str0ng!Pass123', 'full_name' => 'New User', 'org_id' => $this->orgId,
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $created = json_decode((string) $app->handle($create)->getBody(), true);
        $id = $created['data']['id'];

        $noMatch = $this->json($this->createRequest('PUT', "/api/v1/users/$id"), ['full_name' => 'Renamed'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $this->assertEquals(428, $app->handle($noMatch)->getStatusCode());

        $stale = $this->json($this->createRequest('PUT', "/api/v1/users/$id"), ['full_name' => 'Renamed'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('If-Match', '99');
        $response = $app->handle($stale);
        $this->assertEquals(409, $response->getStatusCode());

        $fresh = $this->json($this->createRequest('PUT', "/api/v1/users/$id"), ['full_name' => 'Renamed'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('If-Match', '1');
        $response = $app->handle($fresh);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertEquals(2, $data['data']['version']);
        $this->assertEquals('Renamed', $data['data']['full_name']);
    }

    public function testSetRolesRequiresReasonAndAudits(): void
    {
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('ROLE_NEW', 'New Role', false)");
        $app = $this->getAppInstance();

        $target = $this->json($this->createRequest('POST', '/api/v1/users'), [
            'username' => 'newuser', 'email' => 'newuser@example.com',
            'password' => 'Str0ng!Pass123', 'full_name' => 'New User', 'org_id' => $this->orgId,
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $userId = json_decode((string) $app->handle($target)->getBody(), true)['data']['id'];

        $noReason = $this->json($this->createRequest('PUT', "/api/v1/users/$userId/roles"), ['roles' => [['code' => 'ROLE_NEW']]])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $response = $app->handle($noReason);
        $this->assertEquals(422, $response->getStatusCode());

        $withReason = $this->json($this->createRequest('PUT', "/api/v1/users/$userId/roles"), [
            'roles' => [['code' => 'ROLE_NEW']], 'reason' => 'Grant survey access (TASK-034 test)',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $response = $app->handle($withReason);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('ROLE_NEW', $data['data']['roles'][0]['code']);
        $this->assertEquals(2, $data['data']['version']);

        $stmt = $this->pdo->prepare("SELECT reason FROM audit.audit_logs WHERE entity_type = 'app.user_roles' AND entity_id = :id ORDER BY id DESC LIMIT 1");
        $stmt->execute([':id' => (string) $userId]);
        $this->assertSame('Grant survey access (TASK-034 test)', $stmt->fetchColumn());
    }

    public function testSetAndListScopes(): void
    {
        $app = $this->getAppInstance();
        $created = json_decode((string) $app->handle($this->json($this->createRequest('POST', '/api/v1/users'), [
            'username' => 'newuser', 'email' => 'newuser@example.com',
            'password' => 'Str0ng!Pass123', 'full_name' => 'New User', 'org_id' => $this->orgId,
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken))->getBody(), true);
        $userId = $created['data']['id'];

        $bad = $this->json($this->createRequest('PUT', "/api/v1/users/$userId/scopes"), [
            'scopes' => [['type' => 'BARANGAY', 'ref_code' => self::BRGY, 'access_level' => 'WRITE']],
            'reason' => 'assign scope',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $this->assertEquals(422, $app->handle($bad)->getStatusCode());

        $ok = $this->json($this->createRequest('PUT', "/api/v1/users/$userId/scopes"), [
            'scopes' => [['type' => 'BARANGAY', 'ref_code' => self::BRGY, 'access_level' => 'EDIT']],
            'reason' => 'Enable edit access to barangay (TASK-034)',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $response = $app->handle($ok);
        $this->assertEquals(200, $response->getStatusCode());

        $response = $app->handle($this->createRequest('GET', "/api/v1/users/$userId/scopes")->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $data = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $data['data']['scopes']);
        $this->assertSame('BARANGAY', $data['data']['scopes'][0]['type']);
        $this->assertSame('EDIT', $data['data']['scopes'][0]['access']);
        $this->assertSame('Test Barangay', $data['data']['scopes'][0]['name']);
    }

    public function testForcePasswordReset(): void
    {
        $app = $this->getAppInstance();
        $created = json_decode((string) $app->handle($this->json($this->createRequest('POST', '/api/v1/users'), [
            'username' => 'newuser', 'email' => 'newuser@example.com',
            'password' => 'Str0ng!Pass123', 'full_name' => 'New User', 'org_id' => $this->orgId,
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken))->getBody(), true);
        $userId = $created['data']['id'];

        $response = $app->handle($this->json($this->createRequest('POST', "/api/v1/users/$userId/force-password-reset"), ['reason' => 'Compromised credentials'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertStringStartsWith('Tmp!', $data['data']['temporary_password']);

        $stmt = $this->pdo->prepare('SELECT must_change_password FROM app.users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $this->assertEquals(1, (int) $stmt->fetchColumn());
    }

    public function testDeactivateUser(): void
    {
        $app = $this->getAppInstance();
        $created = json_decode((string) $app->handle($this->json($this->createRequest('POST', '/api/v1/users'), [
            'username' => 'newuser', 'email' => 'newuser@example.com',
            'password' => 'Str0ng!Pass123', 'full_name' => 'New User', 'org_id' => $this->orgId,
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken))->getBody(), true);
        $userId = $created['data']['id'];

        $response = $app->handle($this->json($this->createRequest('POST', "/api/v1/users/$userId/deactivate"), ['reason' => 'Separated'])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(200, $response->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT status, deleted_at FROM app.users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        $this->assertSame('DISABLED', $row['status']);
        $this->assertNotNull($row['deleted_at']);
    }

    public function testEffectiveAccessMatchesParcelScope(): void
    {
        $app = $this->getAppInstance();

        $created = json_decode((string) $app->handle($this->json($this->createRequest('POST', '/api/v1/users'), [
            'username' => 'scopetest', 'email' => 'scopetest@example.com',
            'password' => 'Str0ng!Pass123', 'full_name' => 'Scope User', 'org_id' => $this->orgId,
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken))->getBody(), true);
        $userId = $created['data']['id'];

        $this->pdo->exec("INSERT INTO app.parcels (id, parcel_code, psgc_barangay, psgc_municipality, psgc_province, org_id) VALUES (gen_random_uuid(), 'PAR-UADM-1', '" . self::BRGY . "', '" . self::MUN . "', '" . self::PROV . "', $this->orgId)");
        $parcelId = (string) $this->pdo->query("SELECT id FROM app.parcels WHERE parcel_code = 'PAR-UADM-1'")->fetchColumn();

        $this->pdo->exec("INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level) VALUES ($userId, 'BARANGAY', '" . self::BRGY . "', 'EDIT')");

        $response = $app->handle($this->createRequest('GET', "/api/v1/users/$userId/effective-access?entity_type=parcel&entity_id=$parcelId")
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('parcel', $data['data']['entity']['type']);
        $this->assertSame('EDIT', $data['data']['entity_access']['decision']);
        $this->assertTrue($data['data']['entity_access']['granted']);
        $this->assertCount(1, $data['data']['entity_access']['matched_scopes']);
    }

    public function testEffectiveAccessUnsupportedEntityType(): void
    {
        $app = $this->getAppInstance();
        $response = $app->handle($this->createRequest('GET', "/api/v1/users/$this->adminUserId/effective-access?entity_type=title&entity_id=1")
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertEquals(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $data['error']['code']);
    }
}