<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;
use PDOException;

class PsgcTest extends TestCase
{
    public function testHierarchyResolvesCorrectly(): void
    {
        $app = $this->getAppInstance();
        $pdo = $app->getContainer()->get(PDO::class);

        // Fetch a barangay
        $stmt = $pdo->query("SELECT * FROM ref.psgc_areas WHERE code = '041005001'");
        $barangay = $stmt->fetch();
        $this->assertNotEmpty($barangay);
        $this->assertSame('BARANGAY', $barangay['level']);

        // Fetch its parent (Municipality/City)
        $stmt = $pdo->prepare("SELECT * FROM ref.psgc_areas WHERE code = ?");
        $stmt->execute([$barangay['parent_code']]);
        $city = $stmt->fetch();
        $this->assertNotEmpty($city);
        $this->assertSame('MUNICIPALITY', $city['level']);
        $this->assertSame('041000000', $city['parent_code']);
    }

    public function testUnknownParentCodeIsRejected(): void
    {
        $app = $this->getAppInstance();
        $pdo = $app->getContainer()->get(PDO::class);

        $this->expectException(PDOException::class);
        
        // Error codes differ by driver, but we expect an integrity constraint violation (23503 for Postgres FK)
        // Trying to insert a record with a non-existent parent_code
        $pdo->exec("
            INSERT INTO ref.psgc_areas (code, level, name, parent_code)
            VALUES ('999999999', 'BARANGAY', 'Ghost Barangay', 'UNKNOWN_PAR')
        ");
    }
}
