<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Core\Geo\OgrAdapter;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * TASK-125 — DXF/CAD import (FR-250–255).
 *
 * A hand-written ASCII DXF exercises the whole pipeline: the entity-layer →
 * GIS-layer mapping is mandatory for a FEATURE import, annotation entities are
 * dropped and reported, a local/assumed grid requires a documented
 * `coordinate_transformations` row before geometry is accepted, committed
 * geometry is stamped `CAD_IMPORT`, and CAD survey points land as UNVERIFIED
 * candidates.
 *
 * Skipped where GDAL is unavailable so the suite stays green without it.
 */
class DxfImportTest extends TestCase
{
    private const LAYER_ID = 8807;
    private const FILENAME_PREFIX = 'DXFTEST_';

    /** Two points on the PARCEL CAD layer (EPSG:4326) plus a dropped TEXT label. */
    private const DXF_GEOGRAPHIC = <<<'DXF'
    0
    SECTION
    2
    ENTITIES
    0
    POINT
    8
    PARCEL
    10
    121.000
    20
    14.500
    30
    0.0
    0
    POINT
    8
    PARCEL
    10
    121.100
    20
    14.600
    30
    0.0
    0
    TEXT
    8
    ANNOTATIONS
    10
    121.050
    20
    14.550
    30
    0.0
    40
    1.0
    1
    Hello survey
    0
    ENDSEC
    0
    EOF
    DXF;

    /** Two points in an un-georeferenced local CAD grid. */
    private const DXF_LOCAL_GRID = <<<'DXF'
    0
    SECTION
    2
    ENTITIES
    0
    POINT
    8
    PARCEL
    10
    100.0
    20
    200.0
    30
    0.0
    0
    POINT
    8
    PARCEL
    10
    110.0
    20
    210.0
    30
    0.0
    0
    ENDSEC
    0
    EOF
    DXF;

    private string $storageDir = '/tmp/import-dxf-test-storage';

    protected function setUp(): void
    {
        parent::setUp();

        if (!(new OgrAdapter())->isAvailable()) {
            $this->markTestSkipped('GDAL/ogr2ogr is not installed; DXF import tests skipped.');
        }

        putenv('DOCUMENTS_STORAGE_DIR=' . $this->storageDir);
        $_ENV['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $_SERVER['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $this->cleanup();

        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type, srid)
            VALUES (" . self::LAYER_ID . ", 'TEST_DXF_LAYER', 'DXF Parcel Layer', 'POINT', 4326)
            ON CONFLICT (id) DO UPDATE SET code = 'TEST_DXF_LAYER', geometry_type = 'POINT'
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
     * The entity-layer map is required, annotation entities are dropped and
     * reported, and committed CAD geometry carries CAD_IMPORT provenance.
     */
    public function testMapsEntityLayersAndReportsDroppedEntities(): void
    {
        $token = $this->authToken('dxftestuser')['token'];
        $jobId = $this->createJob($token, self::DXF_GEOGRAPHIC, 'DXFTEST_geographic.dxf', 'FEATURE', self::LAYER_ID);

        $job = $this->getJob($token, $jobId);
        $this->assertSame('DXF', $job['source_format']);
        // FR-250 — the source's dropped entity types and CAD layers are reported.
        $this->assertSame(1, $job['dropped']['TEXT'] ?? null);
        $this->assertContains('PARCEL', $job['entity_layers']);
        $this->assertContains('ANNOTATIONS', $job['entity_layers']);
        // CRS is never inferred for CAD data.
        $this->assertNull($job['declared_crs']);

        // A DXF FEATURE import without an entity-layer map is refused.
        $res = $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:4326']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('VALIDATION_FAILED', $this->errorCode($res));

        $mapped = $this->putMapping($token, $jobId, [
            'declared_crs'     => 'EPSG:4326',
            'entity_layer_map' => ['PARCEL' => self::LAYER_ID],
        ]);
        $this->assertSame(200, $mapped->getStatusCode(), (string) $mapped->getBody());

        $validateRes = $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token);
        $this->assertSame(200, $validateRes->getStatusCode(), (string) $validateRes->getBody());
        $data = json_decode((string) $validateRes->getBody(), true)['data'];
        $this->assertSame('VALIDATED', $data['status']);
        // The TEXT label is dropped, not staged: only the two PARCEL points remain.
        $this->assertSame(2, $data['total_rows']);
        $this->assertSame(2, $data['valid_rows']);
        $this->assertSame(1, $data['validation_result']['cad']['dropped_rows']);
        $this->assertSame(['PARCEL'], $data['validation_result']['cad']['entity_layers']);

        $commit = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => false]);
        $this->assertSame(200, $commit->getStatusCode(), (string) $commit->getBody());
        $this->assertSame(2, json_decode((string) $commit->getBody(), true)['data']['committed_rows']);

        // FR-253 — CAD_IMPORT provenance and no auto-approval (PENDING, not ACTIVE).
        $stmt = $this->pdo()->prepare(
            'SELECT provenance, status FROM app.gis_features
              WHERE source_document_id = (SELECT source_document_id FROM app.import_jobs WHERE id = :id)'
        );
        $stmt->execute([':id' => $jobId]);
        $features = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $features);
        foreach ($features as $feature) {
            $this->assertSame('CAD_IMPORT', $feature['provenance']);
            $this->assertSame('PENDING', $feature['status']);
        }
    }

    /**
     * A local/assumed CAD grid cannot be validated until a documented
     * transformation is recorded, and the affine is applied before the declared
     * CRS is imposed (FR-252).
     */
    public function testLocalGridRequiresDocumentedTransformation(): void
    {
        $token = $this->authToken('dxftestuser')['token'];
        $jobId = $this->createJob($token, self::DXF_LOCAL_GRID, 'DXFTEST_local.dxf', 'FEATURE', self::LAYER_ID);

        // Declaring a local grid without a transformation is refused.
        $res = $this->putMapping($token, $jobId, [
            'declared_crs'     => 'EPSG:4326',
            'entity_layer_map' => ['PARCEL' => self::LAYER_ID],
            'options'          => ['cad_local_grid' => true],
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('VALIDATION_FAILED', $this->errorCode($res));

        // With the documented transformation, it is recorded and applied.
        $mapped = $this->putMapping($token, $jobId, [
            'declared_crs'     => 'EPSG:4326',
            'entity_layer_map' => ['PARCEL' => self::LAYER_ID],
            'options'          => ['cad_local_grid' => true],
            'transformation'   => ['origin_x' => 121.0, 'origin_y' => 14.5, 'scale' => 0.001, 'rotation_deg' => 0],
        ]);
        $this->assertSame(200, $mapped->getStatusCode(), (string) $mapped->getBody());
        $mappedJob = json_decode((string) $mapped->getBody(), true)['data'];
        $this->assertNotNull($mappedJob['transformation_id']);

        $tx = $this->pdo()->prepare(
            "SELECT method, parameters FROM app.coordinate_transformations
              WHERE entity_type = 'import_job' AND entity_id = :id"
        );
        $tx->execute([':id' => (string) $jobId]);
        $record = $tx->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($record);
        $this->assertSame('AFFINE_LOCAL', $record['method']);
        $this->assertSame(0.001, (float) json_decode((string) $record['parameters'], true)['scale']);

        $validateRes = $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token);
        $this->assertSame(200, $validateRes->getStatusCode(), (string) $validateRes->getBody());
        $data = json_decode((string) $validateRes->getBody(), true)['data'];
        $this->assertSame(2, $data['valid_rows']);
        $this->assertTrue($data['validation_result']['cad']['local_grid']);

        // (100, 200) → 121.0 + 0.001*100, 14.5 + 0.001*200 = (121.1, 14.7).
        $geom = $this->pdo()->prepare(
            'SELECT ST_X(geom) AS x, ST_Y(geom) AS y FROM staging.import_job_rows
              WHERE job_id = :id AND row_number = 1'
        );
        $geom->execute([':id' => $jobId]);
        $point = $geom->fetch(\PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(121.1, (float) $point['x'], 1e-6);
        $this->assertEqualsWithDelta(14.7, (float) $point['y'], 1e-6);
    }

    /**
     * FR-254 — CAD survey points become UNVERIFIED candidate control points,
     * never verified control.
     */
    public function testCadSurveyPointsBecomeUnverifiedCandidates(): void
    {
        $token = $this->authToken('dxftestuser')['token'];
        $jobId = $this->createJob($token, self::DXF_GEOGRAPHIC, 'DXFTEST_points.dxf', 'CONTROL_POINT', null);

        $this->putMapping($token, $jobId, ['declared_crs' => 'EPSG:4326']);

        $data = json_decode((string) $this->request('POST', "/api/v1/imports/{$jobId}/validate", $token)->getBody(), true)['data'];
        $this->assertSame(2, $data['valid_rows']);

        $commit = $this->request('POST', "/api/v1/imports/{$jobId}/commit", $token, ['partial' => false]);
        $this->assertSame(200, $commit->getStatusCode(), (string) $commit->getBody());
        $commitData = json_decode((string) $commit->getBody(), true)['data'];
        $this->assertSame(2, $commitData['committed_rows']);
        $this->assertSame(0, $commitData['skipped_rows']);

        $stmt = $this->pdo()->prepare(
            "SELECT status, latitude, longitude, native_crs_id FROM app.survey_control_points
              WHERE source LIKE 'CAD import: " . self::FILENAME_PREFIX . "%'
           ORDER BY point_name"
        );
        $stmt->execute();
        $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $candidates);
        foreach ($candidates as $candidate) {
            $this->assertSame('UNVERIFIED', $candidate['status']);
            $this->assertNotNull($candidate['latitude']);
            $this->assertNotNull($candidate['longitude']);
            $this->assertNotNull($candidate['native_crs_id']);
        }
        // Nothing was written to a GIS layer for a control-point import.
        $this->assertSame(0, $this->committedFeatureCount($jobId));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createJob(string $token, string $content, string $filename, string $target, ?int $layerId): int
    {
        $stream = (new StreamFactory())->createStream($content);
        $upload = new UploadedFile($stream, $filename, 'application/dxf', \strlen($content), UPLOAD_ERR_OK);

        $body = ['target_entity' => $target];
        if ($layerId !== null) {
            $body['target_layer_id'] = $layerId;
        }

        $request = $this->createRequest('POST', '/api/v1/imports')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withUploadedFiles(['file' => $upload])
            ->withParsedBody($body);

        $res = $this->handle($request);
        if ($res->getStatusCode() !== 201) {
            $this->fail('DXF import job creation failed: ' . (string) $res->getBody());
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
        $this->pdo()->exec("DELETE FROM app.survey_control_points WHERE source LIKE 'CAD import: $prefix%'");
        $this->pdo()->exec("
            DELETE FROM audit.gis_feature_versions WHERE feature_id IN (
                SELECT id FROM app.gis_features WHERE source_document_id IN (
                    SELECT source_document_id FROM app.import_jobs WHERE source_filename LIKE '$prefix%'
                )
            )
        ");
        $this->pdo()->exec("DELETE FROM app.gis_features WHERE source_document_id IN (SELECT source_document_id FROM app.import_jobs WHERE source_filename LIKE '$prefix%')");
        $this->pdo()->exec("DELETE FROM staging.import_job_rows WHERE job_id IN (SELECT id FROM app.import_jobs WHERE source_filename LIKE '$prefix%')");
        $this->pdo()->exec("UPDATE app.import_jobs SET transformation_id = NULL WHERE source_filename LIKE '$prefix%'");
        $this->pdo()->exec("DELETE FROM app.coordinate_transformations WHERE entity_type = 'import_job' AND entity_id IN (SELECT id::text FROM app.import_jobs WHERE source_filename LIKE '$prefix%')");
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
