<?php
declare(strict_types=1);

namespace App\RBAC;

use PDO;
use Psr\SimpleCache\CacheInterface;

/**
 * Resolves a user's effective capabilities for GIS layers.
 * Capabilities are aggregated (logical OR) across all roles assigned to the user.
 * 
 * Uses a cache keyed by user_id and the user's database `version` to ensure
 * changes to role assignments automatically invalidate the cache.
 */
final class LayerCapabilityResolver
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CacheInterface $cache
    ) {}

    /**
     * Get an aggregated array of capabilities for a single layer.
     * 
     * @return array<string, bool> ['can_view' => bool, 'can_create' => bool, ...]
     */
    public function getLayerCapabilities(int $userId, int $userVersion, int $layerId): array
    {
        $allCapabilities = $this->getAllCapabilities($userId, $userVersion);
        
        return $allCapabilities[$layerId] ?? [
            'can_view' => false,
            'can_create' => false,
            'can_update' => false,
            'can_delete' => false,
            'can_approve' => false
        ];
    }

    /**
     * Get all effective layer capabilities for the user, keyed by layer_id.
     * 
     * @return array<int, array<string, bool>> 
     */
    public function getAllCapabilities(int $userId, int $userVersion): array
    {
        $cacheKey = "layer_capabilities_{$userId}_v{$userVersion}";

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $stmt = $this->pdo->prepare("
            SELECT 
                lp.layer_id,
                BOOL_OR(lp.can_view) as can_view,
                BOOL_OR(lp.can_create) as can_create,
                BOOL_OR(lp.can_update) as can_update,
                BOOL_OR(lp.can_delete) as can_delete,
                BOOL_OR(lp.can_approve) as can_approve
            FROM app.layer_permissions lp
            JOIN app.user_roles ur ON lp.role_id = ur.role_id
            WHERE ur.user_id = :user_id
            GROUP BY lp.layer_id
        ");
        
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $capabilities = [];
        foreach ($rows as $row) {
            $layerId = (int)$row['layer_id'];
            $capabilities[$layerId] = [
                'can_view' => (bool)$row['can_view'],
                'can_create' => (bool)$row['can_create'],
                'can_update' => (bool)$row['can_update'],
                'can_delete' => (bool)$row['can_delete'],
                'can_approve' => (bool)$row['can_approve'],
            ];
        }

        $this->cache->set($cacheKey, $capabilities, 86400 * 30); // 30 days

        return $capabilities;
    }
}
