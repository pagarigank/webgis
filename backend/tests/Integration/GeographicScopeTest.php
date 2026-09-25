<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;

/**
 * Regression coverage for the geographic data-scope fix in migration
 * 20260925000002 (FixPsgcScopeHierarchy).
 *
 * Before that migration, app.fn_user_can_see / app.fn_user_can_edit matched a
 * parcel's PSGC code with `p_psgc LIKE ds.scope_ref_code || '%'`. PSGC codes do
 * not nest by string prefix, so only BARANGAY scopes ever matched; a
 * MUNICIPALITY, PROVINCE or REGION scope matched nothing. Any parcel outside a
 * barangay scope was reported as out of scope, and the FR-18.3 split /
 * consolidation commit-time assertion rejected the caller for the very parcel
 * they had just created.
 *
 * These tests assert the hierarchy-based behaviour at every geographic level,
 * including the negative cases, and cover the non-conforming seed rows whose
 * children are not prefixed by their parent code.
 */
class GeographicScopeTest extends TestCase
{
    private PDO $pdo;
    private int $testUserId;

    /**
     * The seeded sample tree is deliberately non-conforming:
     *   SAMPLE_REGION 990000000? -> SAMPLE_PROVINCE 990000000
     *     -> SAMPLE_MUNI_1 990100000
     *        -> SAMPLE_BRGY_1 990101000
     * Prefix matching can never relate 990101000 to 990000000, so a passing
     * test here proves hierarchy resolution rather than lucky string prefixes.
     */
    private const BRGY_IN_SAMPLE   = '990101000';
    private const BRGY_IN_SAMPLE_2 = '990102000';
    private const BRGY_ELSEWHERE   = '041005001';
    private const SAMPLE_PROVINCE  = '990000000';
    private const SAMPLE_MUNI      = '990100000';
    private const SAMPLE_MUNI_2    = '990200000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->getAppInstance()->getContainer()->get(PDO::class);

        $this->pdo->exec("DELETE FROM app.users WHERE email = 'geo_scope_user@example.com'");
        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, full_name, password_hash, status)
            VALUES ('geo_scope_user', 'geo_scope_user@example.com', 'Geo Scope Test', 'hash', 'ACTIVE')
            RETURNING id
        ");
        $stmt->execute();
        $this->testUserId = (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('RESET ROLE');
        $this->pdo->exec("DELETE FROM app.data_scopes WHERE user_id = {$this->testUserId}");
        $this->pdo->exec("DELETE FROM app.users WHERE id = {$this->testUserId}");

        parent::tearDown();
    }

    private function grantScope(string $scopeType, string $scopeRefCode, string $accessLevel = 'EDIT'): void
    {
        $this->pdo->exec("
            INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level)
            VALUES ({$this->testUserId}, '{$scopeType}', '{$scopeRefCode}', '{$accessLevel}')
        ");
    }

    private function canSee(string $psgc): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT app.fn_user_can_see(:uid, :psgc, NULL)'
        );
        $stmt->execute(['uid' => $this->testUserId, 'psgc' => $psgc]);

        return (bool) $stmt->fetchColumn();
    }

    private function canEdit(string $psgc): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT app.fn_user_can_edit(:uid, :psgc, NULL)'
        );
        $stmt->execute(['uid' => $this->testUserId, 'psgc' => $psgc]);

        return (bool) $stmt->fetchColumn();
    }

    public function testProvinceScopeCoversDescendantBarangays(): void
    {
        $this->grantScope('PROVINCE', self::SAMPLE_PROVINCE);

        $this->assertTrue($this->canSee(self::BRGY_IN_SAMPLE), 'province scope must cover its barangays');
        $this->assertTrue($this->canSee(self::BRGY_IN_SAMPLE_2), 'province scope must cover all descendant barangays');
        $this->assertTrue($this->canEdit(self::BRGY_IN_SAMPLE), 'province EDIT scope must allow edits on descendants');
    }

    public function testProvinceScopeExcludesOtherProvinces(): void
    {
        $this->grantScope('PROVINCE', self::SAMPLE_PROVINCE);

        $this->assertFalse($this->canSee(self::BRGY_ELSEWHERE), 'province scope must not leak into other provinces');
        $this->assertFalse($this->canEdit(self::BRGY_ELSEWHERE), 'province EDIT scope must not allow edits outside the province');
    }

    public function testMunicipalityScopeCoversOnlyItsOwnBarangays(): void
    {
        // Every seeded sample barangay belongs to SAMPLE_MUNI_1, so the
        // narrower negative is the sibling municipality SAMPLE_MUNI_2.
        $this->grantScope('MUNICIPALITY', self::SAMPLE_MUNI);

        $this->assertTrue($this->canSee(self::BRGY_IN_SAMPLE), 'municipality scope must cover its barangays');
        $this->assertTrue($this->canSee(self::BRGY_IN_SAMPLE_2), 'all barangays of the municipality are in scope');
        $this->assertFalse(
            $this->canSee(self::SAMPLE_MUNI_2),
            'municipality scope must not cover a sibling municipality'
        );
        $this->assertFalse($this->canSee(self::BRGY_ELSEWHERE), 'municipality scope must not cover other provinces');
    }

    public function testBarangayScopeMatchesOnlyItself(): void
    {
        $this->grantScope('BARANGAY', self::BRGY_IN_SAMPLE);

        $this->assertTrue($this->canSee(self::BRGY_IN_SAMPLE));
        $this->assertFalse($this->canSee(self::BRGY_IN_SAMPLE_2));
        $this->assertFalse($this->canSee(self::BRGY_ELSEWHERE));
    }

    public function testRegionScopeCoversWholeRegion(): void
    {
        // Resolve the region that owns the sample province.
        $stmt = $this->pdo->prepare('
            SELECT prov.parent_code
            FROM ref.psgc_areas muni
            JOIN ref.psgc_areas prov ON prov.code = muni.parent_code
            WHERE muni.code = :code
        ');
        $stmt->execute(['code' => self::SAMPLE_MUNI]);
        $regionCode = $stmt->fetchColumn();

        if ($regionCode === false) {
            $this->markTestSkipped('Seed data has no region above the sample province.');
        }

        $this->grantScope('REGION', (string) $regionCode);

        $this->assertTrue($this->canSee(self::BRGY_IN_SAMPLE), 'region scope must cover descendant barangays');
        $this->assertTrue($this->canSee(self::BRGY_IN_SAMPLE_2));
    }

    public function testViewScopeDoesNotGrantEdit(): void
    {
        $this->grantScope('PROVINCE', self::SAMPLE_PROVINCE, 'VIEW');

        $this->assertTrue($this->canSee(self::BRGY_IN_SAMPLE), 'VIEW scope must grant read');
        $this->assertFalse($this->canEdit(self::BRGY_IN_SAMPLE), 'VIEW scope must not grant edit');
    }

    public function testGlobalScopeStillOverridesGeography(): void
    {
        $this->grantScope('PROVINCE', self::SAMPLE_PROVINCE, 'VIEW');
        $this->grantScope('GLOBAL', 'GLOBAL', 'EDIT');

        $this->assertTrue($this->canEdit(self::BRGY_ELSEWHERE), 'GLOBAL EDIT scope must reach any barangay');
    }

    public function testGlobalViewScopeSeesEverywhereButEditsNothing(): void
    {
        $this->grantScope('GLOBAL', 'GLOBAL', 'VIEW');

        $this->assertTrue(
            $this->canSee(self::BRGY_ELSEWHERE),
            'GLOBAL VIEW scope must be able to see any barangay'
        );
        $this->assertFalse(
            $this->canEdit(self::BRGY_ELSEWHERE),
            'GLOBAL VIEW scope must not grant edit anywhere'
        );
    }

    public function testUnscopedParcelIsNeverVisible(): void
    {
        $this->grantScope('PROVINCE', self::SAMPLE_PROVINCE);

        $stmt = $this->pdo->prepare('SELECT app.fn_user_can_see(:uid, NULL, NULL)');
        $stmt->execute(['uid' => $this->testUserId]);
        $this->assertFalse((bool) $stmt->fetchColumn(), 'a parcel with no PSGC must not match a geographic scope');
    }

    public function testMostSpecificMatchingScopeWins(): void
    {
        $this->grantScope('PROVINCE', self::SAMPLE_PROVINCE, 'VIEW');
        $this->grantScope('MUNICIPALITY', self::SAMPLE_MUNI, 'EDIT');

        $this->assertTrue(
            $this->canEdit(self::BRGY_IN_SAMPLE),
            'the narrower EDIT grant must win over the broader VIEW grant'
        );
    }

    public function testNonReferenceScopeCodeFallsBackToPrefixMatching(): void
    {
        // A scope code that is not itself a ref.psgc_areas row keeps the legacy
        // prefix behaviour so custom / partial scope codes keep working.
        $this->grantScope('PROVINCE', '999000');

        $this->assertTrue($this->canSee('999000123'), 'a custom scope code keeps legacy prefix matching');
        $this->assertFalse($this->canSee(self::BRGY_IN_SAMPLE), 'a custom scope code must not widen to real codes');
    }
}
