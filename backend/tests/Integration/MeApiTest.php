<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use Tests\TestCase;

class MeApiTest extends TestCase
{
    private PDO $pdo;
    private int $testUserId;
    private string $testToken;

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

        // Clean up from previous run
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'metestuser'");
        $this->pdo->exec("DELETE FROM app.roles WHERE code = 'ROLE_ME_TEST'");
        $this->pdo->exec("DELETE FROM app.permissions WHERE code = 'me.test.view'");
        $this->pdo->exec("DELETE FROM app.gis_layers WHERE code = 'ME_TEST_LAYER'");
        $this->pdo->exec("DELETE FROM ref.psgc_areas WHERE code = '012800000'");

        // 1. Create a permission
        $this->pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('me.test.view', 'Test')");
        $stmt = $this->pdo->query("SELECT id FROM app.permissions WHERE code = 'me.test.view'");
        $permId = (int)$stmt->fetchColumn();

        // 2. Create a role and assign permission
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('ROLE_ME_TEST', 'Me Test', false)");
        $stmt = $this->pdo->query("SELECT id FROM app.roles WHERE code = 'ROLE_ME_TEST'");
        $roleId = (int)$stmt->fetchColumn();

        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($roleId, $permId)");

        // 3. Create a layer and assign capabilities
        $this->pdo->exec("INSERT INTO app.gis_layers (code, name, geometry_type) VALUES ('ME_TEST_LAYER', 'Layer', 'POINT')");
        $stmt = $this->pdo->query("SELECT id FROM app.gis_layers WHERE code = 'ME_TEST_LAYER'");
        $layerId = (int)$stmt->fetchColumn();

        $this->pdo->exec("INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create) VALUES ($layerId, $roleId, true, true)");

        // 4. Create PSGC area for scope
        $this->pdo->exec("INSERT INTO ref.psgc_areas (code, level, name) VALUES ('012800000', 'PROVINCE', 'Test Province')");

        // 5. Create user
        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, password_hash, full_name, status, version) 
            VALUES ('metestuser', 'me@example.com', 'dummy', 'Me Test', 'ACTIVE', 1) 
            RETURNING id
        ");
        $stmt->execute();
        $this->testUserId = (int)$stmt->fetchColumn();

        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ({$this->testUserId}, $roleId)");
        
        // 6. Assign scope
        $this->pdo->exec("INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level) VALUES ({$this->testUserId}, 'PROVINCE', '012800000', 'EDIT')");

        // 7. Generate a real JWT signed with the same secret the app will use
        $jwtSecret = 'dummy_secret_for_testing_long_enough_for_hs256_0123456789';
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode([
            'sub' => (string)$this->testUserId,
            'v' => 1,
            'exp' => time() + 3600
        ]));
        
        $signature = hash_hmac('sha256', "$header.$payload", $jwtSecret, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        $this->testToken = "$header.$payload.$base64UrlSignature";
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM app.users WHERE id = {$this->testUserId}");
        $this->pdo->exec("DELETE FROM app.roles WHERE code = 'ROLE_ME_TEST'");
        $this->pdo->exec("DELETE FROM app.permissions WHERE code = 'me.test.view'");
        $this->pdo->exec("DELETE FROM app.gis_layers WHERE code = 'ME_TEST_LAYER'");
        $this->pdo->exec("DELETE FROM ref.psgc_areas WHERE code = '012800000'");
    }

    public function testGetMeRequiresAuthentication(): void
    {
        $app = $this->getAppInstance();
        $request = $this->createRequest('GET', '/api/v1/me');
        
        $response = $app->handle($request);
        
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testGetMeReturnsFullProfileAndEffectiveAccess(): void
    {
        $jwtSecret = 'dummy_secret_for_testing_long_enough_for_hs256_0123456789';
        putenv("JWT_SECRET=$jwtSecret");
        $_SERVER['JWT_SECRET'] = $jwtSecret;
        $_ENV['JWT_SECRET'] = $jwtSecret;

        $app = $this->getAppInstance();
        $request = $this->createRequest('GET', '/api/v1/me')
                        ->withHeader('Authorization', 'Bearer ' . $this->testToken);
        
        $response = $app->handle($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        
        $payload = $data['data'];
        
        $this->assertEquals($this->testUserId, $payload['user']['id']);
        $this->assertEquals('metestuser', $payload['user']['username']);
        
        $this->assertContains('ROLE_ME_TEST', $payload['roles']);
        $this->assertContains('me.test.view', $payload['permissions']);
        
        // Assert scopes
        $this->assertCount(1, $payload['scopes']);
        $this->assertEquals('PROVINCE', $payload['scopes'][0]['type']);
        $this->assertEquals('012800000', $payload['scopes'][0]['code']);
        $this->assertEquals('Test Province', $payload['scopes'][0]['name']);
        $this->assertEquals('EDIT', $payload['scopes'][0]['access']);
        
        $this->assertEquals(1, $payload['scope_version']);
    }
}
