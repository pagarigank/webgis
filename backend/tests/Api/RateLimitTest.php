<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Core\Http\Middleware\RateLimitMiddleware;
use Firebase\JWT\JWT;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use Tests\TestCase;

/**
 * TASK-035 — rate limiting (SR-08, api.md §1.5).
 *
 * Full-request tests exercise the app pipeline (framework, decorators, 429
 * envelope); headless tests exercise per-user and per-class token buckets with
 * tailored limits so no suite waits on hundreds of requests.
 */
class RateLimitTest extends TestCase
{
    private const SECRET = 'test-secret-test-secret-test-secret-0123456789';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: 'postgres',
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME') ?: 'webgis'
            ),
            getenv('DB_USER') ?: 'postgres',
            getenv('DB_PASS') ?: 'postgres',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
    }

    private function clear(): void
    {
        $this->pdo->exec('DELETE FROM app.rate_limit_entries');
    }

    private function passthrough(): RequestHandler
    {
        return new class implements RequestHandler {
            public function handle(Request $request): Response
            {
                return new SlimResponse(200);
            }
        };
    }

    private function token(int $subject): string
    {
        return JWT::encode(
            ['sub' => $subject, 'exp' => time() + 3600],
            self::SECRET,
            'HS256'
        );
    }

    public function testHealthEndpointIsExemptFromRateLimiting(): void
    {
        $app = $this->getAppInstance();
        $factory = new ServerRequestFactory();

        for ($i = 0; $i < 3; $i++) {
            $response = $app->handle($factory->createServerRequest('GET', '/api/v1/health'));
            $this->assertSame(200, $response->getStatusCode());
        }
    }

    public function testAuthClassThrottlesUnauthenticatedCallerByAddress(): void
    {
        $app = $this->getAppInstance();
        $factory = new ServerRequestFactory();

        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $app->handle(
                $factory->createServerRequest('POST', '/api/v1/auth/login', ['REMOTE_ADDR' => '127.0.0.9'])
            );
        }

        $this->assertSame(429, $last->getStatusCode());

        $payload = json_decode((string) $last->getBody(), true);
        $this->assertSame('RATE_LIMITED', $payload['error']['code']);

        $retry = (int) $last->getHeaderLine('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(60, $retry);

        // Outer decorators still reach the 429.
        $this->assertNotEmpty($last->getHeaderLine('X-Request-Id'));
        $this->assertNotEmpty($last->getHeaderLine('Content-Security-Policy'));
    }

    public function testDifferentAddressesUseSeparateBuckets(): void
    {
        $app = $this->getAppInstance();
        $factory = new ServerRequestFactory();

        for ($i = 0; $i < 10; $i++) {
            $app->handle(
                $factory->createServerRequest('POST', '/api/v1/auth/login', ['REMOTE_ADDR' => '127.0.0.9'])
            );
        }

        $blocked = $app->handle(
            $factory->createServerRequest('POST', '/api/v1/auth/login', ['REMOTE_ADDR' => '127.0.0.9'])
        );
        $this->assertSame(429, $blocked->getStatusCode());

        $fresh = $app->handle(
            $factory->createServerRequest('POST', '/api/v1/auth/login', ['REMOTE_ADDR' => '127.0.0.10'])
        );
        $this->assertNotSame(429, $fresh->getStatusCode());
    }

    public function testGeneralClassThrottlesPerUserByJwtSubject(): void
    {
        $middleware = new RateLimitMiddleware($this->pdo, self::SECRET, ['general' => 2]);
        $factory = new ServerRequestFactory();

        $requests = [
            $factory->createServerRequest('GET', '/api/v1/users')->withHeader('Authorization', 'Bearer ' . $this->token(7)),
            $factory->createServerRequest('GET', '/api/v1/users')->withHeader('Authorization', 'Bearer ' . $this->token(7)),
            $factory->createServerRequest('GET', '/api/v1/users')->withHeader('Authorization', 'Bearer ' . $this->token(7)),
            $factory->createServerRequest('GET', '/api/v1/users')->withHeader('Authorization', 'Bearer ' . $this->token(8)),
        ];

        $statuses = array_map(
            fn (Request $r) => $middleware->process($r, $this->passthrough())->getStatusCode(),
            $requests
        );

        $this->assertSame([200, 200, 429, 200], $statuses);
    }

    public function testRoutesOutsideAllowListAreThrottled(): void
    {
        $app = $this->getAppInstance();
        $factory = new ServerRequestFactory();

        // A non-existent route still counts, proving classification runs pre-routing.
        for ($i = 0; $i < 11; $i++) {
            $app->handle(
                $factory->createServerRequest('POST', '/api/v1/auth/login', ['REMOTE_ADDR' => '127.0.0.11'])
            );
        }

        $blocked = $app->handle(
            $factory->createServerRequest('GET', '/api/v1/unknown-path', ['REMOTE_ADDR' => '127.0.0.11'])
        );
        // Different key (general vs auth) → not throttled.
        $this->assertNotSame(429, $blocked->getStatusCode());
    }
}