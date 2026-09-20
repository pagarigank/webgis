<?php
declare(strict_types=1);

namespace App\RBAC;

use PDO;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Resolves a user's effective permissions based on their assigned roles.
 * Employs a cache keyed by the user's database `version` to eliminate
 * redundant DB queries across requests, strictly invalidating if the user
 * or their role assignments change.
 */
final class PermissionResolver
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CacheInterface $cache
    ) {}

    /**
     * Get an array of all permission codes the user currently holds.
     * 
     * @return array<string>
     * @throws InvalidArgumentException
     */
    public function getEffectivePermissions(int $userId, int $userVersion): array
    {
        $cacheKey = "user_permissions_{$userId}_v{$userVersion}";

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        // Cache miss: compute from DB
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT p.code
            FROM app.permissions p
            JOIN app.role_permissions rp ON p.id = rp.permission_id
            JOIN app.user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = :user_id
        ");
        
        $stmt->execute([':user_id' => $userId]);
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Cache indefinitely (or for a long time); it naturally invalidates 
        // when the user's `version` is incremented.
        $this->cache->set($cacheKey, $permissions, 86400 * 30); // 30 days

        return $permissions;
    }
}
