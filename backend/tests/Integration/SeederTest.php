<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;

class SeederTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->getAppInstance()->getContainer()->get(PDO::class);
    }

    public function testSeedersAreIdempotent(): void
    {
        // Execute the phinx seed command
        $output = [];
        $returnVar = 0;
        // In the test, we'll just test that we can query the seeded data
        // We assume the test suite runs `phinx migrate` and `phinx seed:run` beforehand
        
        $count = $this->pdo->query("SELECT count(*) FROM ref.units WHERE code = 'ha'")->fetchColumn();
        $this->assertGreaterThan(0, $count, 'RefSeeder failed to seed units');

        $count = $this->pdo->query("SELECT count(*) FROM app.roles WHERE code = 'SYS_ADMIN'")->fetchColumn();
        $this->assertGreaterThan(0, $count, 'SystemSeeder failed to seed roles');

        $count = $this->pdo->query("SELECT count(*) FROM app.parcels WHERE parcel_code = 'TEST_PARCEL_001'")->fetchColumn();
        $this->assertGreaterThan(0, $count, 'SampleDataSeeder failed to seed sample parcel');
        
        // Execute seed command explicitly to test idempotency
        exec('vendor/bin/phinx seed:run -e development', $output, $returnVar);
        
        $this->assertEquals(0, $returnVar, 'Seed command should be idempotent and return 0');
    }
}
