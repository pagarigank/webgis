<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Core\Error\ApiError;
use App\ImportExport\Application\ExportService;
use App\ImportExport\Domain\ExportScope;
use Tests\TestCase;

/**
 * TASK-127 — export formats, CRS rules, scope and the row cap.
 *
 * The point of these tests is that all five formats are produced by one service
 * and therefore cannot disagree: same scope, same CRS decision, same disclaimer.
 * Each format is opened and inspected rather than merely checked for a 200,
 * because a "successful" export of the wrong bytes is the failure that matters.
 */
class ExportFormatTest extends TestCase
{
    private int $layerId = 8811;
    private string $featureA = '88111111-2222-4000-8000-000000000001';
    private string $featureB = '88111111-2222-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();

        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type)
            VALUES ({$this->layerId}, 'TEST_EXPORT_FMT', 'Format Layer', 'POLYGON')
            ON CONFLICT (id) DO UPDATE SET name = 'Format Layer'
        ");
        $this->pdo()->exec("
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
            SELECT {$this->layerId}, id, true, true, true, true, true FROM app.roles WHERE code = 'SYS_ADMIN'
            ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true
        ");
        $this->pdo()->exec("
            INSERT INTO app.gis_layer_fields (layer_id, field_name, field_label, field_type, is_pii, sort_order)
            VALUES ({$this->layerId}, 'lot_label', 'Lot Label', 'text', false, 1)
            ON CONFLICT (layer_id, field_name) DO UPDATE SET field_label = EXCLUDED.field_label
        ");
        $this->pdo()->exec("
            INSERT INTO app.gis_features (id, layer_id, geom, attributes, status, version, created_by)
            VALUES (
                '{$this->featureA}', {$this->layerId},
                ST_GeomFromText('POLYGON((120.97 14.58, 120.99 14.58, 120.99 14.60, 120.97 14.60, 120.97 14.58))', 4326),
                '{\"lot_label\": \"Lot A\"}', 'ACTIVE', 1, 1
            ), (
                '{$this->featureB}', {$this->layerId},
                ST_GeomFromText('POLYGON((123.88 10.30, 123.90 10.30, 123.90 10.32, 123.88 10.32, 123.88 10.30))', 4326),
                '{\"lot_label\": \"Lot B\"}', 'ACTIVE', 1, 1
            )
        ");
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->cleanupSharedUsers();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $ids = "'{$this->featureA}','{$this->featureB}'";
        $this->pdo()->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.gis_features' AND entity_id IN ({$ids}, '{$this->layerId}')");
        $this->pdo()->exec("DELETE FROM audit.gis_feature_versions WHERE feature_id IN ({$ids})");
        $this->pdo()->exec("DELETE FROM app.gis_features WHERE id IN ({$ids})");
        $this->pdo()->exec("DELETE FROM app.export_jobs WHERE query_spec->>'layer_id' = '{$this->layerId}'");
        $this->pdo()->exec("DELETE FROM app.layer_permissions WHERE layer_id = {$this->layerId}");
        $this->pdo()->exec("DELETE FROM app.gis_layer_fields WHERE layer_id = {$this->layerId}");
        $this->pdo()->exec("DELETE FROM app.gis_layers WHERE id = {$this->layerId}");
    }

    // ------------------------------------------------------------------
    // One test per format: each artefact is opened and inspected.
    // ------------------------------------------------------------------

    public function testGeoJsonIsFeatureCollectionWithProvenanceMember(): void
    {
        $res = $this->export('GEOJSON');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/geo+json', $res->getHeaderLine('Content-Type'));

        $body = json_decode((string) $res->getBody(), true);
        $this->assertIsArray($body, 'GeoJSON must be valid JSON');
        $this->assertSame('FeatureCollection', $body['type']);
        $this->assertCount(2, $body['features']);
        $this->assertSame('Polygon', $body['features'][0]['geometry']['type']);

        // RFC 7946 allows foreign members, which is where the provenance lives.
        $this->assertArrayHasKey('x_webgis_export', $body);
        $this->assertSame('EPSG:4326', $body['x_webgis_export']['crs']);
        $this->assertSame(2, $body['x_webgis_export']['feature_count']);
        $this->assertStringContainsString('not a certified title document', $body['x_webgis_export']['disclaimer']);
    }

    public function testCsvCarriesDisclaimerInLeadingCommentLines(): void
    {
        $res = $this->export('CSV');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/csv', $res->getHeaderLine('Content-Type'));

        $csv = (string) $res->getBody();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertStringContainsString('not a certified title document', $csv);
        $this->assertStringStartsWith('#', $lines[0], 'first line must be a provenance comment');

        $headerIndex = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, 'id,status,')) {
                $headerIndex = $i;
            }
        }
        $this->assertNotNull($headerIndex);
        $this->assertStringContainsString('lot_label', $lines[$headerIndex]);
        $this->assertCount(2, array_slice($lines, $headerIndex + 1));
    }

    public function testKmlIsWellFormedXmlWithDisclaimerInDescription(): void
    {
        $res = $this->export('KML');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('kml', $res->getHeaderLine('Content-Type'));

        $body = (string) $res->getBody();

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($doc, 'KML must be well-formed XML');
        $this->assertStringContainsString('not a certified title document', $body);
        $this->assertStringContainsString('<Placemark>', $body);
        // KML coordinates are lon,lat in WGS 84.
        $this->assertMatchesRegularExpression('/<coordinates>\s*120\.9/', $body);
    }

    public function testShapefileIsZipWithSidecarsAndDisclaimerReadme(): void
    {
        $res = $this->export('SHAPEFILE');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/zip', $res->getHeaderLine('Content-Type'));

        $path = $this->writeTemp((string) $res->getBody());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Shapefile export must be a readable zip');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('layer_' . $this->layerId . '.shp', $names, 'zip must contain the .shp');
        $this->assertContains('layer_' . $this->layerId . '.shx', $names, 'zip must contain the .shx');
        $this->assertContains('layer_' . $this->layerId . '.dbf', $names, 'zip must contain the .dbf');
        $this->assertContains('README.txt', $names, 'zip must contain the provenance sidecar');

        $readme = (string) $zip->getFromName('README.txt');
        $this->assertStringContainsString('not a certified title document', $readme);

        $zip->close();
        @unlink($path);
    }

    public function testGeoPackageIsValidSqliteCarryingDisclaimerInMetadata(): void
    {
        $res = $this->export('GEOPACKAGE');
        $this->assertSame(200, $res->getStatusCode());

        $path = $this->writeTemp((string) $res->getBody());
        $this->assertTrue(is_file($path) && filesize($path) > 0);

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // 'GPKG' application id: 0x47504B47.
        $this->assertSame('1196444487', (string) $this->sqliteStmt($pdo, 'SELECT application_id FROM pragma_application_id')->fetchColumn());

        // GDAL writes a NATIVE_DATA row of its own, so the provenance row is
        // found by content rather than by position.
        $rows = $this->sqliteStmt($pdo, 'SELECT id, metadata FROM gpkg_metadata')->fetchAll(\PDO::FETCH_ASSOC);
        $carrying = array_values(array_filter(
            $rows,
            static fn (array $r) => str_contains((string) $r['metadata'], 'not a certified title document'),
        ));
        $this->assertNotEmpty($carrying, 'GeoPackage must carry provenance metadata');
        $this->assertSame('dataset', (string) $this->sqliteStmt($pdo, "SELECT md_scope FROM gpkg_metadata WHERE id = " . (int) $carrying[0]['id'])
            ->fetchColumn());

        // The metadata must also be referenced, or readers will not surface it.
        $refs = (int) $this->sqliteStmt($pdo, 'SELECT count(*) FROM gpkg_metadata_reference WHERE md_file_id = ' . (int) $carrying[0]['id'])
            ->fetchColumn();
        $this->assertSame(1, $refs, 'provenance metadata must have a reference row');

        @unlink($path);
    }

    // ------------------------------------------------------------------
    // Format negotiation
    // ------------------------------------------------------------------

    public function testFormatsEndpointDescribesEverySupportedFormat(): void
    {
        $req = $this->createRequest('GET', '/api/v1/exports/formats')
            ->withHeader('Authorization', 'Bearer ' . $this->authToken('fmtexport')['token']);
        $res = $this->handle($req);

        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true)['data'];

        $byFormat = [];
        foreach ($data['formats'] as $f) {
            $byFormat[$f['format']] = $f;
        }

        foreach (['GEOJSON', 'CSV', 'KML', 'SHAPEFILE', 'GEOPACKAGE'] as $format) {
            $this->assertArrayHasKey($format, $byFormat, "formats endpoint must advertise {$format}");
        }

        // Specs that pin WGS 84 must say so.
        $this->assertFalse($byFormat['GEOJSON']['crs_selectable']);
        $this->assertSame('EPSG:4326', $byFormat['GEOJSON']['fixed_crs']);
        $this->assertFalse($byFormat['KML']['crs_selectable']);
        $this->assertTrue($byFormat['SHAPEFILE']['crs_selectable']);

        $this->assertSame(ExportService::MAX_INLINE_ROWS, $data['max_inline_rows']);
        $this->assertStringContainsString('not a certified title document', $data['disclaimer']);
    }

    public function testUnknownFormatIsRejectedWithSupportedList(): void
    {
        $res = $this->exportJson('DXF');
        $this->assertSame(422, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame('EXPORT_FORMAT_UNSUPPORTED', $body['error']['code']);
    }

    public function testMissingFormatIsRejected(): void
    {
        $res = $this->exportJson('');
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testGeoJsonRejectsNonWgs84Crs(): void
    {
        $res = $this->exportJson('GEOJSON', ['crs' => 'EPSG:3857']);
        $this->assertSame(422, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame('CRS_NOT_ALLOWED_FOR_FORMAT', $body['error']['code']);
    }

    public function testShapefileAcceptsProjectedCrs(): void
    {
        // EPSG:3857 is registered in ref.crs_registry, and Shapefile may carry it.
        $registered = (int) $this->stmt("SELECT count(*) FROM ref.crs_registry WHERE UPPER(code) = 'EPSG:3857'")
            ->fetchColumn();
        if ($registered === 0) {
            $this->markTestSkipped('EPSG:3857 is not present in ref.crs_registry.');
        }

        $res = $this->exportJson('SHAPEFILE', ['crs' => 'EPSG:3857']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('EPSG:3857', $res->getHeaderLine('X-Export-Crs'));

        $path = $this->writeTemp((string) $res->getBody());
        $zip = new \ZipArchive();
        $zip->open($path);
        $prj = (string) $zip->getFromName('layer_' . $this->layerId . '.prj');
        $zip->close();
        @unlink($path);

        // A .prj is WKT, not an EPSG code. What matters is that it describes a
        // projected CRS rather than the geographic WGS 84 it started as.
        $this->assertNotSame('', $prj, 'a reprojected shapefile must carry a .prj');
        $this->assertStringContainsString('PROJCS', $prj);
        $this->assertStringContainsString('Mercator', $prj);
    }

    public function testUnregisteredCrsIsRejected(): void
    {
        $res = $this->exportJson('SHAPEFILE', ['crs' => 'EPSG:999999']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('CRS_UNSUPPORTED', json_decode((string) $res->getBody(), true)['error']['code']);
    }

    // ------------------------------------------------------------------
    // Scope
    // ------------------------------------------------------------------

    public function testBboxScopeSelectsOnlyOverlappingFeature(): void
    {
        $res = $this->export('GEOJSON', ['bbox' => '123.85,10.25,123.95,10.35']);
        $body = json_decode((string) $res->getBody(), true);

        $this->assertCount(1, $body['features']);
        $this->assertSame($this->featureB, $body['features'][0]['id']);
        $this->assertSame('1', $res->getHeaderLine('X-Export-Row-Count'));
    }

    public function testFeatureIdsScopeSelectsOnlyListedFeatures(): void
    {
        $res = $this->export('GEOJSON', ['feature_ids' => [$this->featureA]]);
        $body = json_decode((string) $res->getBody(), true);

        $this->assertCount(1, $body['features']);
        $this->assertSame($this->featureA, $body['features'][0]['id']);
    }

    public function testStatusScopeFiltersByLifecycleStatus(): void
    {
        $this->pdo()->exec("UPDATE app.gis_features SET status = 'ARCHIVED' WHERE id = '{$this->featureB}'");

        $res = $this->export('GEOJSON', ['status' => 'ACTIVE']);
        $body = json_decode((string) $res->getBody(), true);
        $this->assertCount(1, $body['features']);
        $this->assertSame($this->featureA, $body['features'][0]['id']);
    }

    public function testInvalidStatusIsRejected(): void
    {
        $res = $this->exportJson('GEOJSON', ['status' => 'NONSENSE']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testMalformedBboxIsRejected(): void
    {
        $res = $this->exportJson('GEOJSON', ['bbox' => '1,2,3']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testInvertedBboxIsRejected(): void
    {
        $res = $this->exportJson('GEOJSON', ['bbox' => '10,10,5,5']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testFeatureIdsAreCappedToBoundTheQuery(): void
    {
        $tooMany = [];
        for ($i = 0; $i <= ExportScope::MAX_EXPLICIT_IDS; $i++) {
            $tooMany[] = '00000000-0000-4000-8000-' . str_pad((string) $i, 12, '0', STR_PAD_LEFT);
        }

        // Sent in the JSON body: a list this long cannot survive a query string.
        $res = $this->handle(
            $this->createJsonRequest(
                'POST',
                "/api/v1/layers/{$this->layerId}/exports",
                ['format' => 'GEOJSON', 'feature_ids' => $tooMany],
            )->withHeader('Authorization', 'Bearer ' . $this->authToken('fmtexport')['token'])
        );

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('EXPORT_SCOPE_TOO_LARGE', json_decode((string) $res->getBody(), true)['error']['code']);
    }

    // ------------------------------------------------------------------
    // Row cap
    // ------------------------------------------------------------------

    /**
     * The cap is injected rather than simulated: building a service with a
     * limit of 1 exercises exactly the same branch as 50,000 without seeding
     * 50,000 rows.
     */
    public function testScopeAboveRowCapIsRefusedAndRecorded(): void
    {
        $service = $this->serviceWithRowCap(1);

        try {
            $service->export(
                $this->authToken('capexport')['id'],
                ExportScope::fromArray(['layer_id' => $this->layerId]),
                'GEOJSON',
            );
            $this->fail('Expected EXPORT_TOO_LARGE');
        } catch (ApiError $e) {
            $this->assertSame('EXPORT_TOO_LARGE', $e->getErrorCode());
            $this->assertSame(409, $e->getApiStatus());
        }

        $job = $this->stmt(
            "SELECT status, row_count FROM app.export_jobs
             WHERE requested_by = (
                 SELECT id FROM app.users WHERE username = 'capexport'
             ) ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('REJECTED', $job['status'], 'a refused export must still be recorded');
        $this->assertGreaterThan(1, (int) $job['row_count']);
    }

    public function testScopeAtExactlyTheCapIsAllowed(): void
    {
        $service = $this->serviceWithRowCap(2);
        $result = $service->export(
            $this->authToken('capok')['id'],
            ExportScope::fromArray(['layer_id' => $this->layerId]),
            'CSV',
        );

        $this->assertSame(2, $result->rowCount, 'a scope exactly at the cap must succeed');
        $this->assertStringContainsString('not a certified title document', $result->body);
    }

    // ------------------------------------------------------------------
    // Provenance headers and the ledger
    // ------------------------------------------------------------------

    public function testResponseHeadersReportProvenance(): void
    {
        $res = $this->export('GEOJSON');

        $this->assertSame('GEOJSON', $res->getHeaderLine('X-Export-Format'));
        $this->assertSame('EPSG:4326', $res->getHeaderLine('X-Export-Crs'));
        $this->assertSame('2', $res->getHeaderLine('X-Export-Row-Count'));
        $this->assertSame('true', $res->getHeaderLine('X-Export-Pii-Included'));
        $this->assertNotSame('', $res->getHeaderLine('X-Export-Job-Id'));
        $this->assertStringContainsString(
            'certified%20title',
            $res->getHeaderLine('X-Export-Disclaimer')
        );
    }

    public function testCompletedExportIsRecordedInTheLedger(): void
    {
        $res = $this->export('CSV');
        $jobId = (int) $res->getHeaderLine('X-Export-Job-Id');

        $job = $this->stmt("SELECT * FROM app.export_jobs WHERE id = {$jobId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($job, 'the returned job id must exist in app.export_jobs');
        $this->assertSame('COMPLETED', $job['status']);
        $this->assertSame('CSV', $job['format']);
        $this->assertSame(2, (int) $job['row_count']);
        $this->assertNull($job['document_id'], 'an inline export stores no document');
        $this->assertStringContainsString('not a certified title document', (string) $job['disclaimer']);

        $spec = json_decode((string) $job['query_spec'], true);
        $this->assertSame($this->layerId, (int) $spec['layer_id']);
    }

    public function testEveryFormatIsAudited(): void
    {
        foreach (['GEOJSON', 'CSV', 'KML', 'SHAPEFILE', 'GEOPACKAGE'] as $format) {
            $this->export($format);
        }

        $rows = $this->stmt(
            "SELECT new_values->>'format' AS format, new_values->>'disclaimer_carried' AS carried
             FROM audit.audit_logs
             WHERE action = 'EXPORT' AND entity_id = '{$this->layerId}'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(5, $rows, 'every format must produce an audit row');
        foreach ($rows as $row) {
            $this->assertSame('true', $row['carried'], 'audit must record that the disclaimer shipped');
        }
    }

    public function testHistoryEndpointReturnsRecordedExports(): void
    {
        $this->export('GEOJSON');

        $req = $this->createRequest('GET', '/api/v1/exports')
            ->withHeader('Authorization', 'Bearer ' . $this->authToken('fmtexport')['token']);
        $res = $this->handle($req);

        $this->assertSame(200, $res->getStatusCode());
        $exports = json_decode((string) $res->getBody(), true)['data']['exports'];
        $this->assertNotEmpty($exports);
        $this->assertArrayHasKey('query_spec', $exports[0]);
        $this->assertArrayHasKey('requested_by_username', $exports[0]);
    }

    public function testExportRequiresTheExportExecutePermission(): void
    {
        // SYS_ADMIN is the only seeded role holding export.execute, so a
        // GIS_EDITOR must be refused even with layer view granted.
        $this->pdo()->exec("
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
            SELECT {$this->layerId}, id, true, false, false, false, false FROM app.roles WHERE code = 'GIS_EDITOR'
            ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true
        ");

        $user = $this->authToken('noexportperm', 'GIS_EDITOR', 'GIS Editor');
        $res = $this->export('GEOJSON', [], $user['token']);

        $this->assertSame(403, $res->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     */
    private function export(string $format, array $query = [], ?string $token = null): \Psr\Http\Message\ResponseInterface
    {
        $token ??= $this->authToken('fmtexport')['token'];
        $query['format'] = $format;

        return $this->exportJson(null, $query, $token);
    }

    /**
     * @param array<string,mixed> $query
     */
    private function exportJson(?string $format, array $query = [], ?string $token = null): \Psr\Http\Message\ResponseInterface
    {
        $token ??= $this->authToken('fmtexport')['token'];
        if ($format !== null) {
            $query['format'] = $format;
        }

        $url = "/api/v1/layers/{$this->layerId}/exports";
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $req = $this->createJsonRequest('POST', $url, ['format' => $query['format'] ?? ''])
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $this->handle($req);
    }

    private function serviceWithRowCap(int $cap): ExportService
    {
        return new ExportService(
            $this->pdo(),
            $this->container()->get(\App\Audit\AuditWriter::class),
            $this->container()->get(\App\Core\Crs\CoordinateTransformationService::class),
            $this->container()->get(\App\ImportExport\Application\ExportFeatureReader::class),
            [
                'GEOJSON' => $this->container()->get(\App\ImportExport\Domain\Writer\GeoJsonExportWriter::class),
                'CSV'     => $this->container()->get(\App\ImportExport\Domain\Writer\CsvExportWriter::class),
                'KML'     => $this->container()->get(\App\ImportExport\Domain\Writer\KmlExportWriter::class),
            ],
            $cap,
        );
    }

    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($path, $bytes);
        return $path;
    }

    /**
     * PDO::query() is typed as PDOStatement|false. On ERRMODE_EXCEPTION a false
     * return is unreachable, so asserting it keeps the call sites on the
     * statement type rather than on a union.
     */
    private function stmt(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Same idea for the throwaway sqlite connections opened against an exported
     * file, which are not the shared test PDO.
     */
    private function sqliteStmt(\PDO $pdo, string $sql): \PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt;
    }
}
