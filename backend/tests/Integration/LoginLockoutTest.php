<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use App\Auth\Hasher;
use App\Auth\TokenService;
use App\Auth\LoginService;
use DomainException;
use PHPUnit\Framework\TestCase;

class LoginLockoutTest extends TestCase
{
    private PDO $pdo;
    private LoginService $loginService;
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

        $hasher = new Hasher();
        $tokenService = new TokenService($this->pdo, 'test-secret-key-which-is-long-enough-for-hs256-0123456789');
        $this->loginService = new LoginService($this->pdo, $hasher, $tokenService);

        // Clean up any previous run
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'lockouttestuser'");

        $pwHash = $hasher->hash('ValidPass123!');
        
        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, password_hash, full_name, status, version) 
            VALUES ('lockouttestuser', 'lockout@example.com', ?, 'Lockout Test User', 'ACTIVE', 1) 
            RETURNING id
        ");
        $stmt->execute([$pwHash]);
        $this->testUserId = (int)$stmt->fetchColumn();
        
        $this->pdo->exec("SET app.user_id = '{$this->testUserId}'");
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare("DELETE FROM app.users WHERE id = ?")->execute([$this->testUserId]);
    }

    public function testSuccessfulLoginResetsCounters(): void
    {
        // Setup: artificially set a failed login
        $this->pdo->exec("UPDATE app.users SET failed_login_count = 2 WHERE id = {$this->testUserId}");
        
        $tokens = $this->loginService->attemptLogin('lockouttestuser', 'ValidPass123!');
        $this->assertIsArray($tokens);
        $this->assertCount(2, $tokens);
        
        // Verify counters are reset
        $stmt = $this->pdo->query("SELECT failed_login_count, locked_until FROM app.users WHERE id = {$this->testUserId}");
        $row = $stmt->fetch();
        
        $this->assertEquals(0, $row['failed_login_count']);
        $this->assertNull($row['locked_until']);
    }

    public function testFailedLoginIncrementsCounter(): void
    {
        try {
            $this->loginService->attemptLogin('lockouttestuser', 'WrongPass!');
        } catch (DomainException $e) {
            $this->assertEquals("Invalid username or password.", $e->getMessage());
        }
        
        $stmt = $this->pdo->query("SELECT failed_login_count FROM app.users WHERE id = {$this->testUserId}");
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    public function testLockoutTriggersAfterMaxAttempts(): void
    {
        // Attempt 5 failed logins
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->loginService->attemptLogin('lockouttestuser', 'WrongPass!');
            } catch (DomainException $e) {
                // Expected
            }
        }
        
        $stmt = $this->pdo->query("SELECT failed_login_count, locked_until FROM app.users WHERE id = {$this->testUserId}");
        $row = $stmt->fetch();
        
        $this->assertEquals(5, $row['failed_login_count']);
        $this->assertNotNull($row['locked_until']);
        
        // Now try with CORRECT password - should still fail because of lockout
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Account is locked. Try again later.");
        
        $this->loginService->attemptLogin('lockouttestuser', 'ValidPass123!');
    }
}
