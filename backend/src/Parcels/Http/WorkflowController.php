<?php
declare(strict_types=1);

namespace App\Parcels\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\Parcels\Workflow\WorkflowEngine;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * TASK-100 — Workflow transitions API.
 *
 *  - POST /parcels/{id}/transitions            {action, reason?, comment?}
 *  - GET  /parcels/{id}/transitions            (permitted actions for caller)
 *  - GET  /parcels/{id}/transitions/history    (full transition history)
 *
 * Permission enforcement lives inside WorkflowEngine (data-driven), so this
 * controller only authenticates and scopes the session.
 */
final class WorkflowController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WorkflowEngine $engine,
    ) {
    }

    public function transition(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);
        $pid = $this->uuid($args, 'id');

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
        }
        $action = (string) ($body['action'] ?? '');
        if (trim($action) === '') {
            throw new ApiError('VALIDATION_FAILED', 'The action field is required.', 422, [
                'fields' => [['field' => 'action', 'rule' => 'REQUIRED']],
            ]);
        }

        $result = $this->engine->transition($pid, $action, $uid, [
            'reason'  => isset($body['reason']) ? (string) $body['reason'] : null,
            'comment' => isset($body['comment']) ? (string) $body['comment'] : null,
            'accepted_computation_id' => isset($body['accepted_computation_id']) ? (int) $body['accepted_computation_id'] : null,
            'accepted_td_revision'    => isset($body['accepted_td_revision']) ? (int) $body['td_revision'] : null,
        ]);

        return Envelope::success($response, $result, 200);
    }

    public function available(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);
        $pid = $this->uuid($args, 'id');

        return Envelope::success($response, [
            'parcel_id' => $pid,
            'actions'   => $this->engine->availableActions($pid, $uid),
        ], 200);
    }

    public function history(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);
        $pid = $this->uuid($args, 'id');

        return Envelope::success($response, [
            'parcel_id' => $pid,
            'history'   => $this->engine->history($pid),
        ], 200);
    }

    private function requireUser(Request $request): int
    {
        $userId = (int) ($request->getAttribute('user_id') ?: 0);
        if ($userId <= 0) {
            throw new ApiError('UNAUTHORIZED', 'Not authenticated', 401);
        }
        return $userId;
    }

    private function scopeSession(int $uid): void
    {
        $this->pdo->exec('SET LOCAL app.current_user_id = ' . (int) $uid);
    }

    private function uuid(array $args, string $key): string
    {
        $val = $args[$key] ?? '';
        if (!is_string($val) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid parcel id', 400);
        }
        return strtolower($val);
    }
}
