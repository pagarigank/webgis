<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

class BulkUpdateTest extends TestCase
{
    private int $layerId = 8801;
    private array $featureIds = [
        '88888888-1111-4000-8000-000000000001',
        '88888888-1111-4000-8000-000000000002',
        '88888888-1111-4000-8000-000000000003',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();

        // Create test layer
        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type)
            VALUES ({$this->layerId}, 'TEST_BULK_LAYER', 'Bulk Layer', 'POLYGON')
            ON CONFLICT (id) DO UPDATE SET code = 'TEST_BULK_LAYER', name = 'Bulk Layer'
        ");

        // Grant full permissions on this layer to SYS_ADMIN
        $this->pdo()->exec("
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
            SELECT {$this->layerId}, id, true, true, true, true, true FROM app.roles WHERE code = 'SYS_ADMIN'
            ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true, can_create = true, can_update = true, can_delete = true, can_approve = true
        ");

        // Seed 3 features
        foreach ($this->featureIds as $idx => $fid) {
            $num = $idx + 1;
            $this->pdo()->exec("
                INSERT INTO app.gis_features (id, layer_id, geom, attributes, status, version, created_by)
                VALUES (
                    '{$fid}',
                    {$this->layerId},
                    ST_GeomFromText('POLYGON((120.0 14.0, 120.1 14.0, 120.1 14.1, 120.0 14.1, 120.0 14.0))', 4326),
                    '{\"name\": \"Feature {$num}\"}',
                    'ACTIVE',
                    1,
                    1
                )
            ");
        }
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
        $this->pdo()->exec("DELETE FROM audit.audit_logs WHERE entity_id IN ({$idList})");
        $this->pdo()->exec("DELETE FROM audit.gis_feature_versions WHERE feature_id IN ({$idList})");
        $this->pdo()->exec("DELETE FROM app.gis_features WHERE id IN ({$idList})");
        $this->pdo()->exec("DELETE FROM audit.gis_feature_versions WHERE feature_id IN ({$idList})");
        $this->pdo()->exec("DELETE FROM app.layer_permissions WHERE layer_id = {$this->layerId}");
        $this->pdo()->exec("DELETE FROM app.gis_layers WHERE id = {$this->layerId}");
    }

    public function testBulkUpdateRequiresAuth(): void
    {
        $req = $this->createRequest('POST', "/api/v1/layers/{$this->layerId}/features/bulk-update")
            ->withHeader('Content-Type', 'application/json');
        $req->getBody()->write(json_encode([
            'ids' => [$this->featureIds[0]],
            'patch' => ['status' => 'PENDING'],
        ]));

        $res = $this->handle($req);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testBulkUpdateValidatesPayload(): void
    {
        $user = $this->authToken('bulktestuser');

        // Missing patch
        $req = $this->createRequest('POST', "/api/v1/layers/{$this->layerId}/features/bulk-update")
            ->withHeader('Authorization', 'Bearer ' . $user['token'])
            ->withHeader('Content-Type', 'application/json');
        $req->getBody()->write(json_encode(['ids' => [$this->featureIds[0]]]));

        $res = $this->handle($req);
        $this->assertSame(400, $res->getStatusCode());

        // Empty ids
        $req2 = $this->createRequest('POST', "/api/v1/layers/{$this->layerId}/features/bulk-update")
            ->withHeader('Authorization', 'Bearer ' . $user['token'])
            ->withHeader('Content-Type', 'application/json');
        $req2->getBody()->write(json_encode(['ids' => [], 'patch' => ['status' => 'PENDING']]));

        $res2 = $this->handle($req2);
        $this->assertSame(400, $res2->getStatusCode());
    }

    public function testBulkUpdateUpdatesFeaturesAndAudits(): void
    {
        $user = $this->authToken('bulktestuser');

        $req = $this->createRequest('POST', "/api/v1/layers/{$this->layerId}/features/bulk-update")
            ->withHeader('Authorization', 'Bearer ' . $user['token'])
            ->withHeader('Content-Type', 'application/json');
        $req->getBody()->write(json_encode([
            'ids' => [$this->featureIds[0], $this->featureIds[1]],
            'patch' => [
                'status' => 'PENDING',
                'attributes' => ['tagged' => true],
            ],
        ]));

        $res = $this->handle($req);
        $this->assertSame(200, $res->getStatusCode());

        $body = json_decode((string) $res->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(2, $body['data']['updated_count']);

        // Check DB state
        $stmt = $this->pdo()->prepare("SELECT status, version, attributes FROM app.gis_features WHERE id = :id");
        
        $stmt->execute([':id' => $this->featureIds[0]]);
        $f1 = $stmt->fetch();
        $this->assertSame('PENDING', $f1['status']);
        $this->assertSame(2, (int) $f1['version']);
        $attrs1 = json_decode($f1['attributes'], true);
        $this->assertTrue($attrs1['tagged']);

        // Check feature 3 was untouched
        $stmt->execute([':id' => $this->featureIds[2]]);
        $f3 = $stmt->fetch();
        $this->assertSame('ACTIVE', $f3['status']);
        $this->assertSame(1, (int) $f3['version']);

        // Check audit logs
        $auditStmt = $this->pdo()->prepare("SELECT COUNT(*) FROM audit.audit_logs WHERE action = 'UPDATE' AND entity_id IN (:id1, :id2)");
        $auditStmt->execute([':id1' => $this->featureIds[0], ':id2' => $this->featureIds[1]]);
        $this->assertSame(2, (int) $auditStmt->fetchColumn());
    }

    public function testBulkDeleteDeletesFeaturesAndAudits(): void
    {
        $user = $this->authToken('bulktestuser');

        $req = $this->createRequest('POST', "/api/v1/layers/{$this->layerId}/features/bulk-delete")
            ->withHeader('Authorization', 'Bearer ' . $user['token'])
            ->withHeader('Content-Type', 'application/json');
        $req->getBody()->write(json_encode([
            'ids' => [$this->featureIds[0], $this->featureIds[1]],
            'reason' => 'Testing bulk removal',
        ]));

        $res = $this->handle($req);
        $this->assertSame(200, $res->getStatusCode());

        $body = json_decode((string) $res->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(2, $body['data']['deleted_count']);

        // Verify soft delete
        $stmt = $this->pdo()->prepare("SELECT deleted_at FROM app.gis_features WHERE id = :id");
        $stmt->execute([':id' => $this->featureIds[0]]);
        $this->assertNotNull($stmt->fetchColumn());

        $stmt->execute([':id' => $this->featureIds[2]]);
        $this->assertNull($stmt->fetchColumn());

        // Check audit log
        $auditStmt = $this->pdo()->prepare("SELECT COUNT(*) FROM audit.audit_logs WHERE action = 'DELETE' AND entity_id IN (:id1, :id2) AND reason = 'Testing bulk removal'");
        $auditStmt->execute([':id1' => $this->featureIds[0], ':id2' => $this->featureIds[1]]);
        $this->assertSame(2, (int) $auditStmt->fetchColumn());
    }
}
