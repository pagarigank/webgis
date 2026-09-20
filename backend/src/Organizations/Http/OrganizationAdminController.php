<?php
declare(strict_types=1);

namespace App\Organizations\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Request\JsonBodyParser;
use App\Core\Http\Response\Envelope;
use App\Organizations\OrganizationAdminService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OrganizationAdminController
{
    public function __construct(private readonly OrganizationAdminService $organizations) {}

    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : 50;
        $query = isset($params['query']) ? trim((string) $params['query']) : null;
        $orgType = isset($params['org_type']) ? trim((string) $params['org_type']) : null;
        $status = isset($params['status']) ? trim((string) $params['status']) : null;

        try {
            return Envelope::success($response, $this->organizations->list($page, $perPage, $query, $orgType, $status));
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $data = $this->jsonBody($request);
            $dto = $this->session($request);
            $org = $this->organizations->create($data, $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, $org, 201);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            return Envelope::success($response, $this->organizations->get((int) $args['id']));
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
            $org = $this->organizations->update((int) $args['id'], $data, $expected, $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, $org);
        } catch (ApiError $e) {
            return Envelope::error($response, $e->getErrorCode(), $e->getMessage(), $e->getDetails(), $e->getApiStatus());
        }
    }

    public function deactivate(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $this->jsonBody($request, allowEmpty: true);
            $dto = $this->session($request);
            $this->organizations->deactivate((int) $args['id'], $dto['user_id'], $dto['request_id'], $data['reason'] ?? null);
            return Envelope::success($response, ['id' => (int) $args['id'], 'status' => 'INACTIVE']);
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