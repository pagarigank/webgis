<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class SampleDataSeeder extends AbstractSeed
{
    public function getDependencies(): array
    {
        return [
            'RefSeeder',
            'PsgcSeeder',
            'SystemSeeder'
        ];
    }

    public function run(): void
    {
        $this->execute("SET app.user_id = '1'");

        // 1. Survey Control Point
        $this->execute("
            INSERT INTO app.survey_control_points (point_name, point_type, native_crs_id, coordinate_origin, status)
            SELECT 'TEST_BLLM_1', 'BLLM', id, 'GEOGRAPHIC', 'VERIFIED'
            FROM ref.crs_registry WHERE srid = 4326
            ON CONFLICT DO NOTHING
        ");

        // 2. Survey Plan
        $this->execute("
            INSERT INTO app.survey_plans (plan_number, plan_type)
            VALUES ('TEST_PSD_01', 'Psd')
            ON CONFLICT (plan_number) DO NOTHING
        ");

        // 3. Parcel
        $parcelId = '123e4567-e89b-12d3-a456-426614174000'; // fixed uuid for testing
        $this->execute("
            INSERT INTO app.parcels (id, parcel_code)
            VALUES ('{$parcelId}', 'TEST_PARCEL_001')
            ON CONFLICT (parcel_code) DO NOTHING
        ");
        
        // Ensure parcel id is consistent with fixed UUID
        // The above ON CONFLICT DO NOTHING will skip if exists, but we need the exact ID for the TD below
        // If it already exists with a different UUID, it might fail. But it's a test seeder, so assuming clean DB.

        // 4. Technical Description
        $this->execute("
            INSERT INTO app.technical_descriptions (parcel_id, revision, source_type, parser_status)
            VALUES ('{$parcelId}', 1, 'MANUALLY_ENTERED', 'PARSED')
            ON CONFLICT DO NOTHING
        ");
        
        // We'll leave the computation out for simplicity, or we can add dummy TD courses
        // Just minimal sample data to pass constraints.
    }
}
