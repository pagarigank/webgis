<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-119 — Historical record handling (FR-233): no default view shows
 * superseded (or archived) parcels; every historical view is an explicit
 * include_historical=true opt-in. Even an explicit status filter on a
 * historical status must not leak rows without the opt-in (same rule as the
 * TASK-070 SUPERSEDED behaviour, now covering ARCHIVED too).
 */
class HistoricalFilterTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();
        $user = $this->createMockUser($this->pdo, ['parcel.view'], ['HIST_ADMIN']);
        $this->token = $user['token'];
        $this->userId = $user['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $pdo = $this->pdo ?? null;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'HISTF_%'");
    }

    private function req(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest('GET', $path)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
    }

    private function list(string $query = ''): array
    {
        $res = $this->handle($this->req('/api/v1/parcels' . $query));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        return json_decode((string) $res->getBody(), true)['data'];
    }

    private function parcel(string $code, string $status): string
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (id, parcel_code, status, geometry_source, version, created_by)
            VALUES (gen_random_uuid(), :code, :status, 'MANUAL_DRAWING', 1, :uid)
            RETURNING id
        ");
        $stmt->execute([':code' => $code, ':status' => $status, ':uid' => $this->userId]);
        return (string) $stmt->fetchColumn();
    }

    public function testDefaultViewExcludesSupersededAndArchived(): void
    {
        $this->parcel('HISTF_ACTIVE_01', 'DRAFT');
        $this->parcel('HISTF_SUP_01', 'SUPERSEDED');
        $this->parcel('HISTF_ARC_01', 'ARCHIVED');

        $body = $this->list('?limit=100');
        $codes = array_column($body['data'], 'parcel_code');
        $this->assertContains('HISTF_ACTIVE_01', $codes);
        $this->assertNotContains('HISTF_SUP_01', $codes);
        $this->assertNotContains('HISTF_ARC_01', $codes);
        $this->assertFalse($body['include_historical']);
    }

    public function testIncludeHistoricalShowsEverything(): void
    {
        $this->parcel('HISTF_ACTIVE_02', 'DRAFT');
        $this->parcel('HISTF_SUP_02', 'SUPERSEDED');
        $this->parcel('HISTF_ARC_02', 'ARCHIVED');

        $body = $this->list('?limit=100&include_historical=true');
        $codes = array_column($body['data'], 'parcel_code');
        $this->assertContains('HISTF_ACTIVE_02', $codes);
        $this->assertContains('HISTF_SUP_02', $codes);
        $this->assertContains('HISTF_ARC_02', $codes);
        $this->assertTrue($body['include_historical']);
    }

    public function testExplicitHistoricalStatusFilterWithoutOptInReturnsNothing(): void
    {
        $this->parcel('HISTF_SUP_03', 'SUPERSEDED');
        $this->parcel('HISTF_ARC_03', 'ARCHIVED');

        foreach (['SUPERSEDED', 'ARCHIVED'] as $status) {
            $body = $this->list('?limit=100&status=' . $status);
            $this->assertSame([], $body['data'], "status=$status must be hidden without include_historical");
        }

        // With the opt-in, the explicit filter works.
        $body = $this->list('?limit=100&status=ARCHIVED&include_historical=true');
        $this->assertCount(1, $body['data']);
        $this->assertSame('HISTF_ARC_03', $body['data'][0]['parcel_code']);
    }
}
