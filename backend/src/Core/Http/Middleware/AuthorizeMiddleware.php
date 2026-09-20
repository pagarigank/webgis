<?php
declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\RBAC\PermissionResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;

/**
 * AuthorizeMiddleware guards a route by enforcing a specific required permission.
 * It expects the AuthenticateMiddleware to have already run and attached
 * 'user_id' and 'user_version' to the Request.
 */
final class AuthorizeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $requiredPermission,
        private readonly PermissionResolver $permissionResolver
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $userId = $request->getAttribute('user_id');
        $userVersion = $request->getAttribute('user_version');

        if ($userId === null || $userVersion === null) {
            // Should not happen if AuthenticateMiddleware is configured properly
            throw new HttpForbiddenException($request, 'Authentication context missing.');
        }

        $effectivePermissions = $this->permissionResolver->getEffectivePermissions(
            (int)$userId, 
            (int)$userVersion
        );

        if (!in_array($this->requiredPermission, $effectivePermissions, true)) {
            // Throw a 403. The HttpErrorHandler will format this into an Envelope
            // and we can mention the specific required permission.
            throw new HttpForbiddenException(
                $request, 
                "PERMISSION_DENIED: {$this->requiredPermission} is required to access this resource."
            );
        }

        return $handler->handle($request);
    }
}
