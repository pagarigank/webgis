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
    private ?App $cachedApp = null;
    private ?\Psr\Container\ContainerInterface $cachedContainer = null;
    private ?\PDO $cachedPdo = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Cross-class isolation: API tests share one identity, so the rate
        // limiter's shared buckets (general 300/min, lineage 10/min, …)
        // would otherwise 429 late-running classes in a full-suite pass.
        // Tests that exercise the limiter itself build their own middleware
        // with dedicated buckets and are unaffected by this truncation.
        try {
            $this->pdo()->exec('DELETE FROM app.rate_limit_entries');
        } catch (\PDOException) {
            // Table may not exist in narrowly-scoped unit-test runs.
        }
    }

    /** Shared container so a test class reuses one DI build. */
    protected function container(): \Psr\Container\ContainerInterface
    {
        if ($this->cachedContainer === null) {
            $this->app(); // forces the build through getAppInstance()
        }
        return $this->cachedContainer;
    }

    /** The test database handle from the app's own DI wiring. */
    protected function pdo(): \PDO
    {
        return $this->cachedPdo ??= $this->container()->get(\PDO::class);
    }

    /**
     * Create (or reuse) a user with SYS_ADMIN role and mint a valid bearer
     * token the AuthenticateMiddleware will accept.
     */
    protected function authToken(string $username = 'proxytest', string $roleCode = 'SYS_ADMIN', string $roleName = 'System Administrator'): array
    {
        $userId = $this->ensureUser($username, 'Str0ng!Pass123', $roleCode, $roleName);

        $token = \Firebase\JWT\JWT::encode([
            'sub' => (string) $userId, 'v' => 1, 'exp' => time() + 3600,
        ], getenv('JWT_SECRET') ?: 'dummy_secret', 'HS256');

        return ['id' => $userId, 'token' => $token];
    }

    /** Shared app instance so a test class hits the same routes/pipeline. */
    protected function app(): App
    {
        return $this->cachedApp ??= $this->getAppInstance();
    }

    protected function getAppInstance(): App
    {
        $containerBuilder = new ContainerBuilder();
        
        $containerBuilder->addDefinitions(__DIR__ . '/../config/dependencies.php');
        
        $container = $containerBuilder->build();
        
        AppFactory::setContainer($container);
        $this->cachedContainer = $container;
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

    /**
     * Build a JSON request with optional headers in one call.
     */
    protected function jsonRequest(string $method, string $path, ?array $data = null, array $headers = []): ServerRequestInterface
    {
        $request = $this->createRequest($method, $path)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');

        if ($data !== null) {
            $request = $request->withParsedBody($data);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Dispatch a request through the app (shared instance).
     */
    protected function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle($request);
    }

    /**
     * Log a user in through the real /auth/login endpoint and return the
     * response payload (access_token, user, ...). The account is created
     * first (idempotently) with the given role.
     */
    protected function loginViaApi(string $username, string $password = 'Str0ng!Pass123', string $roleCode = 'SYS_ADMIN', string $roleName = 'System Administrator'): array
    {
        $this->ensureUser($username, $password, $roleCode, $roleName);

        $response = $this->handle(
            $this->jsonRequest('POST', '/api/v1/auth/login', ['username' => $username, 'password' => $password])
        );

        if ($response->getStatusCode() !== 200) {
            $this->fail(sprintf('Login failed for %s (%d): %s', $username, $response->getStatusCode(), (string) $response->getBody()));
        }

        return json_decode((string) $response->getBody(), true)['data'];
    }

    /**
     * Idempotently create an ACTIVE user with a role + Argon2id password hash.
     */
    protected function ensureUser(string $username, string $password, string $roleCode, string $roleName): int
    {
        $hasherClass = class_exists(\App\Auth\Hasher::class)
            ? \App\Auth\Hasher::class
            : \App\Core\Auth\PasswordHasher::class;
        $hash = (new $hasherClass())->hash($password);

        $this->pdo()->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('TESTORG', 'Test Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT (code) DO NOTHING");
        $orgId = (int) $this->pdo()->query("SELECT id FROM app.organizations WHERE code = 'TESTORG'")->fetchColumn();

        $this->pdo()->exec("DELETE FROM app.users WHERE username = '$username'");
        $this->pdo()->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version, must_change_password) VALUES ('$username', '$username@example.com', '{$hash}', 'Test User', $orgId, 'ACTIVE', 1, false)");
        $userId = (int) $this->pdo()->query("SELECT id FROM app.users WHERE username = '$username'")->fetchColumn();

        $this->pdo()->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('$roleCode', '$roleName', false) ON CONFLICT (code) DO NOTHING");
        $roleId = (int) $this->pdo()->query("SELECT id FROM app.roles WHERE code = '$roleCode'")->fetchColumn();
        $this->pdo()->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($userId, $roleId) ON CONFLICT DO NOTHING");

        return $userId;
    }

    /**
     * Delete rows created by the shared helpers (call in tearDown when used).
     */
    protected function cleanupSharedUsers(): void
    {
        $this->pdo()->exec("DELETE FROM app.users WHERE username = 'proxytest'");
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
