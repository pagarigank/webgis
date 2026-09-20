<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class PsgcSeeder extends AbstractSeed
{
    public function run(): void
    {
        $psgcData = [
            "('130000000', 'REGION', 'NATIONAL CAPITAL REGION (NCR)', NULL)",
            "('133900000', 'PROVINCE', 'NCR, THIRD DISTRICT', '130000000')",
            "('133901000', 'CITY', 'CALOOCAN CITY', '133900000')",
            "('133901001', 'BARANGAY', 'Barangay 1', '133901000')",
            "('040000000', 'REGION', 'REGION IV-A (CALABARZON)', NULL)",
            "('041000000', 'PROVINCE', 'BATANGAS', '040000000')",
            "('041005000', 'MUNICIPALITY', 'BATANGAS CITY (Capital)', '041000000')",
            "('041005001', 'BARANGAY', 'Alangilan', '041005000')"
        ];

        $this->execute("
            INSERT INTO ref.psgc_areas (code, level, name, parent_code)
            VALUES " . implode(", ", $psgcData) . "
            ON CONFLICT (code) DO NOTHING
        ");
    }
}
