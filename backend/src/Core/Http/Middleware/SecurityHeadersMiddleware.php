<?php
declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Applies security headers to every response (architecture.md security
 * checklist, TASK-035). The Content-Security-Policy deliberately contains no
 * ``unsafe-inline`` so XSS can't smuggle script/style payloads into the page.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const HSTS = 'max-age=31536000; includeSubDomains';

    private const CSP = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self'; "
        . "img-src 'self' data:; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'none'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'; "
        . "upgrade-insecure-requests";

    private const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), display-capture=()';

    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('Strict-Transport-Security', self::HSTS)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', self::PERMISSIONS_POLICY)
            ->withHeader('Content-Security-Policy', self::CSP);
    }
}