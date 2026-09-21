<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Core\Config\Config;
use Tests\TestCase;

class FieldRetypeTest extends TestCase
{
    private array $adminUser;
    private array $layer;
    private array $field;
    private array $features = [];
    private \PDO $pdo;
    private \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->pdo = new \PDO(
            'pgsql:host=' . (getenv('DB_HOST') ?: 'postgres') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . (getenv('DB_NAME') ?: 'webgis'),
            getenv('DB_USER') ?: 'postgres',
            getenv('DB_PASS') ?: 'postgres',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
        );

        putenv('JWT_SECRET=dummy_secret_for_testing_long_enough_for_hs256_0123456789');
        $_SERVER['JWT_SECRET'] = 'dummy_secret_for_testing_long_enough_for_hs256_0123456789';
        $_ENV['JWT_SECRET'] = 'dummy_secret_for_testing_long_enough_for_hs256_0123456789';

        $this->app = $this->getAppInstance();

        // Ensure necessary schemas exist (simplified for tests)
        $this->pdo->exec("CREATE SCHEMA IF NOT EXISTS app");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS app.gis_layers (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code varchar(100) UNIQUE, name varchar(100), geometry_type varchar(20), srid int,
                feature_count_cache int DEFAULT 0,
                deleted_at timestamptz
            );
            CREATE TABLE IF NOT EXISTS app.gis_layer_fields (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                layer_id bigint, field_name varchar(50), field_label varchar(100),
                field_type varchar(20), required boolean, default_value jsonb, options jsonb, validation_rules jsonb,
                deleted_at timestamptz
            );
            CREATE TABLE IF NOT EXISTS app.gis_features (
                id uuid PRIMARY KEY, layer_id bigint, geom text, attributes jsonb,
                deleted_at timestamptz
            );
        ");

        $this->cleanup();

        $this->adminUser = $this->createRetypeMockUser(['layer.manage'], ['SYS_ADMIN']);
        
        $stmt = $this->pdo->prepare("INSERT INTO app.gis_layers (code, name, geometry_type, srid) VALUES ('test_layer', 'Test Layer', 'POINT', 4326) RETURNING id");
        $stmt->execute();
        $this->layer = ['id' => (int) $stmt->fetchColumn()];

        $stmt = $this->pdo->prepare("
            INSERT INTO app.gis_layer_fields (layer_id, field_name, field_label, field_type, required)
            VALUES (?, 'age_str', 'Age Str', 'text', false) RETURNING id
        ");
        $stmt->execute([$this->layer['id']]);
        $this->field = ['id' => (int) $stmt->fetchColumn(), 'field_name' => 'age_str'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        // Scope every DELETE to this test's own rows. Unbounded deletes here
        // used to wipe seed/fixture data (gis_features for every layer) and
        // break FixtureLoadTest depending on execution order.
        $this->pdo->exec("
            DELETE FROM audit.gis_feature_versions v
            USING app.gis_features f
            WHERE v.feature_id = f.id
              AND f.layer_id IN (SELECT id FROM app.gis_layers WHERE code = 'test_layer')
        ");
        $this->pdo->exec("DELETE FROM app.gis_features WHERE layer_id IN (SELECT id FROM app.gis_layers WHERE code = 'test_layer')");
        $this->pdo->exec("DELETE FROM app.gis_layer_fields WHERE layer_id IN (SELECT id FROM app.gis_layers WHERE code = 'test_layer')");
        $this->pdo->exec("DELETE FROM app.gis_layers WHERE code = 'test_layer'");
    }

    private function createRetypeMockUser(array $permissions, array $roles): array
    {
        // Organization
        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('TESTORG', 'Test Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT DO NOTHING");
        $orgId = (int) $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'TESTORG'")->fetchColumn();

        // User
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('testuser', 'test@example.com', 'dummy', 'Test', $orgId, 'ACTIVE', 1) ON CONFLICT DO NOTHING");
        $userId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'testuser'")->fetchColumn();

        // Roles & Permissions
        foreach ($roles as $roleCode) {
            $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('$roleCode', '$roleCode', false) ON CONFLICT DO NOTHING");
            $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = '$roleCode'")->fetchColumn();
            $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($userId, $roleId) ON CONFLICT DO NOTHING");

            foreach ($permissions as $permCode) {
                $this->pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('$permCode', '$permCode') ON CONFLICT DO NOTHING");
                $permId = (int) $this->pdo->query("SELECT id FROM app.permissions WHERE code = '$permCode'")->fetchColumn();
                $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($roleId, $permId) ON CONFLICT DO NOTHING");
            }
        }

        $token = \Firebase\JWT\JWT::encode([
            'sub' => (string) $userId, 'v' => 1, 'exp' => time() + 3600
        ], getenv('JWT_SECRET'), 'HS256');

        return ['id' => $userId, 'token' => $token];
    }
    
    private function insertFeature(array $attributes): string
    {
        $id = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $stmt = $this->pdo->prepare("INSERT INTO app.gis_features (id, layer_id, geom, attributes) VALUES (?, ?, 'POINT(0 0)', ?)");
        $stmt->execute([$id, $this->layer['id'], json_encode($attributes)]);
        $this->features[] = $id;
        return $id;
    }

    private function json(\Psr\Http\Message\ServerRequestInterface $request, array $data): \Psr\Http\Message\ServerRequestInterface
    {
        $request->getBody()->write(json_encode($data));
        return $request->withHeader('Content-Type', 'application/json');
    }

    public function testPreviewConvertibleValues(): void
    {
        $this->insertFeature(['age_str' => '25']);
        $this->insertFeature(['age_str' => '30']);

        $req = $this->json($this->createRequest('POST', "/api/v1/layers/{$this->layer['id']}/fields/{$this->field['id']}/retype-preview"), [
            'field_type' => 'integer'
        ])->withHeader('Authorization', 'Bearer ' . $this->adminUser['token']);
        
        $res = $this->app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        
        $body = json_decode((string) $res->getBody(), true);
        $this->assertEquals(2, $body['data']['convertible_count']);
        $this->assertEquals(0, $body['data']['failing_count']);
    }

    public function testPreviewWithFailingValues(): void
    {
        $this->insertFeature(['age_str' => '25']);
        $this->insertFeature(['age_str' => 'abc']); // Unconvertible to int

        $req = $this->json($this->createRequest('POST', "/api/v1/layers/{$this->layer['id']}/fields/{$this->field['id']}/retype-preview"), [
            'field_type' => 'integer'
        ])->withHeader('Authorization', 'Bearer ' . $this->adminUser['token']);
        
        $res = $this->app->handle($req);
        $body = json_decode((string) $res->getBody(), true);
        
        $this->assertEquals(1, $body['data']['convertible_count']);
        $this->assertEquals(1, $body['data']['failing_count']);
        $this->assertCount(1, $body['data']['failing_examples']);
        $this->assertEquals('abc', $body['data']['failing_examples'][0]['value']);
    }

    public function testPreviewWithNewValidationRules(): void
    {
        $this->insertFeature(['age_str' => '25']); // Valid
        $this->insertFeature(['age_str' => '5']);  // Invalid, min is 10

        $req = $this->json($this->createRequest('POST', "/api/v1/layers/{$this->layer['id']}/fields/{$this->field['id']}/retype-preview"), [
            'field_type' => 'integer',
            'validation_rules' => ['min' => 10]
        ])->withHeader('Authorization', 'Bearer ' . $this->adminUser['token']);
        
        $res = $this->app->handle($req);
        $body = json_decode((string) $res->getBody(), true);
        
        $this->assertEquals(1, $body['data']['convertible_count']);
        $this->assertEquals(1, $body['data']['failing_count']);
        $this->assertStringContainsString('at least 10', $body['data']['failing_examples'][0]['error']);
    }

    public function testExecuteSuccessfulConversionUpdatesFeatures(): void
    {
        $id1 = $this->insertFeature(['age_str' => '25']);
        
        $req = $this->json($this->createRequest('PUT', "/api/v1/layers/{$this->layer['id']}/fields/{$this->field['id']}"), [
            'field_type' => 'integer'
        ])->withHeader('Authorization', 'Bearer ' . $this->adminUser['token']);
        
        $res = $this->app->handle($req);
        $this->assertEquals(200, $res->getStatusCode(), (string) $res->getBody());
        
        $stmt = $this->pdo->prepare("SELECT attributes FROM app.gis_features WHERE id = ?");
        $stmt->execute([$id1]);
        $attrs = json_decode($stmt->fetchColumn(), true);
        
        $this->assertSame(25, $attrs['age_str']); // Must be casted to int

        // Check field metadata updated
        $stmt = $this->pdo->prepare("SELECT field_type FROM app.gis_layer_fields WHERE id = ?");
        $stmt->execute([$this->field['id']]);
        $this->assertEquals('integer', $stmt->fetchColumn());
    }

    public function testExecuteWithFailingValuesIsRefusedAndAtomic(): void
    {
        $id1 = $this->insertFeature(['age_str' => '25']);
        $id2 = $this->insertFeature(['age_str' => 'abc']);
        
        $req = $this->json($this->createRequest('PUT', "/api/v1/layers/{$this->layer['id']}/fields/{$this->field['id']}"), [
            'field_type' => 'integer'
        ])->withHeader('Authorization', 'Bearer ' . $this->adminUser['token']);
        
        $res = $this->app->handle($req);
        $this->assertEquals(409, $res->getStatusCode()); // Conflict due to validation failure
        $body = json_decode((string) $res->getBody(), true);
        
        $this->assertEquals('Cannot convert all values', $body['error']['message']);
        $this->assertCount(1, $body['error']['details']['failing_examples']);

        // Check field metadata NOT updated
        $stmt = $this->pdo->prepare("SELECT field_type FROM app.gis_layer_fields WHERE id = ?");
        $stmt->execute([$this->field['id']]);
        $this->assertEquals('text', $stmt->fetchColumn());

        // Check features NOT updated
        $stmt = $this->pdo->prepare("SELECT attributes FROM app.gis_features WHERE id = ?");
        $stmt->execute([$id1]);
        $attrs = json_decode($stmt->fetchColumn(), true);
        $this->assertSame('25', $attrs['age_str']); // Still a string
    }
}
