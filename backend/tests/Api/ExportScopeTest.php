<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

class ExportScopeTest extends TestCase
{
    private int $layerId = 8802;
    private array $featureIds = [
        '88888888-2222-4000-8000-000000000001',
        '88888888-2222-4000-8000-000000000002',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();

        // Create test layer
        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type)
            VALUES ({$this->layerId}, 'TEST_EXPORT_LAYER', 'Export Layer', 'POLYGON')
            ON CONFLICT (id) DO UPDATE SET code = 'TEST_EXPORT_LAYER', name = 'Export Layer'
        ");

        // Grant full permissions on this layer to SYS_ADMIN
        $this->pdo()->exec("
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
            SELECT {$this->layerId}, id, true, true, true, true, true FROM app.roles WHERE code = 'SYS_ADMIN'
            ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true, can_create = true, can_update = true, can_delete = true, can_approve = true
        ");

        // Add layer fields: one normal, one PII
        $this->pdo()->exec("
            INSERT INTO app.gis_layer_fields (layer_id, field_name, field_label, field_type, is_pii, sort_order)
            VALUES 
                ({$this->layerId}, 'lot_label', 'Lot Label', 'text', false, 1),
                ({$this->layerId}, 'owner_tin', 'Owner TIN', 'text', true, 2)
            ON CONFLICT (layer_id, field_name) DO UPDATE SET is_pii = EXCLUDED.is_pii
        ");

        // Seed 2 features:
        // Feature 1: Manila coordinates [120.98, 14.59]
        // Feature 2: Cebu coordinates [123.89, 10.31]
        $this->pdo()->exec("
            INSERT INTO app.gis_features (id, layer_id, geom, attributes, status, version, created_by)
            VALUES (
                '{$this->featureIds[0]}',
                {$this->layerId},
                ST_GeomFromText('POLYGON((120.97 14.58, 120.99 14.58, 120.99 14.60, 120.97 14.60, 120.97 14.58))', 4326),
                '{\"lot_label\": \"Lot Manila\", \"owner_tin\": \"123-456-789\"}',
                'ACTIVE',
                1,
                1
            ), (
                '{$this->featureIds[1]}',
                {$this->layerId},
                ST_GeomFromText('POLYGON((123.88 10.30, 123.90 10.30, 123.90 10.32, 123.88 10.32, 123.88 10.30))', 4326),
                '{\"lot_label\": \"Lot Cebu\", \"owner_tin\": \"987-654-321\"}',
                'ACTIVE',
                1,
                1
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
        $idList = "'" . implode("','", $this->featureIds) . "'";
        $this->pdo()->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.gis_features' AND entity_id IN ({$idList}, '{$this->layerId}')");
        $this->pdo()->exec("DELETE FROM audit.gis_feature_versions WHERE feature_id IN ({$idList})");
        $this->pdo()->exec("DELETE FROM app.gis_features WHERE id IN ({$idList})");
        $this->pdo()->exec("DELETE FROM audit.gis_feature_versions WHERE feature_id IN ({$idList})");
        $this->pdo()->exec("DELETE FROM app.gis_layer_fields WHERE layer_id = {$this->layerId}");
        $this->pdo()->exec("DELETE FROM app.layer_permissions WHERE layer_id = {$this->layerId}");
        $this->pdo()->exec("DELETE FROM app.gis_layers WHERE id = {$this->layerId}");
    }

    public function testGeoJsonExportWithExtentFilterAndAudit(): void
    {
        $user = $this->authToken('exportuser');

        // Request without bbox -> all 2 features
        $reqAll = $this->createRequest('GET', "/api/v1/layers/{$this->layerId}/features.geojson")
            ->withHeader('Authorization', 'Bearer ' . $user['token']);
        $resAll = $this->handle($reqAll);
        $this->assertSame(200, $resAll->getStatusCode());
        $this->assertStringContainsString('application/geo+json', $resAll->getHeaderLine('Content-Type'));

        $bodyAll = json_decode((string) $resAll->getBody(), true);
        $this->assertSame('FeatureCollection', $bodyAll['type']);
        $this->assertCount(2, $bodyAll['features']);

        // Request with bbox covering Manila only: minx=120.95, miny=14.55, maxx=121.05, maxy=14.65
        $reqBbox = $this->createRequest('GET', "/api/v1/layers/{$this->layerId}/features.geojson?bbox=120.95,14.55,121.05,14.65")
            ->withHeader('Authorization', 'Bearer ' . $user['token']);
        $resBbox = $this->handle($reqBbox);
        $this->assertSame(200, $resBbox->getStatusCode());

        $bodyBbox = json_decode((string) $resBbox->getBody(), true);
        $this->assertCount(1, $bodyBbox['features']);
        $this->assertSame($this->featureIds[0], $bodyBbox['features'][0]['id']);

        // Check that export was audited
        $stmt = $this->pdo()->prepare("
            SELECT COUNT(*) 
            FROM audit.audit_logs 
            WHERE action = 'EXPORT' AND entity_type = 'app.gis_features' AND entity_id = :lid
        ");
        $stmt->execute([':lid' => (string) $this->layerId]);
        $this->assertGreaterThanOrEqual(2, (int) $stmt->fetchColumn());
    }

    public function testCsvExportWithExtentFilterAndAudit(): void
    {
        $user = $this->authToken('exportuser');

        // Request CSV with bbox covering Cebu only: 123.85,10.25,123.95,10.35
        $req = $this->createRequest('GET', "/api/v1/layers/{$this->layerId}/features.csv?bbox=123.85,10.25,123.95,10.35")
            ->withHeader('Authorization', 'Bearer ' . $user['token']);
        $res = $this->handle($req);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/csv', $res->getHeaderLine('Content-Type'));

        $csvContent = (string) $res->getBody();
        $lines = array_filter(explode("\n", trim($csvContent)));
        // Header line + 1 data line
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('id,status,psgc_barangay,provenance,version,created_at,updated_at,lot_label,owner_tin', $lines[0]);
        $this->assertStringContainsString($this->featureIds[1], $lines[1]);
        $this->assertStringContainsString('Lot Cebu', $lines[1]);
    }

    public function testPiiRedactionForUnprivilegedUser(): void
    {
        // Create an encoder/viewer user without PII permissions
        $userWithoutPii = $this->authToken('nopiiuser', 'ROLE_VIEWER', 'Viewer');

        // Grant view permission to ROLE_VIEWER on this layer
        $this->pdo()->exec("
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
            SELECT {$this->layerId}, id, true, false, false, false, false FROM app.roles WHERE code = 'ROLE_VIEWER'
            ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true
        ");

        // 1. GeoJSON export: owner_tin must be [REDACTED]
        $reqGj = $this->createRequest('GET', "/api/v1/layers/{$this->layerId}/features.geojson")
            ->withHeader('Authorization', 'Bearer ' . $userWithoutPii['token']);
        $resGj = $this->handle($reqGj);
        $this->assertSame(200, $resGj->getStatusCode());

        $bodyGj = json_decode((string) $resGj->getBody(), true);
        $feat1Attrs = $bodyGj['features'][0]['properties']['attributes'];
        $this->assertSame('[REDACTED]', $feat1Attrs['owner_tin']);
        $this->assertSame('Lot Manila', $feat1Attrs['lot_label']);

        // 2. CSV export: owner_tin must be [REDACTED]
        $reqCsv = $this->createRequest('GET', "/api/v1/layers/{$this->layerId}/features.csv")
            ->withHeader('Authorization', 'Bearer ' . $userWithoutPii['token']);
        $resCsv = $this->handle($reqCsv);
        $this->assertSame(200, $resCsv->getStatusCode());

        $csvContent = (string) $resCsv->getBody();
        $this->assertStringContainsString('[REDACTED]', $csvContent);
        $this->assertStringNotContainsString('123-456-789', $csvContent);
    }
}
