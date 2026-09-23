<?php
declare(strict_types=1);

namespace Tests\Spatial;

use Tests\TestCase;

/**
 * TASK-075 — nearest control point query (GIST KNN + RLS scope).
 *
 * Controller under test: App\Survey\Http\ControlPointController::nearest()
 *   GET /api/v1/control-points/nearest?lat=&lon=&limit=&type=
 *
 * Response envelope (Envelope::success with a pagination payload):
 *   {
 *     success: true,
 *     data: {
 *       data:  [ ...rows sorted by ascending distance... ],
 *       total: N,
 *       limit: X
 *     }
 *   }
 * So the rows live at `body['data']['data']` and totals at
 * `body['data']['total']` / `body['data']['limit']`.
 */
class NearestPointTest extends TestCase
{


    /** Fake psgc for the scoped barangay (10 digits, unique to this class). */
    private const PSGC = '9999990001';

    private \PDO $pdo;

    /** GLOBAL-scope admin; may see every point. */
    private array $admin;

    /** User with the view permission but no data scope → sees nothing. */
    private array $scopedOut;

    /** User scoped to barangay PSGC 9999990001 → sees only CP075_B. */
    private array $brgyScoped;

    /** User with no permission at all → 403 from the authorize middleware. */
    private array $noPerm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->cleanup();

        // Role codes are unique to this class so a permission grant for one
        // user can never leak into another user that shares a global role row.
        // The route carries authorize:control_point.view, so a user whose role
        // grants nothing must be rejected with 403 before the controller runs.
        $this->admin      = $this->createUser('cp075admin', ['control_point.view'], ['CP075_ADMIN'], ['GLOBAL' => 'VIEW']);
        $this->scopedOut  = $this->createUser('cp075scopedout', ['control_point.view'], ['CP075_EDITOR']);
        $this->brgyScoped = $this->createUser('cp075brgy', ['control_point.view'], ['CP075_EDITOR'], ['BARANGAY' => ['9999990001', 'EDIT']]);
        $this->noPerm     = $this->createUser('cp075noperm', [], ['CP075_NOPERM']);

        // Hermetic fixtures: this class must NOT depend on residue left by a
        // sibling test. Seed the psgc row first (the point FK references it),
        // then the three points in a known layout:
        //   CP075_A  BLLM            ~2 m from the query point
        //   CP075_B  CONTROL_POINT   ~11 m, inside barangay 9999990001
        //   CP075_C  BLLM            far (Bulacan)
        $this->seedPsgc(self::PSGC);
        $this->seedPoint('CP075_A', 'BLLM', 121.000000, 14.600000, null);
        $this->seedPoint('CP075_B', 'CONTROL_POINT', 121.000120, 14.600000, self::PSGC);
        $this->seedPoint('CP075_C', 'BLLM', 121.200000, 15.200000, null);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM app.survey_control_points WHERE point_name LIKE 'CP075_%'");
        $this->pdo->exec("DELETE FROM app.survey_control_points WHERE point_name LIKE 'CP075MASS_%'");
        $this->pdo->exec("DELETE FROM app.survey_control_points WHERE point_name LIKE 'TEST_CP_%'");
        $this->pdo->exec("DELETE FROM app.survey_control_points WHERE point_name LIKE 'TEST_CPMASS_%'");
        $this->pdo->exec(
            "DELETE FROM audit.audit_logs WHERE entity_type = 'app.survey_control_points' "
            . "AND entity_id IN (SELECT id::varchar FROM app.survey_control_points WHERE point_name LIKE 'CP075_%')"
        );
        $this->pdo->exec("DELETE FROM ref.psgc_areas WHERE code = '" . self::PSGC . "'");
        $this->pdo->exec("DELETE FROM app.users WHERE username LIKE 'cp075%'");
    }

    /**
     * @param array<string, string|array{0: string, 1: string}> $scopes
     *        scope_type => access_level, or scope_type => [code, access_level]
     */
    private function createUser(string $username, array $permissions, array $roles, array $scopes = []): array
    {
        $this->pdo->exec("INSERT INTO app.organizations (code, name, org_type, status) VALUES ('TESTORG', 'Test Org', 'GOVERNMENT', 'ACTIVE') ON CONFLICT DO NOTHING");
        $orgId = (int) $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'TESTORG'")->fetchColumn();

        $this->pdo->exec("DELETE FROM app.users WHERE username = '$username'");
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version) VALUES ('$username', '$username@example.com', 'dummy', 'Test', $orgId, 'ACTIVE', 1)");
        $userId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = '$username'")->fetchColumn();

        $roleIds = [];
        foreach ($roles as $roleCode) {
            $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('$roleCode', '$roleCode', false) ON CONFLICT DO NOTHING");
            $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = '$roleCode'")->fetchColumn();
            $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($userId, $roleId) ON CONFLICT DO NOTHING");
            $roleIds[] = $roleId;

            foreach ($permissions as $permCode) {
                $this->pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('$permCode', '$permCode') ON CONFLICT DO NOTHING");
                $permId = (int) $this->pdo->query("SELECT id FROM app.permissions WHERE code = '$permCode'")->fetchColumn();
                $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($roleId, $permId) ON CONFLICT DO NOTHING");
            }
        }

        // data_scopes rows: scope_type => access_level, or [code, access_level]
        foreach ($scopes as $scopeType => $value) {
            $code  = is_array($value) ? $value[0] : null;
            $level = is_array($value) ? $value[1] : $value;
            $ref   = $code === null ? 'NULL' : "'" . str_replace("'", "''", (string) $code) . "'";
            $this->pdo->exec(
                "INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level) "
                . "VALUES ($userId, '$scopeType', $ref, '$level')"
            );
        }

        $token = \Firebase\JWT\JWT::encode(
            ['sub' => (string) $userId, 'iat' => time(), 'exp' => time() + 3600],
            (string) getenv('JWT_SECRET'),
            'HS256'
        );

        return ['id' => $userId, 'token' => $token];
    }

    private function seedPsgc(string $code): void
    {
        $this->pdo->exec(
            "INSERT INTO ref.psgc_areas (code, level, name) VALUES ('$code', 'BARANGAY', 'CP075 Test District') "
            . 'ON CONFLICT (code) DO UPDATE SET level = EXCLUDED.level, name = EXCLUDED.name'
        );
    }

    private function seedPoint(string $name, string $type, float $lon, float $lat, ?string $psgc): int
    {
        $crsId = (int) $this->pdo->query('SELECT id FROM ref.crs_registry WHERE srid = 3123')->fetchColumn();
        $psgcSql = $psgc === null ? 'NULL' : "'" . $psgc . "'";
        $this->pdo->exec(
            "INSERT INTO app.survey_control_points "
            . '(point_name, point_type, native_crs_id, latitude, longitude, coordinate_origin, status, psgc_barangay, geom) '
            . "VALUES ('$name', '$type', $crsId, $lat, $lon, 'GEOGRAPHIC', 'UNVERIFIED', $psgcSql, "
            . "ST_SetSRID(ST_MakePoint($lon, $lat), 4326))"
        );
        return (int) $this->pdo->lastInsertId();
    }

    private function nearest(array $user, string $query): array
    {
        $request  = $this->createJsonRequest('GET', '/api/v1/control-points/nearest' . $query)
            ->withHeader('Authorization', 'Bearer ' . $user['token'])
            ->withHeader('Accept', 'application/json');
        $response = $this->handle($request);
        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];
    }

    public function testNearestReturnsClosestFirstWithDistance(): void
    {
        $result = $this->nearest($this->admin, '?lat=14.600000&lon=121.000020');
        $this->assertSame(200, $result['status']);

        $rows = $result['body']['data']['data'];
        $this->assertCount(3, $rows); // GLOBAL scope sees all three

        // Distance ascending: A is ~2 m away, B ~11 m, C far in Bulacan.
        $this->assertSame('CP075_A', $rows[0]['point_name']);
        $this->assertSame('CP075_B', $rows[1]['point_name']);
        $this->assertSame('CP075_C', $rows[2]['point_name']);

        foreach ($rows as $i => $row) {
            $this->assertArrayHasKey('distance_m', $row);
            $this->assertIsFloat($row['distance_m']);
            $this->assertGreaterThan(0.0, $row['distance_m']);
            // The controller emits ST_AsGeoJSON(cp.geom)::json, so `geom` is a
            // decoded GeoJSON object (array), not a raw string: assert its
            // type field carries the Point marker.
            $this->assertSame('Point', $row['geom']['type']);
            if ($i > 0) {
                $this->assertGreaterThan($rows[$i - 1]['distance_m'], $row['distance_m']);
            }
        }

        $this->assertSame(3, $result['body']['data']['total']);
        $this->assertSame(10, $result['body']['data']['limit']);
    }

    public function testNearestHonorsLimitAndTypeFilter(): void
    {
        $limited = $this->nearest($this->admin, '?lat=14.600000&lon=121.000020&limit=1');
        $rows     = $limited['body']['data']['data'];
        $this->assertCount(1, $rows);
        $this->assertSame('CP075_A', $rows[0]['point_name']);
        $this->assertSame(1, $limited['body']['data']['limit']);

        $typed = $this->nearest($this->admin, '?lat=14.600000&lon=121.000020&type=BLLM');
        $names = array_column($typed['body']['data']['data'], 'point_name');
        $this->assertSame(['CP075_A', 'CP075_C'], $names); // CP075_B is CONTROL_POINT

        $clamped = $this->nearest($this->admin, '?lat=14.600000&lon=121.000020&limit=1000');
        $this->assertCount(3, $clamped['body']['data']['data']); // only 3 exist
        $this->assertSame(100, $clamped['body']['data']['limit']);
    }

    public function testNearestValidatesCoordinatesAndType(): void
    {
        $missing = $this->nearest($this->admin, '?lon=121.0');
        $this->assertSame(400, $missing['status']);
        $this->assertSame('VALIDATION_FAILED', $missing['body']['error']['code']);

        $outOfRange = $this->nearest($this->admin, '?lat=91.0&lon=121.0');
        $this->assertSame(400, $outOfRange['status']);
        $this->assertSame('VALIDATION_FAILED', $outOfRange['body']['error']['code']);

        $badType = $this->nearest($this->admin, '?lat=14.6&lon=121.0&type=NOT_A_TYPE');
        $this->assertSame(400, $badType['status']);
        $this->assertSame('VALIDATION_FAILED', $badType['body']['error']['code']);
    }

    public function testNearestRequiresViewPermission(): void
    {
        $result = $this->nearest($this->noPerm, '?lat=14.600000&lon=121.000020');
        $this->assertSame(403, $result['status']);
    }

    public function testNearestRespectsScopeUserWithoutAnyScopeSeesNothing(): void
    {
        $result = $this->nearest($this->scopedOut, '?lat=14.600000&lon=121.000020');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['body']['data']['data']);
        $this->assertSame(0, $result['body']['data']['total']);
    }

    public function testNearestBarangayScopedUserSeesOnlyMatchingPoints(): void
    {
        $result = $this->nearest($this->brgyScoped, '?lat=14.600000&lon=121.000100');

        $this->assertSame(200, $result['status']);
        $names = array_column($result['body']['data']['data'], 'point_name');
        $this->assertSame(['CP075_B'], $names); // only the point inside 9999990001
    }

    /**
     * The KNN ORDER BY geom <-> ... is served by the GIST index on geom.
     *
     * A tiny table (3 rows) always gets a sequential scan from the planner, so
     * this test seeds enough points (via generate_series) for the planner to
     * prefer the gix_cp_geom GIST index, then verifies the chosen plan by
     * EXPLAIN. The WHERE predicate mirrors the controller's scope call exactly
     * (`app.fn_user_can_see(NULLIF(current_setting('app.user_id', true), ''),
     * cp.psgc_barangay, NULL)`), and the ORDER BY geometry operator is the same
     * `<->` the controller uses.
     */
    public function testNearestUsesGistIndexVerifiedByExplain(): void
    {
        // Mass-seed a dense grid around the query point so KNN becomes cheaper
        // than a seq scan + sort. Coordinates stay far from the seeded A/B/C.
        $lon0 = 121.000000;
        $lat0 = 14.600000;
        $this->pdo->exec(
            "INSERT INTO app.survey_control_points "
            . '(point_name, point_type, latitude, longitude, coordinate_origin, status, geom) '
            . "SELECT 'CP075MASS_' || gp, 'BLLM', $lat0 + (gp % 200) * 0.000001, "
            . "       $lon0 + floor(gp / 200.0) * 0.000001, 'GEOGRAPHIC', 'UNVERIFIED', "
            . "       ST_SetSRID(ST_MakePoint($lon0 + floor(gp / 200.0) * 0.000001, "
            . "       $lat0 + (gp % 200) * 0.000001), 4326) "
            . 'FROM generate_series(1, 20000) AS gp'
        );
        $this->pdo->exec('ANALYZE app.survey_control_points');

        // Must not run under the data-facing RLS function results, because a
        // fn_user_can_see that filters to zero rows has no bearing on whether
        // the GIST index can serve the ordered query. EXPLAIN never executes.
        $ref = 'ST_SetSRID(ST_MakePoint(121.00000200, 14.60000000), 4326)';
        $sql = 'SELECT cp.id, cp.point_name, cp.geom, '
             . 'ROUND((ST_Distance(cp.geom::geography, ' . $ref . '::geography))::numeric, 2) AS distance_m '
             . 'FROM app.survey_control_points cp '
             . 'WHERE cp.deleted_at IS NULL '
             . 'AND app.fn_user_can_see(NULLIF(current_setting(\'app.user_id\', true), \'\')::bigint, cp.psgc_barangay, NULL) '
             . 'ORDER BY cp.geom <-> ' . $ref . ' '
             . 'LIMIT 10';

        $planJson = $this->pdo->query('EXPLAIN (FORMAT JSON) ' . $sql)->fetchColumn();
        $plan     = json_decode((string) $planJson, true);

        $this->assertIsArray($plan);
        $found = false;
        $this->collectIndexNames($plan, $found);
        $this->assertTrue($found, 'EXPLAIN plan must use the gix_cp_geom GIST index');
    }

    private function collectIndexNames(array $node, bool &$found): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'Index Name' && (string) $value === 'gix_cp_geom') {
                $found = true;
                return;
            }
            if (is_array($value)) {
                $this->collectIndexNames($value, $found);
            }
        }
    }
}
