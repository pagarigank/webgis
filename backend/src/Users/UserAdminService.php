<?php
declare(strict_types=1);

namespace App\Users;

use App\Audit\AuditWriter;
use App\Auth\Hasher;
use App\Auth\MfaService;
use App\Auth\PasswordPolicy;
use App\Auth\Totp;
use App\Core\Db\DbTransaction;
use App\Core\Error\ApiError;
use DateTimeImmutable;
use PDO;
use Throwable;

final class UserAdminService
{
    private const SCOPE_TYPES = ['ORGANIZATION','PROVINCE','MUNICIPALITY','BARANGAY','REGION','CUSTOM_AREA','GLOBAL','PROJECT'];
    private const ACCESS_LEVELS = ['NONE','VIEW','EDIT','APPROVE'];
    private const USER_STATUSES = ['ACTIVE','SUSPENDED','DISABLED'];
    private const SCOPE_PRIORITY = ['BARANGAY'=>1,'MUNICIPALITY'=>2,'PROVINCE'=>3,'REGION'=>4,'ORGANIZATION'=>5,'CUSTOM_AREA'=>6,'GLOBAL'=>7];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditWriter $audit,
        private readonly Hasher $hasher,
        private readonly DataScopeReader $scopeReader,
        private readonly ?MfaService $mfaService = null,
    ) {}

    public function list(int $page, int $perPage, ?string $query, ?string $status): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 200);

        $where = [];
        $params = [];

        if ($status !== null && $status !== '') {
            if (!in_array($status, self::USER_STATUSES, true)) {
                $this->fail('status', 'VR-USER-208', 'Status must be one of ACTIVE, SUSPENDED, DISABLED.');
            }
            $where[] = 'u.status = :status';
            $params[':status'] = $status;
        }
        if ($query !== null && $query !== '') {
            $where[] = '(u.username ILIKE :q OR u.full_name ILIKE :q OR u.email ILIKE :q)';
            $params[':q'] = '%' . $query . '%';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare("SELECT count(*) FROM app.users u $whereSql");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT id, username, email, full_name, position, org_id, status,
                   mfa_enabled, must_change_password, version, created_at, updated_at, deleted_at
            FROM app.users u
            $whereSql
            ORDER BY u.id
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        $users = [];
        foreach ($stmt->fetchAll() as $row) {
            $users[] = $this->decorate($row, false);
        }

        return [
            'data' => $users,
            'meta' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    public function create(array $data, int $actorId, ?string $requestId): array
    {
        $fields = [];
        $username = $this->requireString($data, 'username', $fields);
        $email = $this->requireString($data, 'email', $fields);
        $password = $this->requireString($data, 'password', $fields);
        $fullName = $this->requireString($data, 'full_name', $fields);

        $this->validateUsername($username, $fields);
        $this->validateEmail($email, $fields);
        $this->validateFullName($fullName, $fields);

        foreach (PasswordPolicy::validate($password, $username) as $message) {
            $fields[] = ['field' => 'password', 'rule' => 'VR-USER-205', 'message' => $message];
        }

        $position = $this->optionalString($data['position'] ?? null, 120, 'position', $fields);
        $orgId = $this->requireInt($data['org_id'] ?? null, 'org_id', $fields);
        $roles = is_array($data['roles'] ?? null) ? $data['roles'] : [];
        $mustChange = (bool) ($data['must_change_password'] ?? true);
        $this->throwIfFields($fields);

        $this->assertUniqueUsername($username, null, $fields);
        $this->assertUniqueEmail($email, null, $fields);
        $this->assertActiveOrganization($orgId, $fields);
        $roleCodes = $this->resolveRoleCodes($roles, $fields, true);
        $this->throwIfFields($fields);

        $reason = $this->optionalReason($data);
        $tx = DbTransaction::begin($this->pdo);

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO app.users
                    (username, email, password_hash, full_name, position, org_id, status,
                     must_change_password, version, created_by, updated_by)
                VALUES
                    (:username, :email, :password_hash, :full_name, :position, :org_id, 'ACTIVE',
                     :must_change_password, 1, :actor, :actor)
                RETURNING id
            ");
            $stmt->execute([
                ':username' => $username,
                ':email'    => $email,
                ':password_hash' => $this->hasher->hash($password),
                ':full_name' => $fullName,
                ':position' => $position,
                ':org_id'   => $orgId,
                ':must_change_password' => $mustChange ? 1 : 0,
                ':actor'    => $actorId,
            ]);
            $userId = (int) $stmt->fetchColumn();
            $this->replaceUserRoles($userId, $roles, $actorId);

            $this->audit->write('INSERT', 'app.users', (string) $userId, null, [
                'username' => $username,
                'org_id'   => $orgId,
                'roles'    => $roleCodes,
                'status'   => 'ACTIVE',
            ], $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return $this->get($userId);
    }

    public function update(int $id, array $data, int $expectedVersion, int $actorId, ?string $requestId): array
    {
        $current = $this->fetchUserOrFail($id);
        $this->assertVersion($current, $expectedVersion);

        $fields = [];
        $username = $this->optionalString($data['username'] ?? null, 40, 'username', $fields);
        $email = $this->optionalString($data['email'] ?? null, 255, 'email', $fields);
        $fullName = $this->optionalString($data['full_name'] ?? null, 160, 'full_name', $fields);

        $position = $data['position'] ?? null;
        $position = ($position !== null && $position !== '') ? $this->optionalString($position, 120, 'position', $fields) : null;

        $orgId = $data['org_id'] ?? null;
        $status = $data['status'] ?? null;
        $mfaEnabled = $data['mfa_enabled'] ?? null;
        $mustChange = $data['must_change_password'] ?? null;

        $this->validateUsername($username ?? (string) $current['username'], $fields);
        $this->validateEmail($email ?? (string) $current['email'], $fields);
        $this->validateFullName($fullName ?? (string) $current['full_name'], $fields);

        if ($status !== null && !in_array($status, self::USER_STATUSES, true)) {
            $fields[] = ['field' => 'status', 'rule' => 'VR-USER-208', 'message' => 'Status must be one of ACTIVE, SUSPENDED, DISABLED.'];
        }
        if ($orgId !== null && !is_int($orgId) && !(is_string($orgId) && ctype_digit($orgId))) {
            $fields[] = ['field' => 'org_id', 'rule' => 'VR-USER-206', 'message' => 'org_id must be an integer.'];
        }
        $this->throwIfFields($fields);

        $this->assertUniqueUsername($username ?? (string) $current['username'], $id, $fields);
        $this->assertUniqueEmail($email ?? (string) $current['email'], $id, $fields);
        if ($orgId !== null) {
            $this->assertActiveOrganization((int) $orgId, $fields);
        }
        $this->throwIfFields($fields);

        $newValues = [
            'username' => $username ?? $current['username'],
            'email'    => $email ?? $current['email'],
            'full_name' => $fullName ?? $current['full_name'],
            'position' => $position ?? $current['position'],
            'org_id'   => $orgId !== null ? (int) $orgId : (int) $current['org_id'],
            'status'   => $status ?? $current['status'],
            'mfa_enabled' => $mfaEnabled !== null ? (bool) $mfaEnabled : (bool) $current['mfa_enabled'],
            'must_change_password' => $mustChange !== null ? (bool) $mustChange : (bool) $current['must_change_password'],
        ];

        $reason = $this->optionalReason($data);
        $tx = DbTransaction::begin($this->pdo);

        try {
            $stmt = $this->pdo->prepare("
                UPDATE app.users
                SET username = :username, email = :email, full_name = :full_name, position = :position,
                    org_id = :org_id, status = :status, mfa_enabled = :mfa_enabled,
                    must_change_password = :must_change_password,
                    version = version + 1, updated_by = :actor, updated_at = now()
                WHERE id = :id
                RETURNING version
            ");
            $stmt->execute([
                ':username' => $newValues['username'],
                ':email'    => $newValues['email'],
                ':full_name' => $newValues['full_name'],
                ':position' => $newValues['position'],
                ':org_id'   => $newValues['org_id'],
                ':status'   => $newValues['status'],
                ':mfa_enabled' => $newValues['mfa_enabled'] ? 1 : 0,
                ':must_change_password' => $newValues['must_change_password'] ? 1 : 0,
                ':actor'    => $actorId,
                ':id'       => $id,
            ]);
            $version = (int) $stmt->fetchColumn();

            $this->audit->write('UPDATE', 'app.users', (string) $id, [
                'username' => $current['username'],
                'full_name' => $current['full_name'],
                'org_id'   => $current['org_id'],
                'status'   => $current['status'],
                'version'  => $current['version'],
            ], $newValues + ['version' => $version], $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return $this->get($id);
    }

    public function deactivate(int $id, int $actorId, ?string $requestId, ?string $reason = null): void
    {
        if ($id === $actorId) {
            $this->fail('id', 'VR-USER-209', 'An administrator cannot deactivate their own account.');
        }

        $current = $this->fetchUserOrFail($id);
        if ($current['status'] === 'DISABLED') {
            throw new ApiError('NOT_FOUND', 'User not found.', 404);
        }

        $tx = DbTransaction::begin($this->pdo);
        try {
            $this->pdo->prepare("
                UPDATE app.users
                SET status = 'DISABLED', deleted_at = now(), version = version + 1,
                    updated_by = :actor, updated_at = now()
                WHERE id = :id
            ")->execute([':actor' => $actorId, ':id' => $id]);
            $this->pdo->prepare('DELETE FROM app.refresh_tokens WHERE user_id = :id')->execute([':id' => $id]);

            $this->audit->write('DELETE', 'app.users', (string) $id,
                ['status' => $current['status'], 'version' => $current['version']],
                ['status' => 'DISABLED', 'deleted_at' => true],
                $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }
    }

    public function setRoles(int $id, array $roles, int $actorId, ?string $requestId, ?string $reason): array
    {
        $this->assertReason($reason);
        $this->fetchUserOrFail($id);

        $fields = [];
        $codes = $this->resolveRoleCodes($roles, $fields, true);
        $this->throwIfFields($fields);

        $tx = DbTransaction::begin($this->pdo);
        try {
            $this->replaceUserRoles($id, $roles, $actorId);
            $stmt = $this->pdo->prepare("UPDATE app.users SET version = version + 1, updated_by = :actor, updated_at = now() WHERE id = :id RETURNING version");
            $stmt->execute([':actor' => $actorId, ':id' => $id]);
            $version = (int) $stmt->fetchColumn();

            $this->audit->write('UPDATE', 'app.user_roles', (string) $id, null,
                ['roles' => $codes, 'version' => $version], $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return $this->get($id);
    }

    public function getScopes(int $id): array
    {
        $this->fetchUserOrFail($id);
        return $this->scopeReader->forUser($id);
    }

    public function setScopes(int $id, array $scopes, int $actorId, ?string $requestId, ?string $reason): array
    {
        $this->assertReason($reason);
        $this->fetchUserOrFail($id);

        $fields = [];
        $normalized = [];
        foreach ($scopes as $index => $scope) {
            $normalized[] = $this->normalizeScope($scope, (string) $index, $fields);
        }
        $this->throwIfFields($fields);

        $tx = DbTransaction::begin($this->pdo);
        try {
            $this->pdo->prepare('DELETE FROM app.data_scopes WHERE user_id = :id')->execute([':id' => $id]);

            $insert = $this->pdo->prepare("
                INSERT INTO app.data_scopes
                    (user_id, scope_type, scope_ref_code, geom, access_level, valid_from, valid_to, granted_by)
                VALUES
                    (:user_id, :type, :ref_code, :geom::geometry, :access, :valid_from, :valid_to, :granted_by)
            ");
            foreach ($normalized as $scope) {
                $insert->execute([
                    ':user_id'    => $id,
                    ':type'       => $scope['type'],
                    ':ref_code'   => $scope['ref_code'],
                    ':geom'       => $scope['geom'],
                    ':access'     => $scope['access_level'],
                    ':valid_from' => $scope['valid_from'],
                    ':valid_to'   => $scope['valid_to'],
                    ':granted_by' => $actorId,
                ]);
            }

            $stmt = $this->pdo->prepare("UPDATE app.users SET version = version + 1, updated_by = :actor, updated_at = now() WHERE id = :id RETURNING version");
            $stmt->execute([':actor' => $actorId, ':id' => $id]);
            $version = (int) $stmt->fetchColumn();

            $auditScopes = array_map(static fn(array $s): array => [
                'type' => $s['type'], 'ref_code' => $s['ref_code'],
                'access_level' => $s['access_level'], 'valid_from' => $s['valid_from'], 'valid_to' => $s['valid_to'],
            ], $normalized);

            $this->audit->write('UPDATE', 'app.data_scopes', (string) $id, null,
                ['scopes' => $auditScopes, 'version' => $version], $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return $this->getScopes($id);
    }

    public function forcePasswordReset(int $id, int $actorId, ?string $requestId, ?string $reason = null): array
    {
        $this->fetchUserOrFail($id);
        $temporary = 'Tmp!' . bin2hex(random_bytes(12));

        $tx = DbTransaction::begin($this->pdo);
        try {
            $this->pdo->prepare("
                UPDATE app.users
                SET password_hash = :hash, must_change_password = true,
                    version = version + 1, password_changed_at = null,
                    updated_by = :actor, updated_at = now()
                WHERE id = :id
            ")->execute([
                ':hash'  => $this->hasher->hash($temporary),
                ':actor' => $actorId,
                ':id'    => $id,
            ]);
            $this->pdo->prepare('DELETE FROM app.refresh_tokens WHERE user_id = :id')->execute([':id' => $id]);

            $this->audit->write('UPDATE', 'app.users', (string) $id,
                ['must_change_password' => false],
                ['must_change_password' => true, 'password_changed_at' => null],
                $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return ['temporary_password' => $temporary, 'must_change_password' => true];
    }

    /** Read-only MFA status; the secret is never returned (AC 036). */
    public function getMfa(int $id): array
    {
        $this->fetchUserOrFail($id);

        $stmt = $this->pdo->prepare("
            SELECT mfa_enabled, mfa_secret_enc FROM app.users WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'user_id'      => $id,
            'mfa_enabled'  => (bool) $row['mfa_enabled'],
            'mfa_secret_set' => $row['mfa_secret_enc'] !== null,
        ];
    }

    /** Enroll a fresh TOTP secret and enable MFA for the user. */
    public function enrollMfa(int $id, int $actorId, ?string $requestId, ?string $reason = null): array
    {
        $this->assertReason($reason);
        $user = $this->fetchUserOrFail($id);
        $mfa = $this->mfaService ?? throw new ApiError('CONFIGURATION_ERROR', 'MFA service is not configured.', 500);

        if ($user['mfa_secret_enc'] !== null) {
            throw new ApiError('VALIDATION_FAILED', 'MFA is already enrolled for this user; disable it first to re-enroll.', 422);
        }

        $secret = Totp::generateSecret();
        $secretEnc = $mfa->encryptSecret($secret);

        $tx = DbTransaction::begin($this->pdo);
        try {
            $stmt = $this->pdo->prepare("
                UPDATE app.users
                SET mfa_enabled = true, mfa_secret_enc = :secret,
                    version = version + 1, updated_by = :actor, updated_at = now()
                WHERE id = :id
                RETURNING version
            ");
            $stmt->execute([':secret' => $secretEnc, ':actor' => $actorId, ':id' => $id]);
            $version = (int) $stmt->fetchColumn();

            $this->audit->write('UPDATE', 'app.users', (string) $id,
                ['mfa_enabled' => false],
                ['mfa_enabled' => true, 'mfa_secret_set' => true, 'version' => $version],
                $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return [
            'user_id'      => $id,
            'mfa_enabled'  => true,
            // Single-use plaintext: returned exactly once at enrollment so the
            // user can scan the QR / enter the code into an authenticator app.
            'secret'       => $secret,
            'issuer'       => 'webgis',
            'account'      => $user['username'],
        ];
    }

    /** Disable MFA and wipe the stored secret. */
    public function disableMfa(int $id, int $actorId, ?string $requestId, ?string $reason = null): array
    {
        $this->assertReason($reason);
        $user = $this->fetchUserOrFail($id);

        $tx = DbTransaction::begin($this->pdo);
        try {
            $stmt = $this->pdo->prepare("
                UPDATE app.users
                SET mfa_enabled = false, mfa_secret_enc = NULL,
                    version = version + 1, updated_by = :actor, updated_at = now()
                WHERE id = :id
                RETURNING version
            ");
            $stmt->execute([':actor' => $actorId, ':id' => $id]);
            $version = (int) $stmt->fetchColumn();

            $this->audit->write('UPDATE', 'app.users', (string) $id,
                ['mfa_enabled' => true, 'mfa_secret_set' => true],
                ['mfa_enabled' => false, 'mfa_secret_set' => false, 'version' => $version],
                $actorId, $requestId, $reason);

            DbTransaction::commit($this->pdo, $tx);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $tx, $e);
        }

        return ['user_id' => $id, 'mfa_enabled' => false];
    }

    public function effectiveAccess(int $id, ?string $entityType, ?string $entityId): array
    {
        $user = $this->fetchUserOrFail($id);
        $version = (int) $user['version'];

        $stmt = $this->pdo->prepare("
            SELECT p.code AS permission, r.code AS role
            FROM app.permissions p
            JOIN app.role_permissions rp ON rp.permission_id = p.id
            JOIN app.user_roles ur ON ur.role_id = rp.role_id
            JOIN app.roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
            ORDER BY p.code, r.code
        ");
        $stmt->execute([':user_id' => $id]);
        $via = [];
        foreach ($stmt->fetchAll() as $row) {
            $via[$row['permission']][] = $row['role'];
        }
        $permissions = array_values(array_map(
            static fn(string $code, array $roles): array => ['code' => $code, 'via' => array_values(array_unique($roles))],
            array_keys($via), $via,
        ));

        $payload = [
            'user_id'       => $id,
            'scope_version' => $version,
            'permissions'   => $permissions,
            'scopes'        => $this->scopeReader->forUser($id),
            'entity'        => null,
            'entity_access' => null,
        ];

        if ($entityType !== null && $entityId !== null) {
            if ($entityType !== 'parcel') {
                $this->fail('entity_type', 'VR-SCOPE-310', 'Only entity_type=parcel is supported.');
            }
            $stmt = $this->pdo->prepare('SELECT id::text, psgc_barangay, org_id FROM app.parcels WHERE id = :id');
            $stmt->execute([':id' => $entityId]);
            $parcel = $stmt->fetch();
            if ($parcel === false) {
                throw new ApiError('NOT_FOUND', 'Entity not found.', 404);
            }

            $payload['entity'] = [
                'type' => 'parcel',
                'id'   => $parcel['id'],
                'psgc_barangay' => $parcel['psgc_barangay'],
                'org_id' => $parcel['org_id'] !== null ? (int) $parcel['org_id'] : null,
            ];
            $payload['entity_access'] = $this->explainEntityAccess($id, $parcel['psgc_barangay'], $parcel['org_id'] !== null ? (int) $parcel['org_id'] : null);
        }

        return $payload;
    }

    public function get(int $id): array
    {
        return $this->decorate($this->fetchUserOrFail($id), true);
    }

    // ----------------------------------------------------------------------

    private function fetchUser(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, username, email, full_name, position, org_id, status,
                   mfa_enabled, mfa_secret_enc, must_change_password, version, created_at, updated_at, deleted_at
            FROM app.users WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function fetchUserOrFail(int $id): array
    {
        $row = $this->fetchUser($id);
        if ($row === null) {
            throw new ApiError('NOT_FOUND', 'User not found.', 404);
        }
        return $row;
    }

    private function decorate(array $row, bool $withScopes): array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.code, r.name, ur.org_id
            FROM app.user_roles ur
            JOIN app.roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
            ORDER BY r.code
        ");
        $stmt->execute([':user_id' => $row['id']]);
        $roles = array_map(static fn(array $r): array => [
            'code' => $r['code'], 'name' => $r['name'],
            'org_id' => $r['org_id'] !== null ? (int) $r['org_id'] : null,
        ], $stmt->fetchAll());

        return [
            'id'                   => (int) $row['id'],
            'username'             => $row['username'],
            'email'                => $row['email'],
            'full_name'            => $row['full_name'],
            'position'             => $row['position'],
            'org_id'               => $row['org_id'] !== null ? (int) $row['org_id'] : null,
            'status'               => $row['status'],
            'mfa_enabled'          => (bool) $row['mfa_enabled'],
            'must_change_password' => (bool) $row['must_change_password'],
            'roles'                => $roles,
            'scopes'               => $withScopes ? $this->scopeReader->forUser((int) $row['id']) : [],
            'version'              => (int) $row['version'],
            'created_at'           => $row['created_at'],
            'updated_at'           => $row['updated_at'],
            'deleted_at'           => $row['deleted_at'],
        ];
    }

    private function validateUsername(string $username, array &$fields): void
    {
        if (!preg_match('/^[a-z0-9._-]{3,40}$/i', $username)) {
            $fields[] = ['field' => 'username', 'rule' => 'VR-USER-201', 'message' => 'Username must be 3-40 characters using letters, digits, dot, underscore or hyphen.'];
        }
    }

    private function validateEmail(string $email, array &$fields): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fields[] = ['field' => 'email', 'rule' => 'VR-USER-202', 'message' => 'A valid email address is required.'];
        }
    }

    private function validateFullName(string $fullName, array &$fields): void
    {
        $len = mb_strlen($fullName);
        if ($len < 2 || $len > 160) {
            $fields[] = ['field' => 'full_name', 'rule' => 'VR-USER-203', 'message' => 'Full name must be 2-160 characters.'];
        }
    }

    private function assertUniqueUsername(string $username, ?int $excludeId, array &$fields): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM app.users WHERE lower(username) = lower(:u) AND (:exclude::bigint IS NULL OR id <> :exclude)");
        $stmt->execute([':u' => $username, ':exclude' => $excludeId]);
        if ($stmt->fetchColumn() !== false) {
            $fields[] = ['field' => 'username', 'rule' => 'VR-USER-201', 'message' => 'An account with this username already exists.'];
        }
    }

    private function assertUniqueEmail(string $email, ?int $excludeId, array &$fields): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM app.users WHERE lower(email) = lower(:e) AND (:exclude::bigint IS NULL OR id <> :exclude)");
        $stmt->execute([':e' => $email, ':exclude' => $excludeId]);
        if ($stmt->fetchColumn() !== false) {
            $fields[] = ['field' => 'email', 'rule' => 'VR-USER-202', 'message' => 'An account with this email address already exists.'];
        }
    }

    private function assertActiveOrganization(int $orgId, array &$fields): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM app.organizations WHERE id = :id AND status = 'ACTIVE'");
        $stmt->execute([':id' => $orgId]);
        if ($stmt->fetchColumn() === false) {
            $fields[] = ['field' => 'org_id', 'rule' => 'VR-USER-206', 'message' => 'The organisation does not exist or is not active.'];
        }
    }

    private function resolveRoleCodes(array $roles, array &$fields, bool $withOrg = false): array
    {
        $codes = [];
        foreach ($roles as $index => $grant) {
            if (!is_array($grant)) {
                $fields[] = ['field' => 'roles', 'rule' => 'VR-USER-207', 'message' => 'Each role grant must be an object with a code.'];
                continue;
            }
            $code = $grant['code'] ?? null;
            if (!is_string($code) || $code === '') {
                $fields[] = ['field' => "roles[$index]", 'rule' => 'VR-USER-207', 'message' => 'Role code is required.'];
                continue;
            }
            $stmt = $this->pdo->prepare('SELECT 1 FROM app.roles WHERE code = :code');
            $stmt->execute([':code' => $code]);
            if ($stmt->fetchColumn() === false) {
                $fields[] = ['field' => "roles[$index]", 'rule' => 'VR-USER-207', 'message' => "Role '{$code}' does not exist."];
                continue;
            }
            if ($withOrg && isset($grant['org_id']) && $grant['org_id'] !== null) {
                $this->assertActiveOrganization((int) $grant['org_id'], $fields);
            }
            $codes[] = $code;
        }
        return $codes;
    }

    private function replaceUserRoles(int $userId, array $roles, int $actorId): void
    {
        $this->pdo->prepare('DELETE FROM app.user_roles WHERE user_id = :user_id')->execute([':user_id' => $userId]);
        $insert = $this->pdo->prepare("
            INSERT INTO app.user_roles (user_id, role_id, org_id, granted_by, granted_at)
            SELECT :user_id, r.id, :org_id, :granted_by, now()
            FROM app.roles r
            WHERE r.code = :code
            ON CONFLICT (user_id, role_id) DO NOTHING
        ");
        foreach ($roles as $grant) {
            $insert->execute([
                ':user_id'    => $userId,
                ':code'       => $grant['code'],
                ':org_id'     => isset($grant['org_id']) && $grant['org_id'] !== null ? (int) $grant['org_id'] : null,
                ':granted_by' => $actorId,
            ]);
        }
    }

    private function normalizeScope(mixed $scope, string $index, array &$fields): array
    {
        if (!is_array($scope)) {
            $fields[] = ['field' => "scopes[$index]", 'rule' => 'VR-SCOPE-401', 'message' => 'Each scope must be an object.'];
            return [];
        }

        $type = $scope['type'] ?? null;
        $access = $scope['access_level'] ?? null;

        if (!is_string($type) || !in_array($type, self::SCOPE_TYPES, true)) {
            $fields[] = ['field' => "scopes[$index].type", 'rule' => 'VR-SCOPE-401', 'message' => 'scope_type must be one of ' . implode(', ', self::SCOPE_TYPES) . '.'];
        }
        if (!is_string($access) || !in_array($access, self::ACCESS_LEVELS, true)) {
            $fields[] = ['field' => "scopes[$index].access_level", 'rule' => 'VR-SCOPE-402', 'message' => 'access_level must be one of ' . implode(', ', self::ACCESS_LEVELS) . '.'];
        }

        $refCode = isset($scope['ref_code']) && $scope['ref_code'] !== null ? (string) $scope['ref_code'] : null;
        $hasGeom = isset($scope['geom']) && is_array($scope['geom']);
        $geom = null;

        if ($type !== 'GLOBAL' && $refCode === null && !$hasGeom) {
            $fields[] = ['field' => "scopes[$index].ref_code", 'rule' => 'VR-SCOPE-403', 'message' => 'A target is required: ref_code or geom unless the scope type is GLOBAL.'];
        }
        if ($refCode !== null) {
            $this->assertScopeTargetExists($type, $refCode, "scopes[$index].ref_code", $fields);
        }
        if ($hasGeom) {
            try {
                $stmt = $this->pdo->prepare('SELECT ST_GeomFromGeoJSON(:geojson)::text');
                $stmt->execute([':geojson' => json_encode($scope['geom'], JSON_THROW_ON_ERROR)]);
                $geom = $stmt->fetchColumn();
                if ($geom === false || $geom === null || $geom === '') {
                    throw new \RuntimeException('empty geometry');
                }
            } catch (Throwable $e) {
                $fields[] = ['field' => "scopes[$index].geom", 'rule' => 'VR-SCOPE-314', 'message' => 'geom must be a valid GeoJSON geometry. Reason: ' . $e->getMessage()];
            }
        }

        $validFrom = $this->parseDate($scope['valid_from'] ?? null, "scopes[$index].valid_from", $fields);
        $validTo = $this->parseDate($scope['valid_to'] ?? null, "scopes[$index].valid_to", $fields);
        if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
            $fields[] = ['field' => "scopes[$index].valid_to", 'rule' => 'VR-SCOPE-315', 'message' => 'valid_to must not precede valid_from.'];
        }

        return [
            'type'         => $type ?? 'GLOBAL',
            'ref_code'     => $refCode,
            'geom'         => $geom,
            'access_level' => $access ?? 'VIEW',
            'valid_from'   => $validFrom,
            'valid_to'     => $validTo,
        ];
    }

    private function assertScopeTargetExists(?string $type, string $refCode, string $field, array &$fields): void
    {
        if ($type === 'ORGANIZATION') {
            $stmt = $this->pdo->prepare('SELECT 1 FROM app.organizations WHERE id = :id');
            $stmt->execute([':id' => (int) $refCode]);
            if ($stmt->fetchColumn() === false) {
                $fields[] = ['field' => $field, 'rule' => 'VR-SCOPE-310', 'message' => "Organisation id '{$refCode}' does not exist."];
            }
            return;
        }
        if (in_array($type, ['PROVINCE', 'MUNICIPALITY', 'BARANGAY', 'REGION'], true)) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ref.psgc_areas WHERE code = :code');
            $stmt->execute([':code' => $refCode]);
            if ($stmt->fetchColumn() === false) {
                $fields[] = ['field' => $field, 'rule' => 'VR-SCOPE-310', 'message' => "PSGC code '{$refCode}' does not exist."];
            }
        }
    }

    private function parseDate(mixed $value, string $field, array &$fields): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== (string) $value) {
            $fields[] = ['field' => $field, 'rule' => 'VR-SCOPE-311', 'message' => 'Date must use the YYYY-MM-DD format.'];
            return null;
        }
        return $parsed->format('Y-m-d');
    }

    private function explainEntityAccess(int $userId, ?string $psgc, ?int $orgId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT ds.scope_type, ds.scope_ref_code, ds.access_level, pa.name
            FROM app.data_scopes ds
            LEFT JOIN ref.psgc_areas pa ON ds.scope_ref_code = pa.code
            WHERE ds.user_id = :user_id
              AND (ds.valid_to IS NULL OR ds.valid_to >= CURRENT_DATE)
            ORDER BY ds.scope_type, ds.scope_ref_code
        ");
        $stmt->execute([':user_id' => $userId]);

        $matched = [];
        foreach ($stmt->fetchAll() as $scope) {
            $type = $scope['scope_type'];
            $code = $scope['scope_ref_code'];
            $matches = match (true) {
                $type === 'GLOBAL' => true,
                $type === 'ORGANIZATION' => $orgId !== null && (int) $code === $orgId,
                $type === 'BARANGAY' => $psgc !== null && $code === $psgc,
                $type === 'MUNICIPALITY' => $psgc !== null && str_starts_with($psgc, (string) substr((string) $code, 0, 6)),
                $type === 'PROVINCE' => $psgc !== null && str_starts_with($psgc, (string) substr((string) $code, 0, 4)),
                $type === 'REGION' => $psgc !== null && str_starts_with($psgc, (string) substr((string) $code, 0, 2)),
                default => false,
            };
            if ($matches) {
                $matched[] = ['type' => $type, 'code' => $code, 'name' => $scope['name'], 'access' => $scope['access_level']];
            }
        }

        if ($matched === []) {
            return [
                'decision' => 'NONE',
                'granted'  => false,
                'rule'     => $orgId === null && $psgc === null
                    ? 'the entity carries no geographic or organisational reference, so no scope can match (default deny)'
                    : 'no scope grant matches the entity reference (default deny)',
                'matched_scopes' => [],
            ];
        }

        foreach ($matched as $scope) {
            if ($scope['access'] === 'NONE') {
                return ['decision' => 'NONE', 'granted' => false, 'rule' => 'explicit NONE grant has priority over positive grants', 'matched_scopes' => $matched];
            }
        }

        usort($matched, fn(array $a, array $b): int => (self::SCOPE_PRIORITY[$a['type']] ?? 99) <=> (self::SCOPE_PRIORITY[$b['type']] ?? 99));
        $winner = $matched[0];

        return [
            'decision' => $winner['access'],
            'granted'  => $winner['access'] !== 'NONE',
            'rule'     => sprintf('most specific matching grant wins: %s %s grants %s', $winner['type'], $winner['code'] ?? '(no code)', $winner['access']),
            'matched_scopes' => $matched,
        ];
    }

    private function optionalReason(array $data): ?string
    {
        $reason = $data['reason'] ?? null;
        if ($reason === null || $reason === '') {
            return null;
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) > 500) {
            $this->fail('reason', 'VR-ADMIN-501', 'The audit reason must not exceed 500 characters.');
        }
        return $reason;
    }

    private function assertReason(?string $reason): void
    {
        if ($reason === null || trim($reason) === '') {
            $this->fail('reason', 'VR-ADMIN-501', 'A reason is required for permission or scope changes (FR-017).');
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

    private function requireInt(mixed $value, string $key, array &$fields): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            $fields[] = ['field' => $key, 'rule' => 'VR-ADMIN-502', 'message' => "Field '{$key}' must be an integer."];
            return 0;
        }
        return (int) $value;
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

    private function assertVersion(array $user, int $expectedVersion): void
    {
        if ((int) $user['version'] !== $expectedVersion) {
            throw new ApiError('VERSION_CONFLICT', 'This record was modified by another user.', 409, ['current_version' => (int) $user['version']]);
        }
    }
}