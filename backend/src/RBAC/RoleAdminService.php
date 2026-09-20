<?php
declare(strict_types=1);

namespace App\RBAC;

use App\Audit\AuditWriter;
use App\Core\Db\DbTransaction;
use App\Core\Error\ApiError;
use PDO;
use Throwable;

final class RoleAdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditWriter $audit,
    ) {}

    public function list(int $page, int $perPage, ?string $query): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 200);

        $where = '';
        $params = [];
        if ($query !== null && $query !== '') {
            $where = ' WHERE (r.code ILIKE :q OR r.name ILIKE :q)';
            $params[':q'] = '%' . $query . '%';
        }

        $stmt = $this->pdo->prepare("SELECT count(*) FROM app.roles r $where");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT r.id, r.code, r.name, r.description, r.is_system, r.created_at, r.updated_at,
                   (SELECT count(*) FROM app.user_roles ur WHERE ur.role_id = r.id) AS user_count
            FROM app.roles r
            $where
            ORDER BY r.code
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $this->map($stmt->fetchAll()),
            'meta' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    public function create(array $data, int $actorId, ?string $requestId, ?string $reason = null): array
    {
        $fields = [];
        $code = $this->requireString($data, 'code', $fields);
        $name = $this->requireString($data, 'name', $fields);
        $description = $data['description'] ?? null;
        $description = $description !== null && $description !== '' ? (string) $description : null;

        if (!preg_match('/^[A-Z][A-Z0-9_]{2,49}$/', $code)) {
            $fields[] = ['field' => 'code', 'rule' => 'VR-ROLE-301', 'message' => 'Role code must be 3-50 characters using uppercase letters, digits and underscores, starting with a letter.'];
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $fields[] = ['field' => 'name', 'rule' => 'VR-ROLE-302', 'message' => 'Role name must be 2-160 characters.'];
        }
        $this->throwIfFields($fields);

        $stmt = $this->pdo->prepare('SELECT 1 FROM app.roles WHERE code = :code');
        $stmt->execute([':code' => $code]);
        if ($stmt->fetchColumn() !== false) {
            $this->fail('code', 'VR-ROLE-301', 'A role with this code already exists.');
        }

        $tx = DbTransaction::begin($this->pdo);
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO app.roles (code, name, description, is_system) VALUES (:code, :name, :description, false) RETURNING id
            ");
            $stmt->execute([':code' => $code, ':name' => $name, ':description' => $description]);
            $roleId = (int) $stmt->fetchColumn();

            $this->audit->write('INSERT', 'app.roles', (string) $roleId, null,
                ['code' => $code, 'name' => $name, 'description' => $description, 'is_system' => false],
                $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return $this->get($roleId);
    }

    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT id, code, name, description, is_system, created_at, updated_at FROM app.roles WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Role not found.', 404);
        }

        $stmt = $this->pdo->prepare("
            SELECT p.code FROM app.permissions p
            JOIN app.role_permissions rp ON rp.permission_id = p.id
            WHERE rp.role_id = :role_id ORDER BY p.code
        ");
        $stmt->execute([':role_id' => $id]);
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $role = $this->map([$row])[0];
        $role['permissions'] = $permissions;
        return $role;
    }

    public function update(int $id, array $data, int $actorId, ?string $requestId, ?string $reason = null): array
    {
        $current = $this->fetchRoleOrFail($id);

        $fields = [];
        $name = $this->optionalString($data['name'] ?? null, 160, 'name', $fields);
        $description = isset($data['description']) ? (string) $data['description'] : null;
        if ($name !== null && (mb_strlen($name) < 2 || mb_strlen($name) > 160)) {
            $fields[] = ['field' => 'name', 'rule' => 'VR-ROLE-302', 'message' => 'Role name must be 2-160 characters.'];
        }
        $this->throwIfFields($fields);

        if (isset($data['code']) && (string) $data['code'] !== (string) $current['code']) {
            if ((bool) $current['is_system']) {
                $this->fail('code', 'VR-ROLE-303', 'The code of a system role cannot be changed.');
            }
            $fields = [];
            $code = $this->requireString($data, 'code', $fields);
            if (!preg_match('/^[A-Z][A-Z0-9_]{2,49}$/', $code)) {
                $fields[] = ['field' => 'code', 'rule' => 'VR-ROLE-301', 'message' => 'Role code must be 3-50 characters using uppercase letters, digits and underscores, starting with a letter.'];
            }
            $this->throwIfFields($fields);
            $stmt = $this->pdo->prepare('SELECT 1 FROM app.roles WHERE code = :code AND id <> :id');
            $stmt->execute([':code' => $code, ':id' => $id]);
            if ($stmt->fetchColumn() !== false) {
                $this->fail('code', 'VR-ROLE-301', 'A role with this code already exists.');
            }
        }

        $newCode = $data['code'] ?? $current['code'];
        $newName = $name ?? $current['name'];
        $newDescription = $data['description'] ?? $current['description'];

        $tx = DbTransaction::begin($this->pdo);
        try {
            $stmt = $this->pdo->prepare("
                UPDATE app.roles SET code = :code, name = :name, description = :description, updated_at = now() WHERE id = :id RETURNING id
            ");
            $stmt->execute([':code' => $newCode, ':name' => $newName, ':description' => $newDescription, ':id' => $id]);

            if ($newCode !== $current['code']) {
                $this->bumpUserVersions($id);
            }

            $this->audit->write('UPDATE', 'app.roles', (string) $id,
                ['code' => $current['code'], 'name' => $current['name']],
                ['code' => $newCode, 'name' => $newName, 'description' => $newDescription],
                $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return $this->get($id);
    }

    public function delete(int $id, int $actorId, ?string $requestId, ?string $reason = null): void
    {
        $current = $this->fetchRoleOrFail($id);
        if ((bool) $current['is_system']) {
            $this->fail('id', 'VR-ROLE-304', 'System roles cannot be deleted.');
        }

        $stmt = $this->pdo->prepare('SELECT count(*) FROM app.user_roles WHERE role_id = :role_id');
        $stmt->execute([':role_id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $this->fail('id', 'VR-ROLE-304', 'The role cannot be deleted while it is assigned to one or more users.');
        }

        $tx = DbTransaction::begin($this->pdo);
        try {
            $this->pdo->prepare('DELETE FROM app.role_permissions WHERE role_id = :role_id')->execute([':role_id' => $id]);
            $this->pdo->prepare('DELETE FROM app.roles WHERE id = :id')->execute([':id' => $id]);

            $this->audit->write('DELETE', 'app.roles', (string) $id,
                ['code' => $current['code'], 'name' => $current['name']],
                null, $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }
    }

    public function setPermissions(int $id, array $permissionCodes, int $actorId, ?string $requestId, ?string $reason): array
    {
        $this->assertReason($reason);
        $current = $this->fetchRoleOrFail($id);
        if ((bool) $current['is_system']) {
            $this->fail('id', 'VR-ROLE-303', 'The permission set of a system role cannot be changed.');
        }

        $fields = [];
        $codes = [];
        foreach ($permissionCodes as $index => $code) {
            if (!is_string($code) || $code === '') {
                $fields[] = ['field' => "permissions[$index]", 'rule' => 'VR-ROLE-305', 'message' => 'Each permission must be a non-empty code string.'];
                continue;
            }
            $stmt = $this->pdo->prepare('SELECT 1 FROM app.permissions WHERE code = :code');
            $stmt->execute([':code' => $code]);
            if ($stmt->fetchColumn() === false) {
                $fields[] = ['field' => "permissions[$index]", 'rule' => 'VR-ROLE-305', 'message' => "Permission '{$code}' does not exist in the catalogue."];
                continue;
            }
            $codes[] = $code;
        }
        $this->throwIfFields($fields);
        $codes = array_values(array_unique($codes));

        $tx = DbTransaction::begin($this->pdo);
        try {
            $this->pdo->prepare('DELETE FROM app.role_permissions WHERE role_id = :role_id')->execute([':role_id' => $id]);

            $insert = $this->pdo->prepare("
                INSERT INTO app.role_permissions (role_id, permission_id)
                SELECT :role_id, p.id FROM app.permissions p WHERE p.code = :code
            ");
            foreach ($codes as $code) {
                $insert->execute([':role_id' => $id, ':code' => $code]);
            }

            $this->bumpUserVersions($id);

            $this->audit->write('UPDATE', 'app.role_permissions', (string) $id,
                null, ['permissions' => $codes], $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return $this->get($id);
    }

    public function listPermissions(?string $module, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 500);

        $where = '';
        $params = [];
        if ($module !== null && $module !== '') {
            $where = ' WHERE module = :module';
            $params[':module'] = $module;
        }

        $stmt = $this->pdo->prepare("SELECT count(*) FROM app.permissions p $where");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT code, module, description FROM app.permissions p
            $where ORDER BY module, code LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(),
            'meta' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    /** @return array<int, array<string,mixed>> */
    private function map(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'          => (int) $row['id'],
                'code'        => $row['code'],
                'name'        => $row['name'],
                'description' => $row['description'],
                'is_system'   => (bool) $row['is_system'],
                'user_count'  => isset($row['user_count']) ? (int) $row['user_count'] : null,
                'created_at'  => $row['created_at'],
                'updated_at'  => $row['updated_at'],
            ];
        }
        return $out;
    }

    private function fetchRoleOrFail(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT id, code, name, description, is_system FROM app.roles WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Role not found.', 404);
        }
        return $row;
    }

    private function bumpUserVersions(int $roleId): void
    {
        $this->pdo->prepare("
            UPDATE app.users SET version = version + 1
            WHERE id IN (SELECT user_id FROM app.user_roles WHERE role_id = :role_id)
        ")->execute([':role_id' => $roleId]);
    }

    private function assertReason(?string $reason): void
    {
        if ($reason === null || trim($reason) === '') {
            $this->fail('reason', 'VR-ADMIN-501', 'A reason is required for permission changes (FR-017).');
        }
        if (mb_strlen($reason) > 500) {
            $this->fail('reason', 'VR-ADMIN-501', 'The audit reason must not exceed 500 characters.');
        }
    }

    private function requireString(array $data, string $key, array &$fields): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            $fields[] = ['field' => $key, 'rule' => 'VR-ADMIN-502', 'message' => "Field '{$key}' is required."];
            return '';
        }
        return $value;
    }

    private function optionalString(mixed $value, int $max, string $key, array &$fields): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $fields[] = ['field' => $key, 'rule' => 'VR-ADMIN-502', 'message' => "Field '{$key}' must be a string."];
            return null;
        }
        if (mb_strlen($value) > $max) {
            $fields[] = ['field' => $key, 'rule' => 'VR-ADMIN-503', 'message' => "Field '{$key}' must not exceed {$max} characters."];
        }
        return $value;
    }

    private function fail(string $field, string $rule, string $message): never
    {
        throw new ApiError('VALIDATION_FAILED', 'Request failed validation.', 422, [
            'fields' => [['field' => $field, 'rule' => $rule, 'message' => $message]],
        ]);
    }

    private function throwIfFields(array $fields): void
    {
        if ($fields !== []) {
            throw new ApiError('VALIDATION_FAILED', 'Request failed validation.', 422, ['fields' => $fields]);
        }
    }
}