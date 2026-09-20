<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Seeds the immutable permission catalogue (specification.md §3.3, FR-012) and
 * grants the full catalogue to the SYS_ADMIN system role (FR-011).
 *
 * TASK-023 marked the catalogue as seeded, but the seed was never materialised:
 * app.permissions and app.role_permissions were still empty. TASK-034's admin
 * APIs (users/roles/scopes/organisations) depend on user.manage/role.manage/
 * scope.manage/system.config existing, so this migration materialises the
 * documented code set. ON CONFLICT makes re-runs and the earlier FixtureSeeder
 * compatible.
 */
final class SeedPermissionCatalogue extends AbstractMigration
{
    /** code => [module, description] — mirror of specification.md §3.3. */
    private const CATALOGUE = [
        'gis.layer.view'              => ['gis', 'View layer definitions'],
        'gis.layer.create'            => ['gis', 'Create new layers'],
        'gis.layer.update'            => ['gis', 'Update layer definitions'],
        'gis.layer.delete'            => ['gis', 'Delete/archive layers'],
        'gis.field.manage'            => ['gis', 'Manage layer fields and field order'],
        'gis.style.manage'            => ['gis', 'Manage layer styles'],
        'gis.feature.view'            => ['gis', 'View features in layers'],
        'gis.feature.create'          => ['gis', 'Create features'],
        'gis.feature.update'          => ['gis', 'Update features'],
        'gis.feature.delete'          => ['gis', 'Delete features'],
        'basemap.view'                => ['gis', 'View basemaps'],
        'basemap.manage'              => ['gis', 'Manage basemap providers'],

        'parcel.view'                 => ['parcel', 'View parcels'],
        'parcel.create'               => ['parcel', 'Create parcels'],
        'parcel.update'               => ['parcel', 'Update parcels'],
        'parcel.delete'               => ['parcel', 'Delete parcels'],
        'parcel.submit'               => ['parcel', 'Submit parcel for approval'],
        'parcel.review'               => ['parcel', 'Review submitted parcels'],
        'parcel.verify'               => ['parcel', 'Verify parcels'],
        'parcel.approve'              => ['parcel', 'Approve parcels'],
        'parcel.publish'              => ['parcel', 'Publish parcels'],
        'parcel.archive'              => ['parcel', 'Archive parcels'],
        'parcel.split'                => ['parcel', 'Split a parcel'],
        'parcel.consolidate'          => ['parcel', 'Consolidate parcels'],
        'parcel.lineage.view'         => ['parcel', 'View parcel lineage'],
        'parcel.version.restore'      => ['parcel', 'Restore a historical parcel version'],

        'survey.view'                 => ['survey', 'View surveys'],
        'survey.create'               => ['survey', 'Create surveys'],
        'survey.update'               => ['survey', 'Update surveys'],
        'survey.approve'              => ['survey', 'Approve surveys'],
        'techdesc.view'               => ['survey', 'View technical descriptions'],
        'techdesc.create'             => ['survey', 'Create technical descriptions'],
        'techdesc.update'             => ['survey', 'Update technical descriptions'],
        'techdesc.parse'              => ['survey', 'Parse technical descriptions into courses'],
        'techdesc.confirm'            => ['survey', 'Confirm resolved courses'],
        'control_point.view'          => ['survey', 'View control points'],
        'control_point.create'        => ['survey', 'Create control points'],
        'control_point.update'        => ['survey', 'Update control points'],
        'control_point.verify'        => ['survey', 'Verify control points'],

        'title.view'                  => ['title', 'View titles'],
        'title.update'                => ['title', 'Update titles'],
        'title.view_owner'            => ['title', 'View title owner information'],

        'party.view'                  => ['party', 'View parties'],
        'party.manage'                => ['party', 'Manage parties'],

        'document.view'               => ['document', 'View document metadata'],
        'document.upload'             => ['document', 'Upload documents'],
        'document.download_restricted'=> ['document', 'Download restricted documents'],
        'document.delete'             => ['document', 'Delete documents'],

        'rpt.view'                    => ['rpt', 'View RPT records'],
        'rpt.update'                  => ['rpt', 'Update RPT records'],

        'import.execute'              => ['import', 'Execute imports'],
        'export.execute'              => ['import', 'Execute exports'],
        'cad.import'                  => ['import', 'Import CAD files'],

        'user.manage'                 => ['rbac', 'Manage users'],
        'role.manage'                 => ['rbac', 'Manage roles and the permission catalogue'],
        'scope.manage'                => ['rbac', 'Assign data scopes'],

        'audit.view'                  => ['audit', 'View audit logs'],
        'audit.export'                => ['audit', 'Export audit logs'],

        'report.view'                 => ['reports', 'View reports'],
        'report.export'               => ['reports', 'Export reports'],

        'system.config'               => ['system', 'Manage system configuration'],
    ];

    public function change(): void
    {
        if ($this->isMigratingUp()) {
            $this->seedCatalogue();
            $this->grantToSysAdmin();
        } else {
            $this->revokeFromSysAdmin();
            $this->unseedCatalogue();
        }
    }

    private function seedCatalogue(): void
    {
        $rows = [];
        foreach (self::CATALOGUE as $code => [$module, $description]) {
            $rows[] = sprintf(
                "(%s, %s, %s)",
                $this->q($code),
                $this->q($module),
                $this->q($description)
            );
        }

        $this->execute(
            'INSERT INTO app.permissions (code, module, description) VALUES '
            . implode(', ', $rows)
            . ' ON CONFLICT (code) DO NOTHING'
        );
    }

    private function grantToSysAdmin(): void
    {
        $this->execute("
            INSERT INTO app.role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM app.roles r CROSS JOIN app.permissions p
            WHERE r.code = 'SYS_ADMIN'
            ON CONFLICT (role_id, permission_id) DO NOTHING
        ");
    }

    private function revokeFromSysAdmin(): void
    {
        $this->execute("
            DELETE FROM app.role_permissions rp
            USING app.roles r, app.permissions p
            WHERE rp.role_id = r.id AND rp.permission_id = p.id
              AND r.code = 'SYS_ADMIN'
              AND p.code IN (" . $this->quotedCodes() . ')
        ');
    }

    private function unseedCatalogue(): void
    {
        $this->execute('DELETE FROM app.permissions WHERE code IN (' . $this->quotedCodes() . ')');
    }

    private function quotedCodes(): string
    {
        return implode(', ', array_map(fn(string $c): string => $this->q($c), array_keys(self::CATALOGUE)));
    }

    private function q(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}