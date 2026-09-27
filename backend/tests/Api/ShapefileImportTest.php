<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Core\Geo\OgrAdapter;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * TASK-124 — Shapefile (and the other OGR formats) import through the job
 * lifecycle, including the `.prj` suggestion and the area-of-use guard.
 *
 *  - the source CRS detected by OGR is surfaced as `suggested_crs` only; it is
 *    never auto-declared (FR-252/VR-52);
 *  - a wrong declared CRS is caught at validation/preview by the area-of-use
 *    check (VR-53) and can never reach a production table.
 *
 * Skipped where GDAL is unavailable so the suite stays green without it.
 */
class ShapefileImportTest extends TestCase
{
    private const LAYER_ID = 8806;
    private const FILENAME_PREFIX = 'SHPIMPTEST_';

    /** Two points in the Philippines (EPSG:4326). */
    private const POINTS_GEOJSON = <<<'JSON'
    {
      "type": "FeatureCollection",
      "features": [
        {"type": "Feature", "properties": {"name": "Alpha"},
         "geometry": {"type": "Point", "coordinates": [121.000, 14.500]}},
        {"type": "Feature", "properties": {"name": "Beta"},
         "geometry": {"type": "Point", "coordinates": [121.100, 14.600]}}
      ]
    }
    JSON;

    private string $storageDir = '/tmp/import-shapefile-test-storage';

    protected function setUp(): void
    {
        parent::setUp();

        $adapter = new OgrAdapter();
        if (!$adapter->isAvailable()) {
            $this->markTestSkipped('GDAL/ogr2ogr is not installed; Shapefile import tests skipped.');
        }

        putenv('DOCUMENTS_STORAGE_DIR=' . $this->storageDir);
        $_ENV['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $_SERVER['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $this->cleanup();

        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type, srid)
            VALUES (" . self::LAYER_ID . ", 'TEST_SHP_LAYER', 'Shapefile Layer', 'POINT', 4326)
            ON CONFLICT (id) DO UPDATE SET code = 'TEST_SHP_LAYER', geometry_type = 'POINT'
        ");
        $this->pdo()->exec("
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
            SELECT " . self::LAYER_ID . ", id, true, true, true, true, true FROM app.roles WHERE code = 'SYS_ADMIN'
            ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true, can_create = true
        ");
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->cleanupSharedUsers();
        parent::tearDown();
    }

    /**
     * A zipped shapefile imports through the pipeline; the `.prj` CRS is offered
     * as a suggestion only, and must be explicitly declared before committing.
     */
    public function testShapefileSuggestsCrsThenCommits(): void
    {
        $token = $this->authToken('shptestuser')['token'];
        $jobId = $this->createJob($token, $this->shapefileZip(), 'SHPIMPTEST_points.zip');

        $job = $this->getJob($token, $jobId);
        $this->assertSame('SHAPEFILE', $job['source_format']);
        // The `.prj` is a suggestion the user confirms — not an auto-declaration.
        $this->assertSame('EPSG:4326', $job['suggested_crs']);
        $this->assertNull($job['declared_crs']);
        $this->assertSame('UPLOADED', $job['status']);

        $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:4326']);

        $data = json_decode((string) $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token)->getBody(), true)['data'];
        $this->assertSame('VALIDATED', $data['status']);
        $this->assertSame(2, $data['total_rows']);
        $this->assertSame(2, $data['valid_rows']);
        $this->assertSame(0, $data['invalid_rows']);
        $this->assertContains('name', $data['validation_result']['detected_fields']);
        $this->assertSame(0, $data['validation_result']['area_of_use']['outside_rows']);
        $this->assertSame(0, $this->committedFeatureCount($jobId), 'validation must not touch production tables');

        $commit = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => false]);
        $this->assertSame(200, $commit->getStatusCode(), (string) $commit->getBody());
        $this->assertSame(2, json_decode((string) $commit->getBody(), true)['data']['committed_rows']);
        $this->assertSame(2, $this->committedFeatureCount($jobId));
    }

    /**
     * A shapefile whose data is in EPSG:4326, declared as a projected Philippine
     * CRS, must be flagged per row at preview and refused at commit (AC-09).
     */
    public function testWrongDeclaredCrsIsCaughtAtPreviewBeforeCommit(): void
    {
        $token = $this->authToken('shptestuser')['token'];
        $jobId = $this->createJob($token, $this->shapefileZip(), 'SHPIMPTEST_wrongcrs.zip');

        // EPSG:3121 (PRS92 Zone I) is projected with a Philippines area of use;
        // lon/lat degree coordinates interpreted as eastings/northings fall far
        // outside it.
        $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:3121']);

        $data = json_decode((string) $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token)->getBody(), true)['data'];
        $this->assertSame(2, $data['total_rows']);
        $this->assertSame(0, $data['valid_rows']);
        $this->assertSame(2, $data['invalid_rows']);
        $this->assertSame(2, $data['validation_result']['area_of_use']['outside_rows']);
        $this->assertSame(2, $data['validation_result']['error_summary']['VR-53']);

        $preview = json_decode((string) $this->request('GET', "/api/v1/imports/{$jobId}/preview", $token)->getBody(), true)['data'];
        $this->assertFalse($preview['rows'][0]['is_valid']);
        $this->assertSame('VR-53', $preview['rows'][0]['errors'][0]['rule']);
        $this->assertStringContainsString('area of use', $preview['rows'][0]['errors'][0]['message']);

        // Neither a strict nor a partial commit may land anything.
        $strict = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => false]);
        $this->assertSame(422, $strict->getStatusCode());
        $this->assertSame('IMPORT_INVALID', $this->errorCode($strict));

        $partial = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => true]);
        $this->assertSame(422, $partial->getStatusCode());
        $this->assertSame('IMPORT_INVALID', $this->errorCode($partial));

        $this->assertSame(0, $this->committedFeatureCount($jobId));
    }

    /**
     * The raw OGR reader reads a zipped shapefile into rows without reprojecting:
     * the geometry coordinates stay in the source CRS (lon/lat here).
     */
    public function testOgrRowReaderKeepsSourceCoordinates(): void
    {
        $reader = new \App\ImportExport\Domain\OgrRowReader(new OgrAdapter(), new \App\ImportExport\Domain\GeoJsonRowReader());

        $rows = $reader->read($this->shapefileZip(), 'SHAPEFILE');

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['row_number']);
        $this->assertSame('Alpha', $rows[0]['raw']['name']);

        $geometry = $rows[0]['geometry'];
        $this->assertIsArray($geometry);
        $this->assertSame('Point', $geometry['type']);

        $coordinates = $geometry['coordinates'] ?? null;
        $this->assertIsArray($coordinates);
        // No -t_srs: the source's own coordinates survive as-is.
        $this->assertEqualsWithDelta(121.000, $coordinates[0] ?? null, 0.0005);
        $this->assertEqualsWithDelta(14.500, $coordinates[1] ?? null, 0.0005);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Build a zipped shapefile from the point fixture and return the raw bytes. */
    private function shapefileZip(): string
    {
        $adapter = new OgrAdapter();
        $tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'shpimp_' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);

        try {
            $source = $tmp . DIRECTORY_SEPARATOR . 'points.geojson';
            file_put_contents($source, self::POINTS_GEOJSON);

            $shpDir = $tmp . DIRECTORY_SEPARATOR . 'shp';
            mkdir($shpDir);
            $adapter->convert($source, $shpDir, [], 'ESRI Shapefile');

            $zipPath = $tmp . DIRECTORY_SEPARATOR . 'points.zip';
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
            foreach (glob($shpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();

            return (string) file_get_contents($zipPath);
        } finally {
            $this->removeDir($tmp);
        }
    }

    private function createJob(string $token, string $content, string $filename): int
    {
        $stream = (new StreamFactory())->createStream($content);
        $upload = new UploadedFile($stream, $filename, 'application/zip', \strlen($content), UPLOAD_ERR_OK);

        $request = $this->createRequest('POST', '/api/v1/imports')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withUploadedFiles(['file' => $upload])
            ->withParsedBody([
                'target_entity'   => 'FEATURE',
                'target_layer_id' => self::LAYER_ID,
            ]);

        $res = $this->handle($request);
        if ($res->getStatusCode() !== 201) {
            $this->fail('Shapefile import job creation failed: ' . (string) $res->getBody());
        }
        return (int) json_decode((string) $res->getBody(), true)['data']['id'];
    }

    private function getJob(string $token, int $jobId): array
    {
        $res = $this->request('GET', "/api/v1/imports/{$jobId}", $token);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        return json_decode((string) $res->getBody(), true)['data'];
    }

    private function putMapping(string $token, int $jobId, array $body): ResponseInterface
    {
        return $this->request('PUT', "/api/v1/imports/{$jobId}/mapping", $token, $body);
    }

    private function request(string $method, string $path, string $token, ?array $body = null): ResponseInterface
    {
        $request = $this->createRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'application/json');

        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->handle($request);
    }

    private function errorCode(ResponseInterface $res): string
    {
        return (string) (json_decode((string) $res->getBody(), true)['error']['code'] ?? '');
    }

    private function committedFeatureCount(int $jobId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM app.gis_features
              WHERE source_document_id = (SELECT source_document_id FROM app.import_jobs WHERE id = :id)'
        );
        $stmt->execute([':id' => $jobId]);
        return (int) $stmt->fetchColumn();
    }

    private function cleanup(): void
    {
        $prefix = self::FILENAME_PREFIX;
        $this->pdo()->exec("
            DELETE FROM audit.gis_feature_versions WHERE feature_id IN (
                SELECT id FROM app.gis_features WHERE source_document_id IN (
                    SELECT source_document_id FROM app.import_jobs WHERE source_filename LIKE '$prefix%'
                )
            )
        ");
        $this->pdo()->exec("DELETE FROM app.gis_features WHERE source_document_id IN (SELECT source_document_id FROM app.import_jobs WHERE source_filename LIKE '$prefix%')");
        $this->pdo()->exec("DELETE FROM staging.import_job_rows WHERE job_id IN (SELECT id FROM app.import_jobs WHERE source_filename LIKE '$prefix%')");
        $this->pdo()->exec("DELETE FROM app.import_jobs WHERE source_filename LIKE '$prefix%'");
        $this->pdo()->exec("DELETE FROM app.documents WHERE original_filename LIKE '$prefix%'");
        $this->pdo()->exec('DELETE FROM app.layer_permissions WHERE layer_id = ' . self::LAYER_ID);
        $this->pdo()->exec('DELETE FROM app.gis_layers WHERE id = ' . self::LAYER_ID);
        foreach (glob($this->storageDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storageDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) && !is_link($child) ? $this->removeDir($child) : unlink($child);
        }
        rmdir($dir);
    }
}
