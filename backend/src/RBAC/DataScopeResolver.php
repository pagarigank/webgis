<?php
declare(strict_types=1);

namespace App\RBAC;

use PDO;

/**
 * Resolves whether a user has access to a specific record based on their Data Scopes.
 * Implements the FR-016 resolution order: 
 * explicit NONE deny > most specific grant > default deny.
 */
final class DataScopeResolver
{
    private const PRIORITY = [
        'BARANGAY'       => 1,
        'MUNICIPALITY'   => 2,
        'PROVINCE'       => 3,
        'REGION'         => 4,
        'ORGANIZATION'   => 5,
        'CUSTOM_AREA'    => 6,
        'GLOBAL'         => 7,
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Resolves the maximum access level a user has for a given record.
     * Returns 'NONE', 'VIEW', 'EDIT', or 'APPROVE'.
     */
    public function resolveMaxAccessLevel(
        int $userId,
        ?string $psgc = null,
        ?int $orgId = null,
        ?string $geomWkb = null
    ): string {
        
        $sql = "
            SELECT scope_type, access_level, scope_ref_code
            FROM app.data_scopes
            WHERE user_id = :user_id
              AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)
              AND (
                  (scope_type = 'BARANGAY' AND scope_ref_code = :psgc_brgy)
                  OR (scope_type = 'MUNICIPALITY' AND :psgc_mun LIKE SUBSTRING(scope_ref_code, 1, 6) || '%')
                  OR (scope_type = 'PROVINCE' AND :psgc_prov LIKE SUBSTRING(scope_ref_code, 1, 4) || '%')
                  OR (scope_type = 'REGION' AND :psgc_reg LIKE SUBSTRING(scope_ref_code, 1, 2) || '%')
                  OR (scope_type = 'ORGANIZATION' AND scope_ref_code = :org_id)
                  OR (scope_type = 'GLOBAL')
                  -- CUSTOM_AREA requires ST_Intersects which we handle below dynamically
        ";

        $params = [
            ':user_id' => $userId,
            ':psgc_brgy' => $psgc,
            ':psgc_mun' => $psgc,
            ':psgc_prov' => $psgc,
            ':psgc_reg' => $psgc,
            ':org_id' => $orgId !== null ? (string)$orgId : null,
        ];

        if ($geomWkb !== null && $geomWkb !== '') {
            $sql .= " OR (scope_type = 'CUSTOM_AREA' AND ST_Intersects(geom, ST_GeomFromWKB(decode(:geom_wkb, 'hex'), 4326))) ";
            $params[':geom_wkb'] = $geomWkb;
        }

        $sql .= " )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $scopes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($scopes)) {
            return 'NONE'; // Default Deny
        }

        // 1. Explicit NONE deny wins instantly
        foreach ($scopes as $scope) {
            if ($scope['access_level'] === 'NONE') {
                return 'NONE';
            }
        }

        // 2. Sort by specificity
        usort($scopes, function ($a, $b) {
            $pA = self::PRIORITY[$a['scope_type']] ?? 99;
            $pB = self::PRIORITY[$b['scope_type']] ?? 99;
            return $pA <=> $pB;
        });

        // 3. Return the access level of the most specific grant
        return $scopes[0]['access_level'];
    }

    /**
     * Convenience method to check if a user has a specific minimum access level.
     * Note: Access level hierarchy: VIEW < EDIT < APPROVE
     */
    public function hasAccess(
        int $userId,
        string $requiredAccessLevel,
        ?string $psgc = null,
        ?int $orgId = null,
        ?string $geomWkb = null
    ): bool {
        $level = $this->resolveMaxAccessLevel($userId, $psgc, $orgId, $geomWkb);
        
        if ($level === 'NONE') {
            return false;
        }
        if ($level === $requiredAccessLevel) {
            return true;
        }
        
        // Hierarchy resolution
        if ($requiredAccessLevel === 'VIEW' && in_array($level, ['EDIT', 'APPROVE'], true)) {
            return true;
        }
        if ($requiredAccessLevel === 'EDIT' && $level === 'APPROVE') {
            return true;
        }

        return false;
    }

    /**
     * Gets all formatted data scopes for a user, suitable for the /me API response.
     * Includes human-readable names for geographic and organizational scopes.
     *
     * @return array<int, array{type: string, code: string|null, name: string|null, access: string}>
     */
    public function getUserScopes(int $userId): array
    {
        // A UNION query to easily join different reference tables based on scope_type
        $sql = "
            SELECT 
                ds.scope_type as type,
                ds.scope_ref_code as code,
                ds.access_level as access,
                pa.name as name
            FROM app.data_scopes ds
            LEFT JOIN ref.psgc_areas pa ON ds.scope_ref_code = pa.code
            WHERE ds.user_id = :user_id 
              AND ds.scope_type IN ('BARANGAY', 'MUNICIPALITY', 'PROVINCE', 'REGION')
              AND (ds.valid_to IS NULL OR ds.valid_to >= CURRENT_DATE)
              
            UNION ALL
            
            SELECT 
                ds.scope_type as type,
                ds.scope_ref_code as code,
                ds.access_level as access,
                org.name as name
            FROM app.data_scopes ds
            LEFT JOIN app.organizations org ON ds.scope_ref_code = org.id::varchar
            WHERE ds.user_id = :user_id 
              AND ds.scope_type = 'ORGANIZATION'
              AND (ds.valid_to IS NULL OR ds.valid_to >= CURRENT_DATE)
              
            UNION ALL
            
            SELECT 
                ds.scope_type as type,
                ds.scope_ref_code as code,
                ds.access_level as access,
                NULL as name
            FROM app.data_scopes ds
            WHERE ds.user_id = :user_id 
              AND ds.scope_type NOT IN ('BARANGAY', 'MUNICIPALITY', 'PROVINCE', 'REGION', 'ORGANIZATION')
              AND (ds.valid_to IS NULL OR ds.valid_to >= CURRENT_DATE)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        
        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'type'   => $row['type'],
                'code'   => $row['code'],
                'name'   => $row['name'],
                'access' => $row['access'],
            ];
        }

        return $results;
    }
}
