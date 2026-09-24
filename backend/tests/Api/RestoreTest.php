<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-106 — Version restore acceptance.
 *
 * ACs covered here:
 *  - Restore is additive: it creates a NEW version, never deletes or
 *    rewrites history; the version sequence remains monotonic.
 *  - The previously existing versions remain retrievable and unchanged
 *    (byte-for-byte on key attributes) after the restore.
 *  - Restore requires If-Match (428/409) and records a reason.
 *  - Restoring a version that carried geometry brings the geometry back.
 */
class RestoreTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'RESTORE_%'");
        $user = $this->createMockUser($this->pdo, [
            'parcel.view', 'parcel.create', 'parcel.update', 'parcel.delete',
            'parcel.lineage.view', 'parcel.version.restore',
        ], ['GIS_MANAGER']);
        $this->token = $user['token'];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $this->createdIds));
            $this->pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN ($ids)");
            $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.parcels' AND entity_id IN ($ids)");
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
            $this->createdIds = [];
        }
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'RESTORE_%'");
        parent::tearDown();
    }

    private function req(string $method, string $path, array $data = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }
        return $request;
    }

    private function polygon(float $offset = 0.0): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[[121.0 + $offset, 14.5], [121.01 + $offset, 14.5], [121.01 + $offset, 14.51], [121.0 + $offset, 14.5]]],
        ];
    }

    private function versionRows(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT version, change_reason FROM audit.parcel_versions WHERE parcel_id = :pid ORDER BY version');
        $stmt->execute([':pid' => $id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testRestoreIsAdditiveAndMonotonic(): void
    {
        $res = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => 'RESTORE_MONO_01',
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 1000.0,
        ]));
        $parcel = json_decode((string) $res->getBody(), true)['data'];
        $this->createdIds[] = $parcel['id'];
        $id = $parcel['id'];

        $this->handle($this->req('PATCH', '/api/v1/parcels/' . $id, ['lot_number' => 'R-1'], ['If-Match' => '1']));
        $this->handle($this->req('PATCH', '/api/v1/parcels/' . $id, ['lot_number' => 'R-2'], ['If-Match' => '2']));

        // Restore version 2 (lot R-1) -> creates version 4.
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$id}/versions/2/restore", ['reason' => 'Rolling back lot edit'], ['If-Match' => '3']));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $restored = json_decode((string) $res->getBody(), true)['data'];
        $this->assertSame(4, $restored['version']);
        $this->assertSame('R-1', $restored['lot_number']);

        $rows = $this->versionRows($id);
        $this->assertSame([1, 2, 3, 4], array_map(fn ($r) => (int) $r['version'], $rows), 'Version sequence stays monotonic and append-only');
        $this->assertSame('Rolling back lot edit', $rows[3]['change_reason']);
    }

    public function testHistoricalVersionsRemainUnchangedAfterRestore(): void
    {
        $res = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => 'RESTORE_KEEP_01',
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 1000.0,
        ]));
        $parcel = json_decode((string) $res->getBody(), true)['data'];
        $this->createdIds[] = $parcel['id'];
        $id = $parcel['id'];

        $this->handle($this->req('PATCH', '/api/v1/parcels/' . $id, ['lot_number' => 'KEEP-A'], ['If-Match' => '1']));
        $this->handle($this->req('PATCH', '/api/v1/parcels/' . $id, ['lot_number' => 'KEEP-B'], ['If-Match' => '2']));

        // Snapshot the historical rows before restoring.
        $before = $this->versionRows($id);

        $this->handle($this->req('POST', "/api/v1/parcels/{$id}/versions/1/restore", ['reason' => 'Back to original'], ['If-Match' => '3']));

        $after = $this->versionRows($id);
        // All pre-existing rows must be identical (append-only, never rewritten).
        foreach ($before as $i => $row) {
            $this->assertSame($row, $after[$i], "Historical version row {$row['version']} unchanged after restore");
        }
        $this->assertCount(4, $after);

        // The historical version endpoint still returns v2 with KEEP-A.
        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$id}/versions/2"));
        $body = json_decode((string) $res->getBody(), true)['data'];
        $snap = is_string($body['snapshot']) ? json_decode($body['snapshot'], true) : $body['snapshot'];
        $this->assertSame('KEEP-A', $snap['lot_number']);
    }

    public function testRestoreBringsBackGeometry(): void
    {
        $res = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => 'RESTORE_GEOM_01',
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 1000.0,
        ]));
        $parcel = json_decode((string) $res->getBody(), true)['data'];
        $this->createdIds[] = $parcel['id'];
        $id = $parcel['id'];

        // v2: add geometry.
        $this->handle($this->req('PATCH', '/api/v1/parcels/' . $id, ['geometry' => $this->polygon(0.0)], ['If-Match' => '1']));
        // v3: remove it again.
        $this->handle($this->req('PATCH', '/api/v1/parcels/' . $id, ['geometry' => null], ['If-Match' => '2']));

        $get = json_decode((string) $this->handle($this->req('GET', "/api/v1/parcels/{$id}"))->getBody(), true)['data'];
        $this->assertNull($get['geometry']);

        // Restore v2 (with geometry).
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$id}/versions/2/restore", ['reason' => 'Geometry came back'], ['If-Match' => '3']));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $restored = json_decode((string) $res->getBody(), true)['data'];
        $this->assertNotEmpty($restored['geometry'], 'Restored version brings geometry back');
    }

    public function testRestoreRequiresIfMatch(): void
    {
        $res = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => 'RESTORE_IFM_01',
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 1000.0,
        ]));
        $parcel = json_decode((string) $res->getBody(), true)['data'];
        $this->createdIds[] = $parcel['id'];

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcel['id']}/versions/1/restore", ['reason' => 'x']));
        $this->assertSame(428, $res->getStatusCode());

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcel['id']}/versions/1/restore", ['reason' => 'x'], ['If-Match' => '99']));
        $this->assertSame(409, $res->getStatusCode());
    }
}
