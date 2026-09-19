<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateIdentityAndAccessTables extends AbstractMigration
{
    public function change(): void
    {
        // 1. Organizations
        $organizations = $this->table('app.organizations', ['id' => false, 'primary_key' => ['id']]);
        $organizations
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('code', 'string', ['limit' => 40])
            ->addColumn('name', 'string', ['limit' => 160])
            ->addColumn('org_type', 'string', ['limit' => 24])
            ->addColumn('parent_id', 'biginteger', ['null' => true])
            ->addColumn('psgc_code', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'ACTIVE'])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        // 2. Add foreign keys
        $organizations->addForeignKey('parent_id', 'app.organizations', 'id')->save();
        $organizations->addForeignKey('psgc_code', 'ref.psgc_areas', 'code')->save();

        $this->table('app.users')
            ->addForeignKey('org_id', 'app.organizations', 'id')
            ->save();

        $this->table('app.user_roles')
            ->addForeignKey('org_id', 'app.organizations', 'id')
            ->save();

        // 3. Permissions
        $permissions = $this->table('app.permissions', ['id' => false, 'primary_key' => ['id']]);
        $permissions
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('code', 'string', ['limit' => 80])
            ->addColumn('module', 'string', ['limit' => 40])
            ->addColumn('description', 'text')
            ->addIndex(['code'], ['unique' => true])
            ->create();

        // 4. Role Permissions
        $rolePermissions = $this->table('app.role_permissions', ['id' => false, 'primary_key' => ['role_id', 'permission_id']]);
        $rolePermissions
            ->addColumn('role_id', 'biginteger')
            ->addColumn('permission_id', 'biginteger')
            ->addForeignKey('role_id', 'app.roles', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('permission_id', 'app.permissions', 'id')
            ->create();

        // 5. Data Scopes
        $dataScopes = $this->table('app.data_scopes', ['id' => false, 'primary_key' => ['id']]);
        $dataScopes
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger')
            ->addColumn('scope_type', 'string', ['limit' => 24])
            ->addColumn('scope_ref_code', 'string', ['limit' => 40, 'null' => true])
            // geom is added via raw SQL below
            ->addColumn('access_level', 'string', ['limit' => 12])
            ->addColumn('valid_from', 'date', ['null' => true])
            ->addColumn('valid_to', 'date', ['null' => true])
            ->addColumn('granted_by', 'biginteger', ['null' => true])
            ->addColumn('granted_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'app.users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('granted_by', 'app.users', 'id')
            ->addIndex(['user_id', 'scope_type'])
            ->create();

        // Raw SQL for Data Scopes geometry, index, and check constraint
        if ($this->isMigratingUp()) {
            $this->execute("ALTER TABLE app.data_scopes ADD COLUMN geom geometry(MultiPolygon,4326)");
            $this->execute("CREATE INDEX gix_scopes_geom ON app.data_scopes USING GIST (geom)");
            $this->execute("ALTER TABLE app.data_scopes ADD CONSTRAINT ck_scope_target CHECK (scope_ref_code IS NOT NULL OR geom IS NOT NULL)");
        } else {
            $this->execute("ALTER TABLE app.data_scopes DROP CONSTRAINT IF EXISTS ck_scope_target");
            $this->execute("DROP INDEX IF EXISTS app.gix_scopes_geom");
            $this->execute("ALTER TABLE app.data_scopes DROP COLUMN IF EXISTS geom");
        }

        // 6. Refresh Tokens
        $refreshTokens = $this->table('app.refresh_tokens', ['id' => false, 'primary_key' => ['id']]);
        $refreshTokens
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger')
            ->addColumn('token_hash', 'binary') // bytea
            ->addColumn('family_id', 'uuid')
            ->addColumn('issued_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('expires_at', 'timestamp')
            ->addColumn('rotated_from', 'biginteger', ['null' => true])
            ->addColumn('revoked_at', 'timestamp', ['null' => true])
            ->addColumn('revoked_reason', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('user_agent', 'text', ['null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true]) // inet not supported by default phinx, will use string
            ->addForeignKey('user_id', 'app.users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('rotated_from', 'app.refresh_tokens', 'id')
            ->addIndex(['token_hash'], ['unique' => true])
            ->create();

        if ($this->isMigratingUp()) {
            $this->execute("ALTER TABLE app.refresh_tokens ALTER COLUMN ip TYPE inet USING ip::inet");
            $this->execute("CREATE INDEX idx_rt_active ON app.refresh_tokens(user_id) WHERE revoked_at IS NULL");
        }
    }
}
