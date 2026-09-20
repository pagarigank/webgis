<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use App\Auth\TokenService;
use PHPUnit\Framework\TestCase;

class TokenRotationTest extends TestCase
{
    private PDO $pdo;
    private TokenService $tokenService;
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

        $this->tokenService = new TokenService($this->pdo, 'test-secret-key-which-is-long-enough-for-hs256-0123456789');

        // Insert a dummy user for the test
        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, password_hash, full_name, status, version) 
            VALUES ('tokentestuser', 'token@example.com', 'dummyhash', 'Token Test User', 'ACTIVE', 1) 
            RETURNING id
        ");
        $stmt->execute();
        $this->testUserId = (int)$stmt->fetchColumn();
        
        // Ensure tenant isolation allows us to view this user's data
        $this->pdo->exec("SET app.user_id = '{$this->testUserId}'");
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->pdo->prepare("DELETE FROM app.users WHERE id = ?")->execute([$this->testUserId]);
    }

    public function testIssueTokensCreatesFamily(): void
    {
        [$jwt, $refreshToken] = $this->tokenService->issueTokens($this->testUserId);
        
        $this->assertNotEmpty($jwt);
        $this->assertNotEmpty($refreshToken);
        $this->assertStringContainsString(':', $refreshToken);

        [$familyId, $plainToken] = explode(':', $refreshToken, 2);
        
        $stmt = $this->pdo->prepare("SELECT * FROM app.refresh_tokens WHERE family_id = ?");
        $stmt->execute([$familyId]);
        $row = $stmt->fetch();
        
        $this->assertNotFalse($row);
        $this->assertEquals($this->testUserId, $row['user_id']);
        $this->assertNull($row['revoked_at']);
        
        $expectedHash = hash('sha256', $plainToken, true);
        $this->assertEquals($expectedHash, stream_get_contents($row['token_hash']));
    }

    public function testRotateRefreshTokenHappyPath(): void
    {
        [$jwt, $refreshToken] = $this->tokenService->issueTokens($this->testUserId);
        [$familyId, $plainToken] = explode(':', $refreshToken, 2);

        [$newJwt, $newRefreshToken] = $this->tokenService->rotateRefreshToken($refreshToken);
        
        $this->assertNotEmpty($newJwt);
        $this->assertNotEquals($jwt, $newJwt);
        $this->assertNotEquals($refreshToken, $newRefreshToken);
        
        [$newFamilyId, $newPlainToken] = explode(':', $newRefreshToken, 2);
        
        // Family ID should remain the same
        $this->assertEquals($familyId, $newFamilyId);

        // Old token should be revoked
        $stmt = $this->pdo->prepare("SELECT revoked_at FROM app.refresh_tokens WHERE token_hash = decode(?, 'hex')");
        $stmt->execute([hash('sha256', $plainToken)]);
        $oldRow = $stmt->fetch();
        $this->assertNotNull($oldRow['revoked_at']);

        // New token should be valid
        $stmt = $this->pdo->prepare("SELECT revoked_at FROM app.refresh_tokens WHERE token_hash = decode(?, 'hex')");
        $stmt->execute([hash('sha256', $newPlainToken)]);
        $newRow = $stmt->fetch();
        $this->assertNull($newRow['revoked_at']);
    }

    public function testReuseDetectionRevokesFamily(): void
    {
        [$jwt, $refreshToken] = $this->tokenService->issueTokens($this->testUserId);
        
        // 1st rotation - valid
        [$newJwt, $newRefreshToken] = $this->tokenService->rotateRefreshToken($refreshToken);
        
        // 2nd rotation using OLD token - reuse attack!
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Refresh token reuse detected. All sessions revoked.");
        
        $this->tokenService->rotateRefreshToken($refreshToken);
    }
}
