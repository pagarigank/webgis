<?php
declare(strict_types=1);

namespace App\RBAC\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Request\JsonBodyParser;
use App\Core\Http\Response\Envelope;
use App\RBAC\RoleAdminService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RoleAdminController
{
    public function __construct(private readonly RoleAdminService $roles) {}

    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : 50;
        $query = isset($params['query']) ? trim((string) $params['query']) : null;

        try {
            return Envelope::success($response, $this->roles->list($page, $perPage, $query));
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $data = $this->jsonBody($request);
            $dto = $this->session($request);
            $role = $this->roles->create($data, $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, $role, 201);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            return Envelope::success($response, $this->roles->get((int) $args['id']));
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $this->jsonBody($request);
            $dto = $this->session($request);
            $role = $this->roles->update((int) $args['id'], $data, $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, $role);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $this->jsonBody($request, allowEmpty: true);
            $dto = $this->session($request);
            $this->roles->delete((int) $args['id'], $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, ['id' => (int) $args['id'], 'deleted' => true]);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function setPermissions(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $this->jsonBody($request);
            $dto = $this->session($request);
            $role = $this->roles->setPermissions((int) $args['id'], $data['permissions'] ?? [], $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, $role);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function listPermissions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $module = isset($params['module']) ? trim((string) $params['module']) : null;
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : 100;

        try {
            return Envelope::success($response, $this->roles->listPermissions($module, $page, $perPage));
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    /** @return array{user_id: int, request_id: string|null} */
    private function session(Request $request): array
    {
        $userId = $request->getAttribute('user_id');
        return [
            'user_id' => $userId !== null ? (int) $userId : 0,
            'request_id' => $request->getAttribute('request_id') !== null ? (string) $request->getAttribute('request_id') : null,
        ];
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request, bool $allowEmpty = false): array
    {
        $body = JsonBodyParser::parse($request, $allowEmpty);
        return $body;
    }
}