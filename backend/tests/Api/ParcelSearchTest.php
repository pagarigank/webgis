<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-070 — Parcel list, search, and map integration acceptance.
 *
 * ACs covered here:
 *  - Keyword search matches lot, block, survey plan, title, tax declaration,
 *    parcel code, location, and barangay NAME (not just the PSGC code).
 *  - List is filterable by status and psgc_barangay, supports sort + pagination.
 *  - Map preview support: `bbox` restricts results to parcels intersecting the
 *    envelope (EPSG:4326, west,south,east,north).
 *  - Historical records excluded by default: `include_historical=true` is the
 *    ONLY way to see SUPERSEDED parcels — even a status=SUPERSEDED filter must
 *    return nothing until include_historical=true is passed.
 */
class ParcelSearchTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    private string $adminToken;

    private const PARCEL_PERMS = ['parcel.view', 'parcel.create', 'parcel.update', 'parcel.delete'];

    /** Survey plan id used by the seeded fixture parcel (cleaned on teardown). */
    private ?int $planId = null;

    /** Parcel ids seeded for this test, cleaned precisely on teardown. */
    private array $parcelIds = [];

    /** 10-digit PSGC code seeded into ref.psgc_areas (the API validates 10-12 digits). */
    private const PSGC_10 = '0701001010';

    protected function setUp(): void
    {
        parent::setUp();
        $app = $this->getAppInstance();
        $this->pdo = $app->getContainer()->get(\PDO::class);

        $this->cleanup();

        $user = $this->createMockUser($this->pdo, self::PARCEL_PERMS, ['SYS_ADMIN']);
        $this->adminToken = $user['token'];

        $this->seedSearchFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        if (!empty($this->parcelIds)) {
            $ids = implode(', ', array_map(fn ($id) => "'$id'", $this->parcelIds));
            $this->pdo->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.parcels' AND entity_id IN ($ids)");
            $this->pdo->exec("DELETE FROM app.parcels WHERE id IN ($ids)");
            $this->parcelIds = [];
        }
        $this->pdo->exec("DELETE FROM app.survey_plans WHERE plan_number = 'SRCH-PLAN-42'");
        $this->pdo->exec("DELETE FROM app.survey_plans WHERE psgc_barangay = '" . self::PSGC_10 . "'");
        $this->pdo->exec("DELETE FROM app.parcels WHERE psgc_barangay = '" . self::PSGC_10 . "'");
        $this->pdo->exec("DELETE FROM ref.psgc_areas WHERE code = '" . self::PSGC_10 . "'");
        $this->planId = null;
    }

    private function apiRequest(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->withHeader('Accept', 'application/json');
    }

    /** @return array{data: mixed[], total: int, limit: int, offset: int} decoded list payload */
    private function list(array $query): array
    {
        $qs = http_build_query($query);
        $response = $this->handle($this->apiRequest('GET', '/api/v1/parcels' . ($qs ? '?' . $qs : '')));
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        return $decoded['data'];
    }

    /**
     * Seed deterministic fixtures: five active parcels with distinct searchable
     * attributes plus one SUPERSEDED parcel (historical). One parcel carries a
     * survey plan reference and a polygon geometry near (121.0, 14.5).
     */
    private function seedSearchFixtures(): void
    {
        $this->pdo->exec("INSERT INTO app.survey_plans (plan_number, plan_type, psgc_barangay) VALUES ('SRCH-PLAN-42', 'Psd', '990101000') RETURNING id");
        $stmt = $this->pdo->query("SELECT id FROM app.survey_plans WHERE plan_number = 'SRCH-PLAN-42'");
        $this->planId = (int) $stmt->fetchColumn();

        // The API validates PSGC codes as 10-12 digits (sample seed uses 9-digit
        // synthetic codes), so seed a dedicated 10-digit barangay for the psgc filter.
        $this->pdo->exec(
            "INSERT INTO ref.psgc_areas (code, level, name, parent_code) "
            . "VALUES ('" . self::PSGC_10 . "', 'BARANGAY', 'SAMPLE_BRGY_FILTER', '990100000') "
            . "ON CONFLICT (code) DO NOTHING"
        );

        $polygon = '{"type":"MultiPolygon","coordinates":[[[[121.0,14.5],[121.1,14.5],[121.1,14.6],[121.0,14.6],[121.0,14.5]]]]}';

        $rows = [
            [
                'code'          => 'SEARCH_TEST_LOT',
                'lot_number'    => 'SRCH-LOT-A',
                'block_number'  => 'SRCH-BLOCK-1',
                'title_number_ref' => 'TCT-SRCH-001',
                'tax_declaration_no' => 'TD-SRCH-77',
                'psgc_barangay' => '990101000',
                'survey_plan_id' => $this->planId,
                'geom_json'     => $polygon,
            ],
            ['code' => 'SEARCH_TEST_BLOCK', 'block_number' => 'SRCH-BLOCK-2', 'psgc_barangay' => self::PSGC_10, 'survey_plan_id' => null, 'geom_json' => null],
            ['code' => 'SEARCH_TEST_TITLE', 'title_number_ref' => 'TCT-SRCH-002', 'psgc_barangay' => '990103000', 'survey_plan_id' => null, 'geom_json' => null],
            ['code' => 'SEARCH_TEST_TD', 'tax_declaration_no' => 'TD-SRCH-88', 'psgc_barangay' => '990104000', 'survey_plan_id' => null, 'geom_json' => null],
            ['code' => 'SEARCH_TEST_BRGY', 'location_description' => 'near SRCH plaza', 'psgc_barangay' => '990105000', 'survey_plan_id' => null, 'geom_json' => null],
            ['code' => 'SEARCH_HIST_01', 'status' => 'SUPERSEDED', 'psgc_barangay' => '990106000', 'survey_plan_id' => null, 'geom_json' => null],
        ];

        foreach ($rows as $r) {
            $status = $r['status'] ?? 'DRAFT';
            $stmt = $this->pdo->prepare(
                "INSERT INTO app.parcels "
                . "(id, parcel_code, lot_number, block_number, title_number_ref, tax_declaration_no, "
                . " location_description, psgc_barangay, status, geometry_source, survey_plan_id, geom, version) "
                . "VALUES (gen_random_uuid(), :code, :lot, :block, :title, :td, :loc, :psgc, :status, "
                . "'MANUAL_DRAWING', :plan, ST_Multi(ST_GeomFromGeoJSON(:geom)), 1) "
                . "RETURNING id"
            );
            $stmt->execute([
                ':code'  => $r['code'],
                ':lot'   => $r['lot_number'] ?? null,
                ':block' => $r['block_number'] ?? null,
                ':title' => $r['title_number_ref'] ?? null,
                ':td'    => $r['tax_declaration_no'] ?? null,
                ':loc'   => $r['location_description'] ?? null,
                ':psgc'  => $r['psgc_barangay'],
                ':status' => $status,
                ':plan'  => $r['survey_plan_id'],
                ':geom'  => $r['geom_json'],
            ]);
            $this->parcelIds[] = (string) $stmt->fetchColumn();
        }
    }

    public function testSearchByLotNumber(): void
    {
        $body = $this->list(['q' => 'SRCH-LOT-A']);
        $this->assertCount(1, $body['data']);
        $this->assertEquals('SEARCH_TEST_LOT', $body['data'][0]['parcel_code']);
    }

    public function testSearchByBlockNumber(): void
    {
        $body = $this->list(['q' => 'SRCH-BLOCK']);
        $codes = array_column($body['data'], 'parcel_code');
        sort($codes);
        $this->assertEquals(['SEARCH_TEST_BLOCK', 'SEARCH_TEST_LOT'], $codes);
    }

    public function testSearchBySurveyPlanNumber(): void
    {
        $body = $this->list(['q' => 'SRCH-PLAN-42']);
        $this->assertCount(1, $body['data']);
        $this->assertEquals('SEARCH_TEST_LOT', $body['data'][0]['parcel_code']);
        $this->assertEquals('SRCH-PLAN-42', $body['data'][0]['survey_plan_number']);
    }

    public function testSearchByTitleTaxOrCode(): void
    {
        $this->assertCount(1, $this->list(['q' => 'TCT-SRCH-001'])['data']);
        $this->assertCount(1, $this->list(['q' => 'TD-SRCH-88'])['data']);
        $this->assertCount(0, $this->list(['q' => 'TD-SRCH-99'])['data']);
        $this->assertCount(1, $this->list(['q' => 'SEARCH_TEST_BRGY'])['data']);
        $this->assertCount(1, $this->list(['q' => 'tct-srch-002'])['data']); // case-insensitive
    }

    public function testSearchByBarangayNameReturnsPsgcName(): void
    {
        // `SAMPLE_BRGY_3` is the ref.psgc_areas.name for code 990103000.
        $body = $this->list(['q' => 'SAMPLE_BRGY_3']);
        $this->assertCount(1, $body['data']);
        $this->assertEquals('SEARCH_TEST_TITLE', $body['data'][0]['parcel_code']);
        $this->assertEquals('990103000', $body['data'][0]['psgc_barangay']);
        $this->assertEquals('SAMPLE_BRGY_3', $body['data'][0]['psgc_barangay_name']);
    }

    public function testIncludeHistoricalIsOnlyWayToSeeSuperseded(): void
    {
        // Default list hides the historical parcel.
        $body = $this->list(['q' => 'SEARCH_HIST']);
        $this->assertEquals(0, $body['total']);
        $this->assertCount(0, $body['data']);

        // Even an explicit status=SUPERSEDED filter returns nothing on its own.
        $body = $this->list(['status' => 'SUPERSEDED']);
        $this->assertEquals(0, $body['total']);

        // include_historical=true is the only way to surface it.
        $body = $this->list(['include_historical' => 'true']);
        $superseded = array_values(array_filter($body['data'], fn ($p) => $p['status'] === 'SUPERSEDED'));
        $this->assertCount(1, $superseded);
        $this->assertEquals('SEARCH_HIST_01', $superseded[0]['parcel_code']);

        // And the flag combination (status + include_historical) returns it too.
        $body = $this->list(['status' => 'SUPERSEDED', 'include_historical' => 'true']);
        $this->assertEquals(1, $body['total']);
        $this->assertEquals('SEARCH_HIST_01', $body['data'][0]['parcel_code']);
    }

    public function testStatusFilter(): void
    {
        $all = $this->list([]);
        $this->assertGreaterThanOrEqual(5, $all['total']);

        $body = $this->list(['status' => 'DRAFT']);
        $this->assertGreaterThanOrEqual(5, $body['total']);
        foreach ($body['data'] as $p) {
            $this->assertEquals('DRAFT', $p['status']);
        }
    }

    public function testPsgcBarangayFilter(): void
    {
        // The 10-digit code is unique to the seeded block fixture.
        $body = $this->list(['psgc_barangay' => self::PSGC_10]);
        $this->assertEquals(1, $body['total']);
        $this->assertEquals('SEARCH_TEST_BLOCK', $body['data'][0]['parcel_code']);
    }

    public function testPaginationAndSort(): void
    {
        $page = $this->list(['sort' => 'parcel_code', 'dir' => 'ASC', 'limit' => 3, 'offset' => 0]);
        $this->assertCount(3, $page['data']);
        $codes = array_column($page['data'], 'parcel_code');
        sort($codes);
        $this->assertEquals($codes, array_map(fn ($p) => $p['parcel_code'], $page['data']));

        $second = $this->list(['sort' => 'parcel_code', 'dir' => 'ASC', 'limit' => 3, 'offset' => 3]);
        $this->assertGreaterThan(0, count($second['data']));
    }

    public function testBboxFilterRestrictsResults(): void
    {
        // Parcel SEARCH_TEST_LOT sits near (121.05, 14.55).
        $inside = $this->list(['bbox' => '120.9,14.4,121.2,14.7']);
        $codes = array_column($inside['data'], 'parcel_code');
        $this->assertContains('SEARCH_TEST_LOT', $codes);

        // Disjoint envelope excludes everything.
        $outside = $this->list(['bbox' => '150.0,-10.0,151.0,-9.0']);
        $this->assertEquals([], array_column($outside['data'], 'parcel_code'));
    }

    public function testBboxValidation(): void
    {
        $bad = [
            '1,2,3',                     // three parts
            'a,b,c,d',                   // non-numeric
            '121.0,14.6,120.0,14.5',    // west > east
        ];
        foreach ($bad as $bbox) {
            $response = $this->handle($this->apiRequest('GET', '/api/v1/parcels?bbox=' . urlencode($bbox)));
            $this->assertEquals(400, $response->getStatusCode(), "bbox '$bbox' should be rejected");
            $decoded = json_decode((string) $response->getBody(), true);
            $this->assertSame('VALIDATION_FAILED', $decoded['error']['code']);
        }
    }

    public function testListRequiresParcelViewPermission(): void
    {
        $user = $this->createMockUserWithPerms($this->pdo, ['audit.view']);
        $request = $this->createJsonRequest('GET', '/api/v1/parcels')
            ->withHeader('Authorization', 'Bearer ' . $user['token'])
            ->withHeader('Accept', 'application/json');
        $response = $this->handle($request);
        $this->assertEquals(403, $response->getStatusCode(), (string) $response->getBody());
    }

    private function createMockUserWithPerms(\PDO $pdo, array $permissions): array
    {
        $pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('TESTORG', 'Test Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT DO NOTHING");
        $orgId = (int) $pdo->query("SELECT id FROM app.organizations WHERE code = 'TESTORG'")->fetchColumn();

        $pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('searcher_403', 'searcher403@example.com', 'dummy', 'Searcher', $orgId, 'ACTIVE', 1) ON CONFLICT DO NOTHING");
        $userId = (int) $pdo->query("SELECT id FROM app.users WHERE username = 'searcher_403'")->fetchColumn();

        $pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('PARCEL_SEARCH_ONLY', 'Parcel Search Only', false) ON CONFLICT DO NOTHING");
        $roleId = (int) $pdo->query("SELECT id FROM app.roles WHERE code = 'PARCEL_SEARCH_ONLY'")->fetchColumn();
        $pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($userId, $roleId) ON CONFLICT DO NOTHING");

        foreach ($permissions as $permCode) {
            $pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('$permCode', '$permCode') ON CONFLICT DO NOTHING");
            $permId = (int) $pdo->query("SELECT id FROM app.permissions WHERE code = '$permCode'")->fetchColumn();
            $pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($roleId, $permId) ON CONFLICT DO NOTHING");
        }

        $token = \Firebase\JWT\JWT::encode([
            'sub' => (string) $userId, 'v' => 1, 'exp' => time() + 3600
        ], getenv('JWT_SECRET') ?: 'dummy_secret', 'HS256');

        return ['id' => $userId, 'token' => $token];
    }
}