<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class RefSeeder extends AbstractSeed
{
    public function run(): void
    {
        // 1. Seed Units
        $unitsData = [
            [
                'code' => 'm',
                'name' => 'Meter',
                'unit_type' => 'LENGTH',
                'conversion_factor_to_base' => 1.0,
                'is_base' => true
            ],
            [
                'code' => 'sqm',
                'name' => 'Square Meter',
                'unit_type' => 'AREA',
                'conversion_factor_to_base' => 1.0,
                'is_base' => true
            ],
            [
                'code' => 'ha',
                'name' => 'Hectare',
                'unit_type' => 'AREA',
                'conversion_factor_to_base' => 10000.0,
                'is_base' => false
            ],
        ];
        
        $unitsTable = $this->table('ref.units');
        $unitsTable->insert($unitsData)
                   ->saveData();

        // 2. Seed CRS Registry
        $crsData = [
            [
                'srid' => 4326,
                'code' => 'EPSG:4326',
                'name' => 'WGS 84',
                'datum' => 'WGS 84',
                'is_projected' => false,
                'is_historical' => false,
            ],
            [
                'srid' => 3857,
                'code' => 'EPSG:3857',
                'name' => 'WGS 84 / Pseudo-Mercator',
                'datum' => 'WGS 84',
                'is_projected' => true,
                'is_historical' => false,
            ],
            // PRS92 PTM Zones
            [
                'srid' => 3121,
                'code' => 'EPSG:3121',
                'name' => 'PRS92 / Philippines zone I',
                'datum' => 'PRS92',
                'zone' => 'I',
                'is_projected' => true,
                'is_historical' => false,
            ],
            [
                'srid' => 3122,
                'code' => 'EPSG:3122',
                'name' => 'PRS92 / Philippines zone II',
                'datum' => 'PRS92',
                'zone' => 'II',
                'is_projected' => true,
                'is_historical' => false,
            ],
            [
                'srid' => 3123,
                'code' => 'EPSG:3123',
                'name' => 'PRS92 / Philippines zone III',
                'datum' => 'PRS92',
                'zone' => 'III',
                'is_projected' => true,
                'is_historical' => false,
            ],
            [
                'srid' => 3124,
                'code' => 'EPSG:3124',
                'name' => 'PRS92 / Philippines zone IV',
                'datum' => 'PRS92',
                'zone' => 'IV',
                'is_projected' => true,
                'is_historical' => false,
            ],
            [
                'srid' => 3125,
                'code' => 'EPSG:3125',
                'name' => 'PRS92 / Philippines zone V',
                'datum' => 'PRS92',
                'zone' => 'V',
                'is_projected' => true,
                'is_historical' => false,
            ],
            // Luzon 1911 Zones
            [
                'srid' => 25391,
                'code' => 'EPSG:25391',
                'name' => 'Luzon 1911 / Philippines zone I',
                'datum' => 'Luzon 1911',
                'zone' => 'I',
                'is_projected' => true,
                'is_historical' => true,
            ],
            [
                'srid' => 25392,
                'code' => 'EPSG:25392',
                'name' => 'Luzon 1911 / Philippines zone II',
                'datum' => 'Luzon 1911',
                'zone' => 'II',
                'is_projected' => true,
                'is_historical' => true,
            ],
            [
                'srid' => 25393,
                'code' => 'EPSG:25393',
                'name' => 'Luzon 1911 / Philippines zone III',
                'datum' => 'Luzon 1911',
                'zone' => 'III',
                'is_projected' => true,
                'is_historical' => true,
            ],
            [
                'srid' => 25394,
                'code' => 'EPSG:25394',
                'name' => 'Luzon 1911 / Philippines zone IV',
                'datum' => 'Luzon 1911',
                'zone' => 'IV',
                'is_projected' => true,
                'is_historical' => true,
            ],
            [
                'srid' => 25395,
                'code' => 'EPSG:25395',
                'name' => 'Luzon 1911 / Philippines zone V',
                'datum' => 'Luzon 1911',
                'zone' => 'V',
                'is_projected' => true,
                'is_historical' => true,
            ],
        ];

        $crsTable = $this->table('ref.crs_registry');
        $crsTable->insert($crsData)
                 ->saveData();
    }
}
