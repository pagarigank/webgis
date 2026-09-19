<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

class ErrorEnvelopeTest extends TestCase
{
    public function testNotFoundReturnsErrorEnvelope(): void
    {
        $app = $this->getAppInstance();
        $request = $this->createRequest('GET', '/api/v1/non-existent-route');
        
        $response = $app->handle($request);
        
        $this->assertSame(404, $response->getStatusCode());
        
        $payload = json_decode((string) $response->getBody(), true);
        
        $this->assertFalse($payload['success']);
        $this->assertSame('HTTP_ERROR_404', $payload['error']['code']);
        $this->assertSame('Not found.', $payload['error']['message']);
    }

    public function testMethodNotAllowedReturnsErrorEnvelope(): void
    {
        $app = $this->getAppInstance();
        // /api/v1/health is GET only
        $request = $this->createRequest('POST', '/api/v1/health');
        
        $response = $app->handle($request);
        
        $this->assertSame(405, $response->getStatusCode());
        
        $payload = json_decode((string) $response->getBody(), true);
        
        $this->assertFalse($payload['success']);
        $this->assertSame('HTTP_ERROR_405', $payload['error']['code']);
    }
}
