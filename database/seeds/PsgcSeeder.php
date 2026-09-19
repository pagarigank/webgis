<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class PsgcSeeder extends AbstractSeed
{
    public function run(): void
    {
        $psgcData = [
            // Region
            [
                'code' => '130000000',
                'level' => 'REGION',
                'name' => 'NATIONAL CAPITAL REGION (NCR)',
                'parent_code' => null,
            ],
            // District (some regions have districts, but let's stick to simple hierarchy)
            [
                'code' => '133900000',
                'level' => 'PROVINCE', // Technically NCR has districts, but we'll map it to province level conceptually or just use PROVINCE
                'name' => 'NCR, THIRD DISTRICT',
                'parent_code' => '130000000',
            ],
            // City
            [
                'code' => '133901000',
                'level' => 'CITY',
                'name' => 'CALOOCAN CITY',
                'parent_code' => '133900000',
            ],
            // Barangay
            [
                'code' => '133901001',
                'level' => 'BARANGAY',
                'name' => 'Barangay 1',
                'parent_code' => '133901000',
            ],
            
            // Another standard Region -> Province -> Muni -> Brgy
            [
                'code' => '040000000',
                'level' => 'REGION',
                'name' => 'REGION IV-A (CALABARZON)',
                'parent_code' => null,
            ],
            [
                'code' => '041000000',
                'level' => 'PROVINCE',
                'name' => 'BATANGAS',
                'parent_code' => '040000000',
            ],
            [
                'code' => '041005000',
                'level' => 'MUNICIPALITY',
                'name' => 'BATANGAS CITY (Capital)',
                'parent_code' => '041000000',
            ],
            [
                'code' => '041005001',
                'level' => 'BARANGAY',
                'name' => 'Alangilan',
                'parent_code' => '041005000',
            ],
        ];

        $psgcTable = $this->table('ref.psgc_areas');
        
        // We'll insert one by one or in batches, ensuring hierarchy is respected.
        // Actually, just inserting in this exact array order works because parents come before children.
        foreach ($psgcData as $row) {
            $psgcTable->insert($row)->saveData();
        }
    }
}
