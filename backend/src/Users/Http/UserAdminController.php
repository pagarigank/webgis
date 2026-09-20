<?php
declare(strict_types=1);

namespace App\Users\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Request\JsonBodyParser;
use App\Core\Http\Response\Envelope;
use App\Users\UserAdminService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class UserAdminController
{
    public function __construct(private readonly UserAdminService $users) {}

    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : 50;
        $query = isset($params['query']) ? trim((string) $params['query']) : null;
        $status = isset($params['status']) ? trim((string) $params['status']) : null;

        try {
            $result = $this->users->list($page, $perPage, $query, $status);
            return Envelope::success($response, $result);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $data = $this->jsonBody($request);
            $dto = $this->session($request);
            $user = $this->users->create($data, $dto['user_id'], $dto['request_id']);
            return Envelope::success($response, $user, 201);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->users->get((int) $args['id']);
            return Envelope::success($response, $user);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $expected = $this->expectedVersion($request);
        if ($expected === null) {
            return Envelope::error($response, 'PRECONDITION_REQUIRED', 'An If-Match header with the current version is required for updates.', [], 428);
        }

        try {
            $data = $this->jsonBody($request);
            $dto = $this->session($request);
            $user = $this->users->update((int) $args['id'], $data, $expected, $dto['user_id'], $dto['request_id']);
            return Envelope::success($response, $user);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function deactivate(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $this->jsonBody($request, allowEmpty: true);
            $dto = $this->session($request);
            $this->users->deactivate((int) $args['id'], $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, ['id' => (int) $args['id'], 'status' => 'DISABLED']);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function setRoles(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $this->jsonBody($request);
            $dto = $this->session($request);
            $user = $this->users->setRoles((int) $args['id'], $data['roles'] ?? [], $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, $user);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function getScopes(Request $request, Response $response, array $args): Response
    {
        try {
            $scopes = $this->users->getScopes((int) $args['id']);
            return Envelope::success($response, ['user_id' => (int) $args['id'], 'scopes' => $scopes]);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function setScopes(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $this->jsonBody($request);
            $dto = $this->session($request);
            $scopes = $this->users->setScopes((int) $args['id'], $data['scopes'] ?? [], $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, ['user_id' => (int) $args['id'], 'scopes' => $scopes]);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function forcePasswordReset(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $this->jsonBody($request, allowEmpty: true);
            $dto = $this->session($request);
            $result = $this->users->forcePasswordReset((int) $args['id'], $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, $result);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function effectiveAccess(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $entityType = isset($params['entity_type']) ? trim((string) $params['entity_type']) : null;
        $entityId = isset($params['entity_id']) ? trim((string) $params['entity_id']) : null;

        try {
            $access = $this->users->effectiveAccess((int) $args['id'], $entityType, $entityId);
            return Envelope::success($response, $access);
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

    private function expectedVersion(Request $request): ?int
    {
        $header = $request->getHeaderLine('If-Match');
        $value = trim($header);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        return (int) $value;
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request, bool $allowEmpty = false): array
    {
        $body = JsonBodyParser::parse($request, $allowEmpty);
        return $body;
    }
}