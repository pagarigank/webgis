<?php
declare(strict_types=1);

namespace App\Parcels\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\Parcels\Application\ConsolidationService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * TASK-114 — Consolidation endpoint (api.md §8.3):
 *
 *   POST /parcels/consolidate?dry_run=true|false
 *
 * `parent_versions` maps parent parcel id → the version the caller saw; every
 * entry is verified against the locked row before anything is written. The
 * route middleware enforces parcel.consolidate; the service verifies the data
 * scope covers EVERY parent (§18.4).
 */
final class ConsolidationController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ConsolidationService $service,
    ) {
    }

    public function consolidate(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);

        $query = $request->getQueryParams();
        foreach (array_keys($query) as $key) {
            if (!in_array((string) $key, ['dry_run'], true)) {
                throw new ApiError('VALIDATION_FAILED', sprintf('Unknown query parameter %s.', $key), 400);
            }
        }
        $dryRun = in_array((string) ($query['dry_run'] ?? 'false'), ['1', 'true'], true);

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object.', 400);
        }

        $ids = $body['parent_parcel_ids'] ?? null;
        if (!is_array($ids) || count($ids) < 2) {
            throw new ApiError('CONSOLIDATION_INVALID', 'parent_parcel_ids must list at least two parcel ids.', 422, [
                'failures' => [['rule' => 'VR-40', 'message' => 'Consolidation requires at least two distinct parent parcels.']],
            ]);
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
                throw new ApiError('VALIDATION_FAILED', 'parent_parcel_ids must contain parcel uuids.', 400);
            }
        }

        $service = $this->service;
        if (!$dryRun) {
            $versions = $body['parent_versions'] ?? null;
            if (!is_array($versions) || $versions === []) {
                throw new ApiError('PRECONDITION_REQUIRED', 'parent_versions (id → version) is required for commit.', 428);
            }
            $map = [];
            foreach ($versions as $pid => $version) {
                if (!ctype_digit((string) $version)) {
                    throw new ApiError('VALIDATION_FAILED', 'parent_versions values must be integers.', 400);
                }
                $map[(string) $pid] = (int) $version;
            }
            $service->setIfMatchVersions($map);
        }

        $requestId = (string) ($request->getAttribute('request_id') ?: '');
        $idempotencyKey = trim((string) $request->getHeaderLine('Idempotency-Key'));

        try {
            $data = $service->consolidate(array_values($ids), $uid, $body, $dryRun, $requestId, $idempotencyKey);
        } finally {
            $service->setIfMatchVersions([]);
        }

        return Envelope::success($response, $data, $dryRun ? 200 : 201);
    }

    private function requireUser(Request $request): int
    {
        $userId = (int) ($request->getAttribute('user_id') ?: 0);
        if ($userId <= 0) {
            throw new ApiError('UNAUTHORIZED', 'Not authenticated', 401);
        }
        return $userId;
    }
}
