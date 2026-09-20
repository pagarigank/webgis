<?php
declare(strict_types=1);

namespace Tests\Api;

use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * TASK-035 — security headers on every response and the explicit CORS
 * allow-list (architecture.md security checklist).
 */
class SecurityHeadersTest extends TestCase
{
    private const ALLOWED = 'https://app.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAllowedOrigins('');
    }

    protected function tearDown(): void
    {
        $this->setAllowedOrigins('');
        parent::tearDown();
    }

    private function setAllowedOrigins(string $value): void
    {
        $_SERVER['CORS_ALLOWED_ORIGINS'] = $value;
        $_ENV['CORS_ALLOWED_ORIGINS'] = $value;
        putenv('CORS_ALLOWED_ORIGINS=' . $value);
    }

    private function request(string $method, string $path, string $origin = '')
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $path);
        if ($origin !== '') {
            $request = $request->withHeader('Origin', $origin);
        }

        return $this->getAppInstance()->handle($request);
    }

    private function assertSecurityHeaders($response): void
    {
        $this->assertSame('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertNotEmpty($response->getHeaderLine('Permissions-Policy'));

        $csp = $response->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);

        $this->assertNotEmpty($response->getHeaderLine('X-Request-Id'));
    }

    public function testSecurityHeadersPresentOnSuccess(): void
    {
        $response = $this->request('GET', '/api/v1/health');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
    }

    public function testSecurityHeadersPresentOnError(): void
    {
        $response = $this->request('GET', '/api/v1/does-not-exist');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
    }

    public function testAllowedOriginIsReflectedWithCredentialsAndCsrfHeaders(): void
    {
        $this->setAllowedOrigins(self::ALLOWED);

        $response = $this->request('GET', '/api/v1/health', self::ALLOWED);

        $this->assertSame(self::ALLOWED, $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertStringContainsString('X-CSRF-Token', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('X-CSRF-Token', $response->getHeaderLine('Access-Control-Expose-Headers'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testDisallowedOriginGetsNoCorsHeaders(): void
    {
        $this->setAllowedOrigins(self::ALLOWED);

        $response = $this->request('GET', '/api/v1/health', 'https://evil.example');

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testNoOriginGetsNoCorsHeaders(): void
    {
        $this->setAllowedOrigins('');

        $response = $this->request('GET', '/api/v1/health');

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testPreflightForAllowedOriginIsDecorated(): void
    {
        $this->setAllowedOrigins(self::ALLOWED);

        $response = $this->request('OPTIONS', '/api/v1/health', self::ALLOWED);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(self::ALLOWED, $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('PUT', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSecurityHeaders($response);
    }
}