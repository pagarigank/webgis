<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

class StyleTest extends TestCase
{
    private $pdo;
    private $adminToken;
    private $layerId = 777;

    protected function setUp(): void
    {
        parent::setUp();
        $app = $this->getAppInstance();
        $this->pdo = $app->getContainer()->get(\PDO::class);

        $this->cleanup();

        $this->pdo->exec("INSERT INTO app.gis_layers (id, code, name, geometry_type) VALUES ({$this->layerId}, 'style_test_layer', 'Style Test Layer', 'POLYGON')");

        $user = $this->createMockUser($this->pdo, ['gis.style.manage', 'gis.layer.update'], ['SYS_ADMIN']);
        $this->adminToken = $user['token'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM app.gis_layer_styles");
        $this->pdo->exec("DELETE FROM app.gis_layers WHERE id = {$this->layerId}");
    }

    public function testCreateSingleStyle()
    {
        $app = $this->getAppInstance();
        
        $request = $this->createJsonRequest('POST', "/api/v1/layers/{$this->layerId}/styles", [
            'style_type' => 'SINGLE',
            'default_rule' => ['fill' => '#ff0000', 'opacity' => 0.8],
            'is_active' => true
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        
        $response = $app->handle($request);
        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());
        
        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('id', $body['data']);
        
        $id = $body['data']['id'];
        $stmt = $this->pdo->query("SELECT * FROM app.gis_layer_styles WHERE id = $id");
        $style = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertEquals('SINGLE', $style['style_type']);
        $this->assertTrue((bool)$style['is_active']);
    }

    public function testCreateInvalidStyleTypeFails()
    {
        $app = $this->getAppInstance();
        
        $request = $this->createJsonRequest('POST', "/api/v1/layers/{$this->layerId}/styles", [
            'style_type' => 'UNKNOWN_TYPE',
            'default_rule' => ['fill' => '#ff0000']
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        
        $response = $app->handle($request);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testUpdateStyleSetsOthersInactive()
    {
        $app = $this->getAppInstance();
        
        $this->pdo->exec("
            INSERT INTO app.gis_layer_styles (layer_id, style_type, default_rule, is_active)
            VALUES ({$this->layerId}, 'SINGLE', '{\"fill\":\"#000\"}', true)
        ");
        
        $request = $this->createJsonRequest('POST', "/api/v1/layers/{$this->layerId}/styles", [
            'style_type' => 'CATEGORIZED',
            'attribute_field' => 'status',
            'default_rule' => ['fill' => '#cccccc'],
            'rules' => [['value' => 'ACTIVE', 'fill' => '#00ff00']],
            'is_active' => true
        ])->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        
        $response = $app->handle($request);
        $this->assertEquals(201, $response->getStatusCode());
        
        // Check that only 1 is active
        $stmt = $this->pdo->query("SELECT count(*) FROM app.gis_layer_styles WHERE layer_id = {$this->layerId} AND is_active = true");
        $this->assertEquals(1, (int)$stmt->fetchColumn());
    }
}
