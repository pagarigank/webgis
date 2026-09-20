<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;
use DomainException;
use RuntimeException;

/**
 * LoginService handles credential verification, brute-force protection
 * (lockout with backoff), and orchestrates token issuance.
 */
final class LoginService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Hasher $hasher,
        private readonly TokenService $tokenService
    ) {}

    /**
     * Attempt login with username and password.
     * 
     * @return array{0: string, 1: string} [jwt, refreshToken]
     * @throws DomainException on invalid credentials or lockout
     */
    public function attemptLogin(string $username, string $password): array
    {
        // 1. Fetch user by username (case-insensitive due to DB index)
        $stmt = $this->pdo->prepare("
            SELECT id, password_hash, failed_login_count, locked_until 
            FROM app.users 
            WHERE lower(username) = lower(:username)
            FOR UPDATE
        ");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Timing attack mitigation: still hash a dummy password so time is roughly equal
            $this->hasher->hash($password);
            throw new DomainException("Invalid username or password.");
        }

        // 2. Check lockout
        if ($user['locked_until'] !== null) {
            $lockedUntilTs = strtotime($user['locked_until']);
            if ($lockedUntilTs > time()) {
                throw new DomainException("Account is locked. Try again later.");
            }
        }

        // 3. Verify password
        [$verified, $newHash] = $this->hasher->verifyAndRehash($password, $user['password_hash']);

        if (!$verified) {
            $this->handleFailedLogin((int)$user['id'], (int)$user['failed_login_count']);
            throw new DomainException("Invalid username or password.");
        }

        // 4. Handle successful login
        $this->handleSuccessfulLogin((int)$user['id'], $newHash);

        // 5. Issue tokens
        return $this->tokenService->issueTokens((int)$user['id']);
    }

    private function handleFailedLogin(int $userId, int $currentAttempts): void
    {
        $newAttempts = $currentAttempts + 1;
        $lockedUntil = null;

        if ($newAttempts >= self::MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + (self::LOCKOUT_MINUTES * 60));
        }

        $stmt = $this->pdo->prepare("
            UPDATE app.users 
            SET failed_login_count = :attempts,
                locked_until = :locked_until
            WHERE id = :id
        ");
        $stmt->execute([
            ':attempts' => $newAttempts,
            ':locked_until' => $lockedUntil,
            ':id' => $userId
        ]);
    }

    private function handleSuccessfulLogin(int $userId, ?string $newPasswordHash): void
    {
        $sql = "
            UPDATE app.users 
            SET failed_login_count = 0,
                locked_until = NULL,
                last_login_at = NOW()
        ";
        $params = [':id' => $userId];

        if ($newPasswordHash !== null) {
            $sql .= ", password_hash = :hash";
            $params[':hash'] = $newPasswordHash;
        }

        $sql .= " WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
