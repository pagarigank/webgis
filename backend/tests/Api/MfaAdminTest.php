<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Auth\MfaService;
use App\Auth\Totp;
use App\Auth\TokenService;
use PDO;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

/**
 * TASK-036 — admin MFA endpoints (api.md §3).
 *
 * GET /users/{id}/mfa, POST /users/{id}/mfa/enroll, POST /users/{id}/mfa/disable.
 * The unencrypted secret is returned exactly once (enrollment); every later
 * read only reports whether a secret is set.
 */
class MfaAdminTest extends TestCase
{
    private const SECRET = 'dummy_secret_for_testing_long_enough_for_hs256_0123456789';
    private const MFA_KEY = 'test-mfa-encryption-key-32-bytes-long-!!';

    private PDO $pdo;
    private int $orgId;
    private int $adminUserId;
    private string $adminToken;
    private MfaService $mfa;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            'pgsql:host=' . (getenv('DB_HOST') ?: 'postgres') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . (getenv('DB_NAME') ?: 'webgis'),
            getenv('DB_USER') ?: 'postgres',
            getenv('DB_PASS') ?: 'postgres',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $this->setupJwtSecret();
        $this->cleanup();

        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('MFADMORG', 'MFA Org', 'GOVERNMENT', 'ACTIVE')");
        $this->orgId = (int) $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'MFADMORG'")->fetchColumn();

        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('ROLE_MFADM', 'MFA Admin', false)");
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'ROLE_MFADM'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) SELECT $roleId, p.id FROM app.permissions p WHERE p.code = 'user.manage'");

        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('mfadmtest', 'mfadm@example.com', 'dummy', 'MFA Admin', $this->orgId, 'ACTIVE', 1)");
        $this->adminUserId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'mfadmtest'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($this->adminUserId, $roleId)");

        $this->adminToken = $this->signToken($this->adminUserId);

        $this->mfa = new MfaService(
            $this->pdo,
            new TokenService($this->pdo, self::SECRET),
            self::SECRET,
            self::MFA_KEY
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM app.refresh_tokens WHERE user_id IN (SELECT id FROM app.users WHERE username IN ('mfadmtest', 'mqatarget'))");
        $this->pdo->exec("DELETE FROM app.users WHERE username IN ('mfadmtest', 'mqatarget')");
        $this->pdo->exec("DELETE FROM app.roles WHERE code = 'ROLE_MFADM'");
        $this->pdo->exec("DELETE FROM app.organizations WHERE code = 'MFADMORG'");
        $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.users'");
        $this->pdo->exec('DELETE FROM app.rate_limit_entries');
    }

    private function setupJwtSecret(): void
    {
        putenv('JWT_SECRET=' . self::SECRET);
        $_SERVER['JWT_SECRET'] = self::SECRET;
        $_ENV['JWT_SECRET'] = self::SECRET;

        putenv('MFA_ENCRYPTION_KEY=' . self::MFA_KEY);
        $_SERVER['MFA_ENCRYPTION_KEY'] = self::MFA_KEY;
        $_ENV['MFA_ENCRYPTION_KEY'] = self::MFA_KEY;
    }

    private function signToken(int $userId): string
    {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode(['sub' => (string) $userId, 'v' => 1, 'exp' => time() + 3600]));
        $signature = hash_hmac('sha256', "$header.$payload", self::SECRET, true);
        return "$header.$payload." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    }

    /** @param array<string,mixed> $data */
    private function json(ServerRequestInterface $request, array $data): ServerRequestInterface
    {
        $request->getBody()->write(json_encode($data));
        return $request->withHeader('Content-Type', 'application/json');
    }

    private function createTargetUser(): int
    {
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('mqatarget', 'mqatarget@example.com', 'dummy', 'MFA Qa', $this->orgId, 'ACTIVE', 1)");
        return (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'mqatarget'")->fetchColumn();
    }

    public function testMfaEndpointsRequirePermission(): void
    {
        $targetId = $this->createTargetUser();
        $app = $this->getAppInstance();

        $noAuth = $app->handle($this->createRequest('GET', "/api/v1/users/$targetId/mfa"));
        $this->assertSame(401, $noAuth->getStatusCode());
    }

    public function testEnrollThenVerifyFlowAndDisable(): void
    {
        $targetId = $this->createTargetUser();
        $app = $this->getAppInstance();

        // Initially not enrolled.
        $get = $app->handle($this->createRequest('GET', "/api/v1/users/$targetId/mfa")
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertSame(200, $get->getStatusCode());
        $getData = json_decode((string) $get->getBody(), true);
        $this->assertFalse($getData['data']['mfa_enabled']);
        $this->assertFalse($getData['data']['mfa_secret_set']);
        $this->assertArrayNotHasKey('secret', $getData['data']);

        // Enroll → the plaintext secret is returned exactly once.
        $enroll = $app->handle($this->json($this->createRequest('POST', "/api/v1/users/$targetId/mfa/enroll"), [
            'reason' => 'Enroll for MFA QA (TASK-036)',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertSame(200, $enroll->getStatusCode());
        $enrollData = json_decode((string) $enroll->getBody(), true);
        $this->assertTrue($enrollData['data']['mfa_enabled']);
        $this->assertTrue(Totp::isValidSecret($enrollData['data']['secret']));
        $this->assertSame(32, strlen($enrollData['data']['secret']));
        $plainSecret = $enrollData['data']['secret'];

        // The DB holds only the encrypted form.
        $stored = $this->pdo->query("SELECT mfa_secret_enc FROM app.users WHERE id = $targetId")->fetchColumn();
        $this->assertNotSame($plainSecret, $stored);
        $this->assertSame($plainSecret, $this->mfa->secretForUser($targetId));

        // A valid TOTP code for that secret now verifies via MfaService.
        $code = Totp::codeAt($plainSecret);
        $this->assertTrue(Totp::verify($plainSecret, $code));

        // Later reads never expose the secret again.
        $get2 = $app->handle($this->createRequest('GET', "/api/v1/users/$targetId/mfa")
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $get2Data = json_decode((string) $get2->getBody(), true);
        $this->assertTrue($get2Data['data']['mfa_enabled']);
        $this->assertTrue($get2Data['data']['mfa_secret_set']);
        $this->assertArrayNotHasKey('secret', $get2Data['data']);

        // Re-enroll is rejected while a secret exists.
        $again = $app->handle($this->json($this->createRequest('POST', "/api/v1/users/$targetId/mfa/enroll"), [
            'reason' => 'double enroll attempt',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertSame(422, $again->getStatusCode());

        // Disable wipes the secret.
        $disable = $app->handle($this->json($this->createRequest('POST', "/api/v1/users/$targetId/mfa/disable"), [
            'reason' => 'Board rotation (TASK-036)',
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertSame(200, $disable->getStatusCode());
        $disableData = json_decode((string) $disable->getBody(), true);
        $this->assertFalse($disableData['data']['mfa_enabled']);

        $storedAfter = $this->pdo->query("SELECT mfa_secret_enc FROM app.users WHERE id = $targetId")->fetchColumn();
        $this->assertNull($storedAfter);
    }

    public function testEnrollRequiresReason(): void
    {
        $targetId = $this->createTargetUser();
        $app = $this->getAppInstance();

        $noReason = $app->handle($this->json($this->createRequest('POST', "/api/v1/users/$targetId/mfa/enroll"), [])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertSame(422, $noReason->getStatusCode());
        $data = json_decode((string) $noReason->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $data['error']['code']);

        $badReason = $app->handle($this->json($this->createRequest('POST', "/api/v1/users/$targetId/mfa/enroll"), [
            'reason' => str_repeat('x', 501),
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken));
        $this->assertSame(422, $badReason->getStatusCode());
        $data = json_decode((string) $badReason->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $data['error']['code']);
    }
}