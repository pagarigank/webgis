<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Error\ApiError;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
use Throwable;

/**
 * TOTP MFA orchestration (TASK-036, specification.md FR-006).
 *
 * A login that passes credential verification and belongs to an MFA-required
 * account produces a short-lived MFA challenge JWT (typ=mfa, 5 minutes)
 * instead of an access/refresh pair. The user proves possession of the
 * authenticator by submitting the current TOTP code together with that token;
 * verifying it issues the real token pair.
 *
 * Secrets are stored encrypted at rest with libsodium secretbox (XChaCha20-
 * Poly1305) using a 32-byte key derived from MFA_ENCRYPTION_KEY (ADR-22).
 * The plaintext secret only ever appears in the enrollment response, never in
 * later reads.
 */
final class MfaService
{
    private const ALGO = 'HS256';
    private const CHALLENGE_TTL = 300; // 5 minutes — enough for a code cycle
    private const TOKEN_TYPE = 'mfa';

    public function __construct(
        private readonly PDO $pdo,
        private readonly TokenService $tokenService,
        private readonly string $jwtSecret,
        private readonly string $encryptionKey = '',
    ) {}

    /**
     * Start an MFA challenge for a user whose credentials have been verified.
     * The returned token authorises a single /auth/mfa/verify call.
     *
     * @throws ApiError if MFA cannot be satisfied
     */
    public function beginChallenge(int $userId, ?string $encryptedSecret): string
    {
        if ($this->encryptionKey === '') {
            throw new ApiError('AUTH_INVALID', 'MFA is not configured on this server.', 401);
        }
        if ($encryptedSecret === null) {
            // Role demands MFA but the account has no enrolled secret: fail
            // closed rather than silently downgrading (TASK-036 AC).
            throw new ApiError('AUTH_INVALID', 'MFA is required for this account but is not yet enrolled.', 401, [
                'enrolled' => false,
            ]);
        }

        $payload = [
            'iss' => 'webgis',
            'typ' => self::TOKEN_TYPE,
            'sub' => (string) $userId,
            'iat' => time(),
            'exp' => time() + self::CHALLENGE_TTL,
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode($payload, $this->jwtSecret, self::ALGO);
    }

    /**
     * Verify a TOTP code against the challenge token and, on success, issue
     * the real token pair.
     *
     * @return array{0: string, 1: string} [jwt, refreshToken]
     * @throws ApiError on invalid token or wrong code
     */
    public function verifyCode(string $mfaToken, string $code): array
    {
        try {
            $decoded = JWT::decode($mfaToken, new Key($this->jwtSecret, self::ALGO));
        } catch (Throwable) {
            throw new ApiError('AUTH_INVALID', 'The MFA challenge is invalid or expired.', 401);
        }

        if (($decoded->typ ?? '') !== self::TOKEN_TYPE) {
            throw new ApiError('AUTH_INVALID', 'This token is not an MFA challenge.', 401);
        }

        $userId = (int) ($decoded->sub ?? 0);
        $secret = $this->secretForUser($userId);

        if (!Totp::verify($secret, $code)) {
            throw new ApiError('AUTH_INVALID', 'The authentication code is incorrect.', 401);
        }

        return $this->tokenService->issueTokens($userId);
    }

    /** Decrypt and return the plaintext TOTP secret for a user. */
    public function secretForUser(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT mfa_secret_enc FROM app.fn_user_profile(:id)');
        $stmt->execute([':id' => $userId]);
        $encrypted = $stmt->fetchColumn();

        if ($this->encryptionKey === '' || $encrypted === false || $encrypted === null) {
            throw new ApiError('AUTH_INVALID', 'MFA is not enrolled for this account.', 401);
        }

        return $this->decryptSecret((string) $encrypted);
    }

    /** Base64-encrypt the plaintext secret for storage. */
    public function encryptSecret(string $plaintext): string
    {
        if ($this->encryptionKey === '') {
            throw new ApiError('AUTH_INVALID', 'MFA is not configured on this server.', 401);
        }

        $key = hash('sha256', $this->encryptionKey, true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce . $cipher);
    }

    private function decryptSecret(string $stored): string
    {
        $key = hash('sha256', $this->encryptionKey, true);
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new ApiError('AUTH_INVALID', 'The stored MFA secret is invalid.', 401);
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new ApiError('AUTH_INVALID', 'The stored MFA secret could not be decrypted.', 401);
        }

        return $plain;
    }
}