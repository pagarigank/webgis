<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class SystemSeeder extends AbstractSeed
{
    public function run(): void
    {
        // 1. Roles
        $rolesData = [
            "('app_rw', 'Application Read/Write', 'Read and write access to application data', true, false)",
            "('app_ro', 'Application Read Only', 'Read-only access to application data', true, false)",
            "('app_migrator', 'Application Migrator', 'Access for running migrations', true, false)",
            "('SYS_ADMIN', 'System Administrator', 'Full administrative access', true, true)",
            "('DATA_ENCODER', 'Data Encoder', 'Data entry role', true, false)",
            "('GIS_SPECIALIST', 'GIS Specialist', 'GIS operations role', true, false)",
            "('SURVEYOR', 'Surveyor', 'Survey and parcel operations role', true, false)"
        ];

        $this->execute("
            INSERT INTO app.roles (code, name, description, is_system, requires_mfa)
            VALUES " . implode(", ", $rolesData) . "
            ON CONFLICT (code) DO UPDATE SET
                requires_mfa = EXCLUDED.requires_mfa
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

        // 4. Base Workflows (Parcel Approval) — full FR-135 state machine
        // (TASK-100). Idempotent: legacy partial rows are reconciled by
        // DELETE + re-INSERT so every environment converges on the same
        // table-driven transition matrix.
        $this->execute("
            INSERT INTO app.workflow_definitions (code, entity_type, name, is_active)
            VALUES ('PARCEL_APPROVAL', 'PARCEL', 'Parcel Approval Workflow', true)
            ON CONFLICT (code) DO NOTHING
        ");

        $this->execute("
            DELETE FROM app.workflow_transitions
            WHERE definition_id = (SELECT id FROM app.workflow_definitions WHERE code = 'PARCEL_APPROVAL')
        ");
        $this->execute("
            DELETE FROM app.workflow_states
            WHERE definition_id = (SELECT id FROM app.workflow_definitions WHERE code = 'PARCEL_APPROVAL')
        ");

        $states = [
            // code, name, is_initial, is_terminal, display_order
            ['DRAFT', 'Draft', true, false, 10],
            ['SUBMITTED', 'Submitted for Review', false, false, 20],
            ['UNDER_REVIEW', 'Under Review', false, false, 30],
            ['RETURNED', 'Returned to Draft', false, false, 40],
            ['VERIFIED', 'Verified', false, false, 50],
            ['APPROVED', 'Approved', false, false, 60],
            ['PUBLISHED', 'Published', false, false, 70],
            ['ARCHIVED', 'Archived', false, true, 80],
            ['SUPERSEDED', 'Superseded', false, true, 90],
        ];
        foreach ($states as $i => [$code, $name, $isInitial, $isTerminal, $order]) {
            $stmt = $this->getAdapter()->getConnection()->prepare("
                INSERT INTO app.workflow_states (definition_id, code, name, is_initial, is_terminal, display_order)
                SELECT d.id, :code, :name, :is_initial, :is_terminal, :display_order
                FROM app.workflow_definitions d WHERE d.code = 'PARCEL_APPROVAL'
                ON CONFLICT (definition_id, code) DO NOTHING
            ");
            $stmt->execute([
                ':code' => $code, ':name' => $name,
                ':is_initial' => $isInitial ? 'true' : 'false',
                ':is_terminal' => $isTerminal ? 'true' : 'false',
                ':display_order' => $order,
            ]);
        }

        // Transition matrix: [action, from, to, permission, requires_reason,
        // requires_comment, guard]. Guards are named keys resolved by
        // WorkflowEngine (TASK-100); 'validation_passed' evaluates the
        // TASK-096 checklist and blocks on any blocking failure.
        $transitions = [
            ['SUBMIT',       'DRAFT',        'SUBMITTED',   'parcel.submit',  false, false, 'validation_passed'],
            ['SUBMIT',       'RETURNED',     'SUBMITTED',   'parcel.submit',  false, false, 'validation_passed'],
            ['START_REVIEW', 'SUBMITTED',    'UNDER_REVIEW','parcel.review',  false, false, null],
            ['RETURN',       'SUBMITTED',    'RETURNED',    'parcel.review',  true,  false, null],
            ['RETURN',       'UNDER_REVIEW', 'RETURNED',    'parcel.review',  true,  false, null],
            ['VERIFY',       'UNDER_REVIEW', 'VERIFIED',    'parcel.verify',  false, false, null],
            ['APPROVE',      'VERIFIED',     'APPROVED',    'parcel.approve', false, true,  'validation_passed'],
            ['PUBLISH',      'APPROVED',     'PUBLISHED',   'parcel.publish', false, false, null],
            ['ARCHIVE',      'DRAFT',        'ARCHIVED',    'parcel.archive', true,  false, null],
            ['ARCHIVE',      'RETURNED',     'ARCHIVED',    'parcel.archive', true,  false, null],
            ['ARCHIVE',      'APPROVED',     'ARCHIVED',    'parcel.archive', true,  false, null],
            ['ARCHIVE',      'PUBLISHED',    'ARCHIVED',    'parcel.archive', true,  false, null],
        ];
        foreach ($transitions as [$action, $from, $to, $perm, $reqReason, $reqComment, $guard]) {
            $stmt = $this->getAdapter()->getConnection()->prepare("
                INSERT INTO app.workflow_transitions
                    (definition_id, from_state_id, to_state_id, action_code, required_permission, requires_reason, requires_comment, guard_expression)
                SELECT d.id, s1.id, s2.id, :action, :perm, :req_reason, :req_comment, :guard
                FROM app.workflow_definitions d
                JOIN app.workflow_states s1 ON s1.definition_id = d.id AND s1.code = :from
                JOIN app.workflow_states s2 ON s2.definition_id = d.id AND s2.code = :to
                WHERE d.code = 'PARCEL_APPROVAL'
                ON CONFLICT (definition_id, from_state_id, action_code) DO NOTHING
            ");
            $stmt->execute([
                ':action' => $action, ':from' => $from, ':to' => $to,
                ':perm' => $perm,
                ':req_reason' => $reqReason ? 'true' : 'false',
                ':req_comment' => $reqComment ? 'true' : 'false',
                ':guard' => $guard,
            ]);
        }
    }
}
