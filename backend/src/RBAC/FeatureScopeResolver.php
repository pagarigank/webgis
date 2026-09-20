<?php
declare(strict_types=1);

namespace App\RBAC;

use PDO;

final class FeatureScopeResolver
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Return capability bitset for a layer: view | create | update | delete | approve.
     * Aggregates across all roles assigned to $userId; S = data-scope (row-level) gates
     * are applied at read/write time inside gis_features RLS policies, not here.
     */
    public function getLayerCapabilities(int $userId, int $layerId): array
    {
        $sql = "
            SELECT
                BOOL_OR(lp.can_view)  AS can_view,
                BOOL_OR(lp.can_create) AS can_create,
                BOOL_OR(lp.can_update) AS can_update,
                BOOL_OR(lp.can_delete) AS can_delete,
                BOOL_OR(lp.can_approve) AS can_approve
            FROM app.layer_permissions lp
            JOIN app.user_roles ur ON lp.role_id = ur.role_id
            WHERE ur.user_id = :uid AND lp.layer_id = :lid
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':lid' => $layerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'can_view' => false,
                'can_create' => false,
                'can_update' => false,
                'can_delete' => false,
                'can_approve' => false,
            ];
        }

        return [
            'can_view'  => (bool) $row['can_view'],
            'can_create' => (bool) $row['can_create'],
            'can_update' => (bool) $row['can_update'],
            'can_delete' => (bool) $row['can_delete'],
            'can_approve' => (bool) $row['can_approve'],
        ];
    }

    /**
     * Confirm a user may at least view features of a layer.
     */
    public function canViewLayer(int $userId, int $layerId): bool
    {
        return $this->getLayerCapabilities($userId, $layerId)['can_view'];
    }

    /**
     * Confirm a user may create/update/delete features of a layer.
     */
    public function canEditLayer(int $userId, int $layerId): bool
    {
        $caps = $this->getLayerCapabilities($userId, $layerId);
        return $caps['can_create'] || $caps['can_update'] || $caps['can_delete'];
    }

    /**
     * Return user_id so the controller can SET LOCAL app.current_user_id = :uid
     * for RLS on gis_features. Returns 0 when not authenticated (shouldn't happen here).
     */
    public function getUserId(int $rawUserId): int
    {
        return $rawUserId > 0 ? $rawUserId : 0;
    }
}
