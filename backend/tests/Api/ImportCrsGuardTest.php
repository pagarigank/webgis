<?php
declare(strict_types=1);

namespace Tests\Api;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * TASK-122 — the CRS is never guessed (FR-252, VR-52) and a malformed source
 * fails cleanly at upload.
 */
class ImportCrsGuardTest extends TestCase
{
    private const LAYER_ID = 8803;
    private const FILENAME_PREFIX = 'CRSTEST_';

    private const GEOJSON = '{"type":"FeatureCollection","features":[]}';

    private string $storageDir = '/tmp/import-crs-test-storage';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DOCUMENTS_STORAGE_DIR=' . $this->storageDir);
        $_ENV['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $_SERVER['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $this->cleanup();
        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type, srid)
            VALUES (" . self::LAYER_ID . ", 'TEST_CRS_LAYER', 'CRS Layer', 'POLYGON', 4326)
            ON CONFLICT (id) DO UPDATE SET code = 'TEST_CRS_LAYER'
        ");
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->cleanupSharedUsers();
        parent::tearDown();
    }

    public function testMappingWithoutDeclaredCrsIsRejected(): void
    {
        $token = $this->authToken('crstestuser')['token'];
        $jobId = $this->createJob($token, self::GEOJSON, 'CRSTEST_guard.geojson');

        $res = $this->request('PUT', "/api/v1/imports/{$jobId}/mapping", $token, ['field_mapping' => []]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('CRS_REQUIRED', $this->errorCode($res));
    }

    public function testValidateWithoutDeclaredCrsIsRejected(): void
    {
        $token = $this->authToken('crstestuser')['token'];
        $jobId = $this->createJob($token, self::GEOJSON, 'CRSTEST_validate.geojson');

        $res = $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('CRS_REQUIRED', $this->errorCode($res));
    }

    public function testUnknownCrsIsUnsupported(): void
    {
        $token = $this->authToken('crstestuser')['token'];
        $jobId = $this->createJob($token, self::GEOJSON, 'CRSTEST_unknown.geojson');

        $res = $this->request('PUT', "/api/v1/imports/{$jobId}/mapping", $token, ['declared_crs' => 'EPSG:999999']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('CRS_UNSUPPORTED', $this->errorCode($res));
    }

    public function testCrsAliasResolvesThroughTheRegistry(): void
    {
        $token = $this->authToken('crstestuser')['token'];
        $jobId = $this->createJob($token, self::GEOJSON, 'CRSTEST_alias.geojson');

        // Bare SRID form must resolve to the same registry row as EPSG:4326.
        $res = $this->request('PUT', "/api/v1/imports/{$jobId}/mapping", $token, ['declared_crs' => '4326']);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('EPSG:4326', json_decode((string) $res->getBody(), true)['data']['declared_crs']);
    }

    public function testMalformedArchiveFailsCleanlyAtUpload(): void
    {
        $token = $this->authToken('crstestuser')['token'];

        $stream = (new StreamFactory())->createStream("PK\x03\x04" . random_bytes(64));
        $upload = new UploadedFile($stream, 'CRSTEST_broken.zip', 'application/zip', 68, UPLOAD_ERR_OK);
        $request = $this->createRequest('POST', '/api/v1/imports')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withUploadedFiles(['file' => $upload])
            ->withParsedBody(['target_entity' => 'FEATURE', 'target_layer_id' => self::LAYER_ID]);

        $res = $this->handle($request);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('IMPORT_INVALID', $this->errorCode($res));
        $this->assertMatchesRegularExpression('/corrupt|truncated/i', json_decode((string) $res->getBody(), true)['error']['message']);
    }

    public function testUnknownFormatFailsCleanlyAtUpload(): void
    {
        $token = $this->authToken('crstestuser')['token'];

        $stream = (new StreamFactory())->createStream('hello world, not geospatial');
        $upload = new UploadedFile($stream, 'CRSTEST_data.txt', 'text/plain', 28, UPLOAD_ERR_OK);
        $request = $this->createRequest('POST', '/api/v1/imports')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withUploadedFiles(['file' => $upload])
            ->withParsedBody(['target_entity' => 'FEATURE', 'target_layer_id' => self::LAYER_ID]);

        $res = $this->handle($request);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('IMPORT_INVALID', $this->errorCode($res));
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
            $this->fail('Import job creation failed: ' . (string) $res->getBody());
        }
        return (int) json_decode((string) $res->getBody(), true)['data']['id'];
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

    private function cleanup(): void
    {
        $prefix = self::FILENAME_PREFIX;
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
