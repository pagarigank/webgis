<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class RefSeeder extends AbstractSeed
{
    public function run(): void
    {
        // 1. Seed Units using ON CONFLICT DO NOTHING
        $unitsData = [
            "('m', 'Meter', 'LENGTH', 1.0, true)",
            "('sqm', 'Square Meter', 'AREA', 1.0, true)",
            "('ha', 'Hectare', 'AREA', 10000.0, false)"
        ];
        
        $this->execute("
            INSERT INTO ref.units (code, name, unit_type, conversion_factor_to_base, is_base)
            VALUES " . implode(", ", $unitsData) . "
            ON CONFLICT (code) DO NOTHING
        ");

        // 2. Seed CRS Registry using ON CONFLICT DO NOTHING
        $crsData = [
            "(4326, 'EPSG:4326', 'WGS 84', 'WGS 84', NULL, false, false)",
            "(3857, 'EPSG:3857', 'WGS 84 / Pseudo-Mercator', 'WGS 84', NULL, true, false)",
            "(3121, 'EPSG:3121', 'PRS92 / Philippines zone I', 'PRS92', 'I', true, false)",
            "(3122, 'EPSG:3122', 'PRS92 / Philippines zone II', 'PRS92', 'II', true, false)",
            "(3123, 'EPSG:3123', 'PRS92 / Philippines zone III', 'PRS92', 'III', true, false)",
            "(3124, 'EPSG:3124', 'PRS92 / Philippines zone IV', 'PRS92', 'IV', true, false)",
            "(3125, 'EPSG:3125', 'PRS92 / Philippines zone V', 'PRS92', 'V', true, false)",
            "(25391, 'EPSG:25391', 'Luzon 1911 / Philippines zone I', 'Luzon 1911', 'I', true, true)",
            "(25392, 'EPSG:25392', 'Luzon 1911 / Philippines zone II', 'Luzon 1911', 'II', true, true)",
            "(25393, 'EPSG:25393', 'Luzon 1911 / Philippines zone III', 'Luzon 1911', 'III', true, true)",
            "(25394, 'EPSG:25394', 'Luzon 1911 / Philippines zone IV', 'Luzon 1911', 'IV', true, true)",
            "(25395, 'EPSG:25395', 'Luzon 1911 / Philippines zone V', 'Luzon 1911', 'V', true, true)"
        ];

        $this->execute("
            INSERT INTO ref.crs_registry (srid, code, name, datum, zone, is_projected, is_historical)
            VALUES " . implode(", ", $crsData) . "
            ON CONFLICT (srid) DO NOTHING
        ");
    }
}
