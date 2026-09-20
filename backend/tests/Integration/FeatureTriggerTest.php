<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;
use PDOException;

class FeatureTriggerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->getAppInstance()->getContainer()->get(PDO::class);
        
        // Clean up any previous test data
        $this->pdo->exec("DELETE FROM audit.gis_feature_versions WHERE feature_id IN ('11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222')");
        $this->pdo->exec("DELETE FROM app.gis_features WHERE id IN ('11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222')");
        $this->pdo->exec("DELETE FROM app.gis_layer_fields WHERE layer_id = 9999");
        $this->pdo->exec("DELETE FROM app.gis_layers WHERE id = 9999");
        
        // Create test layer
        $this->pdo->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type)
            VALUES (9999, 'TEST_LAYER', 'Test Layer', 'POLYGON')
        ");
        
        // Create test field
        $this->pdo->exec("
            INSERT INTO app.gis_layer_fields (layer_id, field_name, field_label, field_type, required)
            VALUES (9999, 'lot_no', 'Lot Number', 'integer', true)
        ");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM audit.gis_feature_versions WHERE feature_id IN ('11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222')");
        $this->pdo->exec("DELETE FROM app.gis_features WHERE id IN ('11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222')");
        $this->pdo->exec("DELETE FROM app.gis_layer_fields WHERE layer_id = 9999");
        $this->pdo->exec("DELETE FROM app.gis_layers WHERE id = 9999");
        parent::tearDown();
    }

    public function testEnforceGeometryType(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/Geometry type mismatch. Expected POLYGON, got ST_Point/');
        
        // Try inserting a point into a polygon layer
        $this->pdo->exec("
            INSERT INTO app.gis_features (id, layer_id, geom, attributes)
            VALUES ('11111111-1111-1111-1111-111111111111', 9999, ST_GeomFromText('POINT(121 14)', 4326), '{\"lot_no\": 1}')
        ");
    }

    public function testValidateAttributesRequiredField(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/Validation failed: Required attribute lot_no is missing./');
        
        // Try inserting without lot_no
        $this->pdo->exec("
            INSERT INTO app.gis_features (id, layer_id, geom, attributes)
            VALUES ('11111111-1111-1111-1111-111111111111', 9999, ST_GeomFromText('POLYGON((121 14, 121 15, 122 15, 122 14, 121 14))', 4326), '{}')
        ");
    }

    public function testFeatureVersioningOnInsertAndUpdate(): void
    {
        // 1. Insert successfully
        $this->pdo->exec("
            INSERT INTO app.gis_features (id, layer_id, geom, attributes, updated_by)
            VALUES ('22222222-2222-2222-2222-222222222222', 9999, ST_GeomFromText('POLYGON((121 14, 121 15, 122 15, 122 14, 121 14))', 4326), '{\"lot_no\": 1}', 1)
        ");

        $versionRow = $this->pdo->query("SELECT * FROM audit.gis_feature_versions WHERE feature_id = '22222222-2222-2222-2222-222222222222' AND version = 1")->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($versionRow, 'Version 1 not created');
        $this->assertSame('INSERT', $versionRow['operation']);
        $this->assertEquals(1, $versionRow['changed_by']);
        
        // 2. Update successfully
        $this->pdo->exec("
            UPDATE app.gis_features SET attributes = '{\"lot_no\": 2}', version = version + 1 WHERE id = '22222222-2222-2222-2222-222222222222'
        ");

        $versionRow2 = $this->pdo->query("SELECT * FROM audit.gis_feature_versions WHERE feature_id = '22222222-2222-2222-2222-222222222222' AND version = 2")->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($versionRow2, 'Version 2 not created');
        $this->assertSame('UPDATE', $versionRow2['operation']);
        
        // Check current version in main table
        $currentVersion = $this->pdo->query("SELECT version FROM app.gis_features WHERE id = '22222222-2222-2222-2222-222222222222'")->fetchColumn();
        $this->assertEquals(2, $currentVersion);
        
        // 3. Delete (soft-delete) successfully
        $this->pdo->exec("
            UPDATE app.gis_features SET deleted_at = NOW(), version = version + 1 WHERE id = '22222222-2222-2222-2222-222222222222'
        ");

        $versionRow3 = $this->pdo->query("SELECT * FROM audit.gis_feature_versions WHERE feature_id = '22222222-2222-2222-2222-222222222222' AND version = 3")->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($versionRow3, 'Version 3 not created');
        $this->assertSame('DELETE', $versionRow3['operation']);
    }
}
