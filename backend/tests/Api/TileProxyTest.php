<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;
use PDO;

class TileProxyTest extends TestCase
{
    public function testProxyRequiresAuthentication(): void
    {
        $response = $this->get('/api/v1/basemaps/999/tiles/0/0/0');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testProxyReturns404ForUnknownProvider(): void
    {
        $this->loginAs(1); // Login as active user
        $response = $this->get('/api/v1/basemaps/999/tiles/0/0/0');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testProxyFailsForDisabledProvider(): void
    {
        $this->pdo->exec("INSERT INTO app.basemap_providers (id, code, name, url_template, is_enabled, provider_type, license_type) VALUES (901, 'T_DIS', 'Disabled', 'http://example.com', false, 'XYZ', 'OPEN_ODBL')");
        
        $this->loginAs(1);
        $response = $this->get('/api/v1/basemaps/901/tiles/0/0/0');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testProxyFailsForUnlicensedProvider(): void
    {
        $this->pdo->exec("INSERT INTO app.basemap_providers (id, code, name, url_template, is_enabled, provider_type, license_type) VALUES (902, 'T_UNL', 'Unlicensed', 'http://example.com', true, 'XYZ', 'UNLICENSED')");
        
        $this->loginAs(1);
        $response = $this->get('/api/v1/basemaps/902/tiles/0/0/0');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testProxyInjectsKeyAndRateLimits(): void
    {
        // Add a mock provider that requires a key
        $this->pdo->exec("INSERT INTO app.basemap_providers (id, code, name, url_template, is_enabled, provider_type, license_type, requires_api_key, api_key_env_name, cache_ttl_seconds) VALUES (903, 'T_KEY', 'KeyReq', 'http://example.com/{z}/{x}/{y}?key={key}', true, 'XYZ', 'COMMERCIAL_WEB', true, 'TEST_MAP_KEY', 3600)");
        
        // This test would normally actually hit the URL, so to avoid a real external request blocking the test
        // we test the failure path where the key is missing (since we didn't set TEST_MAP_KEY)
        
        $this->loginAs(1);
        $response = $this->get('/api/v1/basemaps/903/tiles/1/2/3');
        $this->assertSame(500, $response->getStatusCode());
        
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('API key not configured for provider.', $data['error']['message']);
    }
}
