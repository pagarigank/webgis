<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;
use PDOException;

class SchemaSupportTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->getAppInstance()->getContainer()->get(PDO::class);
    }

    public function testBasemapLicenceConstraint(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/ck_license_enabled/');

        // Try to enable an unlicensed basemap
        $this->pdo->exec("
            INSERT INTO app.basemap_providers (code, name, provider_type, attribution_html, license_type, is_enabled) 
            VALUES ('UNLICENSED_MAP', 'Test Map', 'XYZ', 'Test Attribution', 'UNLICENSED', true)
        ");
    }

    public function testBasemapAttributionConstraint(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/ck_attribution/');

        // Try to enable a map without attribution
        $this->pdo->exec("
            INSERT INTO app.basemap_providers (code, name, provider_type, attribution_html, license_type, is_enabled) 
            VALUES ('NO_ATTRIB_MAP', 'Test Map 2', 'XYZ', '', 'OPEN_ODBL', true)
        ");
    }
}
