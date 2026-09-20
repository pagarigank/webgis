<?php
declare(strict_types=1);

namespace App\Auth\Http;

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
}
