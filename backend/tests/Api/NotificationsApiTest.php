<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;
use Firebase\JWT\JWT;

/**
 * TASK-103 — Notification read surface (api.md §12).
 *
 * ACs covered here:
 *  - GET /notifications returns only the caller's own rows (a notification is
 *    private to its recipient; FR-140 rows are written for the parcel creator).
 *  - ?unread=1 filters to unread rows and reports unread_count for the bell.
 *  - POST /notifications/{id}/read stamps read_at and is idempotent (200).
 *  - Marking another user's notification is 404 NOT_FOUND, never 403
 *    (out-of-scope records do not exist for the caller).
 *  - Unknown query parameters are rejected 400 (contract strictness).
 */
class NotificationsApiTest extends TestCase
{
    private \PDO $pdo;
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->users['a'] = $this->ensureUser('notif_user_a', 'Str0ng!Pass123', 'app_ro', 'Application Read Only');
        $this->users['b'] = $this->ensureUser('notif_user_b', 'Str0ng!Pass123', 'app_ro', 'Application Read Only');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM app.notifications WHERE user_id IN ({$this->users['a']}, {$this->users['b']})");
        $this->pdo->exec("DELETE FROM app.users WHERE username IN ('notif_user_a', 'notif_user_b')");
        parent::tearDown();
    }

    private function tokenFor(int $userId): string
    {
        return JWT::encode(
            ['sub' => (string) $userId, 'v' => 1, 'exp' => time() + 3600],
            getenv('JWT_SECRET') ?: 'dummy_secret',
            'HS256'
        );
    }

    private function insertNotification(int $userId, string $title, ?string $readAt = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.notifications (user_id, type, title, body, entity_type, entity_id, read_at)
             VALUES (:uid, 'WORKFLOW_SUBMIT', :title, 'body', 'PARCEL', '00000000-0000-0000-0000-000000000000', :read_at)
             RETURNING id"
        );
        $stmt->execute([':uid' => $userId, ':title' => $title, ':read_at' => $readAt]);
        return (int) $stmt->fetchColumn();
    }

    private function get(string $path, string $token): array
    {
        $res = $this->handle(
            $this->createJsonRequest('GET', $path)
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Accept', 'application/json')
        );
        return ['status' => $res->getStatusCode(), 'body' => json_decode((string) $res->getBody(), true)];
    }

    private function markRead(int $id, string $token): array
    {
        $res = $this->handle(
            $this->createJsonRequest('POST', "/api/v1/notifications/{$id}/read")
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Accept', 'application/json')
        );
        return ['status' => $res->getStatusCode(), 'body' => json_decode((string) $res->getBody(), true)];
    }

    public function testListReturnsOnlyOwnNotifications(): void
    {
        $ownId = $this->insertNotification($this->users['a'], 'Own notification');
        $foreignId = $this->insertNotification($this->users['b'], 'Foreign notification');

        $r = $this->get('/api/v1/notifications', $this->tokenFor($this->users['a']));
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));

        $ids = array_column($r['body']['data']['data'], 'id');
        $this->assertContains($ownId, $ids);
        $this->assertNotContains($foreignId, $ids, 'A user never sees another user\'s notifications');
    }

    public function testUnreadFilterAndCounts(): void
    {
        $this->insertNotification($this->users['a'], 'Unread one');
        $this->insertNotification($this->users['a'], 'Unread two');
        $this->insertNotification($this->users['a'], 'Already read', date('Y-m-d H:i:s'));

        $r = $this->get('/api/v1/notifications?unread=1', $this->tokenFor($this->users['a']));
        $this->assertSame(200, $r['status']);
        $data = $r['body']['data'];
        $this->assertCount(2, $data['data'], 'only unread rows are listed');
        $this->assertSame(3, $data['total'], 'total counts all own rows');
        $this->assertSame(2, $data['unread_count']);

        // The bell also reads the unread count off the unfiltered call.
        $all = $this->get('/api/v1/notifications', $this->tokenFor($this->users['a']));
        $this->assertSame(2, $all['body']['data']['unread_count']);
        $this->assertSame(3, $all['body']['data']['total']);
    }

    public function testMarkReadStampsReadAtAndIsIdempotent(): void
    {
        $id = $this->insertNotification($this->users['a'], 'To be read');

        $r = $this->markRead($id, $this->tokenFor($this->users['a']));
        $this->assertSame(200, $r['status'], (string) json_encode($r['body']));
        $this->assertTrue($r['body']['data']['read'] === true);

        $readAt = $this->pdo->query("SELECT read_at FROM app.notifications WHERE id = {$id}")->fetchColumn();
        $this->assertNotNull($readAt);

        // Idempotent: marking a read row again is still 200.
        $again = $this->markRead($id, $this->tokenFor($this->users['a']));
        $this->assertSame(200, $again['status']);
        $this->assertSame($readAt, $this->pdo->query("SELECT read_at FROM app.notifications WHERE id = {$id}")->fetchColumn());
    }

    public function testMarkReadOfForeignNotificationIs404(): void
    {
        $foreignId = $this->insertNotification($this->users['b'], 'Not yours');

        $r = $this->markRead($foreignId, $this->tokenFor($this->users['a']));
        $this->assertSame(404, $r['status'], (string) json_encode($r['body']));
        $this->assertSame('NOT_FOUND', $r['body']['error']['code']);

        // The foreign row is untouched.
        $this->assertNull(
            $this->pdo->query("SELECT read_at FROM app.notifications WHERE id = {$foreignId}")->fetchColumn()
        );
    }

    public function testListRejectsUnknownQueryParameters(): void
    {
        $r = $this->get('/api/v1/notifications?bogus=1', $this->tokenFor($this->users['a']));
        $this->assertSame(400, $r['status']);
        $this->assertSame('VALIDATION_FAILED', $r['body']['error']['code']);
    }

    public function testListClampsOutOfRangeLimit(): void
    {
        $r = $this->get('/api/v1/notifications?limit=5000', $this->tokenFor($this->users['a']));
        $this->assertSame(200, $r['status']);
        $this->assertSame(50, $r['body']['data']['limit'], 'limit falls back to the default when out of range');
    }
}
