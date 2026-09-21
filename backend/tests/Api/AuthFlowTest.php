<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Auth\Hasher;
use App\Auth\MfaService;
use App\Auth\Totp;
use App\Auth\TokenService;
use PDO;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

/**
 * TASK-036 — full HTTP auth surface (api.md §2).
 *
 * Login → refresh rotation → logout, plus the MFA challenge gate: a user whose
 * account (or role) demands MFA gets a one-time ''mfa'' challenge JWT from
 * login and only receives the real token pair after a valid TOTP code.
 */
class AuthFlowTest extends TestCase
{
    private const SECRET = 'dummy_secret_for_testing_long_enough_for_hs256_0123456789';
    private const MFA_KEY = 'test-mfa-encryption-key-32-bytes-long-!!';
    private const BASE32 = 'JBSWY3DPEHPK3PXP'; // base32 for "Hello!\xDE\xAD\xBE\xEF" (16 chars)

    private PDO $pdo;
    private MfaService $mfa;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            'pgsql:host=' . (getenv('DB_HOST') ?: 'postgres') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . (getenv('DB_NAME') ?: 'webgis'),
            getenv('DB_USER') ?: 'postgres',
            getenv('DB_PASS') ?: 'postgres',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        putenv('JWT_SECRET=' . self::SECRET);
        $_SERVER['JWT_SECRET'] = self::SECRET;
        $_ENV['JWT_SECRET'] = self::SECRET;

        putenv('MFA_ENCRYPTION_KEY=' . self::MFA_KEY);
        $_SERVER['MFA_ENCRYPTION_KEY'] = self::MFA_KEY;
        $_ENV['MFA_ENCRYPTION_KEY'] = self::MFA_KEY;

        $this->mfa = new MfaService(
            $this->pdo,
            new TokenService($this->pdo, self::SECRET),
            self::SECRET,
            self::MFA_KEY
        );

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM app.refresh_tokens WHERE user_id IN (SELECT id FROM app.users WHERE username IN ('authplain', 'authmfa'))");
        $this->pdo->exec("DELETE FROM app.users WHERE username IN ('authplain', 'authmfa')");
        $this->pdo->exec('DELETE FROM app.rate_limit_entries');
    }

    private function createUser(string $username, bool $mfaEnabled, ?string $secretEnc): int
    {
        $pwHash = (new Hasher())->hash('Str0ng!Pass123');
        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, password_hash, full_name, status, version, mfa_enabled, mfa_secret_enc)
            VALUES (:u, :e, :h, 'Auth Test', 'ACTIVE', 1, :mfa, :sec)
            RETURNING id
        ");
        $stmt->execute([
            ':u' => $username,
            ':e' => $username . '@example.com',
            ':h' => $pwHash,
            ':mfa' => $mfaEnabled ? 'true' : 'false',
            ':sec' => $secretEnc,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    private function json(ServerRequestInterface $request, array $data): ServerRequestInterface
    {
        $request->getBody()->write(json_encode($data));
        return $request->withHeader('Content-Type', 'application/json');
    }

    private function cookies(string $refresh, string $csrf): string
    {
        return "refresh_token={$refresh}; csrf_token={$csrf}";
    }

    public function testLoginFailureReturnsAuthInvalid(): void
    {
        $this->createUser('authplain', false, null);

        $app = $this->getAppInstance();
        $response = $app->handle(
            $this->json($this->createRequest('POST', '/api/v1/auth/login'), ['username' => 'authplain', 'password' => 'WrongPass!'])
        );

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('AUTH_INVALID', $data['error']['code']);
    }

    public function testLoginAndRefreshRotationAndLogout(): void
    {
        $userId = $this->createUser('authplain', false, null);
        $app = $this->getAppInstance();

        // 1. Login → token pair + refresh cookie.
        $login = $app->handle(
            $this->json($this->createRequest('POST', '/api/v1/auth/login'), ['username' => 'authplain', 'password' => 'Str0ng!Pass123'])
        );
        $this->assertSame(200, $login->getStatusCode());
        $loginData = json_decode((string) $login->getBody(), true);
        $this->assertTrue($loginData['success']);
        $this->assertSame(900, $loginData['data']['expires_in']);
        $this->assertSame('Bearer', $loginData['data']['token_type']);
        $this->assertSame('authplain', $loginData['data']['user']['username']);
        $this->assertSame($userId, $loginData['data']['user']['id']);

        $cookieHeader = $login->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('refresh_token=', $cookieHeader);
        $this->assertStringContainsString('HttpOnly', $cookieHeader);
        // Environment-aware cookie flags (ADR-13): local/dev uses Lax over HTTP,
        // production uses Strict + Secure over HTTPS. Accept either here.
        $this->assertMatchesRegularExpression('/SameSite=(Strict|Lax)/', $cookieHeader);
        $refresh = $this->extractCookie($cookieHeader, 'refresh_token');
        $this->assertNotSame('', $refresh);

        // The CSRF half arrives as a JS-readable cookie and a response header
        // (ADR-23) so the SPA can echo it back on refresh/logout.
        $csrf = $login->getHeaderLine('X-CSRF-Token');
        $this->assertNotSame('', $csrf);
        $this->assertSame($csrf, $this->extractCookie($cookieHeader, 'csrf_token'));
        $this->assertStringNotContainsString('HttpOnly', $this->csrfCookieHeaderOf($cookieHeader));

        // 2. Refresh rotates the pair (CSRF double-submit required).
        $refreshRes = $app->handle(
            $this->createRequest('POST', '/api/v1/auth/refresh')
                ->withHeader('Cookie', $this->cookies($refresh, $csrf))
                ->withHeader('X-CSRF-Token', $csrf)
        );
        $this->assertSame(200, $refreshRes->getStatusCode());
        $refreshData = json_decode((string) $refreshRes->getBody(), true);
        $this->assertSame('authplain', $refreshData['data']['user']['username']);

        $newCookieHeader = $refreshRes->getHeaderLine('Set-Cookie');
        $newRefresh = $this->extractCookie($newCookieHeader, 'refresh_token');
        $this->assertNotSame('', $newRefresh);
        $this->assertNotSame($refresh, $newRefresh);

        // 3. The old cookie still exists server-side (rotated token is active).
        $this->assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM app.refresh_tokens WHERE user_id = $userId AND revoked_at IS NULL")->fetchColumn());

        // 4. Logout → 204 and the whole family is revoked.
        $logout = $app->handle(
            $this->createRequest('POST', '/api/v1/auth/logout')
                ->withHeader('Cookie', $this->cookies($newRefresh, $csrf))
                ->withHeader('X-CSRF-Token', $csrf)
        );
        $this->assertSame(204, $logout->getStatusCode());
        $this->assertStringContainsString('Max-Age=0', $logout->getHeaderLine('Set-Cookie'));
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM app.refresh_tokens WHERE user_id = $userId AND revoked_at IS NULL")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT count(*) FROM app.refresh_tokens WHERE user_id = $userId AND revoked_at IS NOT NULL")->fetchColumn());
    }

    public function testRefreshRejectsBadCsrf(): void
    {
        $this->createUser('authplain', false, null);
        $app = $this->getAppInstance();

        $login = $app->handle(
            $this->json($this->createRequest('POST', '/api/v1/auth/login'), ['username' => 'authplain', 'password' => 'Str0ng!Pass123'])
        );
        $refresh = $this->extractCookie($login->getHeaderLine('Set-Cookie'), 'refresh_token');

        $response = $app->handle(
            $this->createRequest('POST', '/api/v1/auth/refresh')
                ->withHeader('Cookie', $this->cookies($refresh, 'csrf-test-token-123'))
                ->withHeader('X-CSRF-Token', 'attacker-token-999')
        );

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('PERMISSION_DENIED', $data['error']['code']);
    }

    public function testMfaGateRequiresSecondFactorAndVerifies(): void
    {
        $secretEnc = $this->mfa->encryptSecret(self::BASE32);
        $userId = $this->createUser('authmfa', true, $secretEnc);
        $app = $this->getAppInstance();

        // 1. Password accepted but MFA required → 401 MFA_REQUIRED with a token.
        $login = $app->handle(
            $this->json($this->createRequest('POST', '/api/v1/auth/login'), ['username' => 'authmfa', 'password' => 'Str0ng!Pass123'])
        );
        $this->assertSame(401, $login->getStatusCode());
        $loginData = json_decode((string) $login->getBody(), true);
        $this->assertSame('MFA_REQUIRED', $loginData['error']['code']);
        $this->assertArrayHasKey('mfa_token', $loginData['error']['details'] ?? []);
        $this->assertTrue($loginData['error']['details']['enrolled']);
        $mfaToken = $loginData['error']['details']['mfa_token'];

        // 2. No access token is issued at this point.
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM app.refresh_tokens WHERE user_id = $userId")->fetchColumn());

        // 3. Wrong code → AUTH_INVALID, still no tokens.
        $bad = $app->handle(
            $this->json($this->createRequest('POST', '/api/v1/auth/mfa/verify'), ['mfa_token' => $mfaToken, 'code' => '000000'])
        );
        $this->assertSame(401, $bad->getStatusCode());
        $badData = json_decode((string) $bad->getBody(), true);
        $this->assertSame('AUTH_INVALID', $badData['error']['code']);

        // 4. Correct code → real token pair.
        $code = $this->codeAt(self::BASE32, time());
        $verify = $app->handle(
            $this->json($this->createRequest('POST', '/api/v1/auth/mfa/verify'), ['mfa_token' => $mfaToken, 'code' => $code])
        );
        $this->assertSame(200, $verify->getStatusCode());
        $verifyData = json_decode((string) $verify->getBody(), true);
        $this->assertSame('authmfa', $verifyData['data']['user']['username']);
        $this->assertStringContainsString('refresh_token=', $verify->getHeaderLine('Set-Cookie'));
    }

    private function extractCookie(string $setCookieHeader, string $name): string
    {
        foreach (explode(',', $setCookieHeader) as $chunk) {
            if (preg_match('/^' . preg_quote($name, '/') . '=([^;]+)/', trim($chunk), $m)) {
                return rawurldecode($m[1]);
            }
        }
        return '';
    }

    private function csrfCookieHeaderOf(string $setCookieHeader): string
    {
        foreach (explode(',', $setCookieHeader) as $chunk) {
            if (preg_match('/^csrf_token=/', trim($chunk))) {
                return $chunk;
            }
        }
        return '';
    }

    /** Fresh RFC 6238 code for a secret/time (mirrors Totp internals). */
    private function codeAt(string $base32Secret, int $time): string
    {
        $secret = $this->base32Decode($base32Secret);
        $counter = (int) floor($time / 30);
        $message = pack('N', $counter >> 32) . pack('N', $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $message, $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
                | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $input): string
    {
        $input = rtrim(strtoupper($input), '=');
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bitsLeft = 0;
        $out = '';
        foreach (str_split($input) as $char) {
            $value = strpos($alphabet, $char);
            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $out .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $out;
    }
}