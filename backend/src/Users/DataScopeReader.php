<?php
declare(strict_types=1);

namespace App\Users;

use PDO;

/**
 * Reads the full set of data-scope grants for a user for administrative views.
 * Unlike RBAC\DataScopeResolver::getUserScopes (which is restricted to active
 * grants and omits validity windows), this reader returns every grant row —
 * including expired/upcoming ones — plus valid_from/valid_to.
 */
final class DataScopeReader
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array{id: int, type: string, code: string|null, name: string|null, access: string, valid_from: string|null, valid_to: string|null}>
     */
    public function forUser(int $userId): array
    {
        $sql = "
            SELECT
                ds.id,
                ds.scope_type AS type,
                ds.scope_ref_code AS code,
                ds.access_level AS access,
                to_char(ds.valid_from, 'YYYY-MM-DD') AS valid_from,
                to_char(ds.valid_to, 'YYYY-MM-DD') AS valid_to,
                pa.name AS name
            FROM app.data_scopes ds
            LEFT JOIN ref.psgc_areas pa ON ds.scope_ref_code = pa.code
            WHERE ds.user_id = :user_id
              AND ds.scope_type IN ('BARANGAY', 'MUNICIPALITY', 'PROVINCE', 'REGION')

            UNION ALL

            SELECT
                ds.id,
                ds.scope_type AS type,
                ds.scope_ref_code AS code,
                ds.access_level AS access,
                to_char(ds.valid_from, 'YYYY-MM-DD') AS valid_from,
                to_char(ds.valid_to, 'YYYY-MM-DD') AS valid_to,
                org.name AS name
            FROM app.data_scopes ds
            LEFT JOIN app.organizations org ON ds.scope_ref_code = org.id::varchar
            WHERE ds.user_id = :user_id
              AND ds.scope_type = 'ORGANIZATION'

            UNION ALL

            SELECT
                ds.id,
                ds.scope_type AS type,
                ds.scope_ref_code AS code,
                ds.access_level AS access,
                to_char(ds.valid_from, 'YYYY-MM-DD') AS valid_from,
                to_char(ds.valid_to, 'YYYY-MM-DD') AS valid_to,
                NULL AS name
            FROM app.data_scopes ds
            WHERE ds.user_id = :user_id
              AND ds.scope_type NOT IN ('BARANGAY', 'MUNICIPALITY', 'PROVINCE', 'REGION', 'ORGANIZATION')

            ORDER BY type, code
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id'         => (int) $row['id'],
                'type'       => $row['type'],
                'code'       => $row['code'],
                'name'       => $row['name'],
                'access'     => $row['access'],
                'valid_from' => $row['valid_from'],
                'valid_to'   => $row['valid_to'],
            ];
        }

        return $rows;
    }
}