<?php
declare(strict_types=1);

namespace Tests\Api;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * TASK-122 — import job lifecycle over HTTP.
 *
 * upload → detect → declare CRS → map fields → validate → preview → commit.
 * Asserts the two load-bearing guarantees: nothing reaches a production table
 * before commit, and a commit replay with the same Idempotency-Key inserts
 * nothing.
 */
class ImportPipelineTest extends TestCase
{
    private const LAYER_ID = 8802;
    private const FILENAME_PREFIX = 'IMPTEST_';

    private string $storageDir = '/tmp/import-pipeline-test-storage';

    /** 2 valid polygons + a feature with no geometry (invalid). */
    private const MIXED_GEOJSON = <<<'JSON'
    {
      "type": "FeatureCollection",
      "features": [
        {"type": "Feature", "properties": {"name": "A"},
         "geometry": {"type": "Polygon", "coordinates": [[[120.000,14.000],[120.010,14.000],[120.010,14.010],[120.000,14.010],[120.000,14.000]]]}},
        {"type": "Feature", "properties": {"name": "B"},
         "geometry": {"type": "Polygon", "coordinates": [[[120.020,14.000],[120.030,14.000],[120.030,14.010],[120.020,14.010],[120.020,14.000]]]}},
        {"type": "Feature", "properties": {"name": "C"}, "geometry": null}
      ]
    }
    JSON;

    private const VALID_GEOJSON = <<<'JSON'
    {
      "type": "FeatureCollection",
      "features": [
        {"type": "Feature", "properties": {"name": "A"},
         "geometry": {"type": "Polygon", "coordinates": [[[120.000,14.000],[120.010,14.000],[120.010,14.010],[120.000,14.010],[120.000,14.000]]]}},
        {"type": "Feature", "properties": {"name": "B"},
         "geometry": {"type": "Polygon", "coordinates": [[[120.020,14.000],[120.030,14.000],[120.030,14.010],[120.020,14.010],[120.020,14.000]]]}}
      ]
    }
    JSON;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DOCUMENTS_STORAGE_DIR=' . $this->storageDir);
        $_ENV['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $_SERVER['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $this->cleanup();

        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type, srid)
            VALUES (" . self::LAYER_ID . ", 'TEST_IMPORT_LAYER', 'Import Layer', 'POLYGON', 4326)
            ON CONFLICT (id) DO UPDATE SET code = 'TEST_IMPORT_LAYER', geometry_type = 'POLYGON'
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

    public function testFullPipelineStagesThenCommits(): void
    {
        $token = $this->authToken('importtestuser')['token'];
        $jobId = $this->createJob($token, self::MIXED_GEOJSON, 'IMPTEST_mixed.geojson');

        // --- create: UPLOADED, nothing in production ---
        $this->assertSame('UPLOADED', $this->getJob($token, $jobId)['status']);
        $this->assertSame(0, $this->committedFeatureCount($jobId));

        // --- mapping (declares CRS) ---
        $mapped = $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:4326']);
        $this->assertSame(200, $mapped->getStatusCode());
        $this->assertSame('MAPPED', $this->getJob($token, $jobId)['status']);

        // --- validate: 3 rows, 2 valid, 1 invalid, still nothing in production ---
        $res = $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token);
        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true)['data'];
        $this->assertSame('VALIDATED', $body['status']);
        $this->assertSame(3, $body['total_rows']);
        $this->assertSame(2, $body['valid_rows']);
        $this->assertSame(1, $body['invalid_rows']);
        $this->assertContains('name', $body['validation_result']['detected_fields']);
        $this->assertSame(0, $this->committedFeatureCount($jobId), 'validation must not touch production tables');

        // --- preview ---
        $preview = json_decode((string) $this->request('GET', "/api/v1/imports/{$jobId}/preview", $token)->getBody(), true)['data'];
        $this->assertSame(3, $preview['total']);
        $this->assertFalse($preview['rows'][2]['is_valid']);
        $this->assertNotEmpty($preview['rows'][2]['errors']);

        // --- error report CSV ---
        $csvRes = $this->request('GET', "/api/v1/imports/{$jobId}/errors", $token);
        $this->assertSame(200, $csvRes->getStatusCode());
        $this->assertStringContainsString('text/csv', $csvRes->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('VR-32', (string) $csvRes->getBody());

        // --- commit without partial must be refused ---
        $res = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => false]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('IMPORT_INVALID', json_decode((string) $res->getBody(), true)['error']['code']);
        $this->assertSame(0, $this->committedFeatureCount($jobId));

        // --- partial commit succeeds ---
        $res = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => true]);
        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertSame('COMMITTED', $data['status']);
        $this->assertSame(2, $data['committed_rows']);

        $this->assertSame(2, $this->committedFeatureCount($jobId));
        $stmt = $this->pdo()->query(
            'SELECT provenance FROM app.gis_features WHERE source_document_id = '
            . '(SELECT source_document_id FROM app.import_jobs WHERE id = ' . $jobId . ')'
        );
        $this->assertNotFalse($stmt);
        $provenance = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(2, $provenance);
        $this->assertSame(['IMPORTED_GIS', 'IMPORTED_GIS'], $provenance);
    }

    public function testCommitIsIdempotentByKey(): void
    {
        $token = $this->authToken('importtestuser')['token'];
        $jobId = $this->createJob($token, self::VALID_GEOJSON, 'IMPTEST_valid.geojson');
        $this->putMapping($token, $jobId, ['declared_crs' => '4326']);
        $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token);

        $first = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => false], ['Idempotency-Key' => 'key-abc-123']);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(2, json_decode((string) $first->getBody(), true)['data']['committed_rows']);
        $this->assertSame(2, $this->committedFeatureCount($jobId));

        // Replay: same key, no new rows.
        $second = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => false], ['Idempotency-Key' => 'key-abc-123']);
        $this->assertSame(200, $second->getStatusCode());
        $this->assertTrue(json_decode((string) $second->getBody(), true)['data']['idempotent']);
        $this->assertSame(2, $this->committedFeatureCount($jobId), 'a replay must not insert duplicates');
    }

    public function testCancelPurgesStagingAndBlocksCommit(): void
    {
        $token = $this->authToken('importtestuser')['token'];
        $jobId = $this->createJob($token, self::VALID_GEOJSON, 'IMPTEST_cancel.geojson');
        $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:4326']);
        $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token);

        $staged = $this->scalar("SELECT COUNT(*) FROM staging.import_job_rows WHERE job_id = $jobId");
        $this->assertSame(2, $staged);

        $res = $this->request('DELETE', "/api/v1/imports/{$jobId}", $token);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('CANCELLED', $this->getJob($token, $jobId)['status']);
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM staging.import_job_rows WHERE job_id = $jobId"));

        $commit = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => true]);
        $this->assertSame(422, $commit->getStatusCode());
    }

    public function testForeignJobIsNotFound(): void
    {
        $token = $this->authToken('importtestuser')['token'];
        $jobId = $this->createJob($token, self::VALID_GEOJSON, 'IMPTEST_private.geojson');

        $other = $this->authToken('importotheruser')['token'];
        $res = $this->request('GET', "/api/v1/imports/{$jobId}", $other);
        $this->assertSame(404, $res->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createJob(string $token, string $content, string $filename): int
    {
        $stream = (new StreamFactory())->createStream($content);
        $upload = new UploadedFile($stream, $filename, 'application/geo+json', \strlen($content), UPLOAD_ERR_OK);

        $request = $this->createRequest('POST', '/api/v1/imports')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withUploadedFiles(['file' => $upload])
            ->withParsedBody([
                'target_entity'   => 'FEATURE',
                'target_layer_id' => self::LAYER_ID,
            ]);

        $res = $this->handle($request);
        if ($res->getStatusCode() !== 201) {
            $this->fail('Import job creation failed: ' . (string) $res->getBody());
        }
        return (int) json_decode((string) $res->getBody(), true)['data']['id'];
    }

    private function getJob(string $token, int $jobId): array
    {
        $res = $this->request('GET', "/api/v1/imports/{$jobId}", $token);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        return json_decode((string) $res->getBody(), true)['data'];
    }

    private function putMapping(string $token, int $jobId, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('PUT', "/api/v1/imports/{$jobId}/mapping", $token, $body);
    }

    /** @param array<string,string> $headers */
    private function request(string $method, string $path, string $token, ?array $body = null, array $headers = []): \Psr\Http\Message\ResponseInterface
    {
        $request = $this->createRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');

        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->handle($request);
    }

    /** Scalar query helper that narrows PDOStatement|false for PHPStan. */
    private function scalar(string $sql): int
    {
        $stmt = $this->pdo()->query($sql);
        $this->assertNotFalse($stmt);
        return (int) $stmt->fetchColumn();
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
}
