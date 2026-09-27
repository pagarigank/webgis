<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * Land-title endpoints (Phase 16) — HTTP-level regression tests.
 *
 * The two write endpoints (POST /parcels/{id}/titles, POST /titles/{id}/parties)
 * used to open their own beginTransaction() inside the ambient transaction that
 * AuthenticateMiddleware already holds, so every call aborted with
 * "There is already an active transaction" (HTTP 500). No test previously drove
 * these routes over HTTP — the standing lesson from the Phase 5-15 audit is that
 * any write endpoint ships with at least one HTTP-level test; these are they.
 */
class LandTitleApiTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private int $userId;
    private ?int $scopeId = null;
    private string $parcelId = '';
    private array $titleIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();
        $user = $this->createMockUser($this->pdo, ['parcel.view', 'parcel.update'], ['LT_ADMIN']);
        $this->token = $user['token'];
        $this->userId = $user['id'];
        $stmt = $this->pdo->prepare("INSERT INTO app.data_scopes (user_id, scope_type, access_level) VALUES (:uid, 'GLOBAL', 'EDIT') RETURNING id");
        $stmt->execute([':uid' => $this->userId]);
        $this->scopeId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (id, parcel_code, status, geometry_source, version, created_by)
            VALUES (gen_random_uuid(), 'LT_API_HOST', 'DRAFT', 'MANUAL_DRAWING', 1, :uid)
            RETURNING id
        ");
        $stmt->execute([':uid' => $this->userId]);
        $this->parcelId = (string) $stmt->fetchColumn();
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
        if ($this->parcelId !== '') {
            $pdo->exec("DELETE FROM app.parcel_titles WHERE parcel_id = '{$this->parcelId}'");
        }
        foreach ($this->titleIds as $tid) {
            $pdo->exec("DELETE FROM app.title_parties WHERE title_id = $tid");
            $pdo->exec("DELETE FROM app.land_titles WHERE id = $tid");
        }
        $pdo->exec("DELETE FROM app.title_parties WHERE title_id IN (SELECT id FROM app.land_titles WHERE title_number LIKE 'LT-API-%')");
        $pdo->exec("DELETE FROM app.land_titles WHERE title_number LIKE 'LT-API-%'");
        if ($this->parcelId !== '') {
            $pdo->exec("DELETE FROM app.parcels WHERE id = '{$this->parcelId}'");
        }
        $this->titleIds = [];
        $this->parcelId = '';
        if ($this->scopeId !== null) {
            $pdo->exec("DELETE FROM app.data_scopes WHERE id = {$this->scopeId}");
            $this->scopeId = null;
        }
    }

    private function req(string $method, string $path, ?array $data = null, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->createJsonRequest($method, $path, $data ?? [])
            ->withHeader('Authorization', 'Bearer ' . $this->token);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    public function testCreateTitleForParcelReturns201AndLinks(): void
    {
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$this->parcelId}/titles", [
            'title_number' => 'LT-API-001',
            'title_type' => 'OCT',
            'area_sqm' => 298.31,
            'registry_office' => 'Angeles ROD',
        ]));
        $this->assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->titleIds[] = (int) $data['id'];

        $link = $this->pdo->query("SELECT COUNT(*) FROM app.parcel_titles WHERE parcel_id = '{$this->parcelId}' AND title_id = {$data['id']}")->fetchColumn();
        $this->assertSame(1, (int) $link);
    }

    public function testAddPartyToTitleReturns201(): void
    {
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$this->parcelId}/titles", [
            'title_number' => 'LT-API-002',
        ]));
        $this->assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $titleId = (int) json_decode((string) $res->getBody(), true)['data']['id'];
        $this->titleIds[] = $titleId;

        $res2 = $this->handle($this->req('POST', "/api/v1/titles/{$titleId}/parties", [
            'full_name' => 'Juan dela Cruz',
            'party_type' => 'INDIVIDUAL',
            'role' => 'REGISTERED_OWNER',
        ]));
        $this->assertSame(201, $res2->getStatusCode(), (string) $res2->getBody());
        $party = json_decode((string) $res2->getBody(), true)['data'];
        $this->assertGreaterThan(0, (int) $party['party_id']);
    }

    public function testListReturnsLinkedTitlesWithParties(): void
    {
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$this->parcelId}/titles", [
            'title_number' => 'LT-API-003',
        ]));
        $titleId = (int) json_decode((string) $res->getBody(), true)['data']['id'];
        $this->titleIds[] = $titleId;

        $list = $this->handle($this->req('GET', "/api/v1/parcels/{$this->parcelId}/titles"));
        $this->assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        $titles = json_decode((string) $list->getBody(), true)['data'];
        $this->assertCount(1, $titles);
        $this->assertSame('LT-API-003', $titles[0]['title_number']);
        $this->assertArrayHasKey('parties', $titles[0]);
    }

    public function testUpdateTitleReturns200(): void
    {
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$this->parcelId}/titles", [
            'title_number' => 'LT-API-004',
        ]));
        $titleId = (int) json_decode((string) $res->getBody(), true)['data']['id'];
        $this->titleIds[] = $titleId;

        $upd = $this->handle($this->req('PUT', "/api/v1/titles/{$titleId}", [
            'remarks' => 'Updated by regression test',
        ]));
        $this->assertSame(200, $upd->getStatusCode(), (string) $upd->getBody());
        $this->assertSame('Updated by regression test', json_decode((string) $upd->getBody(), true)['data']['remarks']);
    }

    public function testLinkAndUnlinkExistingTitle(): void
    {
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$this->parcelId}/titles", [
            'title_number' => 'LT-API-005',
        ]));
        $titleId = (int) json_decode((string) $res->getBody(), true)['data']['id'];
        $this->titleIds[] = $titleId;

        $unlink = $this->handle($this->req('DELETE', "/api/v1/parcels/{$this->parcelId}/titles/{$titleId}"));
        $this->assertSame(200, $unlink->getStatusCode(), (string) $unlink->getBody());

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM app.parcel_titles WHERE parcel_id = :pid AND title_id = :tid");
        $stmt->execute([':pid' => $this->parcelId, ':tid' => $titleId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        $relink = $this->handle($this->req('POST', "/api/v1/parcels/{$this->parcelId}/titles/{$titleId}", [
            'relationship' => 'COVERS',
        ]));
        $this->assertSame(201, $relink->getStatusCode(), (string) $relink->getBody());

        $stmt->execute([':pid' => $this->parcelId, ':tid' => $titleId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testDuplicateTitleNumberIs409(): void
    {
        $r1 = $this->handle($this->req('POST', "/api/v1/parcels/{$this->parcelId}/titles", [
            'title_number' => 'LT-API-DUP',
            'registry_office' => 'Angeles ROD',
        ]));
        $this->assertSame(201, $r1->getStatusCode(), (string) $r1->getBody());
        $this->titleIds[] = (int) json_decode((string) $r1->getBody(), true)['data']['id'];

        $r2 = $this->handle($this->req('POST', "/api/v1/parcels/{$this->parcelId}/titles", [
            'title_number' => 'LT-API-DUP',
            'registry_office' => 'Angeles ROD',
        ]));
        $this->assertSame(409, $r2->getStatusCode(), (string) $r2->getBody());
        $this->assertSame('CONFLICT', json_decode((string) $r2->getBody(), true)['error']['code']);
    }

    public function testMissingTitleNumberIs422(): void
    {
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$this->parcelId}/titles", []));
        $this->assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('VALIDATION_FAILED', json_decode((string) $res->getBody(), true)['error']['code']);
    }
}
