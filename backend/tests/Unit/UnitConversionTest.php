<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use PDO;

class UnitConversionTest extends TestCase
{
    public function testUnitConversionFactors(): void
    {
        $app = $this->getAppInstance();
        $pdo = $app->getContainer()->get(PDO::class);

        $stmt = $pdo->query("SELECT conversion_factor_to_base FROM ref.units WHERE code = 'ha'");
        $factor = (float) $stmt->fetchColumn();

        $this->assertSame(10000.0, $factor, 'Hectare to square meter factor should be exact 10000');
    }
}
