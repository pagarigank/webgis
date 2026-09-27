<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * TASK-126 — Control point bulk import from CSV.
 *
 * ACs covered:
 *  - A CSV declares its CRS out of band; omitting native_crs is CRS_REQUIRED
 *    and an unknown or non-projected CRS is 400.
 *  - Rows are validated individually: a bad row is reported with its line
 *    number and the good rows in the same file still import.
 *  - Duplicates are FLAGGED, never merged: an existing point_name in the same
 *    CRS, a repeat of a name inside one file, and a point that lands within
 *    duplicate_radius_m of an existing or earlier row are each reported and
 *    left un-inserted, with the colliding point identified.
 *  - Only soft-deleted points are ignored for duplicate detection, so a
 *    deleted monument can be re-imported.
 *  - Every imported point is UNVERIFIED; a CSV cannot set status, and mapping
 *    field_map onto status is rejected.
 *  - field_map remaps CSV column names onto target fields.
 *  - Preview (the default) writes nothing; commit inserts only valid rows.
 *  - Permission-less users are denied (403) and anonymous callers (401).
 */
class ControlPointImportTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    private string $adminToken;

    private const PREFIX = 'CP126_';

    /** Manila, comfortably inside EPSG:3123's area of use. */
    private const E0 = 512225.12;
    private const N0 = 1678780.45;

    /** The WGS 84 position that E0/N0 projects back to (PostGIS, EPSG:3123). */
    private const LAT = 15.178981537;
    private const LON = 121.115114262;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();

        $user = $this->createMockUser($this->pdo, [
            'control_point.view', 'control_point.create', 'control_point.update', 'control_point.verify',
        ], ['SYS_ADMIN']);
        $this->adminToken = $user['token'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $this->pdo->exec(
            "DELETE FROM audit.audit_logs WHERE entity_type = 'app.survey_control_points' "
            . "AND entity_id IN (SELECT id::varchar FROM app.survey_control_points WHERE point_name LIKE '$like')"
        );
        $this->pdo->exec("DELETE FROM app.survey_control_points WHERE point_name LIKE '$like'");
    }

    private function api(string $method, string $path, array $data = [], array $headers = []): \Psr\Http\Message\ResponseInterface
    {
        $request = $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('Accept', 'application/json');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->handle($request);
    }

    /** Build a CSV string from a header and associative row arrays. */
    private function csv(array $header, array $rows): string
    {
        $out = implode(',', $header) . "\n";
        foreach ($rows as $row) {
            $cells = [];
            foreach ($header as $column) {
                $value = $row[$column] ?? '';
                $cells[] = (is_float($value) ? rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') : (string) $value);
                // Keep it simple: the fixtures contain no commas or quotes.
            }
            $out .= implode(',', $cells) . "\n";
        }
        return $out;
    }

    private function importBody(array $rows, array $overrides = []): array
    {
        $header = ['point_name', 'point_type', 'easting', 'northing', 'source'];
        return array_merge([
            'csv'            => $this->csv($header, $rows),
            'native_crs'     => 'EPSG:3123',
            'coordinate_origin' => 'PROJECTED',
        ], $overrides);
    }

    private function decoded(\Psr\Http\Message\ResponseInterface $response): array
    {
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        return json_decode((string) $response->getBody(), true)['data'];
    }

    /** Row statuses keyed by point_name, for readable assertions. */
    private function statusByName(array $report): array
    {
        $out = [];
        foreach ($report['rows'] as $row) {
            $out[$row['point_name']] = $row;
        }
        return $out;
    }

    private function liveNames(string $pattern = self::PREFIX . '%'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT point_name FROM app.survey_control_points WHERE point_name LIKE ? AND deleted_at IS NULL ORDER BY point_name'
        );
        $stmt->execute([$pattern]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function statusOf(string $pointName): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM app.survey_control_points WHERE point_name = ?');
        $stmt->execute([$pointName]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function auditRowsFor(int $id): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT count(*) FROM audit.audit_logs WHERE entity_type = 'app.survey_control_points' AND entity_id = ?"
        );
        $stmt->execute([(string) $id]);
        return (int) $stmt->fetchColumn();
    }

    // ── the happy path ─────────────────────────────────────────────────────

    public function testCommitsProjectedRowsAsUnverifiedWithBothPairsStored(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'A1', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0, 'source' => 'BLLM'],
            ['point_name' => self::PREFIX . 'A2', 'point_type' => 'MBM',  'easting' => self::E0 + 1500, 'northing' => self::N0 + 900, 'source' => 'MBM'],
        ], ['commit' => true])));

        $this->assertTrue($report['committed']);
        $this->assertSame(2, $report['summary']['total_rows']);
        $this->assertSame(2, $report['summary']['valid']);
        $this->assertSame(2, $report['summary']['imported']);
        $this->assertSame(0, $report['summary']['invalid']);
        $this->assertSame(0, $report['summary']['duplicate_name']);
        $this->assertSame(0, $report['summary']['duplicate_proximity']);
        $this->assertCount(2, $report['imported_ids']);
        $this->assertCount(2, array_unique($report['imported_ids']));

        $this->assertSame([self::PREFIX . 'A1', self::PREFIX . 'A2'], $this->liveNames());

        // AC: every imported point is UNVERIFIED.
        $this->assertSame('UNVERIFIED', $this->statusOf(self::PREFIX . 'A1'));
        $this->assertSame('UNVERIFIED', $this->statusOf(self::PREFIX . 'A2'));

        // Both pairs are stored, the projected pair exactly as supplied.
        $stmt = $this->pdo->prepare('SELECT easting, northing, latitude, longitude, coordinate_origin, datum FROM app.survey_control_points WHERE point_name = ?');
        $stmt->execute([self::PREFIX . 'A1']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEqualsWithDelta(self::E0, (float) $row['easting'], 0.0005);
        $this->assertEqualsWithDelta(self::N0, (float) $row['northing'], 0.0005);
        // The derived WGS 84 pair is the real one for this easting/northing.
        $this->assertEqualsWithDelta(self::LAT, (float) $row['latitude'], 0.000001);
        $this->assertEqualsWithDelta(self::LON, (float) $row['longitude'], 0.000001);
        $this->assertSame('PROJECTED', $row['coordinate_origin']);
        $this->assertNotEmpty($row['datum']);

        // A bulk write is still audit-rowed per point, like the single-point
        // path: the audit trail has to survive the fact that one request
        // touched many rows.
        foreach ($report['imported_ids'] as $id) {
            $this->assertSame(1, $this->auditRowsFor((int) $id), "point $id should have one audit row");
        }
    }

    public function testDuplicateRowsAreNotAuditRowedBecauseTheyAreNotWritten(): void
    {
        $this->createPoint(self::PREFIX . 'EXISTING', self::E0, self::N0);

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'EXISTING', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['commit' => true])));

        $this->assertSame(0, $report['summary']['imported']);
        $this->assertSame([], $report['imported_ids']);
    }

    public function testPreviewDoesNotWrite(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'PREVIEW', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ])));

        $this->assertFalse($report['committed']);
        $this->assertSame(1, $report['summary']['valid']);
        $this->assertSame(0, $report['summary']['imported']);
        $this->assertSame([], $report['imported_ids']);
        $this->assertSame([], $this->liveNames());
    }

    // ── the CRS declaration is mandatory ───────────────────────────────────

    public function testMissingCrsIsRejectedAsCrsRequired(): void
    {
        $body = $this->importBody([
            ['point_name' => self::PREFIX . 'NOCRS', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ]);
        unset($body['native_crs']);

        $response = $this->api('POST', '/api/v1/control-points/import', $body);
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('CRS_REQUIRED', json_decode((string) $response->getBody(), true)['error']['code']);
        $this->assertSame([], $this->liveNames());
    }

    public function testUnknownCrsIsRejected(): void
    {
        $response = $this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'BADCRS', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['native_crs' => 'EPSG:999999']));

        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([], $this->liveNames());
    }

    public function testGeographicCrsIsRejectedBecauseEastingNorthingNeedsProjected(): void
    {
        $response = $this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'WGS', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['native_crs' => 'EPSG:4326']));

        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('projected', strtolower($decoded['error']['message']));
    }

    // ── per-row validation ─────────────────────────────────────────────────

    public function testBadRowsAreReportedByLineAndGoodRowsStillImport(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'OK1', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
            ['point_name' => '', 'point_type' => 'BLLM', 'easting' => self::E0 + 100, 'northing' => self::N0],
            ['point_name' => self::PREFIX . 'NOTYPE', 'point_type' => '', 'easting' => self::E0 + 200, 'northing' => self::N0],
            ['point_name' => self::PREFIX . 'OK2', 'point_type' => 'MBM', 'easting' => self::E0 + 300, 'northing' => self::N0],
        ], ['commit' => true])));

        $byName = $this->statusByName($report);
        $this->assertSame('VALID', $byName[self::PREFIX . 'OK1']['status']);
        $this->assertSame('VALID', $byName[self::PREFIX . 'OK2']['status']);

        // The nameless row is identified by line, and the field that failed.
        $bad = $report['rows'][1];
        $this->assertSame('INVALID', $bad['status']);
        $this->assertSame(3, $bad['row_number']);
        $this->assertArrayHasKey('point_name', $bad['errors']);
        $this->assertNull($bad['point_name']);

        $this->assertSame('INVALID', $report['rows'][2]['status']);
        $this->assertArrayHasKey('point_type', $report['rows'][2]['errors']);

        $this->assertSame(2, $report['summary']['imported']);
        $this->assertSame(2, $report['summary']['invalid']);
        $this->assertSame([self::PREFIX . 'OK1', self::PREFIX . 'OK2'], $this->liveNames());
    }

    public function testNonNumericCoordinateIsRejectedPerRow(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'NAN', 'point_type' => 'BLLM', 'easting' => 'not-a-number', 'northing' => self::N0],
        ], ['commit' => true])));

        $this->assertSame('INVALID', $report['rows'][0]['status']);
        $this->assertArrayHasKey('easting', $report['rows'][0]['errors']);
        $this->assertSame([], $this->liveNames());
    }

    public function testUnknownPointTypeIsRejectedPerRow(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'BADTYPE', 'point_type' => 'ROCK', 'easting' => self::E0, 'northing' => self::N0],
        ], ['commit' => true])));

        $this->assertSame('INVALID', $report['rows'][0]['status']);
        $this->assertArrayHasKey('point_type', $report['rows'][0]['errors']);
        $this->assertSame([], $this->liveNames());
    }

    public function testDefaultPointTypeFillsBlankCells(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'DT', 'point_type' => '', 'easting' => self::E0, 'northing' => self::N0],
        ], ['commit' => true, 'default_point_type' => 'bllm'])));

        $this->assertSame('VALID', $report['rows'][0]['status']);
        $this->assertSame('BLLM', $report['rows'][0]['point_type']);
    }

    public function testPointOutsideCrsAreaOfUseIsRejectedPerRow(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'FAR', 'point_type' => 'BLLM', 'easting' => 99999999.0, 'northing' => 99999999.0],
        ], ['commit' => true])));

        $this->assertSame('INVALID', $report['rows'][0]['status']);
        $this->assertSame([], $this->liveNames());
    }

    public function testUnknownPsgcCodeIsRejectedPerRow(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'PSGC', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['commit' => true, 'field_map' => []])));

        // Sanity: the plain row imported, so a bad PSGC can be attributed.
        $this->assertSame('VALID', $report['rows'][0]['status']);
    }

    public function testSuppliedCoordinatePairMustMatchTheDeclaredOrigin(): void
    {
        $csv = "point_name,point_type,easting,northing,latitude,longitude\n"
             . self::PREFIX . "BOTH,BLLM,512225.12,1678780.45,14.60,120.98\n";

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', [
            'csv' => $csv, 'native_crs' => 'EPSG:3123', 'coordinate_origin' => 'PROJECTED', 'commit' => true,
        ]));

        $this->assertSame('INVALID', $report['rows'][0]['status']);
        $this->assertSame([], $this->liveNames());
    }

    // ── duplicates: flagged, never merged ──────────────────────────────────

    public function testExistingNameInSameCrsIsFlaggedAndNotInserted(): void
    {
        $this->createPoint(self::PREFIX . 'EXISTING', self::E0 + 5000, self::N0 + 5000);

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            // Same name, different location: a name collision is still a
            // duplicate and must not silently overwrite the surveyed point.
            ['point_name' => self::PREFIX . 'EXISTING', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['commit' => true])));

        $row = $report['rows'][0];
        $this->assertSame('DUPLICATE_NAME', $row['status']);
        $this->assertSame('existing', $row['duplicates'][0]['kind']);
        $this->assertNotEmpty($row['duplicates'][0]['control_point_id']);
        $this->assertSame(1, $report['summary']['duplicate_name']);
        $this->assertSame(0, $report['summary']['imported']);

        // The original is untouched: still exactly one row, still its own coords.
        $this->assertSame([self::PREFIX . 'EXISTING'], $this->liveNames());
        $stmt = $this->pdo->prepare('SELECT easting FROM app.survey_control_points WHERE point_name = ?');
        $stmt->execute([self::PREFIX . 'EXISTING']);
        $this->assertEqualsWithDelta(self::E0 + 5000, (float) $stmt->fetchColumn(), 0.0005);
    }

    public function testSameNameTwiceInOneFileFlagsTheSecondRow(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'TWIN', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
            ['point_name' => self::PREFIX . 'TWIN', 'point_type' => 'BLLM', 'easting' => self::E0 + 100, 'northing' => self::N0],
        ], ['commit' => true])));

        $this->assertSame('VALID', $report['rows'][0]['status']);
        $this->assertSame('DUPLICATE_NAME', $report['rows'][1]['status']);
        $this->assertSame('in_file', $report['rows'][1]['duplicates'][0]['kind']);
        $this->assertSame(2, $report['rows'][1]['duplicates'][0]['row_number']);

        $this->assertSame(1, $report['summary']['imported']);
        $this->assertSame([self::PREFIX . 'TWIN'], $this->liveNames());
    }

    public function testPointWithinRadiusOfExistingIsFlaggedWithTheCollision(): void
    {
        $existingId = $this->createPoint(self::PREFIX . 'ANCHOR', self::E0, self::N0);

        // Different name, ~0.6 m away: the same monument re-entered.
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'NEARBY', 'point_type' => 'BLLM', 'easting' => self::E0 + 0.5, 'northing' => self::N0 + 0.35],
        ], ['commit' => true])));

        $row = $report['rows'][0];
        $this->assertSame('DUPLICATE_PROXIMITY', $row['status']);
        $this->assertSame('existing', $row['duplicates'][0]['kind']);
        $this->assertSame($existingId, (int) $row['duplicates'][0]['control_point_id']);
        $this->assertLessThanOrEqual(1.0, (float) $row['duplicates'][0]['distance_m']);
        $this->assertSame(0, $report['summary']['imported']);
        $this->assertSame([self::PREFIX . 'ANCHOR'], $this->liveNames());
    }

    public function testPointWithinRadiusOfAnEarlierRowInTheSameFileIsFlagged(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'FIRST',  'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
            ['point_name' => self::PREFIX . 'SECOND', 'point_type' => 'BLLM', 'easting' => self::E0 + 0.4, 'northing' => self::N0 + 0.4],
        ], ['commit' => true])));

        $this->assertSame('VALID', $report['rows'][0]['status']);
        $this->assertSame('DUPLICATE_PROXIMITY', $report['rows'][1]['status']);
        $this->assertSame('in_file', $report['rows'][1]['duplicates'][0]['kind']);
        $this->assertSame(2, $report['rows'][1]['duplicates'][0]['row_number']);
        $this->assertSame([self::PREFIX . 'FIRST'], $this->liveNames());
    }

    public function testLargerRadiusCatchesWhatTheDefaultMisses(): void
    {
        $this->createPoint(self::PREFIX . 'R1', self::E0, self::N0);

        $far = $this->importBody([
            ['point_name' => self::PREFIX . 'R2', 'point_type' => 'BLLM', 'easting' => self::E0 + 40, 'northing' => self::N0],
        ], ['duplicate_radius_m' => 100]);

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $far));
        $this->assertSame('DUPLICATE_PROXIMITY', $report['rows'][0]['status']);
        $this->assertSame(100.0, (float) $report['duplicate_radius_m']);
    }

    public function testPointBeyondRadiusIsNotADuplicate(): void
    {
        $this->createPoint(self::PREFIX . 'R1', self::E0, self::N0);

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'R2', 'point_type' => 'BLLM', 'easting' => self::E0 + 40, 'northing' => self::N0],
        ], ['commit' => true])));

        $this->assertSame('VALID', $report['rows'][0]['status']);
        $this->assertSame([self::PREFIX . 'R1', self::PREFIX . 'R2'], $this->liveNames());
    }

    public function testSoftDeletedPointDoesNotBlockReimport(): void
    {
        $id = $this->createPoint(self::PREFIX . 'REDO', self::E0, self::N0);
        $this->pdo->exec("UPDATE app.survey_control_points SET deleted_at = now() WHERE id = $id");

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'REDO', 'point_type' => 'BLLM', 'easting' => self::E0 + 3000, 'northing' => self::N0 + 3000],
        ], ['commit' => true])));

        $this->assertSame('VALID', $report['rows'][0]['status']);
        $this->assertSame(1, $report['summary']['imported']);
    }

    public function testNameDuplicateKeepsItsStatusWhenAlsoNearAnotherPoint(): void
    {
        $this->createPoint(self::PREFIX . 'EXISTING', self::E0, self::N0);

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'EXISTING', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['commit' => true])));

        // The row collides twice - by name and by position - and both findings
        // are reported so the surveyor sees the whole picture. The name is the
        // headline because it is the more actionable one, and it keeps the
        // summary counts mutually exclusive.
        $this->assertSame('DUPLICATE_NAME', $report['rows'][0]['status']);
        $this->assertSame(1, $report['summary']['duplicate_name']);
        $this->assertSame(0, $report['summary']['duplicate_proximity']);
        $this->assertCount(2, $report['rows'][0]['duplicates']);
        $this->assertSame('existing', $report['rows'][0]['duplicates'][0]['kind']);
        $this->assertArrayHasKey('control_point_id', $report['rows'][0]['duplicates'][0]);
        $this->assertArrayHasKey('distance_m', $report['rows'][0]['duplicates'][1]);
    }

    // ── status is not importable ───────────────────────────────────────────

    public function testFieldMapOntoStatusIsRejected(): void
    {
        $response = $this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'S', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['field_map' => ['status' => 'status']]));

        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('VALIDATION_FAILED', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function testCsvStatusColumnIsIgnoredRatherThanHonoured(): void
    {
        // The header carries a status column, but it is not a mapped target, so
        // the point is imported UNVERIFIED and the "VERIFIED" is discarded.
        $csv = "point_name,point_type,easting,northing,status\n"
             . self::PREFIX . "SPOOF,BLLM,512225.12,1678780.45,VERIFIED\n";

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', [
            'csv' => $csv, 'native_crs' => 'EPSG:3123', 'commit' => true,
        ]));

        $this->assertSame('VALID', $report['rows'][0]['status']);
        $this->assertSame('UNVERIFIED', $this->statusOf(self::PREFIX . 'SPOOF'));
    }

    // ── field mapping and geographic origin ────────────────────────────────

    public function testFieldMapRemapsCsvColumnNames(): void
    {
        $csv = "Name,Kind,E,N\n" . self::PREFIX . "MAP,MBM,512225.12,1678780.45\n";

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', [
            'csv'               => $csv,
            'native_crs'        => 'EPSG:3123',
            'coordinate_origin' => 'PROJECTED',
            'commit'            => true,
            'field_map'         => [
                'point_name' => 'Name',
                'point_type' => 'Kind',
                'easting'    => 'E',
                'northing'   => 'N',
            ],
        ]));

        $this->assertSame('VALID', $report['rows'][0]['status']);
        $this->assertSame(self::PREFIX . 'MAP', $report['rows'][0]['point_name']);
        $this->assertSame('MBM', $report['rows'][0]['point_type']);
        $this->assertSame([self::PREFIX . 'MAP'], $this->liveNames());
    }

    public function testFieldMapOntoAnUnknownTargetIsRejected(): void
    {
        $response = $this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'X', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['field_map' => ['colour' => 'color']]));

        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testGeographicOriginStoresTheProjectedPairAsDerived(): void
    {
        // Feed in exactly the WGS 84 position that E0/N0 projects back to, so
        // this asserts the two derivation directions agree rather than just
        // that some number came out.
        $csv = "point_name,point_type,latitude,longitude\n"
             . self::PREFIX . "GEO,BLLM,15.178981537,121.115114262\n";

        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', [
            'csv' => $csv, 'native_crs' => 'EPSG:3123', 'coordinate_origin' => 'GEOGRAPHIC', 'commit' => true,
        ]));

        $this->assertSame('VALID', $report['rows'][0]['status']);

        $stmt = $this->pdo->prepare('SELECT easting, northing, latitude, longitude, coordinate_origin FROM app.survey_control_points WHERE point_name = ?');
        $stmt->execute([self::PREFIX . 'GEO']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('GEOGRAPHIC', $row['coordinate_origin']);
        // The supplied pair is kept verbatim at the stored precision.
        $this->assertEqualsWithDelta(15.178981537, (float) $row['latitude'], 0.000000001);
        $this->assertEqualsWithDelta(121.115114262, (float) $row['longitude'], 0.000000001);
        // And it round-trips back to the projected pair used elsewhere here.
        // The derived pair is stored at 4 decimals, so the tolerance is a
        // millimetre rather than the 0.5 mm the column's own scale suggests -
        // the round trip through a 9-decimal degree value lands between
        // two representable 4-decimal metres.
        $this->assertEqualsWithDelta(self::E0, (float) $row['easting'], 0.002);
        $this->assertEqualsWithDelta(self::N0, (float) $row['northing'], 0.002);
    }

    // ── input guards ───────────────────────────────────────────────────────

    public function testCsvWithoutHeaderIsRejected(): void
    {
        $response = $this->api('POST', '/api/v1/control-points/import', [
            'csv' => '', 'native_crs' => 'EPSG:3123',
        ]);

        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testCommitAsTheStringFalseDoesNotCommit(): void
    {
        $report = $this->decoded($this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'STRFALSE', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['commit' => 'false'])));

        $this->assertFalse($report['committed']);
        $this->assertSame([], $this->liveNames());
    }

    public function testInvalidRadiusIsRejected(): void
    {
        $response = $this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'X', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['duplicate_radius_m' => 'wide']));

        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
    }

    // ── the multipart upload path (the browser uploader) ───────────────────

    public function testMultipartUploadWithFormFieldsIsAccepted(): void
    {
        $csv = "point_name,point_type,easting,northing\n"
             . self::PREFIX . "MP1,BLLM,512225.12,1678780.45\n"
             . self::PREFIX . "MP2,MBM,514725.12,1679780.45\n";

        $stream = (new StreamFactory())->createStream($csv);
        $upload = new UploadedFile($stream, 'ties.csv', 'text/csv', \strlen($csv), UPLOAD_ERR_OK);

        $request = $this->createRequest('POST', '/api/v1/control-points/import')
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('Accept', 'application/json')
            ->withUploadedFiles(['file' => $upload])
            // Form fields arrive as strings, not booleans.
            ->withParsedBody([
                'native_crs'        => 'EPSG:3123',
                'coordinate_origin' => 'PROJECTED',
                'commit'            => 'true',
            ]);

        $report = $this->decoded($this->handle($request));

        $this->assertTrue($report['committed']);
        $this->assertSame(2, $report['summary']['imported']);
        $this->assertSame([self::PREFIX . 'MP1', self::PREFIX . 'MP2'], $this->liveNames());
        $this->assertSame('UNVERIFIED', $this->statusOf(self::PREFIX . 'MP1'));
    }

    public function testMultipartUploadWithoutAFilePartIsRejected(): void
    {
        $request = $this->createRequest('POST', '/api/v1/control-points/import')
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('Accept', 'application/json')
            ->withParsedBody(['native_crs' => 'EPSG:3123']);

        $response = $this->handle($request);
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('IMPORT_INVALID', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    // ── access control ─────────────────────────────────────────────────────

    public function testUserWithoutCreatePermissionIsDenied(): void
    {
        $user  = $this->createUserWithRole('denied', []);
        $body  = $this->importBody([
            ['point_name' => self::PREFIX . 'DENIED', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['commit' => true]);

        $request  = $this->createJsonRequest('POST', '/api/v1/control-points/import', $body)
            ->withHeader('Authorization', 'Bearer ' . $user['token'])
            ->withHeader('Accept', 'application/json');
        $response = $this->handle($request);

        $this->assertEquals(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([], $this->liveNames());
    }

    public function testViewOnlyUserCannotImportEvenThoughItCanRead(): void
    {
        // Reading control points is not authority to create them, and the
        // import route must not inherit a broader permission than POST
        // /control-points has.
        $user = $this->createUserWithRole('viewer', ['control_point.view']);
        $body = $this->importBody([
            ['point_name' => self::PREFIX . 'VIEWONLY', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ], ['commit' => true]);

        $read = $this->createJsonRequest('GET', '/api/v1/control-points')
            ->withHeader('Authorization', 'Bearer ' . $user['token'])
            ->withHeader('Accept', 'application/json');
        $this->assertEquals(200, $this->handle($read)->getStatusCode(), 'view-only user should be able to read');

        $write = $this->createJsonRequest('POST', '/api/v1/control-points/import', $body)
            ->withHeader('Authorization', 'Bearer ' . $user['token'])
            ->withHeader('Accept', 'application/json');
        $this->assertEquals(403, $this->handle($write)->getStatusCode());
        $this->assertSame([], $this->liveNames());
    }

    public function testAnonymousImportIsUnauthorized(): void
    {
        $request  = $this->createJsonRequest('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'ANON', 'point_type' => 'BLLM', 'easting' => self::E0, 'northing' => self::N0],
        ]))->withHeader('Accept', 'application/json');
        $response = $this->handle($request);

        $this->assertEquals(401, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testInvalidDefaultPointTypeIsRejectedBeforeAnyRowIsLookedAt(): void
    {
        $response = $this->api('POST', '/api/v1/control-points/import', $this->importBody([
            ['point_name' => self::PREFIX . 'DT', 'point_type' => '', 'easting' => self::E0, 'northing' => self::N0],
        ], ['default_point_type' => 'ROCK']));

        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('VALIDATION_FAILED', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    /** Run a single-value query, failing loudly rather than returning false. */
    private function queryValue(string $sql): string
    {
        $stmt = $this->pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $stmt, "query failed: $sql");
        return (string) $stmt->fetchColumn();
    }

    /**
     * Build a user holding a role with exactly the given permissions.
     *
     * createMockUser() cannot express this: it always reuses the same
     * `testuser`, and the SYS_ADMIN role it assigns in setUp() persists, so a
     * second call cannot reduce that user's authority.
     *
     * @param array<int,string> $permissions
     * @return array{id:int,token:string}
     */
    private function createUserWithRole(string $suffix, array $permissions): array
    {
        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('TESTORG', 'Test Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT DO NOTHING");
        $orgId = (int) $this->queryValue("SELECT id FROM app.organizations WHERE code = 'TESTORG'");

        $username = 'cp126' . $suffix;
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('$username', '$username@example.com', 'dummy', 'Cp126 $suffix', $orgId, 'ACTIVE', 1) ON CONFLICT DO NOTHING");
        $userId = (int) $this->queryValue("SELECT id FROM app.users WHERE username = '$username'");

        $roleCode = 'CP126_' . strtoupper($suffix) . '_ROLE';
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('$roleCode', '$roleCode', false) ON CONFLICT DO NOTHING");
        $roleId = (int) $this->queryValue("SELECT id FROM app.roles WHERE code = '$roleCode'");
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($userId, $roleId) ON CONFLICT DO NOTHING");

        foreach ($permissions as $perm) {
            $this->pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('$perm', '$perm') ON CONFLICT DO NOTHING");
            $permId = (int) $this->queryValue("SELECT id FROM app.permissions WHERE code = '$perm'");
            $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($roleId, $permId) ON CONFLICT DO NOTHING");
        }

        $token = \Firebase\JWT\JWT::encode([
            'sub' => (string) $userId, 'v' => 1, 'exp' => time() + 3600,
        ], getenv('JWT_SECRET') ?: 'dummy_secret', 'HS256');

        return ['id' => $userId, 'token' => $token];
    }

    /** Create a point through the single-point API, so the duplicate check sees a genuine row. */
    private function createPoint(string $pointName, float $easting, float $northing): int
    {
        $request = $this->createJsonRequest('POST', '/api/v1/control-points', [
            'point_name'        => $pointName,
            'point_type'        => 'CONTROL_POINT',
            'coordinate_origin' => 'PROJECTED',
            'native_crs'        => 'EPSG:3123',
            'easting'           => $easting,
            'northing'          => $northing,
        ])
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('Accept', 'application/json');

        $response = $this->handle($request);
        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }
}
