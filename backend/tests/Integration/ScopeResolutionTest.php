<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use App\RBAC\DataScopeResolver;
use PHPUnit\Framework\TestCase;

class ScopeResolutionTest extends TestCase
{
    private PDO $pdo;
    private DataScopeResolver $resolver;
    private int $testUserId;
    private int $testOrgId;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST') ?: 'postgres';
        $port = getenv('DB_PORT') ?: '5432';
        $db   = getenv('DB_NAME') ?: 'webgis';
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASS') ?: 'postgres';

        $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->resolver = new DataScopeResolver($this->pdo);

        $this->pdo->exec("DELETE FROM app.users WHERE username = 'scopetestuser'");
        $this->pdo->exec("DELETE FROM app.organizations WHERE code = 'SCOPETEST'");

        $this->pdo->exec("
            INSERT INTO app.organizations (code, name) 
            VALUES ('SCOPETEST', 'Scope Test Org')
        ");
        $stmt = $this->pdo->query("SELECT id FROM app.organizations WHERE code = 'SCOPETEST'");
        $this->testOrgId = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            INSERT INTO app.users (username, email, password_hash, full_name, status, version, org_id) 
            VALUES ('scopetestuser', 'scope@example.com', 'dummy', 'Scope Test User', 'ACTIVE', 1, :org) 
            RETURNING id
        ");
        $stmt->execute([':org' => $this->testOrgId]);
        $this->testUserId = (int)$stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM app.users WHERE id = {$this->testUserId}");
        $this->pdo->exec("DELETE FROM app.organizations WHERE id = {$this->testOrgId}");
    }

    private function addScope(string $type, ?string $refCode, string $accessLevel, ?string $geomText = null): void
    {
        if ($geomText !== null) {
            $stmt = $this->pdo->prepare("
                INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level, geom)
                VALUES (:user_id, :type, :ref_code, :access_level, ST_GeomFromText(:geom, 4326))
            ");
            $stmt->execute([
                ':user_id' => $this->testUserId,
                ':type' => $type,
                ':ref_code' => $refCode,
                ':access_level' => $accessLevel,
                ':geom' => $geomText
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level)
                VALUES (:user_id, :type, :ref_code, :access_level)
            ");
            $stmt->execute([
                ':user_id' => $this->testUserId,
                ':type' => $type,
                ':ref_code' => $refCode,
                ':access_level' => $accessLevel
            ]);
        }
    }

    public function testDefaultDenyWhenNoScopes(): void
    {
        $level = $this->resolver->resolveMaxAccessLevel($this->testUserId, '012801001');
        $this->assertEquals('NONE', $level);
    }

    public function testMostSpecificGrantWins(): void
    {
        // Give VIEW on province 012800000
        $this->addScope('PROVINCE', '012800000', 'VIEW');
        
        // Give EDIT on municipality 012801000
        $this->addScope('MUNICIPALITY', '012801000', 'EDIT');

        // Check a barangay inside that municipality
        $level = $this->resolver->resolveMaxAccessLevel($this->testUserId, '012801001');
        
        // Municipality is more specific than Province, so EDIT should win
        $this->assertEquals('EDIT', $level);
        $this->assertTrue($this->resolver->hasAccess($this->testUserId, 'VIEW', '012801001'));
        $this->assertTrue($this->resolver->hasAccess($this->testUserId, 'EDIT', '012801001'));
        $this->assertFalse($this->resolver->hasAccess($this->testUserId, 'APPROVE', '012801001'));
    }

    public function testExplicitNoneDeniesEverything(): void
    {
        // Give EDIT on entire GLOBAL
        $this->addScope('GLOBAL', null, 'EDIT');
        
        // But explicitly deny NONE on a specific Province
        $this->addScope('PROVINCE', '012800000', 'NONE');

        // Check a barangay inside the denied province
        $levelInside = $this->resolver->resolveMaxAccessLevel($this->testUserId, '012801001');
        $this->assertEquals('NONE', $levelInside);

        // Check a barangay outside the denied province
        $levelOutside = $this->resolver->resolveMaxAccessLevel($this->testUserId, '022801001');
        $this->assertEquals('EDIT', $levelOutside);
    }

    public function testCustomAreaIntersection(): void
    {
        // Custom area: A small polygon from (0,0) to (10,10)
        $polygon = 'MULTIPOLYGON(((0 0, 10 0, 10 10, 0 10, 0 0)))';
        $this->addScope('CUSTOM_AREA', null, 'VIEW', $polygon);

        // Record point at (5,5) -> Inside the polygon
        // WKB for Point(5 5): 0101000020E610000000000000000014400000000000001440
        $stmt = $this->pdo->query("SELECT encode(ST_AsEWKB(ST_GeomFromText('POINT(5 5)', 4326)), 'hex')");
        $insideWkb = $stmt->fetchColumn();

        $levelInside = $this->resolver->resolveMaxAccessLevel($this->testUserId, null, null, $insideWkb);
        $this->assertEquals('VIEW', $levelInside);

        // Record point at (20,20) -> Outside the polygon
        $stmt = $this->pdo->query("SELECT encode(ST_AsEWKB(ST_GeomFromText('POINT(20 20)', 4326)), 'hex')");
        $outsideWkb = $stmt->fetchColumn();

        $levelOutside = $this->resolver->resolveMaxAccessLevel($this->testUserId, null, null, $outsideWkb);
        $this->assertEquals('NONE', $levelOutside);
    }

    public function testOrganizationScope(): void
    {
        $this->addScope('ORGANIZATION', (string)$this->testOrgId, 'APPROVE');

        // Check access with the correct org ID
        $level = $this->resolver->resolveMaxAccessLevel($this->testUserId, null, $this->testOrgId);
        $this->assertEquals('APPROVE', $level);

        // Check access with a different org ID
        $level2 = $this->resolver->resolveMaxAccessLevel($this->testUserId, null, 99999);
        $this->assertEquals('NONE', $level2);
    }
}
