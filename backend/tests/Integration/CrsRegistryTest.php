<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;

class CrsRegistryTest extends TestCase
{
    public function testCrsRegistryContainsPrs92(): void
    {
        $app = $this->getAppInstance();
        $pdo = $app->getContainer()->get(PDO::class);

        $stmt = $pdo->query("SELECT * FROM ref.crs_registry WHERE code = 'EPSG:3121'");
        $row = $stmt->fetch();

        $this->assertNotEmpty($row, 'CRS registry should be queryable and contain EPSG:3121');
        $this->assertSame(3121, $row['srid']);
        $this->assertSame('PRS92', $row['datum']);
        $this->assertFalse($row['is_historical']);
    }
}
