<?php
declare(strict_types=1);

namespace App\Auth\Http;

use App\Auth\LoginService;
use App\Auth\MfaService;
use App\Auth\TokenService;
use App\Core\Error\ApiError;
use App\Core\Http\Request\JsonBodyParser;
use App\Core\Http\Response\Envelope;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use DomainException;

/**
 * HTTP auth surface (TASK-036, api.md §2): login, MFA verification, refresh
 * rotation and logout.
 *
 * The refresh token is only ever handed to the client as an HttpOnly,
 * SameSite cookie restricted to /api/v1/auth; it never appears in the
 * JSON payload. Refresh/logout are the only cookie-authenticated endpoints and
 * are therefore CSRF-gated by CsrfMiddleware (api.md §1.3).
 *
 * Cookie security flags are environment-aware (ADR-13): in local/dev (HTTP)
 * the Secure flag is omitted so the browser actually stores the cookie; in
 * production (HTTPS) Secure is set and SameSite reverts to Strict. The CSRF
 * cookie follows the same rule so the double-submit pair stays consistent.
 */
final class AuthController
{
    private const REFRESH_COOKIE = 'refresh_token';
    private const CSRF_COOKIE = 'csrf_token';
    private const REFRESH_MAX_AGE = 1209600; // 14 days
    private const ALGO = 'HS256';

    public function __construct(
        private readonly LoginService $loginService,
        private readonly MfaService $mfaService,
        private readonly TokenService $tokenService,
        private readonly PDO $pdo,
        private readonly string $jwtSecret,
    ) {}

    /** POST /api/v1/auth/login */
    public function login(Request $request, Response $response): Response
    {
        try {
            $data = JsonBodyParser::parse($request);
            $username = is_string($data['username'] ?? null) ? $data['username'] : '';
            $password = is_string($data['password'] ?? null) ? $data['password'] : '';

            if ($username === '' || $password === '') {
                throw new ApiError('VALIDATION_FAILED', 'Username and password are required.', 422);
            }

            try {
                [$jwt, $refresh] = $this->loginService->attemptLogin($username, $password);
            } catch (DomainException $e) {
                throw new ApiError('AUTH_INVALID', $e->getMessage(), 401);
            }

            $userId = $this->extractUserId($jwt);
            return $this->tokenResponse($response, $jwt, $refresh, $userId, $this->newCsrfToken());
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    /** POST /api/v1/auth/mfa/verify */
    public function mfaVerify(Request $request, Response $response): Response
    {
        try {
            $data = JsonBodyParser::parse($request);
            $mfaToken = is_string($data['mfa_token'] ?? null) ? $data['mfa_token'] : '';
            $code = is_string($data['code'] ?? null) ? $data['code'] : '';

            if ($mfaToken === '' || $code === '') {
                throw new ApiError('VALIDATION_FAILED', 'mfa_token and code are required.', 422);
            }

            [$userId, ] = $this->decodeMfaToken($mfaToken);
            [$jwt, $refresh] = $this->mfaService->verifyCode($mfaToken, $code);

            return $this->tokenResponse($response, $jwt, $refresh, $userId, $this->newCsrfToken());
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    /** POST /api/v1/auth/refresh */
    public function refresh(Request $request, Response $response): Response
    {
        try {
            $combined = $this->refreshCookie($request);
            if ($combined === null) {
                throw new ApiError('AUTH_INVALID', 'Missing refresh token cookie.', 401);
            }

            try {
                [$jwt, $newRefresh] = $this->tokenService->rotateRefreshToken($combined);
            } catch (DomainException $e) {
                throw new ApiError('AUTH_INVALID', $e->getMessage(), 401);
            } catch (\InvalidArgumentException $e) {
                throw new ApiError('AUTH_INVALID', $e->getMessage(), 401);
            }

            return $this->tokenResponse($response, $jwt, $newRefresh, $this->extractUserId($jwt), $this->csrfTokenFromRequest($request) ?? $this->newCsrfToken());
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    /** POST /api/v1/auth/logout */
    public function logout(Request $request, Response $response): Response
    {
        try {
            $combined = $this->refreshCookie($request);
            if ($combined === null) {
                throw new ApiError('AUTH_INVALID', 'Missing refresh token cookie.', 401);
            }
            $this->tokenService->revokeRefreshToken($combined);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }

        return $response
            ->withStatus(204)
            ->withAddedHeader('Set-Cookie', $this->clearRefreshCookie())
            ->withAddedHeader('Set-Cookie', $this->clearCsrfCookie());
    }

    // ----------------------------------------------------------------------

    private function tokenResponse(Response $response, string $jwt, string $refresh, int $userId, string $csrfToken): Response
    {
        $profile = $this->userProfile($userId);

        $payload = [
            'success' => true,
            'data' => [
                'access_token' => $jwt,
                'expires_in' => TokenService::ACCESS_TTL,
                'token_type' => 'Bearer',
                'user' => $profile,
            ],
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-CSRF-Token', $csrfToken)
            ->withAddedHeader('Set-Cookie', $this->refreshCookieHeader($refresh))
            ->withAddedHeader('Set-Cookie', $this->csrfCookieHeader($csrfToken));
    }

    /** @return array{int, string} [userId, mfaToken] */
    private function decodeMfaToken(string $mfaToken): array
    {
        try {
            $decoded = JWT::decode($mfaToken, new Key($this->jwtSecret, self::ALGO));
        } catch (Throwable $e) {
            throw new ApiError('AUTH_INVALID', 'The MFA challenge is invalid or expired.', 401);
        }
        if (($decoded->typ ?? '') !== 'mfa') {
            throw new ApiError('AUTH_INVALID', 'This token is not an MFA challenge.', 401);
        }
        return [(int) ($decoded->sub ?? 0), $mfaToken];
    }

    private function extractUserId(string $jwt): int
    {
        try {
            $decoded = JWT::decode($jwt, new Key($this->jwtSecret, self::ALGO));
        } catch (Throwable) {
            throw new ApiError('AUTH_INVALID', 'The access token is invalid.', 401);
        }
        return (int) ($decoded->sub ?? 0);
    }

    /** @return array<string,mixed> */
    private function userProfile(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM app.fn_user_profile(:id)');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new ApiError('AUTH_INVALID', 'User not found.', 401);
        }
        if ($row['status'] === 'DISABLED' || $row['status'] === 'SUSPENDED') {
            throw new ApiError('AUTH_INVALID', 'This account is not active.', 401);
        }

        return [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'org_id' => $row['org_id'] !== null ? (int) $row['org_id'] : null,
            'must_change_password' => (bool) $row['must_change_password'],
        ];
    }

    private function refreshCookie(Request $request): ?string
    {
        $cookies = [];
        foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
            $pair = trim($pair);
            $sep = strpos($pair, '=');
            if ($sep === false) {
                continue;
            }
            $cookies[trim(substr($pair, 0, $sep))] = trim(substr($pair, $sep + 1));
        }

        $value = $cookies[self::REFRESH_COOKIE] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        return rawurldecode($value);
    }

    private function isProduction(): bool
    {
        return (\getenv('APP_ENV') ?: 'local') !== 'local';
    }

    private function cookieSecureFlag(): string
    {
        return $this->isProduction() ? '; Secure' : '';
    }

    private function cookieSameSite(): string
    {
        return $this->isProduction() ? 'Strict' : 'Lax';
    }

    private function cookieFlagString(): string
    {
        $parts = ['HttpOnly', $this->cookieSameSite()];
        if ($this->isProduction()) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    private function refreshCookieHeader(string $refreshToken): string
    {
        return sprintf(
            '%s=%s; %s; Path=/api/v1/auth; Max-Age=%d',
            self::REFRESH_COOKIE,
            rawurlencode($refreshToken),
            $this->cookieFlagString(),
            self::REFRESH_MAX_AGE
        );
    }

    private function clearRefreshCookie(): string
    {
        return sprintf(
            '%s=%s; %s; Path=/api/v1/auth; Max-Age=0',
            self::REFRESH_COOKIE,
            '',
            $this->cookieFlagString()
        );
    }

    /** JS-readable half of the double-submit CSRF pair (ADR-23). */
    private function csrfCookieHeader(string $csrfToken): string
    {
        return sprintf(
            '%s=%s; %s; Path=/; Max-Age=%d',
            self::CSRF_COOKIE,
            $csrfToken,
            $this->cookieFlagString(),
            self::REFRESH_MAX_AGE
        );
    }

    private function clearCsrfCookie(): string
    {
        return sprintf(
            '%s=%s; %s; Path=/; Max-Age=0',
            self::CSRF_COOKIE,
            '',
            $this->cookieFlagString()
        );
    }

    private function newCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function csrfTokenFromRequest(Request $request): ?string
    {
        $cookies = [];
        foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
            $pair = trim($pair);
            $sep = strpos($pair, '=');
            if ($sep === false) {
                continue;
            }
            $cookies[trim(substr($pair, 0, $sep))] = trim(substr($pair, $sep + 1));
        }
        $value = $cookies[self::CSRF_COOKIE] ?? '';
        return $value === '' ? null : $value;
    }
}