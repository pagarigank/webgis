<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class FixtureSeeder extends AbstractSeed
{
    public function getDependencies(): array
    {
        return [
            'SystemSeeder'
        ];
    }

    public function run(): void
    {
        $this->execute("SET app.user_id = '1'");

        // 1. Fictional Province 990000000
        $this->execute("
            INSERT INTO ref.psgc_areas (code, level, name) VALUES ('990000000', 'PROVINCE', 'SAMPLE_PROVINCE') ON CONFLICT DO NOTHING;
            INSERT INTO ref.psgc_areas (code, level, name, parent_code) VALUES ('990100000', 'MUNICIPALITY', 'SAMPLE_MUNI_1', '990000000') ON CONFLICT DO NOTHING;
            INSERT INTO ref.psgc_areas (code, level, name, parent_code) VALUES ('990200000', 'MUNICIPALITY', 'SAMPLE_MUNI_2', '990000000') ON CONFLICT DO NOTHING;
            INSERT INTO ref.psgc_areas (code, level, name, parent_code) VALUES ('990300000', 'MUNICIPALITY', 'SAMPLE_MUNI_3', '990000000') ON CONFLICT DO NOTHING;
        ");

        for ($i = 1; $i <= 10; $i++) {
            $code = '9901' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '000';
            $this->execute("INSERT INTO ref.psgc_areas (code, level, name, parent_code) VALUES ('$code', 'BARANGAY', 'SAMPLE_BRGY_$i', '990100000') ON CONFLICT DO NOTHING;");
        }

        // 2. Fictional Organization
        $this->execute("
            INSERT INTO app.organizations (id, code, name, org_type, psgc_code)
            VALUES (999, 'SAMPLE_ORG', 'Sample Organization', 'GOVERNMENT', '990000000')
            ON CONFLICT (code) DO NOTHING;
        ");

        // 3. Fictional Users per role
        $roles = ['app_admin', 'lra_encoder', 'denr_geodetic_eng', 'dar_officer', 'assessor', 'surveyor', 'public_user', 'system_api'];
        foreach ($roles as $idx => $roleCode) {
            $uid = 900 + $idx;
            $username = "sample_{$roleCode}";
            $this->execute("
                INSERT INTO app.users (id, username, email, password_hash, full_name, org_id)
                VALUES ($uid, '$username', '$username@sample.local', 'hash', 'Sample $roleCode', 999)
                ON CONFLICT DO NOTHING;
            ");

            $this->execute("
                INSERT INTO app.user_roles (user_id, role_id)
                SELECT $uid, id FROM app.roles WHERE code = '$roleCode'
                ON CONFLICT DO NOTHING;
            ");

            $this->execute("
                INSERT INTO app.data_scopes (user_id, scope_type, scope_ref_code, access_level)
                VALUES ($uid, 'PROVINCE', '990000000', 'EDIT')
                ON CONFLICT DO NOTHING;
            ");
        }

        // 4. 100 Parcels & 30 Titles
        for ($i = 1; $i <= 100; $i++) {
            $uuid = sprintf('99999999-9999-9999-9999-%012d', $i);
            $code = "SAMPLE_PARCEL_" . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            $this->execute("
                INSERT INTO app.parcels (id, parcel_code, psgc_barangay)
                VALUES ('$uuid', '$code', '990101000')
                ON CONFLICT (parcel_code) DO NOTHING;
            ");

            if ($i <= 30) {
                $titleNo = "SAMPLE_TITLE_" . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                $this->execute("
                    INSERT INTO app.land_titles (title_number, title_type, psgc_barangay, registry_office)
                    VALUES ('$titleNo', 'OCT', '990101000', 'SAMPLE_REGISTRY')
                    ON CONFLICT (title_number, registry_office) DO NOTHING;
                ");
            }
        }

        // 5. 5 Overlapping claims
        for ($i = 1; $i <= 5; $i++) {
            $uuid = sprintf('88888888-8888-8888-8888-%012d', $i);
            $code = "SAMPLE_OVERLAP_" . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            $this->execute("
                INSERT INTO app.parcels (id, parcel_code, psgc_barangay)
                VALUES ('$uuid', '$code', '990101000')
                ON CONFLICT (parcel_code) DO NOTHING;
            ");
        }

        // 6. Workflow fixture — code-based, no hardcoded IDs
        $this->execute("
            INSERT INTO app.workflow_definitions (code, entity_type, name, is_active)
            VALUES ('SAMPLE_CONSOLIDATION', 'PARCEL', 'Sample Consolidation', true)
            ON CONFLICT (code) DO NOTHING;
        ");
        $this->execute("
            INSERT INTO app.workflow_states (definition_id, code, is_initial)
            SELECT id, 'SAMPLE_REVIEW', true FROM app.workflow_definitions WHERE code = 'SAMPLE_CONSOLIDATION'
            ON CONFLICT DO NOTHING;
        ");
        $this->execute("
            INSERT INTO app.workflow_instances (definition_id, entity_type, entity_id, current_state_id)
            SELECT d.id, 'PARCEL', '88888888-8888-8888-8888-000000000001', s.id
            FROM app.workflow_definitions d
            JOIN app.workflow_states s ON s.definition_id = d.id AND s.code = 'SAMPLE_REVIEW'
            WHERE d.code = 'SAMPLE_CONSOLIDATION'
            ON CONFLICT DO NOTHING;

            INSERT INTO app.workflow_instances (definition_id, entity_type, entity_id, current_state_id)
            SELECT d.id, 'PARCEL', '88888888-8888-8888-8888-000000000002', s.id
            FROM app.workflow_definitions d
            JOIN app.workflow_states s ON s.definition_id = d.id AND s.code = 'SAMPLE_REVIEW'
            WHERE d.code = 'SAMPLE_CONSOLIDATION'
            ON CONFLICT DO NOTHING;
        ");

        // 7. Synthetic Control Points
        $this->execute("
            INSERT INTO app.survey_control_points (point_name, point_type, native_crs_id, coordinate_origin, status)
            SELECT 'SAMPLE_BLLM_1', 'BLLM', id, 'GEOGRAPHIC', 'VERIFIED'
            FROM ref.crs_registry WHERE srid = 4326
            ON CONFLICT DO NOTHING;
        ");

        // 8. GIS Layer + Feature — code-based, no hardcoded IDs
        $this->execute("
            INSERT INTO app.gis_layers (code, name, geometry_type, description, status)
            VALUES ('SAMPLE_PARCEL_POLYGON', 'Sample Parcel Polygon', 'POLYGON', 'Sample Parcel Layer for Fixtures', 'ACTIVE')
            ON CONFLICT (code) DO NOTHING;
        ");
        $this->execute("
            INSERT INTO app.gis_features (id, layer_id, geom, psgc_barangay, org_id, attributes)
            SELECT
                '77777777-7777-7777-7777-000000000001',
                l.id,
                ST_GeomFromText('POLYGON((120.98 14.58, 120.99 14.58, 120.99 14.59, 120.98 14.59, 120.98 14.58))', 4326),
                '990101000',
                999,
                '{\"fixture\": true}'
            FROM app.gis_layers l
            WHERE l.code = 'SAMPLE_PARCEL_POLYGON'
            ON CONFLICT (id) DO NOTHING;
        ");
    }
}
