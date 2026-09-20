<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use Exception;
use App\Core\Http\Middleware\AuthenticateMiddleware;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class DbSessionContextTest extends TestCase
{
    private PDO $pdo;
    private AuthenticateMiddleware $middleware;
    private string $jwtSecret = 'test-secret-which-is-long-enough-for-hs256-0123456789';
    private int $testUserId;

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

        $this->middleware = new AuthenticateMiddleware($this->pdo, $this->jwtSecret);

        // Reset any existing connection state
        $this->pdo->exec("RESET ALL");

        // Insert a dummy user and role
        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, password_hash, full_name, status, version) 
            VALUES ('contextuser', 'ctx@example.com', 'dummy', 'Context User', 'ACTIVE', 1) 
            RETURNING id
        ");
        $stmt->execute();
        $this->testUserId = (int)$stmt->fetchColumn();

        // Assign app_rw role (assume role exists from seeds)
        // First get role id for app_rw or SYS_ADMIN
        $stmt = $this->pdo->query("SELECT id FROM app.roles WHERE code = 'SYS_ADMIN'");
        $roleId = $stmt->fetchColumn();
        if ($roleId) {
            $this->pdo->prepare("INSERT INTO app.user_roles (user_id, role_id) VALUES (?, ?)")
                      ->execute([$this->testUserId, $roleId]);
        }
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("RESET ALL");
        $this->pdo->prepare("DELETE FROM app.users WHERE id = ?")->execute([$this->testUserId]);
    }

    public function testUnauthenticatedRequestThrowsUnauthorized(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/test');
        
        // Mock handler
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Missing or invalid Authorization header.');

        $this->middleware->process($request, $handler);
        
        // Ensure no transaction was left open
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testAuthenticatedRequestSetsDbContextAndCommits(): void
    {
        $token = JWT::encode([
            'iss' => 'webgis',
            'sub' => (string)$this->testUserId,
            'exp' => time() + 3600
        ], $this->jwtSecret, 'HS256');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/test')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withAttribute('request_id', 'req_12345');

        // Create a handler that checks the DB state inside the transaction
        $handler = new class($this->pdo) implements RequestHandlerInterface {
            public function __construct(private PDO $pdo) {}

            public function handle(Request $request): Response {
                // Ensure we are in a transaction
                if (!$this->pdo->inTransaction()) {
                    throw new Exception("Not in a transaction");
                }

                // Check SET LOCAL variables
                $stmt = $this->pdo->query("
                    SELECT 
                        current_setting('app.user_id', true) as uid,
                        current_setting('app.role_codes', true) as roles,
                        current_setting('app.request_id', true) as reqid
                ");
                $settings = $stmt->fetch();

                if ((int)$settings['uid'] !== $request->getAttribute('user_id')) {
                    throw new Exception("app.user_id not set correctly");
                }
                if ($settings['reqid'] !== 'req_12345') {
                    throw new Exception("app.request_id not set correctly");
                }

                return (new ResponseFactory())->createResponse(200);
            }
        };

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        
        // Ensure transaction committed/ended
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testHandlerExceptionRollsBackTransaction(): void
    {
        $token = JWT::encode([
            'iss' => 'webgis',
            'sub' => (string)$this->testUserId,
            'exp' => time() + 3600
        ], $this->jwtSecret, 'HS256');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/test')
            ->withHeader('Authorization', 'Bearer ' . $token);

        $handler = new class($this->pdo) implements RequestHandlerInterface {
            public function __construct(private PDO $pdo) {}

            public function handle(Request $request): Response {
                // Do a dummy write
                $this->pdo->exec("INSERT INTO app.organizations (code, name) VALUES ('ROLLBACK_TEST', 'Test')");
                throw new Exception("Application Error");
            }
        };

        try {
            $this->middleware->process($request, $handler);
            $this->fail("Expected Exception");
        } catch (Exception $e) {
            $this->assertEquals("Application Error", $e->getMessage());
        }

        // Ensure transaction rolled back
        $this->assertFalse($this->pdo->inTransaction());

        // Verify the insert was rolled back
        $stmt = $this->pdo->query("SELECT count(*) FROM app.organizations WHERE code = 'ROLLBACK_TEST'");
        $this->assertEquals(0, $stmt->fetchColumn());
    }
}
