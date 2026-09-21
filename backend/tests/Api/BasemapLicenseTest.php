<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

class BasemapLicenseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec("DELETE FROM app.basemap_providers");

        // JWT-borne identities (no real /auth/login round-trips, so the
        // suite cannot trip the 10/min auth rate-limit bucket).
        $this->adminToken = $this->authToken('basemap_admin', 'SYS_ADMIN', 'System Administrator');
        $this->userToken = $this->authToken('basemap_user', 'basemap_viewer', 'Basemap Viewer');
    }

    protected function tearDown(): void
    {
        $this->pdo()->exec("DELETE FROM app.basemap_providers WHERE code LIKE 'TEST_%' OR code IN ('M1','M2','M3')");
        $this->pdo()->exec("DELETE FROM app.users WHERE username IN ('basemap_admin', 'basemap_user')");
        parent::tearDown();
    }

    private function authedRequest(string $method, string $path, ?array $data, string $token): \Psr\Http\Message\ResponseInterface
    {
        $request = $this->jsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $this->handle($request);
    }

    public function test_admin_can_manage_basemaps(): void
    {
        $response = $this->authedRequest('POST', '/api/v1/admin/basemaps', [
            'code' => 'TEST_MAP',
            'name' => 'Test Map',
            'provider_type' => 'XYZ',
            'license_type' => 'OPEN_ODBL',
            'is_enabled' => true,
            'attribution_html' => '&copy; Test'
        ], $this->adminToken['token']);

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        $data = json_decode((string)$response->getBody(), true)['data'];
        $this->assertEquals('TEST_MAP', $data['code']);
    }

    public function test_cannot_enable_unlicensed_basemap(): void
    {
        $response = $this->authedRequest('POST', '/api/v1/admin/basemaps', [
            'code' => 'TEST_UNLICENSED',
            'name' => 'Test Map',
            'provider_type' => 'XYZ',
            'license_type' => 'UNLICENSED',
            'is_enabled' => true
        ], $this->adminToken['token']);

        $this->assertEquals(422, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_public_endpoint_excludes_expired_and_keys()
    {
        // 1. Active open basemap
        $this->pdo()->exec("INSERT INTO app.basemap_providers (code, name, provider_type, license_type, is_enabled, attribution_html, api_key_env_name) VALUES ('M1', 'M1', 'XYZ', 'OPEN_ODBL', true, 'A', 'KEY1')");
        // 2. Expired basemap
        $this->pdo()->exec("INSERT INTO app.basemap_providers (code, name, provider_type, license_type, is_enabled, attribution_html, license_expires_on) VALUES ('M2', 'M2', 'XYZ', 'COMMERCIAL_WEB', true, 'A', '2000-01-01')");
        // 3. Disabled basemap
        $this->pdo()->exec("INSERT INTO app.basemap_providers (code, name, provider_type, license_type, is_enabled, attribution_html) VALUES ('M3', 'M3', 'XYZ', 'OPEN_ODBL', false, 'A')");

        $response = $this->authedRequest('GET', '/api/v1/basemaps', null, $this->userToken['token']);

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string)$response->getBody(), true)['data'];

        // Only M1 should be returned
        $this->assertCount(1, $data, (string) $response->getBody());
        $this->assertEquals('M1', $data[0]['code']);

        // Ensure API key is NOT leaked
        $this->assertArrayNotHasKey('api_key_env_name', $data[0]);
    }
}
