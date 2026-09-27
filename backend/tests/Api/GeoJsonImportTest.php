<?php
declare(strict_types=1);

namespace Tests\Api;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * TASK-123 — GeoJSON importer: per-row validation against layer metadata.
 *
 * Each invalid row is rejected individually with its row number and reason;
 * valid rows commit.
 */
class GeoJsonImportTest extends TestCase
{
    private const LAYER_ID = 8805;
    private const FILENAME_PREFIX = 'GEOIMPTEST_';

    /** Row 1 valid; row 2 missing the required `name`; row 3 has a non-integer. */
    private const MIXED = <<<'JSON'
    {
      "type": "FeatureCollection",
      "features": [
        {"type": "Feature", "properties": {"name": "Alpha", "population": 100},
         "geometry": {"type": "Point", "coordinates": [121.000, 14.500]}},
        {"type": "Feature", "properties": {"population": 200},
         "geometry": {"type": "Point", "coordinates": [121.100, 14.600]}},
        {"type": "Feature", "properties": {"name": "Gamma", "population": "not-a-number"},
         "geometry": {"type": "Point", "coordinates": [121.200, 14.700]}}
      ]
    }
    JSON;

    private const ALL_VALID = <<<'JSON'
    {
      "type": "FeatureCollection",
      "features": [
        {"type": "Feature", "properties": {"name": "Alpha", "population": 100},
         "geometry": {"type": "Point", "coordinates": [121.000, 14.500]}},
        {"type": "Feature", "properties": {"name": "Beta", "population": 250},
         "geometry": {"type": "Point", "coordinates": [121.100, 14.600]}}
      ]
    }
    JSON;

    private string $storageDir = '/tmp/import-geojson-test-storage';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DOCUMENTS_STORAGE_DIR=' . $this->storageDir);
        $_ENV['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $_SERVER['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $this->cleanup();

        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type, srid)
            VALUES (" . self::LAYER_ID . ", 'TEST_GEOJSON_LAYER', 'GeoJSON Layer', 'POINT', 4326)
            ON CONFLICT (id) DO UPDATE SET code = 'TEST_GEOJSON_LAYER', geometry_type = 'POINT'
        ");
        $this->pdo()->exec("
            INSERT INTO app.gis_layer_fields (layer_id, field_name, field_label, field_type, required)
            VALUES
                (" . self::LAYER_ID . ", 'name', 'Name', 'text', true),
                (" . self::LAYER_ID . ", 'population', 'Population', 'integer', false)
            ON CONFLICT (layer_id, field_name) DO NOTHING
        ");
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->cleanupSharedUsers();
        parent::tearDown();
    }

    public function testMetadataFailuresAreReportedPerRow(): void
    {
        $token = $this->authToken('geojsontestuser')['token'];
        $jobId = $this->createJob($token, self::MIXED, 'GEOIMPTEST_mixed.geojson');
        $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:4326']);

        $data = json_decode((string) $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token)->getBody(), true)['data'];
        $this->assertSame(3, $data['total_rows']);
        $this->assertSame(1, $data['valid_rows']);
        $this->assertSame(2, $data['invalid_rows']);

        $preview = json_decode((string) $this->request('GET', "/api/v1/imports/{$jobId}/preview", $token)->getBody(), true)['data'];
        $rows = $preview['rows'];
        $this->assertTrue($rows[0]['is_valid']);

        $this->assertSame(2, $rows[1]['row_number']);
        $this->assertSame('LAYER_METADATA', $rows[1]['errors'][0]['rule']);
        $this->assertSame('name', $rows[1]['errors'][0]['field']);
        $this->assertStringContainsString('required', strtolower($rows[1]['errors'][0]['message']));

        $this->assertSame(3, $rows[2]['row_number']);
        $this->assertSame('LAYER_METADATA', $rows[2]['errors'][0]['rule']);
        $this->assertSame('population', $rows[2]['errors'][0]['field']);

        // Error CSV lists both rejected rows with their numbers.
        $csv = (string) $this->request('GET', "/api/v1/imports/{$jobId}/errors", $token)->getBody();
        $this->assertStringContainsString('LAYER_METADATA', $csv);
        $this->assertStringContainsString('Gamma', $csv); // the rejected row's data is carried through
        $this->assertStringNotContainsString('Alpha', $csv, 'the error report lists only rejected rows');

        // Partial commit lands only the valid row.
        $commit = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => true]);
        $this->assertSame(200, $commit->getStatusCode(), (string) $commit->getBody());
        $this->assertSame(1, json_decode((string) $commit->getBody(), true)['data']['committed_rows']);
        $this->assertSame(1, $this->committedFeatureCount($jobId));
    }

    public function testAllValidRowsCommitWithoutPartial(): void
    {
        $token = $this->authToken('geojsontestuser')['token'];
        $jobId = $this->createJob($token, self::ALL_VALID, 'GEOIMPTEST_valid.geojson');
        $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:4326']);

        $data = json_decode((string) $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token)->getBody(), true)['data'];
        $this->assertSame(2, $data['valid_rows']);
        $this->assertSame(0, $data['invalid_rows']);

        $commit = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => false]);
        $this->assertSame(200, $commit->getStatusCode(), (string) $commit->getBody());
        $this->assertSame(2, json_decode((string) $commit->getBody(), true)['data']['committed_rows']);

        // Attributes are carried onto the committed features.
        $stmt = $this->pdo()->prepare(
            "SELECT attributes FROM app.gis_features
              WHERE source_document_id = (SELECT source_document_id FROM app.import_jobs WHERE id = :id)
              ORDER BY attributes->>'name' LIMIT 1"
        );
        $stmt->execute([':id' => $jobId]);
        $attrs = json_decode((string) $stmt->fetchColumn(), true);
        $this->assertSame('Alpha', $attrs['name']);
        $this->assertSame(100, (int) $attrs['population']);
    }

    // ------------------------------------------------------------------

    private function createJob(string $token, string $content, string $filename): int
    {
        $stream = (new StreamFactory())->createStream($content);
        $upload = new UploadedFile($stream, $filename, 'application/geo+json', \strlen($content), UPLOAD_ERR_OK);

        $request = $this->createRequest('POST', '/api/v1/imports')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withUploadedFiles(['file' => $upload])
            ->withParsedBody(['target_entity' => 'FEATURE', 'target_layer_id' => self::LAYER_ID]);

        $res = $this->handle($request);
        if ($res->getStatusCode() !== 201) {
            $this->fail('GeoJSON import job creation failed: ' . (string) $res->getBody());
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
