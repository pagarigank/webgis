<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

class TileProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->pdo()->exec("DELETE FROM app.basemap_providers WHERE code LIKE 'T_%'");
        $this->cleanupSharedUsers();
        parent::tearDown();
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        return $this->handle($this->createRequest('GET', $path));
    }

    public function testProxyRequiresAuthentication(): void
    {
        $response = $this->get('/api/v1/basemaps/999/tiles/0/0/0');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testProxyReturns404ForUnknownProvider(): void
    {
        $user = $this->authToken('proxytest');
        $response = $this->handle(
            $this->createRequest('GET', '/api/v1/basemaps/999/tiles/0/0/0')
                ->withHeader('Authorization', 'Bearer ' . $user['token'])
        );
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testProxyFailsForDisabledProvider(): void
    {
        $this->pdo()->exec("INSERT INTO app.basemap_providers (id, code, name, url_template, is_enabled, provider_type, license_type) VALUES (901, 'T_DIS', 'Disabled', 'http://example.com', false, 'XYZ', 'OPEN_ODBL')");
        $user = $this->authToken('proxytest');

        $response = $this->handle(
            $this->createRequest('GET', '/api/v1/basemaps/901/tiles/0/0/0')
                ->withHeader('Authorization', 'Bearer ' . $user['token'])
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testProxyFailsForUnlicensedProvider(): void
    {
        // The DB CHECK (ck_license_enabled) already refuses enabled+UNLICENSED
        // rows, so this row is inserted disabled — the proxy's own guard is
        // what the assertion covers.
        $this->pdo()->exec("INSERT INTO app.basemap_providers (id, code, name, url_template, is_enabled, provider_type, license_type) VALUES (902, 'T_UNL', 'Unlicensed', 'http://example.com', false, 'XYZ', 'UNLICENSED')");
        $user = $this->authToken('proxytest');

        $response = $this->handle(
            $this->createRequest('GET', '/api/v1/basemaps/902/tiles/0/0/0')
                ->withHeader('Authorization', 'Bearer ' . $user['token'])
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testProxyInjectsKeyAndRateLimits(): void
    {
        // Provider requires a key but the env var is intentionally absent, so
        // the proxy must fail closed with INTERNAL_ERROR (no upstream call).
        $this->pdo()->exec("INSERT INTO app.basemap_providers (id, code, name, url_template, is_enabled, provider_type, license_type, requires_api_key, api_key_env_name, cache_ttl_seconds) VALUES (903, 'T_KEY', 'KeyReq', 'http://example.invalid/{z}/{x}/{y}?key={key}', true, 'XYZ', 'COMMERCIAL_WEB', true, 'TEST_MAP_KEY_DEFINITELY_UNSET', 3600)");
        $user = $this->authToken('proxytest');

        $response = $this->handle(
            $this->createRequest('GET', '/api/v1/basemaps/903/tiles/1/2/3')
                ->withHeader('Authorization', 'Bearer ' . $user['token'])
        );
        $this->assertSame(500, $response->getStatusCode());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('API key not configured for provider.', $data['error']['message']);
    }
}
