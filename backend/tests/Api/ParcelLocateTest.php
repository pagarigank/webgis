<?php

declare(strict_types=1);

namespace Tests\Api;

use Tests\TestCase;

/**
 * TASK-104b - GET /parcels/locate and GET /parcels/overlay acceptance.
 *
 * The generic GIS identify tool answers against app.gis_features, which has no
 * relationship to app.parcels, so a parcel cannot be identified by it. These
 * endpoints make the parcel resolvable from a map click, which is what the
 * open / split / consolidate / lineage / history action menu hangs off.
 *
 * ACs covered here:
 *  - locate returns the parcel containing the clicked point, with area and
 *    distance, and flags contains_point.
 *  - locate falls back to the nearest parcels within the tolerance.
 *  - locate ignores parcels with no geometry (all fixture parcels start null).
 *  - locate validates lng/lat and rejects an oversized tolerance.
 *  - locate requires parcel.view; a scoped user cannot see out-of-scope rows.
 *  - overlay returns GeoJSON for the intersecting parcels only.
 *  - overlay rejects a malformed or oversized bbox.
 */
class ParcelLocateTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private string $otherUserToken;

    /** A user scoped to PROVINCE 990000000 only (the SAMPLE_PROVINCE fixtures). */
    private string $scopedUserToken;

    /** Barangay inside the admin's data scope (SAMPLE_PROVINCE 990000000). */
    private const SCOPED_BARANGAY = '990101000';

    /** A real barangay in BATANGAS, outside that province. */
    private const OUT_OF_SCOPE_BARANGAY = '041005000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->clean();

        $admin = $this->createMockUser($this->pdo, ['parcel.view', 'parcel.update'], ['SURVEY_OFFICER']);
        $this->token = $admin['token'];

        // createMockUser() reuses the shared 'testuser' fixture, which has
        // accumulated grants (including a GLOBAL data scope) in earlier tests,
        // so both the no-permission and the province-scoped cases need their
        // own users.
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version)
            VALUES ('loc_no_perm_user', 'loc_no_perm@example.com', 'dummy', 'No Perm',
                    (SELECT id FROM app.organizations WHERE code = 'TESTORG'), 'ACTIVE', 1)");
        $noPermId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'loc_no_perm_user'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('LOC_NO_PERM', 'Loc No Perm', false) ON CONFLICT DO NOTHING");
        $noPermRoleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'LOC_NO_PERM'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($noPermId, $noPermRoleId) ON CONFLICT DO NOTHING");
        $this->otherUserToken = $this->tokenFor($noPermId);

        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version)
            VALUES ('loc_scoped_user', 'loc_scoped@example.com', 'dummy', 'Scoped',
                    (SELECT id FROM app.organizations WHERE code = 'TESTORG'), 'ACTIVE', 1)");
        $scopedId = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'loc_scoped_user'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.roles (code, name, is_system) VALUES ('LOC_SCOPED', 'Loc Scoped', false) ON CONFLICT DO NOTHING");
        $scopedRoleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'LOC_SCOPED'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($scopedId, $scopedRoleId) ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('parcel.view', 'parcel.view') ON CONFLICT DO NOTHING");
        $viewPermId = (int) $this->pdo->query("SELECT id FROM app.permissions WHERE code = 'parcel.view'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($scopedRoleId, $viewPermId) ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level)
            VALUES ($scopedId, 'PROVINCE', '990000000', 'EDIT')");
        $this->scopedUserToken = $this->tokenFor($scopedId);
    }

    private function tokenFor(int $userId): string
    {
        return \Firebase\JWT\JWT::encode(
            ['sub' => (string) $userId, 'v' => 1, 'exp' => time() + 3600],
            getenv('JWT_SECRET') ?: 'dummy_secret',
            'HS256'
        );
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        $this->pdo->exec("DELETE FROM audit.parcel_versions WHERE parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'LOC_API_%')");
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'LOC_API_%'");
        foreach (['loc_no_perm_user', 'loc_scoped_user'] as $username) {
            $this->pdo->exec("DELETE FROM app.data_scopes WHERE user_id IN (SELECT id FROM app.users WHERE username = '$username')");
            $this->pdo->exec("DELETE FROM app.user_roles WHERE user_id IN (SELECT id FROM app.users WHERE username = '$username')");
            $this->pdo->exec("DELETE FROM app.users WHERE username = '$username'");
        }
    }

    private function req(string $method, string $path, ?string $token = null): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->createJsonRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token))
            ->withHeader('Accept', 'application/json');
    }

    private function insertParcel(string $code, ?string $wkt, string $status = 'APPROVED', ?string $barangay = null): string
    {
        $geomSql = $wkt === null
            ? 'NULL'
            : 'ST_Multi(ST_SetSRID(ST_GeomFromText(:wkt), 4326))';

        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (
                id, parcel_code, lot_number, status, geometry_source,
                psgc_barangay, geom, created_by
            ) VALUES (
                gen_random_uuid(), :code, 'Lot-1', :status, 'MANUAL_DRAWING',
                :barangay, {$geomSql}, 1
            ) RETURNING id
        ");
        $params = [
            ':code'     => $code,
            ':status'   => $status,
            ':barangay' => $barangay ?? self::SCOPED_BARANGAY,
        ];
        if ($wkt !== null) {
            $params[':wkt'] = $wkt;
        }
        $stmt->execute($params);
        return (string) $stmt->fetchColumn();
    }

    /** A ~1.1 ha square, big enough to click comfortably. */
    private const SQUARE = 'POLYGON((121.000 14.600, 121.001 14.600, 121.001 14.601, 121.000 14.601, 121.000 14.600))';

    public function testLocateReturnsContainingParcel(): void
    {
        $id = $this->insertParcel('LOC_API_INSIDE', self::SQUARE);

        $res = $this->handle($this->req('GET', '/api/v1/parcels/locate?lng=121.0005&lat=14.6005'));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertNotEmpty($data['parcels']);

        $first = $data['parcels'][0];
        $this->assertSame($id, $first['id']);
        $this->assertSame('LOC_API_INSIDE', $first['parcel_code']);
        $this->assertTrue($first['contains_point']);
        $this->assertEqualsWithDelta(0.0, $first['distance_m'], 0.001);
        $this->assertGreaterThan(10_000, $first['area_m2']);
        $this->assertNotNull($first['area_ha']);
    }

    public function testLocateOrdersContainingBeforeNearest(): void
    {
        // Nearest sits ~6 m south of the click; containing is a bigger polygon.
        $this->insertParcel('LOC_API_NEAR', 'POLYGON((121.0005 14.60040, 121.0015 14.60040, 121.0015 14.60045, 121.0005 14.60045, 121.0005 14.60040))');
        $this->insertParcel('LOC_API_CONTAINING', self::SQUARE);

        $res = $this->handle($this->req('GET', '/api/v1/parcels/locate?lng=121.0005&lat=14.6005&tolerance_m=50'));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $codes = array_column(json_decode((string) $res->getBody(), true)['data']['parcels'], 'parcel_code');
        $this->assertSame('LOC_API_CONTAINING', $codes[0], 'containing parcel must rank first');
        $this->assertContains('LOC_API_NEAR', $codes);
    }

    public function testLocateIgnoresParcelsWithoutGeometry(): void
    {
        $this->insertParcel('LOC_API_NULLGEOM', null);

        $res = $this->handle($this->req('GET', '/api/v1/parcels/locate?lng=121.0005&lat=14.6005&tolerance_m=2000'));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $codes = array_column(json_decode((string) $res->getBody(), true)['data']['parcels'], 'parcel_code');
        $this->assertNotContains('LOC_API_NULLGEOM', $codes);
    }

    public function testLocateRejectsMissingAndInvalidCoordinates(): void
    {
        $missing = $this->handle($this->req('GET', '/api/v1/parcels/locate?lat=14.6'));
        $this->assertSame(400, $missing->getStatusCode());

        $badLng = $this->handle($this->req('GET', '/api/v1/parcels/locate?lng=999&lat=14.6'));
        $this->assertSame(400, $badLng->getStatusCode());

        $badTol = $this->handle($this->req('GET', '/api/v1/parcels/locate?lng=121&lat=14.6&tolerance_m=-5'));
        $this->assertSame(400, $badTol->getStatusCode());
    }

    /**
     * TASK-104c - scope isolation for the map overlay.
     *
     * The app connects as `app_rw`, which is both the owner of every `app.*`
     * table and a superuser with BYPASSRLS, so the `parcels_scope_*` RLS
     * policies never fire. A user with zero scopes still reads all 249
     * parcels:
     *
     *     SET app.user_id = '0';
     *     SELECT count(*) FROM app.parcels;  -- 249, should be 0
     *
     * ParcelLocator therefore repeats the app.fn_user_can_see(...) predicate in
     * its own WHERE clauses (the pattern ControlPointController and
     * SpatialQuery already use), and these two tests hold that line. The
     * underlying RLS bypass is still unfixed and tracked separately.
     */
    public function testLocateHidesOutOfScopeParcels(): void
    {
        // Identical geometry, so only the data scope can tell them apart. The
        // shared 'testuser' fixture holds a GLOBAL scope, so this must run as
        // the province-scoped user or the assertion is vacuous.
        $this->insertParcel('LOC_API_IN_SCOPE', self::SQUARE, 'APPROVED', self::SCOPED_BARANGAY);
        $this->insertParcel('LOC_API_OUT_OF_SCOPE', self::SQUARE, 'APPROVED', self::OUT_OF_SCOPE_BARANGAY);

        $res = $this->handle($this->req(
            'GET',
            '/api/v1/parcels/locate?lng=121.0005&lat=14.6005&tolerance_m=2000',
            $this->scopedUserToken
        ));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $codes = array_column(json_decode((string) $res->getBody(), true)['data']['parcels'], 'parcel_code');
        $this->assertContains('LOC_API_IN_SCOPE', $codes, 'scoped user must see its own scope');
        $this->assertNotContains('LOC_API_OUT_OF_SCOPE', $codes, 'RLS must hide parcels outside the caller scope');
    }

    public function testOverlayHidesOutOfScopeParcels(): void
    {
        $this->insertParcel('LOC_API_OVL_IN', self::SQUARE, 'APPROVED', self::SCOPED_BARANGAY);
        $this->insertParcel('LOC_API_OVL_OUT', self::SQUARE, 'APPROVED', self::OUT_OF_SCOPE_BARANGAY);

        $res = $this->handle($this->req('GET', '/api/v1/parcels/overlay?bbox=120.9,14.5,121.1,14.7', $this->scopedUserToken));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $codes = array_column(array_column(json_decode((string) $res->getBody(), true)['data']['features'], 'properties'), 'parcel_code');
        $this->assertContains('LOC_API_OVL_IN', $codes);
        $this->assertNotContains('LOC_API_OVL_OUT', $codes, 'overlay must not leak other scopes');
    }

    public function testLocateRequiresAuth(): void
    {
        $res = $this->handle($this->createJsonRequest('GET', '/api/v1/parcels/locate?lng=121&lat=14.6'));
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testLocateRequiresParcelViewPermission(): void
    {
        $res = $this->handle($this->req('GET', '/api/v1/parcels/locate?lng=121.0005&lat=14.6005', $this->otherUserToken));
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testOverlayReturnsIntersectingParcelsOnly(): void
    {
        $inId = $this->insertParcel('LOC_API_OVERLAY_IN', self::SQUARE);
        $this->insertParcel('LOC_API_OVERLAY_OUT', 'POLYGON((122.000 14.600, 122.001 14.600, 122.001 14.601, 122.000 14.601, 122.000 14.600))');

        $res = $this->handle($this->req('GET', '/api/v1/parcels/overlay?bbox=120.9,14.5,121.1,14.7'));
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertSame('FeatureCollection', $data['type']);

        $ids = array_column(array_column($data['features'], 'properties'), 'parcel_code');
        $this->assertSame(['LOC_API_OVERLAY_IN'], $ids);
        $this->assertSame($inId, $data['features'][0]['id']);
        $this->assertSame('MultiPolygon', $data['features'][0]['geometry']['type']);
        $this->assertArrayHasKey('area_m2', $data['features'][0]['properties']);
        // The id is repeated in `properties` so a client can build a
        // MapLibre `['in', ['get', 'id'], ...]` filter to highlight the
        // parcels it has selected; the GeoJSON feature id is not filterable.
        $this->assertSame($inId, $data['features'][0]['properties']['id']);
    }

    public function testOverlayRejectsMalformedAndOversizedBbox(): void
    {
        $missing = $this->handle($this->req('GET', '/api/v1/parcels/overlay'));
        $this->assertSame(400, $missing->getStatusCode());

        $notNumeric = $this->handle($this->req('GET', '/api/v1/parcels/overlay?bbox=a,b,c,d'));
        $this->assertSame(400, $notNumeric->getStatusCode());

        $tooWide = $this->handle($this->req('GET', '/api/v1/parcels/overlay?bbox=100,10,130,30'));
        $this->assertSame(400, $tooWide->getStatusCode());
    }
}
