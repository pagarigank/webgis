<?php
declare(strict_types=1);

namespace App\Organizations;

use App\Audit\AuditWriter;
use App\Core\Db\DbTransaction;
use App\Core\Error\ApiError;
use PDO;
use Throwable;

final class OrganizationAdminService
{
    private const ORG_TYPES = ['GOVERNMENT','REGION','PROVINCE','CITY','MUNICIPALITY','BARANGAY','OFFICE','DEPARTMENT','PROJECT'];
    private const ORG_STATUSES = ['ACTIVE','INACTIVE'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditWriter $audit,
    ) {}

    public function list(int $page, int $perPage, ?string $query, ?string $orgType, ?string $status): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 200);

        $where = [];
        $params = [];
        if ($query !== null && $query !== '') {
            $where[] = '(o.code ILIKE :q OR o.name ILIKE :q)';
            $params[':q'] = '%' . $query . '%';
        }
        if ($orgType !== null && $orgType !== '') {
            $where[] = 'o.org_type = :org_type';
            $params[':org_type'] = $orgType;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'o.status = :status';
            $params[':status'] = $status;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare("SELECT count(*) FROM app.organizations o $whereSql");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT o.id, o.code, o.name, o.org_type, o.parent_id, o.psgc_code, o.status, o.version,
                   o.created_at, o.updated_at,
                   parent.name AS parent_name,
                   (SELECT count(*) FROM app.organizations c WHERE c.parent_id = o.id) AS child_count
            FROM app.organizations o
            LEFT JOIN app.organizations parent ON parent.id = o.parent_id
            $whereSql
            ORDER BY o.code
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => array_map(static fn(array $r): array => [
                'id'          => (int) $r['id'],
                'code'        => $r['code'],
                'name'        => $r['name'],
                'org_type'    => $r['org_type'],
                'parent_id'   => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'parent_name' => $r['parent_name'],
                'psgc_code'   => $r['psgc_code'],
                'status'      => $r['status'],
                'child_count' => (int) $r['child_count'],
                'version'     => (int) $r['version'],
                'created_at'  => $r['created_at'],
                'updated_at'  => $r['updated_at'],
            ], $stmt->fetchAll()),
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
        $orgType = $this->requireString($data, 'org_type', $fields);

        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $code)) {
            $fields[] = ['field' => 'code', 'rule' => 'VR-ORG-501', 'message' => 'Organisation code must be 2-40 characters using uppercase letters, digits, underscore or hyphen.'];
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $fields[] = ['field' => 'name', 'rule' => 'VR-ORG-502', 'message' => 'Organisation name must be 2-160 characters.'];
        }
        if (!in_array($orgType, self::ORG_TYPES, true)) {
            $fields[] = ['field' => 'org_type', 'rule' => 'VR-ORG-503', 'message' => 'org_type must be one of ' . implode(', ', self::ORG_TYPES) . '.'];
        }
        $this->throwIfFields($fields);

        $parentId = $this->optionalId($data['parent_id'] ?? null, 'parent_id', $fields);
        $psgcCode = isset($data['psgc_code']) && $data['psgc_code'] !== '' ? (string) $data['psgc_code'] : null;
        $status = $data['status'] ?? 'ACTIVE';
        if (!in_array($status, self::ORG_STATUSES, true)) {
            $fields[] = ['field' => 'status', 'rule' => 'VR-ORG-505', 'message' => 'status must be one of ' . implode(', ', self::ORG_STATUSES) . '.'];
        }
        $this->throwIfFields($fields);

        $stmt = $this->pdo->prepare('SELECT 1 FROM app.organizations WHERE code = :code');
        $stmt->execute([':code' => $code]);
        if ($stmt->fetchColumn() !== false) {
            $this->fail('code', 'VR-ORG-501', 'An organisation with this code already exists.');
        }
        if ($parentId !== null) {
            $this->assertOrganizationExists($parentId, 'parent_id', $fields);
        }
        if ($psgcCode !== null) {
            $this->assertPsgcExists($psgcCode, 'psgc_code', $fields);
        }
        $this->throwIfFields($fields);

        $tx = DbTransaction::begin($this->pdo);
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO app.organizations
                    (code, name, org_type, parent_id, psgc_code, status, version, created_by, updated_by)
                VALUES
                    (:code, :name, :org_type, :parent_id, :psgc_code, :status, 1, :actor, :actor)
                RETURNING id
            ");
            $stmt->execute([
                ':code'      => $code,
                ':name'      => $name,
                ':org_type'  => $orgType,
                ':parent_id' => $parentId,
                ':psgc_code' => $psgcCode,
                ':status'    => $status,
                ':actor'     => $actorId,
            ]);
            $orgId = (int) $stmt->fetchColumn();

            $this->audit->write('INSERT', 'app.organizations', (string) $orgId, null, [
                'code' => $code, 'name' => $name, 'org_type' => $orgType,
                'parent_id' => $parentId, 'psgc_code' => $psgcCode, 'status' => $status,
            ], $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return $this->get($orgId);
    }

    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT o.id, o.code, o.name, o.org_type, o.parent_id, o.psgc_code, o.status, o.version,
                   o.created_at, o.updated_at,
                   parent.code AS parent_code, parent.name AS parent_name,
                   (SELECT count(*) FROM app.organizations c WHERE c.parent_id = o.id) AS child_count
            FROM app.organizations o
            LEFT JOIN app.organizations parent ON parent.id = o.parent_id
            WHERE o.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Organisation not found.', 404);
        }

        return [
            'id'          => (int) $row['id'],
            'code'        => $row['code'],
            'name'        => $row['name'],
            'org_type'    => $row['org_type'],
            'parent_id'   => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'parent_code' => $row['parent_code'],
            'parent_name' => $row['parent_name'],
            'psgc_code'   => $row['psgc_code'],
            'status'      => $row['status'],
            'child_count' => (int) $row['child_count'],
            'version'     => (int) $row['version'],
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }

    public function update(int $id, array $data, int $expectedVersion, int $actorId, ?string $requestId, ?string $reason = null): array
    {
        $current = $this->fetchOrgOrFail($id);
        $this->assertVersion($current, $expectedVersion);

        $fields = [];
        $name = $this->optionalString($data['name'] ?? null, 160, 'name', $fields);
        $orgType = $this->optionalString($data['org_type'] ?? null, 24, 'org_type', $fields);
        $status = $data['status'] ?? null;
        if ($name !== null && (mb_strlen($name) < 2 || mb_strlen($name) > 160)) {
            $fields[] = ['field' => 'name', 'rule' => 'VR-ORG-502', 'message' => 'Organisation name must be 2-160 characters.'];
        }
        if ($orgType !== null && !in_array($orgType, self::ORG_TYPES, true)) {
            $fields[] = ['field' => 'org_type', 'rule' => 'VR-ORG-503', 'message' => 'org_type must be one of ' . implode(', ', self::ORG_TYPES) . '.'];
        }
        if ($status !== null && !in_array($status, self::ORG_STATUSES, true)) {
            $fields[] = ['field' => 'status', 'rule' => 'VR-ORG-505', 'message' => 'status must be one of ' . implode(', ', self::ORG_STATUSES) . '.'];
        }
        $this->throwIfFields($fields);

        $parentId = $this->optionalId($data['parent_id'] ?? null, 'parent_id', $fields);
        if ($parentId !== null && $parentId === $id) {
            $this->fail('parent_id', 'VR-ORG-504', 'An organisation cannot be its own parent.');
        }
        $psgcCode = isset($data['psgc_code']) && $data['psgc_code'] !== '' ? (string) $data['psgc_code'] : null;
        if ($parentId !== null) {
            $this->assertOrganizationExists($parentId, 'parent_id', $fields);
        }
        if ($psgcCode !== null) {
            $this->assertPsgcExists($psgcCode, 'psgc_code', $fields);
        }
        $this->throwIfFields($fields);

        if (array_key_exists('code', $data)) {
            $fields = [];
            $code = $this->requireString($data, 'code', $fields);
            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $code)) {
                $fields[] = ['field' => 'code', 'rule' => 'VR-ORG-501', 'message' => 'Organisation code must be 2-40 characters using uppercase letters, digits, underscore or hyphen.'];
            }
            $this->throwIfFields($fields);
            $stmt = $this->pdo->prepare('SELECT 1 FROM app.organizations WHERE code = :code AND id <> :id');
            $stmt->execute([':code' => $code, ':id' => $id]);
            if ($stmt->fetchColumn() !== false) {
                $this->fail('code', 'VR-ORG-501', 'An organisation with this code already exists.');
            }
        }

        $newValues = [
            'code'      => $data['code'] ?? $current['code'],
            'name'      => $name ?? $current['name'],
            'org_type'  => $orgType ?? $current['org_type'],
            'parent_id' => $parentId !== null ? $parentId : $current['parent_id'],
            'psgc_code' => array_key_exists('psgc_code', $data) ? $psgcCode : $current['psgc_code'],
            'status'    => $status ?? $current['status'],
        ];

        $tx = DbTransaction::begin($this->pdo);
        try {
            $stmt = $this->pdo->prepare("
                UPDATE app.organizations
                SET code = :code, name = :name, org_type = :org_type, parent_id = :parent_id,
                    psgc_code = :psgc_code, status = :status,
                    version = version + 1, updated_by = :actor, updated_at = now()
                WHERE id = :id
                RETURNING version
            ");
            $stmt->execute([
                ':code'      => $newValues['code'],
                ':name'      => $newValues['name'],
                ':org_type'  => $newValues['org_type'],
                ':parent_id' => $newValues['parent_id'],
                ':psgc_code' => $newValues['psgc_code'],
                ':status'    => $newValues['status'],
                ':actor'     => $actorId,
                ':id'        => $id,
            ]);
            $version = (int) $stmt->fetchColumn();

            $this->audit->write('UPDATE', 'app.organizations', (string) $id,
                ['code' => $current['code'], 'name' => $current['name'], 'status' => $current['status'], 'version' => $current['version']],
                $newValues + ['version' => $version], $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return $this->get($id);
    }

    public function deactivate(int $id, int $actorId, ?string $requestId, ?string $reason = null): void
    {
        $current = $this->fetchOrgOrFail($id);

        if ($current['status'] === 'INACTIVE') {
            throw new ApiError('NOT_FOUND', 'Organisation not found.', 404);
        }

        $stmt = $this->pdo->prepare('SELECT count(*) FROM app.organizations WHERE parent_id = :id AND status = \'ACTIVE\'');
        $stmt->execute([':id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $this->fail('id', 'VR-ORG-506', 'The organisation cannot be deactivated while it has active child organisations.');
        }

        $stmt = $this->pdo->prepare("SELECT count(*) FROM app.users WHERE org_id = :id AND status = 'ACTIVE'");
        $stmt->execute([':id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $this->fail('id', 'VR-ORG-506', 'The organisation cannot be deactivated while active users are assigned to it.');
        }

        $tx = DbTransaction::begin($this->pdo);
        try {
            $this->pdo->prepare("
                UPDATE app.organizations
                SET status = 'INACTIVE', version = version + 1, updated_by = :actor, updated_at = now()
                WHERE id = :id
            ")->execute([':actor' => $actorId, ':id' => $id]);

            $this->audit->write('DELETE', 'app.organizations', (string) $id,
                ['status' => $current['status'], 'version' => $current['version']],
                ['status' => 'INACTIVE'], $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }
    }

    private function fetchOrgOrFail(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT id, code, name, org_type, parent_id, psgc_code, status, version FROM app.organizations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Organisation not found.', 404);
        }
        return $row;
    }

    private function assertOrganizationExists(int $id, string $field, array &$fields): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM app.organizations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->fetchColumn() === false) {
            $fields[] = ['field' => $field, 'rule' => 'VR-ORG-504', 'message' => 'The parent organisation does not exist.'];
        }
    }

    private function assertPsgcExists(string $code, string $field, array &$fields): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ref.psgc_areas WHERE code = :code');
        $stmt->execute([':code' => $code]);
        if ($stmt->fetchColumn() === false) {
            $fields[] = ['field' => $field, 'rule' => 'VR-ORG-507', 'message' => "PSGC code '{$code}' does not exist."];
        }
    }

    private function optionalId(mixed $value, string $key, array &$fields): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            $fields[] = ['field' => $key, 'rule' => 'VR-ADMIN-502', 'message' => "Field '{$key}' must be an integer."];
            return null;
        }
        return (int) $value;
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

    private function assertVersion(array $org, int $expectedVersion): void
    {
        if ((int) $org['version'] !== $expectedVersion) {
            throw new ApiError('VERSION_CONFLICT', 'This record was modified by another user.', 409, ['current_version' => (int) $org['version']]);
        }
    }
}