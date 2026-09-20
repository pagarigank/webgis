<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

class FixtureLoadTest extends TestCase
{
    private PDO $pdo;

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
        
        // NOTE: FixtureSeeder must be run before these tests via phinx seed:run
        // The seeder is idempotent (ON CONFLICT DO NOTHING) so re-running is safe.
    }

    public function testFictionalProvinceExists(): void
    {
        $stmt = $this->pdo->query("SELECT name FROM ref.psgc_areas WHERE code = '990000000'");
        $this->assertEquals('SAMPLE_PROVINCE', $stmt->fetchColumn());
        
        $stmt = $this->pdo->query("SELECT count(*) FROM ref.psgc_areas WHERE parent_code = '990100000'");
        $this->assertEquals(10, $stmt->fetchColumn());
    }

    public function testFictionalUsersExist(): void
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM app.users WHERE username LIKE 'sample_%'");
        $this->assertEquals(8, $stmt->fetchColumn());
    }

    public function testFictionalParcelsExist(): void
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM app.parcels WHERE parcel_code LIKE 'SAMPLE_PARCEL_%'");
        $this->assertEquals(100, $stmt->fetchColumn());
    }

    public function testOverlappingClaimsExist(): void
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM app.parcels WHERE parcel_code LIKE 'SAMPLE_OVERLAP_%'");
        $this->assertEquals(5, $stmt->fetchColumn());
    }
    
    public function testFictionalTitlesExist(): void
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM app.land_titles WHERE title_number LIKE 'SAMPLE_TITLE_%'");
        $this->assertEquals(30, $stmt->fetchColumn());
    }

    public function testFictionalWorkflowsExist(): void
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM app.workflow_definitions WHERE code = 'SAMPLE_CONSOLIDATION'");
        $this->assertEquals(1, $stmt->fetchColumn(), 'SAMPLE_CONSOLIDATION definition not found');

        $stmt = $this->pdo->query("
            SELECT count(*) FROM app.workflow_instances wi
            JOIN app.workflow_definitions wd ON wi.definition_id = wd.id
            WHERE wd.code = 'SAMPLE_CONSOLIDATION'
        ");
        $this->assertEquals(2, $stmt->fetchColumn(), 'Expected 2 workflow instances for SAMPLE_CONSOLIDATION');
    }
    
    public function testFictionalGisFeaturesExist(): void
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM app.gis_layers WHERE code = 'SAMPLE_PARCEL_POLYGON'");
        $this->assertEquals(1, $stmt->fetchColumn(), 'SAMPLE_PARCEL_POLYGON layer not found');

        $stmt = $this->pdo->query("SELECT count(*) FROM app.gis_features WHERE id = '77777777-7777-7777-7777-000000000001'");
        $this->assertEquals(1, $stmt->fetchColumn(), 'Fixture GIS feature not found');
    }
}
