<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use App\RBAC\PermissionResolver;
use App\Core\Http\Middleware\AuthorizeMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class PermissionMatrixTest extends TestCase
{
    private PDO $pdo;
    private PermissionResolver $resolver;
    private ArrayAdapter $cache;
    private int $testUserId;
    private int $testRoleId;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST') ?: 'postgres';
        $port = getenv('DB_PORT') ?: '5432';
        $db   = getenv('DB_NAME') ?: 'webgis';
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASS') ?: 'postgres';

        $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Clean up from previous run if aborted
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'matrixtestuser'");
        $this->pdo->exec("DELETE FROM app.roles WHERE code = 'TEST_ROLE'");
        $this->pdo->exec("DELETE FROM app.permissions WHERE code LIKE 'TEST_%'");

        // 1. Create a dummy permission
        $this->pdo->exec("INSERT INTO app.permissions (code, module, description) VALUES ('TEST_VIEW', 'TEST', 'View test data')");
        $stmt = $this->pdo->query("SELECT id FROM app.permissions WHERE code = 'TEST_VIEW'");
        $permViewId = (int)$stmt->fetchColumn();

        $this->pdo->exec("INSERT INTO app.permissions (code, module, description) VALUES ('TEST_EDIT', 'TEST', 'Edit test data')");
        $stmt = $this->pdo->query("SELECT id FROM app.permissions WHERE code = 'TEST_EDIT'");
        $permEditId = (int)$stmt->fetchColumn();

        // 2. Create a dummy role and assign VIEW permission only
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('TEST_ROLE', 'Test Role', false)");
        $stmt = $this->pdo->query("SELECT id FROM app.roles WHERE code = 'TEST_ROLE'");
        $this->testRoleId = (int)$stmt->fetchColumn();

        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ({$this->testRoleId}, {$permViewId})");

        // 3. Create a dummy user and assign role
        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, password_hash, full_name, status, version) 
            VALUES ('matrixtestuser', 'matrix@example.com', 'dummy', 'Matrix Test User', 'ACTIVE', 1) 
            RETURNING id
        ");
        $stmt->execute();
        $this->testUserId = (int)$stmt->fetchColumn();

        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ({$this->testUserId}, {$this->testRoleId})");

        // Set up resolver with a fast in-memory array cache adapter (PSR-16 compatible via Symfony psr16 wrapper or just use ArrayAdapter)
        // Note: Symfony ArrayAdapter is PSR-6, but Symfony Cache provides Psr16Cache wrapper.
        $this->cache = new ArrayAdapter();
        $psr16Cache = new \Symfony\Component\Cache\Psr16Cache($this->cache);
        
        $this->resolver = new PermissionResolver($this->pdo, $psr16Cache);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM app.users WHERE id = {$this->testUserId}");
        $this->pdo->exec("DELETE FROM app.roles WHERE id = {$this->testRoleId}");
        $this->pdo->exec("DELETE FROM app.permissions WHERE code LIKE 'TEST_%'");
    }

    private function runMiddleware(string $requiredPermission, int $version = 1): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/test')
            ->withAttribute('user_id', $this->testUserId)
            ->withAttribute('user_version', $version);

        $handler = new class implements RequestHandlerInterface {
            public function handle(Request $request): Response {
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $middleware = new AuthorizeMiddleware($requiredPermission, $this->resolver);
        $middleware->process($request, $handler);
    }

    public function testGrantedPermissionSucceeds(): void
    {
        // Should not throw
        $this->runMiddleware('TEST_VIEW');
        $this->assertTrue(true); // Reached here
    }

    public function testMissingPermissionThrowsForbidden(): void
    {
        $this->expectException(HttpForbiddenException::class);
        $this->expectExceptionMessage('PERMISSION_DENIED: TEST_EDIT is required');

        $this->runMiddleware('TEST_EDIT');
    }

    public function testCacheInvalidatesOnVersionBump(): void
    {
        // 1. Initial request (version 1) - Edit is denied, View is allowed.
        try {
            $this->runMiddleware('TEST_EDIT', 1);
            $this->fail("Should throw exception");
        } catch (HttpForbiddenException $e) {}
        
        $this->runMiddleware('TEST_VIEW', 1); // Caches version 1 permissions

        // 2. We assign TEST_EDIT permission to the role in the DB
        $stmt = $this->pdo->query("SELECT id FROM app.permissions WHERE code = 'TEST_EDIT'");
        $permEditId = (int)$stmt->fetchColumn();
        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ({$this->testRoleId}, {$permEditId})");

        // 3. If we try again with version 1, it should STILL deny (because cache is hit)
        try {
            $this->runMiddleware('TEST_EDIT', 1);
            $this->fail("Should STILL throw exception due to cache");
        } catch (HttpForbiddenException $e) {}

        // 4. Now simulate user's version being bumped by an admin action
        $newVersion = 2;
        $this->pdo->exec("UPDATE app.users SET version = {$newVersion} WHERE id = {$this->testUserId}");
        
        // 5. Try with version 2 - cache is missed, new DB state is read, should SUCCEED
        $this->runMiddleware('TEST_EDIT', $newVersion);
        $this->assertTrue(true);
    }
}
