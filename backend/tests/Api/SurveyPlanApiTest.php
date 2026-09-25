<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

class SurveyPlanApiTest extends TestCase
{
    private $pdo;
    private $token;
    private int $planId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $app = $this->getAppInstance();
        $this->pdo = $app->getContainer()->get(\PDO::class);

        $this->cleanup();
        $user = $this->createMockUser(
            $this->pdo,
            ['survey.view', 'survey.create', 'survey.update'],
            ['SYS_ADMIN']
        );
        $this->token = $user['token'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.survey_plans' AND entity_id IN (SELECT id::text FROM app.survey_plans WHERE plan_number LIKE 'TXSP%')");
        $this->pdo->exec("DELETE FROM app.survey_plans WHERE plan_number LIKE 'TXSP%'");
    }

    public function testCreateSurveyPlanSucceedsOverHttp(): void
    {
        $app = $this->getAppInstance();

        $request = $this->createJsonRequest('POST', '/api/v1/survey-plans', [
            'plan_number' => 'TXSP-1',
            'plan_type' => 'Psd',
            'surveyor_name' => 'Tx Surveyor',
        ])->withHeader('Authorization', 'Bearer ' . $this->token);

        $response = $app->handle($request);

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertGreaterThan(0, (int) $this->pdo->query("SELECT count(*) FROM app.survey_plans WHERE plan_number = 'TXSP-1'")->fetchColumn());
    }

    public function testUpdateSurveyPlanSucceedsOverHttp(): void
    {
        $this->pdo->exec("INSERT INTO app.survey_plans (plan_number, plan_type, version, created_by, updated_by) VALUES ('TXSP-2', 'Psd', 1, 1, 1)");
        $this->planId = (int) $this->pdo->query("SELECT id FROM app.survey_plans WHERE plan_number = 'TXSP-2'")->fetchColumn();

        $app = $this->getAppInstance();

        $request = $this->createJsonRequest('PUT', "/api/v1/survey-plans/{$this->planId}", [
            'plan_number' => 'TXSP-2',
            'plan_type' => 'Psu',
            'version' => 1,
        ])->withHeader('Authorization', 'Bearer ' . $this->token);

        $response = $app->handle($request);

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testDeleteSurveyPlanSucceedsOverHttp(): void
    {
        $this->pdo->exec("INSERT INTO app.survey_plans (plan_number, plan_type, version, created_by, updated_by) VALUES ('TXSP-3', 'Psd', 1, 1, 1)");
        $planId = (int) $this->pdo->query("SELECT id FROM app.survey_plans WHERE plan_number = 'TXSP-3'")->fetchColumn();

        $app = $this->getAppInstance();

        $request = $this->createJsonRequest('DELETE', "/api/v1/survey-plans/{$planId}", [
            'reason' => 'Superseded by resurvey',
        ])->withHeader('Authorization', 'Bearer ' . $this->token);

        $response = $app->handle($request);

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testSurveyPlanWritesAnAuditRow(): void
    {
        $app = $this->getAppInstance();

        $request = $this->createJsonRequest('POST', '/api/v1/survey-plans', [
            'plan_number' => 'TXSP-4',
            'plan_type' => 'Psd',
        ])->withHeader('Authorization', 'Bearer ' . $this->token);

        $this->assertEquals(201, $app->handle($request)->getStatusCode());

        $count = (int) $this->pdo->query(
            "SELECT count(*) FROM audit.audit_logs WHERE entity_type = 'app.survey_plans' AND action = 'CREATE'"
        )->fetchColumn();

        $this->assertGreaterThan(0, $count);
    }
}
