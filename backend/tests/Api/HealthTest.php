<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function testHealthEndpointReturnsSuccessEnvelope(): void
    {
        $app = $this->getAppInstance();
        $request = $this->createRequest('GET', '/api/v1/health');
        
        $response = $app->handle($request);
        
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        
        $payload = json_decode((string) $response->getBody(), true);
        
        $this->assertTrue($payload['success']);
        $this->assertSame('pass', $payload['data']['status']);
        $this->assertNotEmpty($payload['data']['request_id']);
    }
}
