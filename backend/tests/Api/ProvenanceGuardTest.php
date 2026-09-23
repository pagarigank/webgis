<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-072 — Provenance guard acceptance (FR-199).
 *
 * ACs covered here:
 *  - A parcel cannot be created with a survey-derived provenance unless survey
 *    data (survey_plan_id) is attached and a justification is recorded.
 *  - Updating a parcel to a survey-derived provenance requires survey data on
 *    the record (either payload or pre-existing) and a recorded change_reason.
 *  - Non-survey provenance values are unaffected by the guard.
 */
class ProvenanceGuardTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    /** @var string[] Parcel ids created during the test, cleaned in tearDown. */
    private array $createdIds = [];

    /** @var int[] Survey plan ids created during the test, cleaned in tearDown. */
    private array $planIds = [];

    private string $adminToken;

    private const PARCEL_PERMS = ['parcel.view', 'parcel.create', 'parcel.update', 'parcel.delete'];

    private const SURVEY_SOURCES = [
        'SURVEY_COORDINATES',
        'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
        'TRANSFORMED_FROM_HISTORICAL_SURVEY',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $app = $this->getAppInstance();
        $this->pdo = $app->getContainer()->get(\PDO::class);

        $this->cleanup();

        $user = $this->createMockUser($this->pdo, self::PARCEL_PERMS, ['SYS_ADMIN']);
        $this->adminToken = $user['token'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        if (!empty($this->createdIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $this->createdIds));
            $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.parcels' AND entity_id IN ($ids)");
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
            $this->createdIds = [];
        }
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'GUARD_TEST_%'");
        if (!empty($this->planIds)) {
            $pids = implode(', ', array_map(fn ($id) => (int) $id, $this->planIds));
            $this->pdo->exec("DELETE FROM app.survey_plans WHERE id IN ($pids)");
            $this->planIds = [];
        }
    }

    private function apiRequest(string $method, string $path, array $data = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->createJsonRequest($method, $path, $data)
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('Accept', 'application/json');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /** Create a survey plan for the guard to reference; cleaned on teardown. */
    private function createSurveyPlan(): int
    {
        $n = count($this->planIds) + 1;
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.survey_plans (plan_number, plan_type) VALUES (:num, 'Psd') RETURNING id"
        );
        $stmt->execute([':num' => 'GUARD-PLAN-' . $n]);
        $id = (int) $stmt->fetchColumn();
        $this->planIds[] = $id;
        return $id;
    }

    /** Create a parcel through the API (expects 201) and track it for cleanup. */
    private function apiCreate(string $parcelCode, array $overrides = []): array
    {
        $body = array_merge([
            'parcel_code'     => $parcelCode,
            'provenance'      => 'MANUAL_DRAWING',
            'source_area_sqm' => 250.0,
        ], $overrides);

        $request = $this->apiRequest('POST', '/api/v1/parcels', $body);
        $response = $this->handle($request);
        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->createdIds[] = $decoded['data']['id'];
        return $decoded['data'];
    }

    private function expectValidationFailed(\Psr\Http\Message\ResponseInterface $response): array
    {
        $this->assertEquals(400, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $decoded['error']['code']);
        return $decoded;
    }

    public function testCreateSurveyDerivedWithoutSurveyDataFails(): void
    {
        foreach (self::SURVEY_SOURCES as $src) {
            $response = $this->handle($this->apiRequest('POST', '/api/v1/parcels', [
                'parcel_code' => 'GUARD_TEST_CREATE_' . (count($this->createdIds) + 1),
                'provenance'  => $src,
                'change_reason' => 'Relabelled from field survey',
            ]));
            $decoded = $this->expectValidationFailed($response);
            $this->assertStringContainsString('survey_plan_id', $decoded['error']['message'], $src);
        }
    }

    public function testCreateSurveyDerivedWithoutJustificationFails(): void
    {
        $planId = $this->createSurveyPlan();

        $response = $this->handle($this->apiRequest('POST', '/api/v1/parcels', [
            'parcel_code'   => 'GUARD_TEST_NOJUST',
            'provenance'    => 'SURVEY_COORDINATES',
            'survey_plan_id' => $planId,
        ]));
        $decoded = $this->expectValidationFailed($response);
        $this->assertStringContainsString('justification', $decoded['error']['message']);
    }

    public function testCreateSurveyDerivedWithSurveyDataAndJustificationSucceeds(): void
    {
        $planId = $this->createSurveyPlan();

        $parcel = $this->apiCreate('GUARD_TEST_CREATE_OK', [
            'provenance'     => 'SURVEY_COORDINATES',
            'survey_plan_id' => $planId,
            'change_reason'  => 'Created directly from surveyed boundaries',
        ]);

        $this->assertSame('SURVEY_COORDINATES', $parcel['provenance']);
        $this->assertSame($planId, $parcel['survey_plan_id']);
        $this->assertEquals(1, $parcel['version']);
    }

    public function testCreateWithNonSurveyProvenanceIsNotGuarded(): void
    {
        $parcel = $this->apiCreate('GUARD_TEST_MANUAL', [
            'provenance' => 'MANUAL_DRAWING',
        ]);
        $this->assertSame('MANUAL_DRAWING', $parcel['provenance']);
        $this->assertNull($parcel['survey_plan_id']);
    }

    public function testCreateRejectsUnknownSurveyPlan(): void
    {
        $response = $this->handle($this->apiRequest('POST', '/api/v1/parcels', [
            'parcel_code'   => 'GUARD_TEST_BADPLAN',
            'provenance'    => 'SURVEY_COORDINATES',
            'survey_plan_id' => 999999999,
            'change_reason' => 'x',
        ]));
        $this->expectValidationFailed($response);
    }

    public function testUpdateToSurveyDerivedWithoutSurveyDataFails(): void
    {
        $parcel = $this->apiCreate('GUARD_TEST_UPD_NODATA');

        $response = $this->handle($this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'provenance'   => 'SURVEY_COORDINATES',
            'change_reason' => 'survey upgrade',
        ], ['If-Match' => '1']));
        $decoded = $this->expectValidationFailed($response);
        $this->assertStringContainsString('survey_plan_id', $decoded['error']['message']);

        // The parcel must remain untouched (no version bump).
        $row = $this->pdo->query("SELECT geometry_source, version FROM app.parcels WHERE id = '{$parcel['id']}'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('MANUAL_DRAWING', $row['geometry_source']);
        $this->assertEquals(1, (int) $row['version']);
    }

    public function testUpdateToSurveyDerivedWithoutJustificationFails(): void
    {
        $parcel = $this->apiCreate('GUARD_TEST_UPD_NOJUST');
        $planId = $this->createSurveyPlan();

        $response = $this->handle($this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'provenance'    => 'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
            'survey_plan_id' => $planId,
        ], ['If-Match' => '1']));
        $decoded = $this->expectValidationFailed($response);
        $this->assertStringContainsString('justification', $decoded['error']['message']);
    }

    public function testUpdateToSurveyDerivedWithSurveyDataAndJustificationSucceeds(): void
    {
        $parcel = $this->apiCreate('GUARD_TEST_UPD_OK');
        $planId = $this->createSurveyPlan();

        $request = $this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'provenance'     => 'TRANSFORMED_FROM_HISTORICAL_SURVEY',
            'survey_plan_id' => $planId,
            'change_reason'  => 'Parcel relabelled after attaching the original survey plan',
        ], ['If-Match' => '1']);
        $response = $this->handle($request);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('TRANSFORMED_FROM_HISTORICAL_SURVEY', $decoded['data']['provenance']);
        $this->assertSame($planId, $decoded['data']['survey_plan_id']);
        $this->assertEquals(2, $decoded['data']['version']);
    }

    public function testUpdateToSurveyDerivedWithPreAttachedSurveyDataSucceeds(): void
    {
        // Survey data attached first while still non-survey, then relabel.
        $parcel = $this->apiCreate('GUARD_TEST_UPD_PREATTACH');
        $planId = $this->createSurveyPlan();

        $req1 = $this->handle($this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'survey_plan_id' => $planId,
        ], ['If-Match' => '1']));
        $this->assertEquals(200, $req1->getStatusCode(), (string) $req1->getBody());

        $req2 = $this->handle($this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'provenance'  => 'SURVEY_COORDINATES',
            'change_reason' => 'proper survey attached',
        ], ['If-Match' => '2']));
        $this->assertEquals(200, $req2->getStatusCode(), (string) $req2->getBody());

        $decoded = json_decode((string) $req2->getBody(), true);
        $this->assertSame('SURVEY_COORDINATES', $decoded['data']['provenance']);
        $this->assertSame($planId, $decoded['data']['survey_plan_id']);
        $this->assertEquals(3, $decoded['data']['version']);
    }

    public function testUpdateToNonSurveyProvenanceIsNotGuarded(): void
    {
        $parcel = $this->apiCreate('GUARD_TEST_UPD_MANUAL');

        $response = $this->handle($this->apiRequest('PATCH', '/api/v1/parcels/' . $parcel['id'], [
            'provenance' => 'DIGITIZED_FROM_IMAGERY',
        ], ['If-Match' => '1']));
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('DIGITIZED_FROM_IMAGERY', $decoded['data']['provenance']);
    }
}