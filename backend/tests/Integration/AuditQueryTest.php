<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

class AuditQueryTest extends TestCase
{
    public function testAuditLogsListAndPiiScrubbing(): void
    {
        // 1. Insert a dummy audit log with PII
        $pdo = $this->getContainer()->get(\PDO::class);
        $pdo->exec("INSERT INTO audit.audit_logs (action, entity_type, entity_id, old_values, new_values) VALUES (
            'CREATE', 'USER', '100', 
            '{\"password_hash\":\"secret\",\"email\":\"test@test.com\"}',
            '{\"password_hash\":\"secret2\",\"email\":\"test2@test.com\"}'
        )");
        $id = $pdo->lastInsertId();

        // 2. Fetch list
        $response = $this->actingAs('SYS_ADMIN')->get('/api/v1/audit-logs');
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getBody(), true)['data'];
        $this->assertNotEmpty($data);

        // 3. Fetch specific with PII scrubbing check
        $response = $this->actingAs('SYS_ADMIN')->get("/api/v1/audit-logs/{$id}");
        $this->assertEquals(200, $response->getStatusCode());
        
        $item = json_decode((string)$response->getBody(), true)['data'];
        $this->assertEquals('***REDACTED***', $item['old_values']['password_hash']);
        $this->assertEquals('***REDACTED***', $item['new_values']['email']);
        $this->assertEquals('***REDACTED***', $item['old_values']['email']);
    }
}
