<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-120 — Split with children derived from survey data (FR-206, todo.md
 * AC: "a child computed from a TD carries COMPUTED_FROM_TECHNICAL_DESCRIPTION
 * and its own closure result").
 *
 * Exact fixture: the parent is a computed 100 m × 50 m square (TASK-101
 * path). A second TD computed from the SAME control point with 50 m × 50 m
 * courses yields EXACTLY the parent's west half (same engine, same tie point,
 * shared edges transform identically), and the east half is derived with
 * ST_Difference — so the children tile the parent exactly (VR-36/VR-37 pass
 * with zero tolerance slack) and the assertions can focus on provenance and
 * per-child closure results.
 */
class SplitFromTechnicalDescriptionTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private int $userId;
    private ?int $scopeId = null;
    private array $createdParcelIds = [];
    private array $createdControlPointIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();
        $user = $this->createMockUser($this->pdo, ['parcel.view', 'parcel.split', 'parcel.update', 'survey.view', 'survey.create', 'survey.update'], ['TDSPLIT_ADMIN']);
        $this->token = $user['token'];
        $this->userId = $user['id'];
        $stmt = $this->pdo->prepare("INSERT INTO app.data_scopes (user_id, scope_type, access_level) VALUES (:uid, 'GLOBAL', 'EDIT') RETURNING id");
        $stmt->execute([':uid' => $this->userId]);
        $this->scopeId = (int) $stmt->fetchColumn();
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
        $ids = array_map(
            static fn (array $r): string => (string) $r['id'],
            $pdo->query("SELECT id FROM app.parcels WHERE parcel_code LIKE 'TDSPLIT\\_%'")->fetchAll(\PDO::FETCH_ASSOC)
        );
        if ($ids !== []) {
            $list = implode(', ', array_map(static fn (string $i): string => "'$i'", $ids));
            $pdo->exec("DELETE FROM app.parcel_relationships WHERE parent_parcel_id IN ($list) OR child_parcel_id IN ($list)");
            $pdo->exec("DELETE FROM app.parcel_operations WHERE id IN (SELECT superseded_by_operation_id FROM app.parcels WHERE id IN ($list))");
            $pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN ($list)");
            $pdo->exec("DELETE FROM app.parcels WHERE id IN ($list)");
            $pdo->exec("DELETE FROM app.parcel_computations WHERE parcel_id IN ($list)");
        }
        $pdo->exec("DELETE FROM app.survey_control_points WHERE point_name LIKE 'TDSPLIT_CP_%'");
        if ($this->scopeId !== null) {
            $pdo->exec("DELETE FROM app.data_scopes WHERE id = {$this->scopeId}");
            $this->scopeId = null;
        }
        $pdo->exec("DELETE FROM app.rate_limit_entries WHERE bucket_key LIKE 'lineage:%'");
        $this->createdParcelIds = [];
    }

    private function req(string $method, string $path, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Accept', 'application/json');
    }

    /**
     * Build a parcel with a confirmed, computed TD (square of the given side
     * lengths, tie point at 121.0/14.5) and return [parcel_id, td_id].
     *
     * @return array{0: string, 1: int}
     */
    private function createComputedSquareParcel(string $code, float $widthM, float $heightM): array
    {
        $cpStmt = $this->pdo->prepare(
            "INSERT INTO app.survey_control_points (point_name, status, geom)
             VALUES (:name, 'VERIFIED', ST_SetSRID(ST_MakePoint(121.0, 14.5), 4326)) RETURNING id"
        );
        $cpStmt->execute([':name' => 'TDSPLIT_CP_' . uniqid()]);
        $cpId = (int) $cpStmt->fetchColumn();
        $this->createdControlPointIds[] = $cpId;

        $res = $this->handle($this->req('POST', '/api/v1/parcels', [
            'parcel_code' => $code,
            'provenance' => 'MANUAL_DRAWING',
            'source_area_sqm' => $widthM * $heightM,
        ]));
        $this->assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());
        $parcelId = (string) json_decode((string) $res->getBody(), true)['data']['id'];
        $this->createdParcelIds[] = $parcelId;

        $tdRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/technical-descriptions", [
            'bearing_reference' => 'GRID',
            'claimed_area_sqm' => $widthM * $heightM,
            'tie_line_bearing' => 'DUE NORTH',
            'tie_line_distance' => 100.0,
        ]));
        $tdId = (int) json_decode((string) $tdRes->getBody(), true)['data']['id'];

        $this->pdo->prepare("INSERT INTO app.tie_points (technical_description_id, control_point_id, sequence, role) VALUES (:tdid, :cp, 1, 'TIE')")
            ->execute([':cp' => $cpId, ':tdid' => $tdId]);

        foreach ([
            ['from_corner' => '1', 'to_corner' => '2', 'bearing_raw' => 'DUE EAST', 'distance_raw' => $widthM],
            ['from_corner' => '2', 'to_corner' => '3', 'bearing_raw' => 'DUE NORTH', 'distance_raw' => $heightM],
            ['from_corner' => '3', 'to_corner' => '4', 'bearing_raw' => 'DUE WEST', 'distance_raw' => $widthM],
            ['from_corner' => '4', 'to_corner' => '1', 'bearing_raw' => 'DUE SOUTH', 'distance_raw' => $heightM],
        ] as $c) {
            $courseRes = $this->handle($this->req('POST', "/api/v1/technical-descriptions/{$tdId}/courses", $c));
            $this->assertContains($courseRes->getStatusCode(), [200, 201], (string) $courseRes->getBody());
        }
        $this->handle($this->req('POST', "/api/v1/technical-descriptions/{$tdId}/confirm"));

        $calcRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/calculate", [
            'technical_description_id' => $tdId,
            'compute_crs' => 'EPSG:3123',
        ]));
        $this->assertContains($calcRes->getStatusCode(), [200, 201], (string) $calcRes->getBody());

        // Accept the computation so parcels.geom and is_current are set —
        // the same state a surveyor reaches before splitting.
        $computationId = (int) json_decode((string) $calcRes->getBody(), true)['data']['computation_id'];
        $acceptRes = $this->handle($this->req('POST', "/api/v1/parcels/{$parcelId}/accept-computation", [
            'computation_id' => $computationId,
            'reason' => 'TD split fixture setup',
        ]));
        $this->assertContains($acceptRes->getStatusCode(), [200, 201], (string) $acceptRes->getBody());

        return [$parcelId, $tdId];
    }

    public function testTdDerivedChildCarriesProvenanceAndClosure(): void
    {
        // Parent: computed 100 × 50 m square.
        [$parentId] = $this->createComputedSquareParcel('TDSPLIT_PARENT_01', 100.0, 50.0);

        // Child TD: 50 × 50 m from the SAME control point → exactly the
        // parent's west half.
        [, $tdWest] = $this->createComputedSquareParcel('TDSPLIT_TDHOST_01', 50.0, 50.0);

        // East half derived exactly from parent minus west half.
        $eastGj = $this->pdo->query("
            SELECT ST_AsGeoJSON(ST_Difference(parent.geom, host.geom))
              FROM app.parcels parent, app.parcels host
             WHERE parent.parcel_code = 'TDSPLIT_PARENT_01'
               AND host.parcel_code = 'TDSPLIT_TDHOST_01'
        ")->fetchColumn();
        $this->assertIsString($eastGj);
        $east = json_decode((string) $eastGj, true);

        $version = (int) $this->pdo->query("SELECT version FROM app.parcels WHERE id = '{$parentId}'")->fetchColumn();

        $body = [
            'method' => 'TECHNICAL_DESCRIPTION',
            'children' => [
                ['lot_number' => '100-TD-W', 'technical_description_id' => $tdWest],
                ['lot_number' => '100-TD-E', 'geometry' => $east],
            ],
            'reason' => 'Subdivision per plan Psd-000009 (survey-derived children)',
        ];

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parentId}/split", $body)
            ->withHeader('If-Match', (string) $version)
            ->withHeader('Idempotency-Key', 'TDSPLIT_KEY_01'));
        $this->assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];

        $this->assertCount(2, $data['children']);

        // Provenance follows the operation method for both children; the
        // TD-derived one additionally carries its own computation row.
        $tdChildId = null;
        foreach ($data['children'] as $child) {
            $row = $this->pdo->query("SELECT geometry_source, current_computation_id FROM app.parcels WHERE id = '{$child['parcel_id']}'")->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('COMPUTED_FROM_TECHNICAL_DESCRIPTION', $row['geometry_source']);
            if ($child['lot_number'] === '100-TD-W') {
                $tdChildId = (string) $child['parcel_id'];
                $this->assertNotNull($row['current_computation_id'], 'TD child must point at its own computation');

                $comp = $this->pdo->query("SELECT closure_status, is_current, technical_description_id FROM app.parcel_computations WHERE id = {$row['current_computation_id']}")->fetch(\PDO::FETCH_ASSOC);
                $this->assertSame('WITHIN_TOLERANCE', $comp['closure_status'], 'the child carries its own closure result');
                $this->assertTrue((bool) $comp['is_current']);
                $this->assertSame($tdWest, (int) $comp['technical_description_id']);
            }
        }
        $this->assertNotNull($tdChildId);

        // The explicit-geometry child has no computation row to point at.
        $explicitChild = null;
        foreach ($data['children'] as $child) {
            if ($child['lot_number'] === '100-TD-E') {
                $explicitChild = $child;
            }
        }
        $this->assertNotNull($explicitChild);
        $explicitId = (string) $explicitChild['parcel_id'];
        $explicitComp = $this->pdo->query("SELECT current_computation_id FROM app.parcels WHERE id = '{$explicitId}'")->fetchColumn();
        $this->assertNull($explicitComp);

        // Parent superseded; SUBDIVISION edges to both children.
        $this->assertSame('SUPERSEDED', $this->pdo->query("SELECT status FROM app.parcels WHERE id = '{$parentId}'")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM app.parcel_relationships WHERE parent_parcel_id = '{$parentId}' AND relationship_type = 'SUBDIVISION'")->fetchColumn());
    }

    public function testChildWithUncomputedTdIsRejected(): void
    {
        [$parentId] = $this->createComputedSquareParcel('TDSPLIT_PARENT_02', 100.0, 50.0);

        // A confirmed-but-uncalculated TD has no computed geometry.
        [$hostId, $tdUncomputed] = $this->createComputedSquareParcel('TDSPLIT_TDHOST_02', 50.0, 50.0);
        $this->pdo->exec("UPDATE app.parcel_computations SET geom = NULL WHERE technical_description_id = {$tdUncomputed}");

        [, $tdWest] = $this->createComputedSquareParcel('TDSPLIT_TDHOST_03', 50.0, 50.0);

        $body = [
            'method' => 'TECHNICAL_DESCRIPTION',
            'children' => [
                ['lot_number' => 'W', 'technical_description_id' => $tdWest],
                ['lot_number' => 'X', 'technical_description_id' => $tdUncomputed],
            ],
            'reason' => 'Uncomputed TD child must be rejected before any write',
        ];

        $res = $this->handle($this->req('POST', "/api/v1/parcels/{$parentId}/split?dry_run=true", $body));
        $this->assertSame(400, $res->getStatusCode(), (string) $res->getBody());
        $this->assertStringContainsString('no computed geometry', (string) $res->getBody());

        // Nothing written by the failed dry run.
        $this->assertSame('DRAFT', $this->pdo->query("SELECT status FROM app.parcels WHERE id = '{$parentId}'")->fetchColumn());
    }
}
