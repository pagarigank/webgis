<?php
declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Response\Envelope;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use Throwable;

/**
 * Per-identity fixed-window rate limiting (specification.md SR-08, api.md §1.5).
 *
 * Tickets come from app.rate_limit_entries so counts are shared across php-fpm
 * workers, unlike an in-process cache. Classification is path-based, matching a
 * "general" fallback; exempt paths (health/metrics) are never throttled. The
 * 429 carries Retry-After and passes back through the header decorators
 * (CORS, security headers, X-Request-Id) because those middlewares wrap this one.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const WINDOW_SECONDS = 60;

    /** Route class => requests per window (api.md §1.5 defaults). */
    private const DEFAULT_LIMITS = [
        'auth'          => 10,
        'auth_refresh'  => 60,
        'search'        => 60,
        'calculate'     => 30,
        'lineage'       => 10,
        'import_commit' => 5,
        'tiles'         => 600,
        'general'       => 300,
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $jwtSecret = '',
        private readonly array $limits = self::DEFAULT_LIMITS,
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $class = $this->classify($request);
        if ($class === null) {
            return $handler->handle($request);
        }

        $identity = $this->identityKey($request);
        $bucketKey = "{$class}:{$identity}";
        $windowStart = (int) floor(time() / self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
        $limit = $this->limits[$class] ?? $this->limits['general'];

        $count = $this->counter($bucketKey, $windowStart);

        if ($count >= $limit) {
            return $this->rateLimited($class, $limit, $windowStart);
        }

        $this->increment($bucketKey, $windowStart);
        $this->maybePurge();

        return $handler->handle($request);
    }

    /** Route class from the request path; null marks exempt routes. */
    private function classify(Request $request): ?string
    {
        $path = (string) $request->getUri()->getPath();

        if (preg_match('#/(health|metrics)$#', $path) === 1) {
            return null;
        }
        if (str_contains($path, '/auth/refresh')) {
            // Token refresh gets its own, much looser bucket. It is a routine
            // session continuation protected by the refresh cookie and the CSRF
            // header, not a credential-guessing vector, so it does not belong in
            // the login-sized bucket. The SPA refreshes on every cold page load
            // and on every token expiry, and a real user reloading a few times
            // exhausted the shared login budget — the refresh then 429'd, the
            // client could not obtain a token, and the session was lost. Brute
            // force protection belongs on the password endpoints below.
            return 'auth_refresh';
        }
        if (str_contains($path, '/auth/')) {
            return 'auth';
        }
        if (str_contains($path, '/search')) {
            return 'search';
        }
        if (str_contains($path, '/calculate')) {
            return 'calculate';
        }
        if (str_contains($path, '/split') || str_contains($path, '/consolidate')) {
            return 'lineage';
        }
        if (preg_match('#/imports/\d+/commit#', $path) === 1) {
            return 'import_commit';
        }
        if (str_contains($path, '/tiles/')) {
            return 'tiles';
        }

        return 'general';
    }

    /**
     * Authenticated callers are keyed by user id (JWT sub); the auth endpoints
     * and any failed-token traffic fall back to the client address so brute
     * force against login is bounded per source.
     */
    private function identityKey(Request $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header !== '' && $this->jwtSecret !== '' && preg_match('/^Bearer\s+(.*)$/i', $header, $matches)) {
            try {
                $decoded = JWT::decode($matches[1], new Key($this->jwtSecret, 'HS256'));
                return 'u:' . (string) ($decoded->sub ?? '');
            } catch (Throwable) {
                // Not a valid token → fall through to the address key
            }
        }

        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        return 'ip:' . $remote;
    }

    private function counter(string $bucketKey, int $windowStart): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT bucket_count FROM app.rate_limit_entries
             WHERE bucket_key = :key AND window_start = :window'
        );
        $stmt->execute([':key' => $bucketKey, ':window' => $windowStart]);

        return (int) $stmt->fetchColumn();
    }

    private function increment(string $bucketKey, int $windowStart): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app.rate_limit_entries (bucket_key, window_start, bucket_count)
             VALUES (:key, :window, 1)
             ON CONFLICT (bucket_key, window_start)
             DO UPDATE SET bucket_count = app.rate_limit_entries.bucket_count + 1'
        );
        $stmt->execute([':key' => $bucketKey, ':window' => $windowStart]);
    }

    private function rateLimited(string $class, int $limit, int $windowStart): Response
    {
        $retryAfter = max(1, $windowStart + self::WINDOW_SECONDS - time());

        $response = Envelope::error(
            new SlimResponse(429),
            'RATE_LIMITED',
            "Too many requests. Try again in {$retryAfter}s.",
            [
                'bucket_class' => $class,
                'window' => '1m',
                'limit' => $limit,
                'retry_after_seconds' => $retryAfter,
            ],
            429
        );

        return $response->withHeader('Retry-After', (string) $retryAfter);
    }

    /** Opportunistic cleanup keeps the counter table from growing forever. */
    private function maybePurge(): void
    {
        if (random_int(0, 255) === 0) {
            $this->pdo->exec(
                'DELETE FROM app.rate_limit_entries WHERE window_start < '
                . (time() - 2 * self::WINDOW_SECONDS)
            );
        }
    }
}