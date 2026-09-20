<?php
declare(strict_types=1);

namespace Tests\Api;

use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * TASK-035 — double-submit CSRF + Origin verification (SR-06, api.md §1.3).
 *
 * The guard is inert until a request presents the refresh cookie, which is the
 * only CSRF-exposed channel (Bearer-authenticated requests are not
 * cookie-authenticated). Rejection reads PERMISSION_DENIED / 403.
 */
class CsrfTest extends TestCase
{
    private const ALLOWED = 'https://app.example.com';
    private const REFRESH_COOKIE = 'refresh_token=abc; csrf_token=one';

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

    private function appRequest(string $method, string $path, string $cookie = '', string $csrfHeader = '', string $origin = '')
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $path);
        if ($cookie !== '') {
            $request = $request->withHeader('Cookie', $cookie);
        }
        if ($csrfHeader !== '') {
            $request = $request->withHeader('X-CSRF-Token', $csrfHeader);
        }
        if ($origin !== '') {
            $request = $request->withHeader('Origin', $origin);
        }

        return $this->getAppInstance()->handle($request);
    }

    public function testMismatchedDoubleSubmitIsRejected(): void
    {
        $response = $this->appRequest('POST', '/api/v1/users', self::REFRESH_COOKIE, 'two');

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('PERMISSION_DENIED', $payload['error']['code']);
    }

    public function testMissingHeaderIsRejected(): void
    {
        $response = $this->appRequest('POST', '/api/v1/users', self::REFRESH_COOKIE);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMatchingDoubleSubmitReachesTheRoute(): void
    {
        $response = $this->appRequest('POST', '/api/v1/users', self::REFRESH_COOKIE, 'one');

        // CSRF passed; the route then fails on the missing Bearer token.
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCrossOriginWithoutAllowListIsRejectedEvenWithMatchingToken(): void
    {
        $this->setAllowedOrigins('');

        $response = $this->appRequest('POST', '/api/v1/users', self::REFRESH_COOKIE, 'one', 'https://evil.example');

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCrossOriginFromAllowListPasses(): void
    {
        $this->setAllowedOrigins(self::ALLOWED);

        $response = $this->appRequest('POST', '/api/v1/users', self::REFRESH_COOKIE, 'one', self::ALLOWED);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testNoCookieLeavesCsrfInert(): void
    {
        $response = $this->appRequest('POST', '/api/v1/users', '', 'anything');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testSafeMethodsAreNotCsrfGated(): void
    {
        $response = $this->appRequest('GET', '/api/v1/health', self::REFRESH_COOKIE);

        $this->assertSame(200, $response->getStatusCode());
    }
}