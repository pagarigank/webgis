<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * TokenService orchestrates issuing and rotating JWT access tokens and
 * opaque refresh tokens with family-based reuse detection.
 */
final class TokenService
{
    private const ALGO = 'HS256';
    public const ACCESS_TTL = 900; // seconds, mirrors JWT_TTL
    private const JWT_TTL = 900; // 15 minutes
    private const REFRESH_TTL = 1209600; // 14 days

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $jwtSecret
    ) {}

    /**
     * Issue a brand new token pair (new family).
     * 
     * @return array{0: string, 1: string} [jwt, refreshToken]
     */
    public function issueTokens(int $userId): array
    {
        $jwt = $this->createJwt($userId);
        $plainRefreshToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainRefreshToken);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO app.refresh_tokens (user_id, family_id, token_hash, expires_at)
            VALUES (:user_id, gen_random_uuid(), decode(:token_hash, 'hex'), NOW() + INTERVAL '14 days')
            RETURNING family_id
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash
        ]);
        
        $familyId = $stmt->fetchColumn();
        if (!$familyId) {
            throw new RuntimeException("Failed to generate family_id for refresh token.");
        }
        
        // Return a combined string so we can parse the family_id and plain token during rotation
        // e.g. "familyId:plainToken"
        $finalRefreshToken = $familyId . ':' . $plainRefreshToken;
        
        return [$jwt, $finalRefreshToken];
    }

    /**
     * Rotate an existing refresh token.
     * Checks if it's revoked. If it is, revokes the whole family (Reuse detection).
     * If valid, revokes it and issues a new pair in the same family.
     * 
     * @return array{0: string, 1: string} [jwt, newRefreshToken]
     */
    public function rotateRefreshToken(string $combinedToken): array
    {
        $parts = explode(':', $combinedToken, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Invalid refresh token format.");
        }
        
        [$familyId, $plainRefreshToken] = $parts;
        $tokenHash = hash('sha256', $plainRefreshToken);
        
        $this->pdo->beginTransaction();
        
        try {
            // Find the token
            $stmt = $this->pdo->prepare("
                SELECT id, user_id, revoked_at, expires_at 
                FROM app.refresh_tokens 
                WHERE family_id = :family_id AND token_hash = decode(:token_hash, 'hex')
                FOR UPDATE
            ");
            $stmt->execute([
                ':family_id' => $familyId,
                ':token_hash' => $tokenHash
            ]);
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                // Token not found
                $this->pdo->rollBack();
                throw new \InvalidArgumentException("Invalid refresh token.");
            }
            
            // If revoked, REUSE DETECTION!
            if ($row['revoked_at'] !== null) {
                // Revoke all sessions for the user
                $revokeStmt = $this->pdo->prepare("
                    UPDATE app.refresh_tokens 
                    SET revoked_at = NOW() 
                    WHERE user_id = :user_id AND revoked_at IS NULL
                ");
                $revokeStmt->execute([':user_id' => $row['user_id']]);
                $this->pdo->commit();
                
                throw new \DomainException("Refresh token reuse detected. All sessions revoked.");
            }
            
            // Check expiration
            if (strtotime($row['expires_at']) < time()) {
                $this->pdo->rollBack();
                throw new \DomainException("Refresh token expired.");
            }
            
            // Revoke current token
            $revokeStmt = $this->pdo->prepare("
                UPDATE app.refresh_tokens 
                SET revoked_at = NOW() 
                WHERE id = :id
            ");
            $revokeStmt->execute([':id' => $row['id']]);
            
            // Issue new refresh token in same family
            $newPlainRefreshToken = bin2hex(random_bytes(32));
            $newTokenHash = hash('sha256', $newPlainRefreshToken);
            
            $insertStmt = $this->pdo->prepare("
                INSERT INTO app.refresh_tokens (user_id, family_id, token_hash, expires_at)
                VALUES (:user_id, :family_id, decode(:token_hash, 'hex'), NOW() + INTERVAL '14 days')
            ");
            $insertStmt->execute([
                ':user_id' => $row['user_id'],
                ':family_id' => $familyId,
                ':token_hash' => $newTokenHash
            ]);
            
            $newJwt = $this->createJwt((int)$row['user_id']);
            $finalNewRefreshToken = $familyId . ':' . $newPlainRefreshToken;
            
            $this->pdo->commit();
            
            return [$newJwt, $finalNewRefreshToken];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Revoke the whole family a refresh token belongs to (logout).
     * A provided combined token that does not parse is a no-op: the session
     * cookie is gone anyway, and re-validating would leak existence info.
     */
    public function revokeRefreshToken(string $combinedToken): void
    {
        $parts = explode(':', $combinedToken, 2);
        if (count($parts) !== 2) {
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE app.refresh_tokens
            SET revoked_at = NOW(), revoked_reason = 'logout'
            WHERE family_id = :family_id AND revoked_at IS NULL
        ");
        $stmt->execute([':family_id' => $parts[0]]);
    }

    private function createJwt(int $userId): string
    {
        $payload = [
            'iss' => 'webgis',
            'sub' => (string)$userId,
            'iat' => time(),
            'exp' => time() + self::JWT_TTL,
            'jti' => bin2hex(random_bytes(16)),
        ];
        
        return JWT::encode($payload, $this->jwtSecret, self::ALGO);
    }
}
