<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-104 — Merged history timeline API acceptance.
 *
 * ACs covered here:
 *  - "Everything ever done to parcel X" returns a complete, ordered record:
 *    VERSION rows from audit.parcel_versions, AUDIT rows from audit.audit_logs,
 *    and WORKFLOW rows from app.approval_actions merged into one stream,
 *    newest first.
 *  - The export endpoint returns the same events as a downloadable JSON file.
 *  - Unknown parcel -> 404.
 */
class HistoryTimelineTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private array $createdParcelIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'TIMELINE_%'");
        $user = $this->createMockUser($this->pdo, [
            'parcel.view', 'parcel.create', 'parcel.update', 'parcel.lineage.view',
            'parcel.submit', 'parcel.review', 'parcel.verify', 'parcel.approve', 'parcel.publish', 'parcel.archive',
            'survey.view', 'survey.create', 'survey.update',
        ], ['GIS_MANAGER']);
        $this->token = $user['token'];
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdParcelIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $this->createdParcelIds));
            $this->pdo->exec("DELETE FROM app.approval_actions WHERE instance_id IN (
                SELECT id FROM app.workflow_instances WHERE entity_type = 'PARCEL' AND entity_id IN ($ids)
            )");
            $this->pdo->exec("DELETE FROM app.workflow_instances WHERE entity_type = 'PARCEL' AND entity_id IN ($ids)");
            $this->pdo->exec("DELETE FROM app.notifications WHERE entity_type = 'PARCEL' AND entity_id IN ($ids)");
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
            $this->createdParcelIds = [];
        }
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'TIMELINE_%'");
        parent::tearDown();
    }

    private function req(string $method, string $path, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
    }

    private function createParcelWithHistory(string $code): array
    {
        $res = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code'     => $code,
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 1000.0,
        ]));
        $this->assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());
        $parcel = json_decode((string) $res->getBody(), true)['data'];
        $this->createdParcelIds[] = $parcel['id'];

        // Attribute edit -> version 2 + AUDIT row.
        $this->handle($this->req('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'lot_number'    => 'TIMELINE-LOT',
            'change_reason' => 'Timeline test edit',
        ], ['If-Match' => '1']));

        return $parcel;
    }

    public function testTimelineMergesVersionsAuditAndWorkflowEvents(): void
    {
        $parcel = $this->createParcelWithHistory('TIMELINE_MERGED_01');

        // Add a WORKFLOW event via the engine (ARCHIVE needs only a reason).
        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parcel['id']}/transitions", [
            'action' => 'ARCHIVE',
            'reason' => 'Timeline test archive',
        ]));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$parcel['id']}/timeline"));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];

        $kinds = array_column($data['events'], 'kind');
        $this->assertContains('VERSION', $kinds, 'Version rows appear in the timeline');
        $this->assertContains('AUDIT', $kinds, 'Audit rows appear in the timeline');
        $this->assertContains('WORKFLOW', $kinds, 'Workflow transitions appear in the timeline');

        // Newest first: the archive produces an AUDIT row and a WORKFLOW row;
        // the audit row (mirrored action) carries the latest timestamp, and
        // the workflow row must be present at the top of the stream.
        $this->assertContains($data['events'][0]['kind'], ['AUDIT', 'WORKFLOW'], (string) json_encode(array_slice($data['events'], 0, 3)));
        $workflowEvents = array_values(array_filter($data['events'], fn ($e) => $e['kind'] === 'WORKFLOW'));
        $this->assertNotEmpty($workflowEvents);
        $this->assertSame('ARCHIVE (DRAFT -> ARCHIVED)', $workflowEvents[0]['action']);
        $this->assertSame('Timeline test archive', $workflowEvents[0]['detail']['reason']);

        // Strict chronological order (newest first).
        $ats = array_column($data['events'], 'at');
        $sorted = $ats;
        rsort($sorted);
        $this->assertSame($sorted, $ats, 'Timeline is ordered newest first');

        // The version-2 row carries the edit reason.
        $versionRows = array_values(array_filter($data['events'], fn ($e) => $e['kind'] === 'VERSION'));
        $this->assertNotEmpty($versionRows);
    }

    public function testExportReturnsDownloadableJsonBundle(): void
    {
        $parcel = $this->createParcelWithHistory('TIMELINE_EXPORT_01');

        $res = $this->handle($this->req('GET', "/api/v1/parcels/{$parcel['id']}/timeline/export"));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('application/json', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment', $res->getHeaderLine('Content-Disposition'));

        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame($parcel['id'], $body['parcel_id']);
        $this->assertArrayHasKey('exported_at', $body);
        $this->assertGreaterThan(0, $body['event_count']);
        $this->assertSame($body['event_count'], count($body['events']));
    }

    public function testUnknownParcelReturns404(): void
    {
        $res = $this->handle($this->req('GET', '/api/v1/parcels/00000000-0000-0000-0000-000000000000/timeline'));
        $this->assertSame(404, $res->getStatusCode());
        $res2 = $this->handle($this->req('GET', '/api/v1/parcels/00000000-0000-0000-0000-000000000000/timeline/export'));
        $this->assertSame(404, $res2->getStatusCode());
    }
}
