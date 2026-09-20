<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class SystemSeeder extends AbstractSeed
{
    public function run(): void
    {
        // 1. Roles
        $rolesData = [
            "('app_rw', 'Application Read/Write', 'Read and write access to application data', true)",
            "('app_ro', 'Application Read Only', 'Read-only access to application data', true)",
            "('app_migrator', 'Application Migrator', 'Access for running migrations', true)",
            "('SYS_ADMIN', 'System Administrator', 'Full administrative access', true)",
            "('DATA_ENCODER', 'Data Encoder', 'Data entry role', true)",
            "('GIS_SPECIALIST', 'GIS Specialist', 'GIS operations role', true)",
            "('SURVEYOR', 'Surveyor', 'Survey and parcel operations role', true)"
        ];

        $this->execute("
            INSERT INTO app.roles (code, name, description, is_system)
            VALUES " . implode(", ", $rolesData) . "
            ON CONFLICT (code) DO NOTHING
        ");

        // 2. Basemap Provider (OSM)
        $this->execute("
            INSERT INTO app.basemap_providers (
                code, name, provider_type, service_url, attribution_html, 
                license_type, is_enabled, is_default, min_zoom, max_zoom
            ) VALUES (
                'OSM_DEFAULT', 'OpenStreetMap', 'XYZ', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                '&copy; <a href=\"https://www.openstreetmap.org/copyright\">OpenStreetMap</a> contributors',
                'OPEN_ODBL', true, true, 0, 19
            )
            ON CONFLICT (code) DO NOTHING
        ");

        // 3. System Settings
        $settingsData = [
            "('SYSTEM_TOLERANCE_CLOSURE', '{\"value\": 0.05}', 'Default closure tolerance for surveys')",
            "('SYSTEM_MIN_LOT_AREA', '{\"value\": 20.0}', 'Minimum lot area for parcels in sqm')"
        ];

        $this->execute("
            INSERT INTO app.system_settings (key, value, description)
            VALUES " . implode(", ", $settingsData) . "
            ON CONFLICT (key) DO NOTHING
        ");

        // 4. Base Workflows (Parcel Approval)
        $this->execute("
            INSERT INTO app.workflow_definitions (code, entity_type, name, is_active)
            VALUES ('PARCEL_APPROVAL', 'PARCEL', 'Parcel Approval Workflow', true)
            ON CONFLICT (code) DO NOTHING
        ");
        
        // Workflow States
        $this->execute("
            INSERT INTO app.workflow_states (definition_id, code, name, is_initial, is_terminal, display_order)
            SELECT d.id, 'DRAFT', 'Draft', true, false, 10
            FROM app.workflow_definitions d WHERE d.code = 'PARCEL_APPROVAL'
            ON CONFLICT (definition_id, code) DO NOTHING
        ");

        $this->execute("
            INSERT INTO app.workflow_states (definition_id, code, name, is_initial, is_terminal, display_order)
            SELECT d.id, 'SUBMITTED', 'Submitted for Review', false, false, 20
            FROM app.workflow_definitions d WHERE d.code = 'PARCEL_APPROVAL'
            ON CONFLICT (definition_id, code) DO NOTHING
        ");
        
        $this->execute("
            INSERT INTO app.workflow_states (definition_id, code, name, is_initial, is_terminal, display_order)
            SELECT d.id, 'APPROVED', 'Approved', false, true, 30
            FROM app.workflow_definitions d WHERE d.code = 'PARCEL_APPROVAL'
            ON CONFLICT (definition_id, code) DO NOTHING
        ");

        // Workflow Transitions
        $this->execute("
            INSERT INTO app.workflow_transitions (definition_id, from_state_id, to_state_id, action_code, required_permission)
            SELECT d.id, s1.id, s2.id, 'SUBMIT', 'PARCEL_SUBMIT'
            FROM app.workflow_definitions d
            JOIN app.workflow_states s1 ON s1.definition_id = d.id AND s1.code = 'DRAFT'
            JOIN app.workflow_states s2 ON s2.definition_id = d.id AND s2.code = 'SUBMITTED'
            WHERE d.code = 'PARCEL_APPROVAL'
            ON CONFLICT (definition_id, from_state_id, action_code) DO NOTHING
        ");

        $this->execute("
            INSERT INTO app.workflow_transitions (definition_id, from_state_id, to_state_id, action_code, required_permission)
            SELECT d.id, s1.id, s2.id, 'APPROVE', 'PARCEL_APPROVE'
            FROM app.workflow_definitions d
            JOIN app.workflow_states s1 ON s1.definition_id = d.id AND s1.code = 'SUBMITTED'
            JOIN app.workflow_states s2 ON s2.definition_id = d.id AND s2.code = 'APPROVED'
            WHERE d.code = 'PARCEL_APPROVAL'
            ON CONFLICT (definition_id, from_state_id, action_code) DO NOTHING
        ");
    }
}
