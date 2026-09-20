<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;
use App\Core\Auth\PasswordHasher;

class BasemapLicenseTest extends TestCase
{
    private array $adminToken;
    private array $userToken;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->pdo->exec("DELETE FROM app.basemap_providers");
        $this->pdo->exec("DELETE FROM app.users");
        
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('Admin123!');
        $this->pdo->exec("INSERT INTO app.users (username, password_hash, full_name, email, is_active) VALUES ('admin', '{$hash}', 'Admin', 'admin@local', true)");
        $adminId = $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) SELECT {$adminId}, id FROM app.roles WHERE name = 'System Administrator'");

        $this->pdo->exec("INSERT INTO app.users (username, password_hash, full_name, email, is_active) VALUES ('user1', '{$hash}', 'User', 'user@local', true)");
        $userId = $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) SELECT {$userId}, id FROM app.roles WHERE name = 'Viewer'");

        $this->adminToken = $this->loginAs('admin', 'Admin123!');
        $this->userToken = $this->loginAs('user1', 'Admin123!');
    }

    private function loginAs(string $username, string $password): array
    {
        $response = $this->request('POST', '/api/v1/auth/login', [
            'username' => $username,
            'password' => $password
        ]);
        
        return json_decode((string)$response->getBody(), true)['data'];
    }

    public function test_admin_can_manage_basemaps()
    {
        $response = $this->request('POST', '/api/v1/admin/basemaps', [
            'code' => 'TEST_MAP',
            'name' => 'Test Map',
            'provider_type' => 'XYZ',
            'license_type' => 'OPEN_ODBL',
            'is_enabled' => true,
            'attribution_html' => '&copy; Test'
        ], ['Authorization' => 'Bearer ' . $this->adminToken['access_token']]);

        $this->assertEquals(201, $response->getStatusCode());
        
        $data = json_decode((string)$response->getBody(), true)['data'];
        $this->assertEquals('TEST_MAP', $data['code']);
    }

    public function test_cannot_enable_unlicensed_basemap()
    {
        $response = $this->request('POST', '/api/v1/admin/basemaps', [
            'code' => 'TEST_UNLICENSED',
            'name' => 'Test Map',
            'provider_type' => 'XYZ',
            'license_type' => 'UNLICENSED',
            'is_enabled' => true
        ], ['Authorization' => 'Bearer ' . $this->adminToken['access_token']]);

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_public_endpoint_excludes_expired_and_keys()
    {
        // 1. Active open basemap
        $this->pdo->exec("INSERT INTO app.basemap_providers (code, name, provider_type, license_type, is_enabled, attribution_html, api_key_env_name) VALUES ('M1', 'M1', 'XYZ', 'OPEN_ODBL', true, 'A', 'KEY1')");
        // 2. Expired basemap
        $this->pdo->exec("INSERT INTO app.basemap_providers (code, name, provider_type, license_type, is_enabled, attribution_html, license_expires_on) VALUES ('M2', 'M2', 'XYZ', 'COMMERCIAL_WEB', true, 'A', '2000-01-01')");
        // 3. Disabled basemap
        $this->pdo->exec("INSERT INTO app.basemap_providers (code, name, provider_type, license_type, is_enabled, attribution_html) VALUES ('M3', 'M3', 'XYZ', 'OPEN_ODBL', false, 'A')");

        $response = $this->request('GET', '/api/v1/basemaps', null, [
            'Authorization' => 'Bearer ' . $this->userToken['access_token']
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true)['data'];
        
        // Only M1 should be returned
        $this->assertCount(1, $data);
        $this->assertEquals('M1', $data[0]['code']);
        
        // Ensure API key is NOT leaked
        $this->assertArrayNotHasKey('api_key_env_name', $data[0]);
    }
}
