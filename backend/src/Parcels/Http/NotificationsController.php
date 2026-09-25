<?php
declare(strict_types=1);

namespace App\Parcels\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * TASK-103 — Notification read surface (api.md §12).
 *
 * The workflow engine (TASK-100) writes app.notifications rows for the parcel
 * creator on every transition (FR-140); this controller is the read/mark side
 * of that surface:
 *
 *  - GET  /notifications?unread=1&limit=&offset=   the caller's own rows
 *  - POST /notifications/{id}/read                 stamp read_at (idempotent)
 *
 * Rows are scoped to the authenticated user (a notification is private to its
 * recipient); app.notifications carries no RLS policy, so the WHERE clause on
 * user_id is the access control and is enforced on every statement.
 */
final class NotificationsController
{
    private const ALLOWED_PARAMS = ['unread', 'limit', 'offset'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $uid = $this->requireUser($request);

        $query = $request->getQueryParams();
        foreach (array_keys($query) as $key) {
            if (!in_array((string) $key, self::ALLOWED_PARAMS, true)) {
                throw new ApiError('VALIDATION_FAILED', sprintf('Unknown query parameter %s.', $key), 400);
            }
        }

        $unreadOnly = isset($query['unread']) && in_array((string) $query['unread'], ['1', 'true'], true);

        $limit = isset($query['limit']) ? (int) $query['limit'] : 50;
        if ($limit < 1 || $limit > 200) {
            $limit = 50;
        }
        $offset = isset($query['offset']) ? (int) $query['offset'] : 0;
        if ($offset < 0) {
            $offset = 0;
        }

        $sql = 'SELECT id, type, title, body, entity_type, entity_id, read_at, created_at
                FROM app.notifications
                WHERE user_id = :uid';
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // total always counts ALL own rows (the filter governs the page,
        // not the total) so the bell's unread badge and the list agree.
        $countSql = 'SELECT count(*) FROM app.notifications WHERE user_id = :uid';
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute([':uid' => $uid]);

        $unreadStmt = $this->pdo->prepare('SELECT count(*) FROM app.notifications WHERE user_id = :uid AND read_at IS NULL');
        $unreadStmt->execute([':uid' => $uid]);

        return Envelope::success($response, [
            'data'         => $rows,
            'total'        => (int) $countStmt->fetchColumn(),
            'unread_count' => (int) $unreadStmt->fetchColumn(),
            'limit'        => $limit,
            'offset'       => $offset,
        ], 200);
    }

    public function markRead(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid notification id', 400);
        }

        // user_id in the predicate: another user's notification is simply
        // NOT_FOUND (never a permission error — it does not exist for them).
        $stmt = $this->pdo->prepare(
            'UPDATE app.notifications
             SET read_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :uid AND read_at IS NULL'
        );
        $stmt->execute([':id' => $id, ':uid' => $uid]);

        if ($stmt->rowCount() === 0) {
            // Either not ours, already read, or nonexistent — look up to keep
            // the idempotent-200 vs 404 distinction exact.
            $check = $this->pdo->prepare('SELECT id FROM app.notifications WHERE id = :id AND user_id = :uid');
            $check->execute([':id' => $id, ':uid' => $uid]);
            if ($check->fetchColumn() === false) {
                throw new ApiError('NOT_FOUND', 'Notification not found.', 404);
            }
        }

        return Envelope::success($response, ['id' => $id, 'read' => true], 200);
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
