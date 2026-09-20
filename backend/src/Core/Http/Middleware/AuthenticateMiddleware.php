<?php
declare(strict_types=1);

namespace App\Core\Http\Middleware;

use PDO;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpUnauthorizedException;
use Throwable;

final class AuthenticateMiddleware implements MiddlewareInterface
{
    private const ALGO = 'HS256';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $jwtSecret
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        if (empty($header) || !preg_match('/^Bearer\s+(.*)$/i', $header, $matches)) {
            throw new HttpUnauthorizedException($request, 'Missing or invalid Authorization header.');
        }

        $token = $matches[1];

        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, self::ALGO));
        } catch (Throwable $e) {
            throw new HttpUnauthorizedException($request, 'Invalid token.');
        }

        $userId = (int)$decoded->sub;

        $this->pdo->beginTransaction();

        try {
            // Fetch roles
            $stmt = $this->pdo->prepare("
                SELECT r.code 
                FROM app.roles r
                JOIN app.user_roles ur ON r.id = ur.role_id
                WHERE ur.user_id = :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
            $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $roleCodes = implode(',', $roles);

            // Fetch active scopes
            $stmt = $this->pdo->prepare("
                SELECT id 
                FROM app.data_scopes 
                WHERE user_id = :user_id 
                  AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)
            ");
            $stmt->execute([':user_id' => $userId]);
            $scopes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $scopeIds = implode(',', $scopes);

            // Fetch user version
            $stmt = $this->pdo->prepare("SELECT version FROM app.users WHERE id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $userVersion = (int)$stmt->fetchColumn();

            $requestId = $request->getAttribute('request_id', '');

            // Escape strings for SET LOCAL to prevent SQL injection issues (though they come from our DB/ID generation)
            // user_id is int, others are strings
            $roleCodesEscaped = $this->pdo->quote($roleCodes);
            $scopeIdsEscaped = $this->pdo->quote($scopeIds);
            $requestIdEscaped = $this->pdo->quote($requestId);

            // Set DB context (ADR-06 / ADR-09)
            $this->pdo->exec("SET LOCAL app.user_id = '{$userId}'");
            
            if ($roleCodes !== '') {
                $this->pdo->exec("SET LOCAL app.role_codes = {$roleCodesEscaped}");
            }
            if ($scopeIds !== '') {
                $this->pdo->exec("SET LOCAL app.scope_ids = {$scopeIdsEscaped}");
            }
            if ($requestId !== '') {
                $this->pdo->exec("SET LOCAL app.request_id = {$requestIdEscaped}");
            }

            // Attach user_id, version, and context to the request for handlers to use if needed
            $request = $request->withAttribute('user_id', $userId)
                               ->withAttribute('user_version', $userVersion)
                               ->withAttribute('role_codes', $roles)
                               ->withAttribute('scope_ids', $scopes);

            // Execute the next middleware or route handler
            $response = $handler->handle($request);

            $this->pdo->commit();

            return $response;

        } catch (Throwable $e) {
            // Ensure transaction rolls back on any application error
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
