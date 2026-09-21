<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

class AuditQueryTest extends TestCase
{
    private \PDO $pdo;
    private array $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->token = $this->authToken('audittest');
    }

    protected function tearDown(): void
    {
        $this->pdo()->exec("DELETE FROM app.users WHERE username = 'audittest'");
        parent::tearDown();
    }

    public function testAuditLogsListAndPiiScrubbing(): void
    {
        // 1. Insert a dummy audit log with PII
        $this->pdo()->exec("INSERT INTO audit.audit_logs (action, entity_type, entity_id, old_values, new_values) VALUES (
            'CREATE', 'USER', '100', 
            '{\"password_hash\":\"secret\",\"email\":\"test@test.com\"}',
            '{\"password_hash\":\"secret2\",\"email\":\"test2@test.com\"}'
        )");
        $id = $this->pdo->lastInsertId();

        // 2. Fetch list
        $response = $this->handle(
            $this->createRequest('GET', '/api/v1/audit-logs')
                ->withHeader('Authorization', 'Bearer ' . $this->token['token'])
        );
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string)$response->getBody(), true)['data'];
        $this->assertNotEmpty($data);

        // 3. Fetch specific with PII scrubbing check
        $response = $this->handle(
            $this->createRequest('GET', "/api/v1/audit-logs/{$id}")
                ->withHeader('Authorization', 'Bearer ' . $this->token['token'])
        );
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $item = json_decode((string)$response->getBody(), true)['data'];
        $this->assertEquals('***REDACTED***', $item['old_values']['password_hash']);
        $this->assertEquals('***REDACTED***', $item['new_values']['email']);
        $this->assertEquals('***REDACTED***', $item['old_values']['email']);
    }
}
