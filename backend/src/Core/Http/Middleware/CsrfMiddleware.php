<?php
declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Response\Envelope;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Double-submit CSRF guard for cookie-authenticated traffic (architecture.md
 * security checklist, series SR-06 / api.md §1.3).
 *
 * The API is Bearer-token authenticated and therefore not CSRF-reachable; only
 * the refresh/logout endpoints ride on the refresh cookie. This middleware is
 * therefore inert unless a request actually presents that cookie. When it does,
 * every state-changing request must satisfy an Origin check and a double-submit
 * match (``X-CSRF-Token`` header === ``csrf_token`` cookie), compared with a
 * constant-time ``hash_equals``. Rejections read PRECONDITION-DENIED 403.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const TOKEN_HEADER = 'X-CSRF-Token';
    private const TOKEN_COOKIE = 'csrf_token';

    /** Cookie whose presence marks a request as cookie-authenticated. */
    private const AUTH_COOKIE = 'refresh_token';

    /** @param array<int, string> $allowedOrigins cross-origin allow-list (CORS origins) */
    public function __construct(
        private readonly array $allowedOrigins = [],
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $cookies = $this->parseCookies($request->getHeaderLine('Cookie'));

        // Without the refresh cookie the request is not CSRF-exposed; pass through.
        if (!isset($cookies[self::AUTH_COOKIE])) {
            return $handler->handle($request);
        }

        // Login establishes a new session and cannot require a CSRF token
        if ($request->getUri()->getPath() === '/api/v1/auth/login') {
            return $handler->handle($request);
        }

        if (!$this->isSafeMethod($request->getMethod())) {
            if (!$this->originAllowed($request)) {
                return $this->reject();
            }

            $cookieToken = $cookies[self::TOKEN_COOKIE] ?? null;
            $headerToken = $request->getHeaderLine(self::TOKEN_HEADER);

            if ($cookieToken === null || $headerToken === '' || !hash_equals($cookieToken, $headerToken)) {
                return $this->reject();
            }
        }

        return $handler->handle($request);
    }

    private function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            // Same-origin non-browser clients are the common case (no Origin header).
            return true;
        }

        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));

        $selfHost = strtolower($request->getUri()->getHost());
        if ($selfHost !== '' && $originHost === $selfHost) {
            return true;
        }

        foreach ($this->allowedOrigins as $allowedOrigin) {
            if (strtolower((string) parse_url($allowedOrigin, PHP_URL_HOST)) === $originHost) {
                return true;
            }
        }

        return false;
    }

    private function reject(): Response
    {
        return Envelope::error(
            new SlimResponse(403),
            'PERMISSION_DENIED',
            'Cross-origin or forged request rejected.',
            ['reason' => 'csrf'],
            403
        );
    }

    /** @return array<string, string> */
    private function parseCookies(string $cookieHeader): array
    {
        $cookies = [];
        foreach (explode(';', $cookieHeader) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $separator = strpos($pair, '=');
            if ($separator === false) {
                continue;
            }
            $name = trim(substr($pair, 0, $separator));
            $value = trim(substr($pair, $separator + 1));
            $cookies[$name] = $value;
        }

        return $cookies;
    }
}