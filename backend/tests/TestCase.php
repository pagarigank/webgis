<?php
declare(strict_types=1);

namespace Tests;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface;
use App\Core\Config\Config;

class TestCase extends PHPUnitTestCase
{
    protected function getAppInstance(): App
    {
        $containerBuilder = new ContainerBuilder();
        
        $containerBuilder->addDefinitions(__DIR__ . '/../config/dependencies.php');
        
        $container = $containerBuilder->build();
        
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        
        (require __DIR__ . '/../config/routes.php')($app);
        
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(true, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            new \App\Core\Http\Handlers\HttpErrorHandler($app->getCallableResolver(), $app->getResponseFactory())
        );

        // Decorators sit OUTSIDE the error middleware so 4xx/5xx error
        // responses (which originate in the error handler) still carry
        // security headers, CORS rules and X-Request-Id. Execution from the
        // outside in: RequestId -> SecurityHeaders -> Cors -> Csrf -> RateLimit.
        $app->add($container->get(\App\Core\Http\Middleware\RateLimitMiddleware::class));
        $app->add($container->get(\App\Core\Http\Middleware\CsrfMiddleware::class));
        $app->add($container->get(\App\Core\Http\Middleware\CorsMiddleware::class));
        $app->add($container->get(\App\Core\Http\Middleware\SecurityHeadersMiddleware::class));
        $app->add(new \App\Core\Http\Middleware\RequestIdMiddleware());
        
        return $app;
    }

    protected function createMockUser(\PDO $pdo, array $permissions, array $roles): array
    {
        $pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('TESTORG', 'Test Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT DO NOTHING");
        $orgId = (int) $pdo->query("SELECT id FROM app.organizations WHERE code = 'TESTORG'")->fetchColumn();

        $pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('testuser', 'test@example.com', 'dummy', 'Test', $orgId, 'ACTIVE', 1) ON CONFLICT DO NOTHING");
        $userId = (int) $pdo->query("SELECT id FROM app.users WHERE username = 'testuser'")->fetchColumn();

        foreach ($roles as $roleCode) {
            $pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('$roleCode', '$roleCode', false) ON CONFLICT DO NOTHING");
            $roleId = (int) $pdo->query("SELECT id FROM app.roles WHERE code = '$roleCode'")->fetchColumn();
            $pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($userId, $roleId) ON CONFLICT DO NOTHING");

            foreach ($permissions as $permCode) {
                $pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('$permCode', '$permCode') ON CONFLICT DO NOTHING");
                $permId = (int) $pdo->query("SELECT id FROM app.permissions WHERE code = '$permCode'")->fetchColumn();
                $pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($roleId, $permId) ON CONFLICT DO NOTHING");
            }
        }

        $token = \Firebase\JWT\JWT::encode([
            'sub' => (string) $userId, 'v' => 1, 'exp' => time() + 3600
        ], getenv('JWT_SECRET') ?: 'dummy_secret', 'HS256');

        return ['id' => $userId, 'token' => $token];
    }

    protected function createRequest(string $method, string $path): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        return $factory->createServerRequest($method, $path);
    }
    
    protected function createJsonRequest(string $method, string $path, array $data = []): ServerRequestInterface
    {
        $request = $this->createRequest($method, $path)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');
            
        if (!empty($data)) {
            $request = $request->withParsedBody($data);
        }
        
        return $request;
    }
}
