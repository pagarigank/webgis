<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use App\GIS\Domain\MigrationGeneratorService;
use Slim\Psr7\Request;

class ExpressionIndexTest extends TestCase
{
    public function testMigrationGeneratedOnSearchableFlagChange()
    {
        $app = $this->getAppInstance();
        $pdo = $app->getContainer()->get(\PDO::class);
        
        $layerId = 999;
        
        $pdo->exec("DELETE FROM app.gis_layer_fields WHERE id = 888");
        $pdo->exec("DELETE FROM app.gis_layers WHERE id = 999");
        
        // 1. Setup Layer and Field (searchable = false)
        $pdo->exec("INSERT INTO app.gis_layers (id, code, name, geometry_type) VALUES ($layerId, 'test_layer', 'test_layer', 'POINT')");
        $pdo->exec("
            INSERT INTO app.gis_layer_fields (id, layer_id, field_name, field_label, field_type, searchable, sortable)
            VALUES (888, $layerId, 'address', 'Address', 'text', 'f', 'f')
        ");
        
        // Ensure migrations path exists and is clean
        $migrationsPath = __DIR__ . '/../../database/migrations/';
        if (!is_dir($migrationsPath)) {
            mkdir($migrationsPath, 0777, true);
        }
        
        $filesBefore = glob($migrationsPath . '*_add_idx_layer_999_address.php');
        
        // 2. Mock Admin User
        $user = $this->createMockUser($pdo, ['gis.layer.update'], ['SYS_ADMIN']);
        $token = $user['token'];
        
        // 3. Update the field to be searchable
        $request = $this->createJsonRequest('PUT', "/api/v1/layers/{$layerId}/fields/888", [
            'searchable' => true
        ])->withHeader('Authorization', 'Bearer ' . $token);
        
        $response = $app->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string)$response->getBody());
        
        // 4. Verify a migration file was created
        $filesAfter = glob($migrationsPath . '*_add_idx_layer_999_address.php');
        $this->assertCount(count($filesBefore) + 1, $filesAfter, "A new migration file should have been generated.");
        
        // 5. Verify the content of the migration file
        $newFile = array_diff($filesAfter, $filesBefore);
        $newFile = reset($newFile);
        
        $content = file_get_contents($newFile);
        $this->assertStringContainsString("CREATE INDEX IF NOT EXISTS idx_features_l999_address", $content);
        $this->assertStringContainsString("ON app.gis_features ((attributes->>'address'))", $content);
        
        // Cleanup
        unlink($newFile);
        $pdo->exec("DELETE FROM app.gis_layer_fields WHERE id = 888");
        $pdo->exec("DELETE FROM app.gis_layers WHERE id = 999");
    }
}
