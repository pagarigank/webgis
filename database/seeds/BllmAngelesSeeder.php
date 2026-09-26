<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * BllmAngelesSeeder
 *
 * Seeds the official BLLM (Barrio Land Lot Monument) / MBM / BBM control-point
 * schedule for Angeles City (Cadastral Survey CAD-94), Pampanga.
 *
 * Source: BLLM Schedule PDF, "ANGELES CAD-94, ANGELES, PAMPANGA" section.
 *
 * Coordinate system: PPCS Zone III — Philippine Plane Coordinate System,
 * Transverse Mercator on Clarke 1866, central meridian 121°E, k0=0.99995,
 * FE=500 000 m, FN=0. No datum shift to WGS 84 (see migration
 * 20260926000001_register_ppcs_zones.php for the full rationale).
 * CRS registry entry: srid=990103, code='PPCS:990103'.
 *
 * PSGC reference (official 2024 edition):
 *   Region III – Central Luzon  : 030000000
 *   Pampanga Province           : 035400000
 *   Angeles City                : 035401000  (independent city)
 *
 * All control points are seeded as UNVERIFIED (D-05): a verifier must
 * review and sign off each point before it may be used as a tie point
 * for a parcel computation.
 */
class BllmAngelesSeeder extends AbstractSeed
{
    public function getDependencies(): array
    {
        return [
            'SystemSeeder',  // roles, permissions
            'RefSeeder',     // CRS registry (PPCS zones registered by migration)
        ];
    }

    public function run(): void
    {
        $this->execute("SET app.user_id = '1'");

        // ------------------------------------------------------------------ //
        // 1. PSGC hierarchy — Region III -> Pampanga -> Angeles City -> Barangays
        // ------------------------------------------------------------------ //
        $this->seedPsgc();

        // ------------------------------------------------------------------ //
        // 2. Angeles City LGU organisation
        // ------------------------------------------------------------------ //
        $this->seedOrganization();

        // ------------------------------------------------------------------ //
        // 3. Sample user scoped to Angeles City (for testing / wiring)
        // ------------------------------------------------------------------ //
        $this->seedUser();

        // ------------------------------------------------------------------ //
        // 4. BLLM / MBM / BBM control points from the CAD-94 schedule
        // ------------------------------------------------------------------ //
        $this->seedControlPoints();
    }

    // ----------------------------------------------------------------------- //

    private function seedPsgc(): void
    {
        /*
         * PSGC 2024 codes.
         *
         * Region  : 030000000
         * Province: 035400000  (Pampanga)
         * City    : 035401000  (Angeles City — independent city, parent = province)
         *
         * Barangays — official 33 barangays of Angeles City.
         */

        // Region III
        $this->execute("
            INSERT INTO ref.psgc_areas (code, level, name)
            VALUES ('030000000', 'REGION', 'Region III – Central Luzon')
            ON CONFLICT (code) DO NOTHING;
        ");

        // Pampanga Province
        $this->execute("
            INSERT INTO ref.psgc_areas (code, level, name, parent_code)
            VALUES ('035400000', 'PROVINCE', 'Pampanga', '030000000')
            ON CONFLICT (code) DO NOTHING;
        ");

        // Angeles City (independent city)
        $this->execute("
            INSERT INTO ref.psgc_areas (code, level, name, parent_code)
            VALUES ('035401000', 'CITY', 'Angeles City', '035400000')
            ON CONFLICT (code) DO NOTHING;
        ");

        // 33 official barangays of Angeles City (PSGC 2024)
        $barangays = [
            ['035401001', 'Agapito del Rosario'],
            ['035401002', 'Amsic'],
            ['035401003', 'Anunas'],
            ['035401004', 'Balibago'],
            ['035401005', 'Capaya'],
            ['035401006', 'Claro M. Recto'],
            ['035401007', 'Cuayan'],
            ['035401008', 'Cutcut'],
            ['035401009', 'Cutud'],
            ['035401010', 'Lourdes Norte'],
            ['035401011', 'Lourdes Sur'],
            ['035401012', 'Lourdes Sur East'],
            ['035401013', 'Malabanias'],
            ['035401014', 'Margot'],
            ['035401015', 'Mining'],
            ['035401016', 'Ninoy Aquino (Marisol)'],
            ['035401017', 'Pandan'],
            ['035401018', 'Pampang'],
            ['035401019', 'Pinyahan'],
            ['035401020', 'Pulung Bulo'],
            ['035401021', 'Pulung Cacutud'],
            ['035401022', 'Pulung Maragul'],
            ['035401023', 'Pulungmasle'],
            ['035401024', 'Salapungan'],
            ['035401025', 'San Jose'],
            ['035401026', 'San Nicolas'],
            ['035401027', 'Santa Teresita'],
            ['035401028', 'Santa Trinidad'],
            ['035401029', 'Santo Cristo'],
            ['035401030', 'Santo Domingo'],
            ['035401031', 'Santo Rosario (Poblacion)'],
            ['035401032', 'Sapalibutad'],
            ['035401033', 'Sapangbato'],
        ];

        foreach ($barangays as [$code, $name]) {
            $safeName = str_replace("'", "''", $name);
            $this->execute("
                INSERT INTO ref.psgc_areas (code, level, name, parent_code)
                VALUES ('{$code}', 'BARANGAY', '{$safeName}', '035401000')
                ON CONFLICT (code) DO NOTHING;
            ");
        }
    }

    private function seedOrganization(): void
    {
        $this->execute("
            INSERT INTO app.organizations (id, code, name, org_type, psgc_code)
            VALUES (1001, 'ANGELES_CITY_LGU', 'Angeles City Local Government Unit', 'GOVERNMENT', '035401000')
            ON CONFLICT (code) DO NOTHING;
        ");
    }

    private function seedUser(): void
    {
        // Angeles City GIS Specialist — for wiring and integration testing
        $uid = 1001;
        $this->execute("
            INSERT INTO app.users (id, username, email, password_hash, full_name, org_id)
            VALUES (
                {$uid},
                'angeles_gis_user',
                'gis@angeles.city.local',
                '\$argon2id\$v=19\$m=65536,t=4,p=1\$VTVYYWM1WXlYNWdKUXRKaA\$AAnD6mkoKuE6hAKEqvmPP4q8/2dQgoTDYiYmIFtMgLs',
                'Angeles City GIS Specialist',
                1001
            )
            ON CONFLICT DO NOTHING;
        ");

        $this->execute("
            INSERT INTO app.user_roles (user_id, role_id)
            SELECT {$uid}, id FROM app.roles WHERE code = 'GIS_SPECIALIST'
            ON CONFLICT DO NOTHING;
        ");

        // Scope: city-level EDIT access
        $this->execute("
            INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level)
            SELECT {$uid}, 'MUNICIPALITY', '035401000', 'EDIT'
            WHERE NOT EXISTS (
                SELECT 1 FROM app.data_scopes
                WHERE user_id = {$uid}
                  AND scope_type = 'MUNICIPALITY'
                  AND scope_ref_code = '035401000'
                  AND access_level = 'EDIT'
            );
        ");
    }

    private function seedControlPoints(): void
    {
        /*
         * Source: BLLM Schedule PDF, CAD-94 (Angeles, Pampanga).
         *
         * Schedule columns (as printed):
         *   Survey/Location | Point | Lat (D M S) | Lon (D M S) | Northing | Easting | dN | dE
         *
         * PPCS Zone III: central meridian 121 E, Clarke 1866, k0=0.99995.
         * Geographic coordinates are in the local Clarke 1866 datum (not WGS84).
         * Offset columns dN/dE measure distance from the BLLM-1 grid origin
         * (N=20 000, E=20 000 in the schedule's local numbering) and are stored
         * in the remarks for audit traceability.
         */

        // Look up PPCS Zone III CRS — we need both the registry PK (for the
        // native_crs_id FK) and the PostGIS SRID (for ST_Transform / ST_SetSRID).
        $ppcsZone3Id   = $this->getCrsId(990103);   // ref.crs_registry.id
        $ppcsZone3Srid = 990103;                     // PostGIS spatial_ref_sys.srid

        $psgcCity = '035401000';  // Angeles City

                $points = [
            [
                'name'        => 'BBM 1',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673191.0000',
                'easting'     => '455590.1000',
                'lat_dd'      => 15.12966,
                'lon_dd'      => 120.5868,
                'description' => 'BBM 1, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673191 E=455590.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 11',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674594.0000',
                'easting'     => '455501.7000',
                'lat_dd'      => 15.14233,
                'lon_dd'      => 120.586,
                'description' => 'BBM 11, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674594 E=455501.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 12',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674172.0000',
                'easting'     => '455571.7000',
                'lat_dd'      => 15.13852,
                'lon_dd'      => 120.5866,
                'description' => 'BBM 12, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674172 E=455571.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 13',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674341.0000',
                'easting'     => '455746.3000',
                'lat_dd'      => 15.14005,
                'lon_dd'      => 120.5883,
                'description' => 'BBM 13, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674341 E=455746.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 14',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674416.0000',
                'easting'     => '455809.2000',
                'lat_dd'      => 15.14073,
                'lon_dd'      => 120.5888,
                'description' => 'BBM 14, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674416 E=455809.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 15',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674284.0000',
                'easting'     => '455978.2000',
                'lat_dd'      => 15.13953,
                'lon_dd'      => 120.5904,
                'description' => 'BBM 15, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674284 E=455978.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 16',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674029.0000',
                'easting'     => '456062.6000',
                'lat_dd'      => 15.13723,
                'lon_dd'      => 120.5912,
                'description' => 'BBM 16, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674029 E=456062.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 17',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674535.0000',
                'easting'     => '456349.2000',
                'lat_dd'      => 15.14181,
                'lon_dd'      => 120.5939,
                'description' => 'BBM 17, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674535 E=456349.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 18',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674061.0000',
                'easting'     => '457308.7000',
                'lat_dd'      => 15.13754,
                'lon_dd'      => 120.6028,
                'description' => 'BBM 18, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674061 E=457308.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 2',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1672902.0000',
                'easting'     => '455779.5000',
                'lat_dd'      => 15.12704,
                'lon_dd'      => 120.5886,
                'description' => 'BBM 2, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1672902 E=455779.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 20',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674529.0000',
                'easting'     => '458214.3000',
                'lat_dd'      => 15.14179,
                'lon_dd'      => 120.6112,
                'description' => 'BBM 20, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674529 E=458214.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 21',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674466.0000',
                'easting'     => '458364.1000',
                'lat_dd'      => 15.14122,
                'lon_dd'      => 120.6126,
                'description' => 'BBM 21, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674466 E=458364.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 24',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676262.0000',
                'easting'     => '458535.2000',
                'lat_dd'      => 15.15746,
                'lon_dd'      => 120.6142,
                'description' => 'BBM 24, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676262 E=458535.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 25',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676404.0000',
                'easting'     => '458349.4000',
                'lat_dd'      => 15.15874,
                'lon_dd'      => 120.6124,
                'description' => 'BBM 25, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676404 E=458349.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 27',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676958.0000',
                'easting'     => '459020.1000',
                'lat_dd'      => 15.16375,
                'lon_dd'      => 120.6187,
                'description' => 'BBM 27, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676958 E=459020.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 29',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677355.0000',
                'easting'     => '459815.9000',
                'lat_dd'      => 15.16735,
                'lon_dd'      => 120.6261,
                'description' => 'BBM 29, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677355 E=459815.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 3',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673322.0000',
                'easting'     => '456392.9000',
                'lat_dd'      => 15.13085,
                'lon_dd'      => 120.5943,
                'description' => 'BBM 3, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673322 E=456392.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 30',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675168.0000',
                'easting'     => '456759.7000',
                'lat_dd'      => 15.14754,
                'lon_dd'      => 120.5977,
                'description' => 'BBM 30, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675168 E=456759.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 31',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675345.0000',
                'easting'     => '456929.2000',
                'lat_dd'      => 15.14915,
                'lon_dd'      => 120.5992,
                'description' => 'BBM 31, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675345 E=456929.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 32',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675413.0000',
                'easting'     => '455780.8000',
                'lat_dd'      => 15.14974,
                'lon_dd'      => 120.5886,
                'description' => 'BBM 32, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675413 E=455780.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 34',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675968.0000',
                'easting'     => '456165.5000',
                'lat_dd'      => 15.15476,
                'lon_dd'      => 120.5921,
                'description' => 'BBM 34, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675968 E=456165.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 35',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676909.0000',
                'easting'     => '456650.4000',
                'lat_dd'      => 15.16327,
                'lon_dd'      => 120.5966,
                'description' => 'BBM 35, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676909 E=456650.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 36',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675831.0000',
                'easting'     => '455601.4000',
                'lat_dd'      => 15.15352,
                'lon_dd'      => 120.5869,
                'description' => 'BBM 36, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675831 E=455601.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 37',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676458.0000',
                'easting'     => '455397.3000',
                'lat_dd'      => 15.15918,
                'lon_dd'      => 120.585,
                'description' => 'BBM 37, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676458 E=455397.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 38',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674192.0000',
                'easting'     => '452734.6000',
                'lat_dd'      => 15.13865,
                'lon_dd'      => 120.5602,
                'description' => 'BBM 38, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674192 E=452734.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 39',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675171.0000',
                'easting'     => '453758.1000',
                'lat_dd'      => 15.14752,
                'lon_dd'      => 120.5697,
                'description' => 'BBM 39, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675171 E=453758.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 4',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673567.0000',
                'easting'     => '456615.8000',
                'lat_dd'      => 15.13307,
                'lon_dd'      => 120.5964,
                'description' => 'BBM 4, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673567 E=456615.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 40',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675892.0000',
                'easting'     => '454841.5000',
                'lat_dd'      => 15.15405,
                'lon_dd'      => 120.5798,
                'description' => 'BBM 40, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675892 E=454841.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 41',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676307.0000',
                'easting'     => '454878.7000',
                'lat_dd'      => 15.1578,
                'lon_dd'      => 120.5801,
                'description' => 'BBM 41, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676307 E=454878.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 42',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676638.0000',
                'easting'     => '454599.8000',
                'lat_dd'      => 15.16079,
                'lon_dd'      => 120.5775,
                'description' => 'BBM 42, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676638 E=454599.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 43',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676633.0000',
                'easting'     => '454050.7000',
                'lat_dd'      => 15.16073,
                'lon_dd'      => 120.5724,
                'description' => 'BBM 43, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676633 E=454050.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 44',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677008.0000',
                'easting'     => '453626.6000',
                'lat_dd'      => 15.16412,
                'lon_dd'      => 120.5685,
                'description' => 'BBM 44, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677008 E=453626.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 45',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677235.0000',
                'easting'     => '454295.9000',
                'lat_dd'      => 15.16618,
                'lon_dd'      => 120.5747,
                'description' => 'BBM 45, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677235 E=454295.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 46',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674737.0000',
                'easting'     => '451766.9000',
                'lat_dd'      => 15.14356,
                'lon_dd'      => 120.5512,
                'description' => 'BBM 46, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674737 E=451766.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 47',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675576.0000',
                'easting'     => '452245.0000',
                'lat_dd'      => 15.15115,
                'lon_dd'      => 120.5557,
                'description' => 'BBM 47, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675576 E=452245.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 48',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676235.0000',
                'easting'     => '452562.4000',
                'lat_dd'      => 15.15711,
                'lon_dd'      => 120.5586,
                'description' => 'BBM 48, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676235 E=452562.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 49',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676385.0000',
                'easting'     => '452797.3000',
                'lat_dd'      => 15.15847,
                'lon_dd'      => 120.5608,
                'description' => 'BBM 49, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676385 E=452797.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 5',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673338.0000',
                'easting'     => '457069.5000',
                'lat_dd'      => 15.13101,
                'lon_dd'      => 120.6006,
                'description' => 'BBM 5, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673338 E=457069.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 50',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676579.0000',
                'easting'     => '450177.7000',
                'lat_dd'      => 15.16018,
                'lon_dd'      => 120.5364,
                'description' => 'BBM 50, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676579 E=450177.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 51',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677736.0000',
                'easting'     => '451660.8000',
                'lat_dd'      => 15.17066,
                'lon_dd'      => 120.5502,
                'description' => 'BBM 51, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677736 E=451660.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 6',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673720.0000',
                'easting'     => '456040.7000',
                'lat_dd'      => 15.13444,
                'lon_dd'      => 120.591,
                'description' => 'BBM 6, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673720 E=456040.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 7',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673911.0000',
                'easting'     => '456077.2000',
                'lat_dd'      => 15.13617,
                'lon_dd'      => 120.5913,
                'description' => 'BBM 7, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673911 E=456077.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 8',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674011.0000',
                'easting'     => '455303.5000',
                'lat_dd'      => 15.13706,
                'lon_dd'      => 120.5841,
                'description' => 'BBM 8, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674011 E=455303.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BBM 9',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674535.0000',
                'easting'     => '454974.9000',
                'lat_dd'      => 15.14179,
                'lon_dd'      => 120.5811,
                'description' => 'BBM 9, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674535 E=454974.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLBM 1',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674613.0000',
                'easting'     => '459760.6000',
                'lat_dd'      => 15.14257,
                'lon_dd'      => 120.6256,
                'description' => 'BLBM 1, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674613 E=459760.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLBM 1',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673533.0000',
                'easting'     => '456874.9000',
                'lat_dd'      => 15.13276,
                'lon_dd'      => 120.5988,
                'description' => 'BLBM 1, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673533 E=456874.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLBM 1',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676522.0000',
                'easting'     => '460112.7000',
                'lat_dd'      => 15.15983,
                'lon_dd'      => 120.6288,
                'description' => 'BLBM 1, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676522 E=460112.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLBM 2',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674644.0000',
                'easting'     => '459865.0000',
                'lat_dd'      => 15.14285,
                'lon_dd'      => 120.6266,
                'description' => 'BLBM 2, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674644 E=459865.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLBM 2',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673463.0000',
                'easting'     => '457027.4000',
                'lat_dd'      => 15.13214,
                'lon_dd'      => 120.6002,
                'description' => 'BLBM 2, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673463 E=457027.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLBM 2',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676573.0000',
                'easting'     => '460037.7000',
                'lat_dd'      => 15.16029,
                'lon_dd'      => 120.6281,
                'description' => 'BLBM 2, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676573 E=460037.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLLM 1',
                'type'        => 'BLLM',
                'monument'    => 'Concrete monument',
                'northing'    => '1673896.0000',
                'easting'     => '455875.7000',
                'lat_dd'      => 15.13603,
                'lon_dd'      => 120.5895,
                'description' => 'BLLM 1, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673896 E=455875.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLLM 1',
                'type'        => 'BLLM',
                'monument'    => 'Concrete monument',
                'northing'    => '1673896.0000',
                'easting'     => '455875.7000',
                'lat_dd'      => 15.13603,
                'lon_dd'      => 120.5895,
                'description' => 'BLLM 1, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673896 E=455875.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLLM 2',
                'type'        => 'BLLM',
                'monument'    => 'Concrete monument',
                'northing'    => '1673947.0000',
                'easting'     => '455814.5000',
                'lat_dd'      => 15.13649,
                'lon_dd'      => 120.5889,
                'description' => 'BLLM 2, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673947 E=455814.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLLM 2',
                'type'        => 'BLLM',
                'monument'    => 'Concrete monument',
                'northing'    => '1673947.0000',
                'easting'     => '455814.5000',
                'lat_dd'      => 15.13649,
                'lon_dd'      => 120.5889,
                'description' => 'BLLM 2, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673947 E=455814.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLLM 3',
                'type'        => 'BLLM',
                'monument'    => 'Concrete monument',
                'northing'    => '1674613.0000',
                'easting'     => '459761.9000',
                'lat_dd'      => 15.14257,
                'lon_dd'      => 120.6256,
                'description' => 'BLLM 3, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674613 E=459761.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLLM 4',
                'type'        => 'BLLM',
                'monument'    => 'Concrete monument',
                'northing'    => '1674644.0000',
                'easting'     => '459866.3000',
                'lat_dd'      => 15.14285,
                'lon_dd'      => 120.6266,
                'description' => 'BLLM 4, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674644 E=459866.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLLM 5',
                'type'        => 'BLLM',
                'monument'    => 'Concrete monument',
                'northing'    => '1676524.0000',
                'easting'     => '460113.7000',
                'lat_dd'      => 15.15985,
                'lon_dd'      => 120.6289,
                'description' => 'BLLM 5, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676524 E=460113.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BLLM 6',
                'type'        => 'BLLM',
                'monument'    => 'Concrete monument',
                'northing'    => '1676574.0000',
                'easting'     => '460038.7000',
                'lat_dd'      => 15.1603,
                'lon_dd'      => 120.6282,
                'description' => 'BLLM 6, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676574 E=460038.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BM 11',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674594.0000',
                'easting'     => '455501.8000',
                'lat_dd'      => 15.14233,
                'lon_dd'      => 120.586,
                'description' => 'BM 11, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674594 E=455501.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BM 24',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676262.0000',
                'easting'     => '458535.2000',
                'lat_dd'      => 15.15746,
                'lon_dd'      => 120.6142,
                'description' => 'BM 24, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676262 E=458535.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BM 30',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675168.0000',
                'easting'     => '456766.2000',
                'lat_dd'      => 15.14754,
                'lon_dd'      => 120.5977,
                'description' => 'BM 30, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675168 E=456766.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'BM 44',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677008.0000',
                'easting'     => '453626.6000',
                'lat_dd'      => 15.16412,
                'lon_dd'      => 120.5685,
                'description' => 'BM 44, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677008 E=453626.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 1',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1672601.0000',
                'easting'     => '456821.5000',
                'lat_dd'      => 15.12434,
                'lon_dd'      => 120.5983,
                'description' => 'MBM 1, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1672601 E=456821.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 10',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1674004.0000',
                'easting'     => '452685.8000',
                'lat_dd'      => 15.13695,
                'lon_dd'      => 120.5598,
                'description' => 'MBM 10, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674004 E=452685.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 11',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1674451.0000',
                'easting'     => '451362.7000',
                'lat_dd'      => 15.14097,
                'lon_dd'      => 120.5475,
                'description' => 'MBM 11, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674451 E=451362.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 12',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1674459.0000',
                'easting'     => '450753.1000',
                'lat_dd'      => 15.14103,
                'lon_dd'      => 120.5418,
                'description' => 'MBM 12, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674459 E=450753.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 13',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1675217.0000',
                'easting'     => '449494.3000',
                'lat_dd'      => 15.14786,
                'lon_dd'      => 120.5301,
                'description' => 'MBM 13, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675217 E=449494.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 14',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1675549.0000',
                'easting'     => '449761.1000',
                'lat_dd'      => 15.15086,
                'lon_dd'      => 120.5325,
                'description' => 'MBM 14, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675549 E=449761.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 15',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1676359.0000',
                'easting'     => '446351.2000',
                'lat_dd'      => 15.15811,
                'lon_dd'      => 120.5008,
                'description' => 'MBM 15, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676359 E=446351.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 16',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1675994.0000',
                'easting'     => '446194.3000',
                'lat_dd'      => 15.15481,
                'lon_dd'      => 120.4993,
                'description' => 'MBM 16, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675994 E=446194.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 17',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1677813.0000',
                'easting'     => '448961.6000',
                'lat_dd'      => 15.1713,
                'lon_dd'      => 120.5251,
                'description' => 'MBM 17, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677813 E=448961.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 18',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1677986.0000',
                'easting'     => '449552.6000',
                'lat_dd'      => 15.17288,
                'lon_dd'      => 120.5306,
                'description' => 'MBM 18, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677986 E=449552.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 19',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678191.0000',
                'easting'     => '450866.0000',
                'lat_dd'      => 15.17476,
                'lon_dd'      => 120.5428,
                'description' => 'MBM 19, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678191 E=450866.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 2',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1672575.0000',
                'easting'     => '456745.6000',
                'lat_dd'      => 15.1241,
                'lon_dd'      => 120.5976,
                'description' => 'MBM 2, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1672575 E=456745.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 20',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678488.0000',
                'easting'     => '452773.6000',
                'lat_dd'      => 15.17748,
                'lon_dd'      => 120.5605,
                'description' => 'MBM 20, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678488 E=452773.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 21',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1677578.0000',
                'easting'     => '453173.5000',
                'lat_dd'      => 15.16926,
                'lon_dd'      => 120.5643,
                'description' => 'MBM 21, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677578 E=453173.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 22',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1677861.0000',
                'easting'     => '455020.1000',
                'lat_dd'      => 15.17185,
                'lon_dd'      => 120.5814,
                'description' => 'MBM 22, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677861 E=455020.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 23',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1677986.0000',
                'easting'     => '455671.1000',
                'lat_dd'      => 15.173,
                'lon_dd'      => 120.5875,
                'description' => 'MBM 23, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677986 E=455671.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 24',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678047.0000',
                'easting'     => '455970.1000',
                'lat_dd'      => 15.17355,
                'lon_dd'      => 120.5903,
                'description' => 'MBM 24, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678047 E=455970.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 25',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678364.0000',
                'easting'     => '460641.0000',
                'lat_dd'      => 15.17648,
                'lon_dd'      => 120.6337,
                'description' => 'MBM 25, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678364 E=460641.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 26',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678314.0000',
                'easting'     => '457269.2000',
                'lat_dd'      => 15.17598,
                'lon_dd'      => 120.6024,
                'description' => 'MBM 26, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678314 E=457269.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 27',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678033.0000',
                'easting'     => '457272.4000',
                'lat_dd'      => 15.17344,
                'lon_dd'      => 120.6024,
                'description' => 'MBM 27, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678033 E=457272.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 28',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1677851.0000',
                'easting'     => '457609.8000',
                'lat_dd'      => 15.17181,
                'lon_dd'      => 120.6055,
                'description' => 'MBM 28, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677851 E=457609.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 29',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678315.0000',
                'easting'     => '457719.8000',
                'lat_dd'      => 15.176,
                'lon_dd'      => 120.6065,
                'description' => 'MBM 29, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678315 E=457719.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 3',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1671945.0000',
                'easting'     => '456334.2000',
                'lat_dd'      => 15.11841,
                'lon_dd'      => 120.5938,
                'description' => 'MBM 3, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1671945 E=456334.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 30',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678295.0000',
                'easting'     => '458361.2000',
                'lat_dd'      => 15.17583,
                'lon_dd'      => 120.6125,
                'description' => 'MBM 30, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678295 E=458361.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 31',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678432.0000',
                'easting'     => '459361.7000',
                'lat_dd'      => 15.17708,
                'lon_dd'      => 120.6218,
                'description' => 'MBM 31, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678432 E=459361.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 32',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678748.0000',
                'easting'     => '459521.6000',
                'lat_dd'      => 15.17994,
                'lon_dd'      => 120.6233,
                'description' => 'MBM 32, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678748 E=459521.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 33',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678899.0000',
                'easting'     => '460449.0000',
                'lat_dd'      => 15.18132,
                'lon_dd'      => 120.6319,
                'description' => 'MBM 33, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678899 E=460449.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 34',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1678718.0000',
                'easting'     => '460745.5000',
                'lat_dd'      => 15.17969,
                'lon_dd'      => 120.6347,
                'description' => 'MBM 34, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678718 E=460745.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 35',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1677789.0000',
                'easting'     => '460843.2000',
                'lat_dd'      => 15.17129,
                'lon_dd'      => 120.6356,
                'description' => 'MBM 35, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677789 E=460843.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 36',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1677049.0000',
                'easting'     => '460600.7000',
                'lat_dd'      => 15.1646,
                'lon_dd'      => 120.6334,
                'description' => 'MBM 36, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677049 E=460600.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 37',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1676773.0000',
                'easting'     => '460673.5000',
                'lat_dd'      => 15.16211,
                'lon_dd'      => 120.6341,
                'description' => 'MBM 37, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676773 E=460673.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 38',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1675329.0000',
                'easting'     => '460493.8000',
                'lat_dd'      => 15.14906,
                'lon_dd'      => 120.6324,
                'description' => 'MBM 38, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675329 E=460493.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 39',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1675135.0000',
                'easting'     => '460381.9000',
                'lat_dd'      => 15.1473,
                'lon_dd'      => 120.6314,
                'description' => 'MBM 39, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675135 E=460381.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 4',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1671363.0000',
                'easting'     => '456125.8000',
                'lat_dd'      => 15.11314,
                'lon_dd'      => 120.5918,
                'description' => 'MBM 4, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1671363 E=456125.8.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 40',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1674741.0000',
                'easting'     => '460291.6000',
                'lat_dd'      => 15.14374,
                'lon_dd'      => 120.6305,
                'description' => 'MBM 40, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674741 E=460291.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 41',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1674044.0000',
                'easting'     => '458831.0000',
                'lat_dd'      => 15.13742,
                'lon_dd'      => 120.617,
                'description' => 'MBM 41, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674044 E=458831.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 42',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1673675.0000',
                'easting'     => '458346.4000',
                'lat_dd'      => 15.13407,
                'lon_dd'      => 120.6125,
                'description' => 'MBM 42, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673675 E=458346.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 43',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1672831.0000',
                'easting'     => '457256.4000',
                'lat_dd'      => 15.12643,
                'lon_dd'      => 120.6023,
                'description' => 'MBM 43, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1672831 E=457256.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 5',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1671162.0000',
                'easting'     => '456022.4000',
                'lat_dd'      => 15.11132,
                'lon_dd'      => 120.5909,
                'description' => 'MBM 5, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1671162 E=456022.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 6',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1671881.0000',
                'easting'     => '454916.1000',
                'lat_dd'      => 15.1178,
                'lon_dd'      => 120.5806,
                'description' => 'MBM 6, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1671881 E=454916.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 7',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1672395.0000',
                'easting'     => '455512.5000',
                'lat_dd'      => 15.12246,
                'lon_dd'      => 120.5861,
                'description' => 'MBM 7, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1672395 E=455512.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 8',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1672744.0000',
                'easting'     => '454905.6000',
                'lat_dd'      => 15.1256,
                'lon_dd'      => 120.5805,
                'description' => 'MBM 8, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1672744 E=454905.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'MBM 9',
                'type'        => 'MBM',
                'monument'    => 'Concrete monument',
                'northing'    => '1673258.0000',
                'easting'     => '454110.4000',
                'lat_dd'      => 15.13023,
                'lon_dd'      => 120.573,
                'description' => 'MBM 9, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673258 E=454110.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 1',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674518.0000',
                'easting'     => '460435.5000',
                'lat_dd'      => 15.14173,
                'lon_dd'      => 120.6319,
                'description' => 'PMG 1, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674518 E=460435.5.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3045 C',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1678119.0000',
                'easting'     => '448605.4000',
                'lat_dd'      => 15.17407,
                'lon_dd'      => 120.5217,
                'description' => 'PMG 3045 C, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678119 E=448605.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3045 D',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677984.0000',
                'easting'     => '448928.7000',
                'lat_dd'      => 15.17285,
                'lon_dd'      => 120.5247,
                'description' => 'PMG 3045 D, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677984 E=448928.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3130',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675674.0000',
                'easting'     => '451140.2000',
                'lat_dd'      => 15.15202,
                'lon_dd'      => 120.5454,
                'description' => 'PMG 3130, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675674 E=451140.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3131',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675685.0000',
                'easting'     => '450608.9000',
                'lat_dd'      => 15.1521,
                'lon_dd'      => 120.5404,
                'description' => 'PMG 3131, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675685 E=450608.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3163',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674824.0000',
                'easting'     => '452596.2000',
                'lat_dd'      => 15.14436,
                'lon_dd'      => 120.5589,
                'description' => 'PMG 3163, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674824 E=452596.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3164',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674991.0000',
                'easting'     => '452513.6000',
                'lat_dd'      => 15.14587,
                'lon_dd'      => 120.5582,
                'description' => 'PMG 3164, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674991 E=452513.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3165',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1678101.0000',
                'easting'     => '447769.0000',
                'lat_dd'      => 15.17388,
                'lon_dd'      => 120.514,
                'description' => 'PMG 3165, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678101 E=447769.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3166',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677698.0000',
                'easting'     => '447067.7000',
                'lat_dd'      => 15.17023,
                'lon_dd'      => 120.5074,
                'description' => 'PMG 3166, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677698 E=447067.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3167',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676986.0000',
                'easting'     => '451299.0000',
                'lat_dd'      => 15.16387,
                'lon_dd'      => 120.5468,
                'description' => 'PMG 3167, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676986 E=451299.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3168',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677028.0000',
                'easting'     => '451020.3000',
                'lat_dd'      => 15.16425,
                'lon_dd'      => 120.5442,
                'description' => 'PMG 3168, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677028 E=451020.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3175',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677801.0000',
                'easting'     => '459036.9000',
                'lat_dd'      => 15.17137,
                'lon_dd'      => 120.6188,
                'description' => 'PMG 3175, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677801 E=459036.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3176',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677176.0000',
                'easting'     => '458665.4000',
                'lat_dd'      => 15.16572,
                'lon_dd'      => 120.6154,
                'description' => 'PMG 3176, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677176 E=458665.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3177',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1674397.0000',
                'easting'     => '459214.7000',
                'lat_dd'      => 15.14061,
                'lon_dd'      => 120.6205,
                'description' => 'PMG 3177, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1674397 E=459214.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 32',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1677699.0000',
                'easting'     => '447089.0000',
                'lat_dd'      => 15.17024,
                'lon_dd'      => 120.5076,
                'description' => 'PMG 32, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1677699 E=447089.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3339',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1678459.0000',
                'easting'     => '459398.7000',
                'lat_dd'      => 15.17733,
                'lon_dd'      => 120.6222,
                'description' => 'PMG 3339, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678459 E=459398.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3340',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1678720.0000',
                'easting'     => '459548.4000',
                'lat_dd'      => 15.17969,
                'lon_dd'      => 120.6236,
                'description' => 'PMG 3340, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678720 E=459548.4.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3341',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1678459.0000',
                'easting'     => '459398.6000',
                'lat_dd'      => 15.17733,
                'lon_dd'      => 120.6222,
                'description' => 'PMG 3341, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678459 E=459398.6.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3342',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1680311.0000',
                'easting'     => '458351.1000',
                'lat_dd'      => 15.19405,
                'lon_dd'      => 120.6124,
                'description' => 'PMG 3342, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1680311 E=458351.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3345',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676622.0000',
                'easting'     => '459677.3000',
                'lat_dd'      => 15.16073,
                'lon_dd'      => 120.6248,
                'description' => 'PMG 3345, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676622 E=459677.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3347',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675632.0000',
                'easting'     => '455334.2000',
                'lat_dd'      => 15.15171,
                'lon_dd'      => 120.5844,
                'description' => 'PMG 3347, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675632 E=455334.2.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 3348',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1675780.0000',
                'easting'     => '455620.7000',
                'lat_dd'      => 15.15305,
                'lon_dd'      => 120.5871,
                'description' => 'PMG 3348, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1675780 E=455620.7.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 5',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1676378.0000',
                'easting'     => '452561.0000',
                'lat_dd'      => 15.1584,
                'lon_dd'      => 120.5586,
                'description' => 'PMG 5, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1676378 E=452561.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'PMG 59',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1678102.0000',
                'easting'     => '447774.1000',
                'lat_dd'      => 15.17389,
                'lon_dd'      => 120.514,
                'description' => 'PMG 59, Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1678102 E=447774.1.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'Triangulation Station Angeles N.B.T.',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673904.0000',
                'easting'     => '455825.3000',
                'lat_dd'      => 15.1361,
                'lon_dd'      => 120.589,
                'description' => 'Triangulation Station Angeles N.B.T., Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673904 E=455825.3.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
            [
                'name'        => 'Triangulation Station Angeles S.B.T.',
                'type'        => 'OTHER',
                'monument'    => 'Concrete monument',
                'northing'    => '1673893.0000',
                'easting'     => '455839.9000',
                'lat_dd'      => 15.136,
                'lon_dd'      => 120.5891,
                'description' => 'Triangulation Station Angeles S.B.T., Cad. 94, Angeles City, Pampanga. Seeded from bllm2.pdf. PPCS Zone III: N=1673893 E=455839.9.',
                'survey_ref'  => 'CAD-94, Angeles, Pampanga',
                'psgc'        => '035401000',
            ],
        ];

        foreach ($points as $pt) {
            $this->insertControlPoint($pt, $ppcsZone3Id, $ppcsZone3Srid);
        }
    }

    /**
     * Insert one control point idempotently.
     * Guard: NOT EXISTS on (point_name, native_crs_id) — mirrors the DB
     * unique index (point_name, native_crs_id).
     */
    private function insertControlPoint(array $pt, int $ppcsZone3Id, int $ppcsZone3Srid): void
    {
        $name      = $this->q($pt['name']);
        $type      = $this->q($pt['type']);
        $monument  = $this->q($pt['monument']);
        $desc      = $this->q($pt['description']);
        $surveyRef = $this->q($pt['survey_ref']);
        $psgc      = $this->q($pt['psgc']);
        $datum     = $this->q('PPCS (Clarke 1866)');
        $zone      = $this->q('III');
        $origin    = $this->q('PROJECTED');
        $status    = $this->q('UNVERIFIED');
        $source    = $this->q('BLLM Schedule (PDF), CAD-94 Angeles City');

        $northing = $pt['northing'] !== null ? $this->q($pt['northing']) : 'NULL';
        $easting  = $pt['easting']  !== null ? $this->q($pt['easting'])  : 'NULL';
        $latVal   = $pt['lat_dd']   !== null ? number_format($pt['lat_dd'], 9, '.', '') : 'NULL';
        $lonVal   = $pt['lon_dd']   !== null ? number_format($pt['lon_dd'], 9, '.', '') : 'NULL';

        // WGS84 geometry:
        //   • If geographic coords are available, use them directly (Clarke 1866
        //     ≈ WGS84 within ~220 m; the approximation is documented).
        //   • Otherwise derive from PPCS via ST_Transform, using the PostGIS
        //     SRID (990103), NOT the ref.crs_registry PK.
        //   • If neither, geom stays NULL.
        if ($pt['lat_dd'] !== null) {
            $geomExpr = "ST_SetSRID(ST_MakePoint({$lonVal}, {$latVal}), 4326)";
        } elseif ($pt['northing'] !== null) {
            $e = $pt['easting'];
            $n = $pt['northing'];
            $geomExpr = "ST_Transform(ST_SetSRID(ST_MakePoint({$e}, {$n}), {$ppcsZone3Srid}), 4326)";
        } else {
            $geomExpr = 'NULL';
        }

        $this->execute("
            INSERT INTO app.survey_control_points (
                point_name, point_type, monument_type,
                easting, northing,
                native_crs_id, coordinate_origin,
                latitude, longitude,
                datum, zone,
                description, survey_reference, source,
                status, psgc_barangay,
                geom
            )
            SELECT
                {$name}, {$type}, {$monument},
                {$easting}, {$northing},
                {$ppcsZone3Id}, {$origin},
                {$latVal}, {$lonVal},
                {$datum}, {$zone},
                {$desc}, {$surveyRef}, {$source},
                {$status}, {$psgc},
                {$geomExpr}
            WHERE NOT EXISTS (
                SELECT 1
                FROM app.survey_control_points
                WHERE point_name = {$name}
                  AND native_crs_id = {$ppcsZone3Id}
                  AND deleted_at IS NULL
            );
        ");
    }

    /** Return ref.crs_registry.id for a given SRID. Throws if missing. */
    private function getCrsId(int $srid): int
    {
        $rows = $this->fetchAll(
            "SELECT id FROM ref.crs_registry WHERE srid = {$srid} LIMIT 1"
        );
        if (empty($rows)) {
            throw new \RuntimeException(
                "CRS srid={$srid} not found in ref.crs_registry. "
                . "Run migration 20260926000001_register_ppcs_zones.php first."
            );
        }
        return (int) $rows[0]['id'];
    }

    /** Quote a string value for safe SQL insertion. */
    private function q(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
