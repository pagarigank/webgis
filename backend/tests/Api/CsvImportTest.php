<?php
declare(strict_types=1);

namespace Tests\Api;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * TASK-123 — CSV importer: coordinate-column mapping and per-row rejection.
 */
class CsvImportTest extends TestCase
{
    private const LAYER_ID = 8804;
    private const FILENAME_PREFIX = 'CSVTEST_';

    private string $storageDir = '/tmp/import-csv-test-storage';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DOCUMENTS_STORAGE_DIR=' . $this->storageDir);
        $_ENV['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $_SERVER['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $this->cleanup();

        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type, srid)
            VALUES (" . self::LAYER_ID . ", 'TEST_CSV_LAYER', 'CSV Point Layer', 'POINT', 4326)
            ON CONFLICT (id) DO UPDATE SET code = 'TEST_CSV_LAYER', geometry_type = 'POINT'
        ");
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->cleanupSharedUsers();
        parent::tearDown();
    }

    public function testCoordinatesAreMappedAndBadRowsRejectedIndividually(): void
    {
        $token = $this->authToken('csvtestuser')['token'];

        $csv = "name,lat,lon\nAlpha,14.500,121.000\nBadRow,not-a-number,121.000\nGamma,14.600,121.100\n";
        $jobId = $this->createJob($token, $csv, 'CSVTEST_points.csv', [
            'coordinate_columns' => ['latitude' => 'lat', 'longitude' => 'lon'],
        ]);

        $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:4326']);

        $res = $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertSame('VALIDATED', $data['status']);
        $this->assertSame(3, $data['total_rows']);
        $this->assertSame(2, $data['valid_rows']);
        $this->assertSame(1, $data['invalid_rows']);
        $this->assertContains('POINT', $data['validation_result']['geometry_types']);
        $this->assertSame(0, $this->committedFeatureCount($jobId));

        // The rejected row carries its number and a reason.
        $preview = json_decode((string) $this->request('GET', "/api/v1/imports/{$jobId}/preview", $token)->getBody(), true)['data'];
        $rows = $preview['rows'];
        $this->assertSame(2, $rows[1]['row_number']);
        $this->assertFalse($rows[1]['is_valid']);
        $this->assertSame('VR-32', $rows[1]['errors'][0]['rule']);

        $csvRes = $this->request('GET', "/api/v1/imports/{$jobId}/errors", $token);
        $this->assertSame(200, $csvRes->getStatusCode());
        $this->assertStringContainsString('VR-32', (string) $csvRes->getBody());
        $this->assertStringContainsString('BadRow', (string) $csvRes->getBody());

        // Commit the two valid points.
        $commit = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => true]);
        $this->assertSame(200, $commit->getStatusCode(), (string) $commit->getBody());
        $this->assertSame(2, json_decode((string) $commit->getBody(), true)['data']['committed_rows']);
        $this->assertSame(2, $this->committedFeatureCount($jobId));

        $geom = $this->pdo()->query(
            "SELECT ST_AsText(geom) FROM app.gis_features
              WHERE source_document_id = (SELECT source_document_id FROM app.import_jobs WHERE id = $jobId)
              ORDER BY ST_X(geom) LIMIT 1"
        );
        $this->assertNotFalse($geom);
        $this->assertSame('POINT(121 14.5)', (string) $geom->fetchColumn());
    }

    public function testRowsWithoutCoordinatesAreRejectedNotDropped(): void
    {
        $token = $this->authToken('csvtestuser')['token'];

        // No coordinate_columns option: every row has a null geometry.
        $csv = "name,lat,lon\nAlpha,14.5,121.0\nBeta,14.6,121.1\n";
        $jobId = $this->createJob($token, $csv, 'CSVTEST_nocols.csv', []);
        $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:4326']);

        $data = json_decode((string) $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token)->getBody(), true)['data'];
        $this->assertSame(2, $data['total_rows']);
        $this->assertSame(0, $data['valid_rows']);
        $this->assertSame(2, $data['invalid_rows']);

        $commit = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => true]);
        $this->assertSame(422, $commit->getStatusCode());
    }

    public function testXyCoordinateColumnsAreSupported(): void
    {
        $token = $this->authToken('csvtestuser')['token'];

        $csv = "id,easting,northing\n1,470000.0,1640000.0\n";
        $jobId = $this->createJob($token, $csv, 'CSVTEST_xy.csv', [
            'coordinate_columns' => ['x' => 'easting', 'y' => 'northing'],
        ]);
        // Declare PPCS Zone III (EPSG:990103), a projected CRS the registry knows.
        $mapping = $this->putMapping($token, $jobId, ['declared_crs' => 'PPCS:990103']);
        $this->assertSame(200, $mapping->getStatusCode(), (string) $mapping->getBody());

        $data = json_decode((string) $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token)->getBody(), true)['data'];
        $this->assertSame(1, $data['total_rows']);
        $this->assertSame(1, $data['valid_rows']);
    }

    // ------------------------------------------------------------------

    private function createJob(string $token, string $content, string $filename, array $options): int
    {
        $stream = (new StreamFactory())->createStream($content);
        $upload = new UploadedFile($stream, $filename, 'text/csv', \strlen($content), UPLOAD_ERR_OK);

        $request = $this->createRequest('POST', '/api/v1/imports')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withUploadedFiles(['file' => $upload])
            ->withParsedBody([
                'target_entity'   => 'FEATURE',
                'target_layer_id' => self::LAYER_ID,
                'options'         => $options,
            ]);

        $res = $this->handle($request);
        if ($res->getStatusCode() !== 201) {
            $this->fail('CSV import job creation failed: ' . (string) $res->getBody());
        }
        return (int) json_decode((string) $res->getBody(), true)['data']['id'];
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
        $this->pdo()->exec('DELETE FROM app.gis_layer_fields WHERE layer_id = ' . self::LAYER_ID);
        $this->pdo()->exec('DELETE FROM app.gis_layers WHERE id = ' . self::LAYER_ID);
        foreach (glob($this->storageDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storageDir);
    }
}
