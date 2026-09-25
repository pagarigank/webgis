<?php
declare(strict_types=1);

namespace App\Parcels\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\Parcels\Application\SplitService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * TASK-112 — Split endpoints (api.md §8.2):
 *
 *   POST /parcels/{id}/split?dry_run=true|false
 *
 * The route middleware enforces parcel.split + authentication; the service
 * additionally verifies the data scope covers the parent (§18.3). On
 * SPLIT_INVALID the envelope carries every failed check in details.failures.
 */
final class SplitController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SplitService $splitService,
    ) {
    }

    public function split(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $parcelId = $this->parseUuid($args['id'] ?? null);

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

        $requestId = (string) ($request->getAttribute('request_id') ?: '');
        $idempotencyKey = trim((string) $request->getHeaderLine('Idempotency-Key'));

        // If-Match is mandatory for commit (§8.2) and must equal the parent's
        // current version; dry runs omit it.
        $ifMatch = trim((string) $request->getHeaderLine('If-Match'));
        $service = $this->splitService;
        if (!$dryRun) {
            if ($ifMatch === '') {
                throw new ApiError('PRECONDITION_REQUIRED', 'An If-Match header with the parent version is required for commit.', 428);
            }
            if (!ctype_digit($ifMatch)) {
                throw new ApiError('VERSION_CONFLICT', 'If-Match must be the parent parcel version.', 409);
            }
            $service->setIfMatchVersion((int) $ifMatch);
        }

        try {
            $data = $service->split($parcelId, $uid, $body, $dryRun, $requestId, $idempotencyKey);
        } finally {
            $service->setIfMatchVersion(null);
        }

        return Envelope::success($response, $data, $dryRun ? 200 : 201);
    }

    private function parseUuid(mixed $raw): string
    {
        $v = (string) ($raw ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
        return $v;
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
