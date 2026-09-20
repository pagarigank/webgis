<?php
declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Explicit cross-origin allow-list (TASK-035). Origins not in the list get no
 * CORS headers, so the browser blocks the call. Requests without an Origin
 * header are same-origin and pass through untouched.
 *
 * Because cookies (refresh token, csrf_token) are used by the auth endpoints,
 * allowed origins are reflected exactly and credited with allow-credentials;
 * X-CSRF-Token is exposed to the SPA. Preflight (OPTIONS) short-circuits to a
 * 204 decorated with the CORS headers.
 *
 * @param array<int, string> $allowedOrigins e.g. ["https://gis.lgu.gov.ph", "https://app.example.com"]
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly array $allowedOrigins = [],
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');

        $allowed = false;
        if ($origin !== '') {
            foreach ($this->allowedOrigins as $allowedOrigin) {
                if (strtolower($origin) === strtolower(rtrim($allowedOrigin, '/'))) {
                    $allowed = true;
                    break;
                }
            }
        }

        if ($origin === '' || $allowed) {
            return $this->decorate(
                $request->getMethod() === 'OPTIONS'
                    ? new SlimResponse(204)
                    : $handler->handle($request),
                $origin,
                $allowed
            );
        }

        // Disallowed cross-origin request: no CORS headers, let the browser enforce.
        return $handler->handle($request);
    }

    private function decorate(Response $response, string $origin, bool $allowed): Response
    {
        $response = $response->withHeader('Vary', 'Origin');

        if ($allowed && $origin !== '') {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader(
                    'Access-Control-Allow-Headers',
                    'X-Requested-With, Content-Type, Accept, Origin, Authorization, If-Match, Idempotency-Key, X-CSRF-Token'
                )
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                ->withHeader('Access-Control-Expose-Headers', 'ETag, X-Request-Id, X-CSRF-Token')
                ->withHeader('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}