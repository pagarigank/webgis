<?php
declare(strict_types=1);

namespace App\Auth\Http;

use App\Auth\Hasher;
use App\Auth\PasswordPolicy;
use App\Core\Error\ApiError;
use App\Core\Http\Request\JsonBodyParser;
use App\Core\Http\Response\Envelope;
use App\RBAC\DataScopeResolver;
use App\RBAC\LayerCapabilityResolver;
use App\RBAC\PermissionResolver;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MeController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Hasher $hasher,
        private readonly PermissionResolver $permissionResolver,
        private readonly LayerCapabilityResolver $layerResolver,
        private readonly DataScopeResolver $scopeResolver
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // 1. Get auth details injected by AuthenticateMiddleware
        $userId = $request->getAttribute('user_id');
        $userVersion = $request->getAttribute('user_version');

        if ($userId === null || $userVersion === null) {
            return Envelope::error($response, 'UNAUTHORIZED', 'Not authenticated.', [], 401);
        }

        // 2. Fetch User Profile
        $stmt = $this->pdo->prepare("SELECT id, username, full_name, email, org_id, status FROM app.users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userRow || $userRow['status'] !== 'ACTIVE') {
            return Envelope::error($response, 'UNAUTHORIZED', 'User not found or inactive.', [], 401);
        }

        // 3. Fetch Roles
        $stmt = $this->pdo->prepare("
            SELECT r.code 
            FROM app.roles r
            JOIN app.user_roles ur ON r.id = ur.role_id
            WHERE ur.user_id = :id
        ");
        $stmt->execute([':id' => $userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 4. Fetch Permissions (Cached)
        $permissions = $this->permissionResolver->getEffectivePermissions($userId, $userVersion);

        // 5. Fetch Layer Capabilities (Cached)
        // Convert to string keys as required by standard JSON objects.
        $layerCapsRaw = $this->layerResolver->getAllCapabilities($userId, $userVersion);
        $layerCapabilities = [];
        foreach ($layerCapsRaw as $layerId => $caps) {
            $layerCapabilities[(string)$layerId] = $caps;
        }

        // 6. Fetch Data Scopes
        $scopes = $this->scopeResolver->getUserScopes($userId);

        // 7. Assemble Payload
        $payload = [
            'user' => [
                'id' => (int)$userRow['id'],
                'username' => $userRow['username'],
                'full_name' => $userRow['full_name'],
                'email' => $userRow['email'],
                'org_id' => $userRow['org_id'] ? (int)$userRow['org_id'] : null,
            ],
            'roles' => $roles,
            'permissions' => $permissions,
            'layer_capabilities' => $layerCapabilities,
            'scopes' => $scopes,
            'scope_version' => $userVersion,
        ];

        return Envelope::success($response, $payload);
    }

    /** PUT /api/v1/me/password — self-service / forced password change. */
    public function changePassword(Request $request, Response $response): Response
    {
        try {
            $userId = (int) ($request->getAttribute('user_id') ?? 0);
            if ($userId <= 0) {
                throw new ApiError('AUTH_REQUIRED', 'Not authenticated.', 401);
            }

            $data = JsonBodyParser::parse($request);
            $current = is_string($data['current_password'] ?? null) ? $data['current_password'] : '';
            $next = is_string($data['new_password'] ?? null) ? $data['new_password'] : '';

            if ($current === '' || $next === '') {
                throw new ApiError('VALIDATION_FAILED', 'current_password and new_password are required.', 422, ['fields' => ['current_password', 'new_password']]);
            }

            $stmt = $this->pdo->prepare("SELECT username, password_hash FROM app.users WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                throw new ApiError('AUTH_INVALID', 'User not found.', 401, []);
            }
            if (!$this->hasher->verify($current, (string) $row['password_hash'])) {
                throw new ApiError('AUTH_INVALID', 'Current password is incorrect.', 401, []);
            }

            $violations = PasswordPolicy::validate($next, (string) $row['username']);
            if ($violations !== []) {
                throw new ApiError('VALIDATION_FAILED', 'New password does not meet the policy.', 422, ['field_errors' => ['new_password' => $violations]]);
            }

            // Bump version so permission caches and the SPA's /me key change.
            $update = $this->pdo->prepare(
                "UPDATE app.users
                    SET password_hash = :hash,
                        must_change_password = false,
                        password_changed_at = CURRENT_TIMESTAMP,
                        version = version + 1,
                        updated_by = :uid
                  WHERE id = :id AND deleted_at IS NULL"
            );
            $update->execute([':hash' => $this->hasher->hash($next), ':uid' => $userId, ':id' => $userId]);

            // Revoke every existing session (password change invalidates refresh tokens).
            $this->pdo->prepare('DELETE FROM app.refresh_tokens WHERE user_id = :id')->execute([':id' => $userId]);

            return Envelope::success($response, ['must_change_password' => false]);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }
}
