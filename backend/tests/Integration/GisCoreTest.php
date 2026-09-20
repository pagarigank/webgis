<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;
use PDOException;


class GisCoreTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->getAppInstance()->getContainer()->get(PDO::class);
        $this->pdo->exec("SET app.user_id = '1'"); // Bypass RLS for test
    }

    public function testEnforceGeometryType(): void
    {
        // Create polygon layer
        $this->pdo->exec("
            INSERT INTO app.gis_layers (code, name, geometry_type, srid) 
            VALUES ('TEST_POLY_LAYER', 'Test Poly', 'POLYGON', 4326)
            ON CONFLICT DO NOTHING
        ");
        
        $layerId = $this->pdo->query("SELECT id FROM app.gis_layers WHERE code = 'TEST_POLY_LAYER'")->fetchColumn();
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/Geometry type mismatch/');

        // Try to insert a Point into a Polygon layer
        $this->pdo->exec("
            INSERT INTO app.gis_features (id, layer_id, geom, attributes)
            VALUES (gen_random_uuid(), {$layerId}, ST_GeomFromText('POINT(120.0 15.0)', 4326), '{}')
        ");
    }

    public function testValidateAttributes(): void
    {
        // Create point layer
        $this->pdo->exec("
            INSERT INTO app.gis_layers (code, name, geometry_type, srid) 
            VALUES ('TEST_ATTR_LAYER', 'Test Attr', 'POINT', 4326)
            ON CONFLICT DO NOTHING
        ");
        $layerId = $this->pdo->query("SELECT id FROM app.gis_layers WHERE code = 'TEST_ATTR_LAYER'")->fetchColumn();

        // Create a required field
        $this->pdo->exec("
            INSERT INTO app.gis_layer_fields (layer_id, field_name, field_label, field_type, required)
            VALUES ({$layerId}, 'building_name', 'Building Name', 'text', true)
            ON CONFLICT DO NOTHING
        ");

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/Validation failed: Required attribute building_name is missing./');

        // Insert missing required field
        $this->pdo->exec("
            INSERT INTO app.gis_features (id, layer_id, geom, attributes)
            VALUES (gen_random_uuid(), {$layerId}, ST_GeomFromText('POINT(120.0 15.0)', 4326), '{\"other_field\": \"value\"}')
        ");
    }

    public function testFeatureVersioning(): void
    {
        // Create linestring layer
        $this->pdo->exec("
            INSERT INTO app.gis_layers (code, name, geometry_type, srid) 
            VALUES ('TEST_VER_LAYER', 'Test Ver', 'LINESTRING', 4326)
            ON CONFLICT DO NOTHING
        ");
        $layerId = $this->pdo->query("SELECT id FROM app.gis_layers WHERE code = 'TEST_VER_LAYER'")->fetchColumn();

        // Insert and get the generated UUID
        $stmt = $this->pdo->query("
            INSERT INTO app.gis_features (id, layer_id, geom, attributes, version)
            VALUES (gen_random_uuid(), {$layerId}, ST_GeomFromText('LINESTRING(120 15, 121 16)', 4326), '{}', 1)
            RETURNING id
        ");
        $uuid = $stmt->fetchColumn();

        // Update
        $this->pdo->exec("
            UPDATE app.gis_features 
            SET version = 2, attributes = '{\"color\": \"red\"}'
            WHERE id = '{$uuid}'
        ");

        // Verify versions
        $stmt = $this->pdo->query("SELECT version, operation, attributes->>'color' as color FROM audit.gis_feature_versions WHERE feature_id = '{$uuid}' ORDER BY version ASC");
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $versions);
        $this->assertEquals('INSERT', $versions[0]['operation']);
        $this->assertEquals(1, $versions[0]['version']);

        $this->assertEquals('UPDATE', $versions[1]['operation']);
        $this->assertEquals(2, $versions[1]['version']);
        $this->assertEquals('red', $versions[1]['color']);
    }
}
