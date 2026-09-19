<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUsersAndRolesTable extends AbstractMigration
{
    public function up(): void
    {
        // Setup RLS and Roles (from ADR-06 / Section 6.3)
        $this->execute("
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'app_rw') THEN
                    CREATE ROLE app_rw;
                END IF;
                IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'app_ro') THEN
                    CREATE ROLE app_ro;
                END IF;
                IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'app_migrator') THEN
                    CREATE ROLE app_migrator;
                END IF;
            END
            $$;
        ");

        // Setup Extensions
        $this->execute("
            CREATE EXTENSION IF NOT EXISTS postgis;
            CREATE EXTENSION IF NOT EXISTS pg_trgm;
            CREATE EXTENSION IF NOT EXISTS pgcrypto;
            CREATE EXTENSION IF NOT EXISTS citext;
            CREATE EXTENSION IF NOT EXISTS btree_gist;
        ");

        // Setup Schemas
        $this->execute("
            CREATE SCHEMA IF NOT EXISTS app;
            CREATE SCHEMA IF NOT EXISTS audit;
            CREATE SCHEMA IF NOT EXISTS ref;
            CREATE SCHEMA IF NOT EXISTS staging;
        ");

        // Set up grants
        $this->execute("
            GRANT USAGE ON SCHEMA app, audit, ref, staging TO app_rw;
            GRANT USAGE ON SCHEMA app, audit, ref TO app_ro;
            
            ALTER DEFAULT PRIVILEGES IN SCHEMA app GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_rw;
            ALTER DEFAULT PRIVILEGES IN SCHEMA app GRANT SELECT ON TABLES TO app_ro;
            
            ALTER DEFAULT PRIVILEGES IN SCHEMA audit GRANT INSERT ON TABLES TO app_rw;
            ALTER DEFAULT PRIVILEGES IN SCHEMA audit GRANT SELECT ON TABLES TO app_ro;
            
            ALTER DEFAULT PRIVILEGES IN SCHEMA ref GRANT SELECT ON TABLES TO app_rw;
            ALTER DEFAULT PRIVILEGES IN SCHEMA ref GRANT SELECT ON TABLES TO app_ro;
            
            ALTER DEFAULT PRIVILEGES IN SCHEMA staging GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_rw;
        ");

        // Roles table
        $roles = $this->table('app.roles', ['id' => false, 'primary_key' => ['id']]);
        $roles
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('code', 'string', ['limit' => 100])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('is_system', 'boolean', ['default' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        // Users table
        $users = $this->table('app.users', ['id' => false, 'primary_key' => ['id']]);
        $users
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('username', 'string', ['limit' => 255])
            ->addColumn('email', 'string', ['limit' => 255])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('full_name', 'string', ['limit' => 255])
            ->addColumn('position', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('org_id', 'biginteger', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 50, 'default' => 'ACTIVE'])
            ->addColumn('mfa_enabled', 'boolean', ['default' => false])
            ->addColumn('mfa_secret_enc', 'text', ['null' => true])
            ->addColumn('must_change_password', 'boolean', ['default' => true])
            ->addColumn('failed_login_count', 'integer', ['default' => 0])
            ->addColumn('locked_until', 'timestamp', ['null' => true])
            ->addColumn('last_login_at', 'timestamp', ['null' => true])
            ->addColumn('password_changed_at', 'timestamp', ['null' => true])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->create();
        
        // Execute raw SQL for case-insensitive unique indexes
        $this->execute('CREATE UNIQUE INDEX users_username_uidx ON app.users (lower(username))');
        $this->execute('CREATE UNIQUE INDEX users_email_uidx ON app.users (lower(email))');

        // User Roles link table
        $userRoles = $this->table('app.user_roles', ['id' => false, 'primary_key' => ['user_id', 'role_id']]);
        $userRoles
            ->addColumn('user_id', 'biginteger')
            ->addColumn('role_id', 'biginteger')
            ->addColumn('org_id', 'biginteger', ['null' => true])
            ->addColumn('granted_by', 'biginteger', ['null' => true])
            ->addColumn('granted_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'app.users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('role_id', 'app.roles', 'id', ['delete' => 'CASCADE'])
            ->create();

        // RLS Backstop
        $this->execute("
            ALTER TABLE app.users ENABLE ROW LEVEL SECURITY;
            ALTER TABLE app.roles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
            
            -- Default deny policies unless app.user_id is set
            CREATE POLICY tenant_isolation_users ON app.users
                FOR ALL
                USING (current_setting('app.user_id', true) IS NOT NULL AND current_setting('app.user_id', true) != '');
                
            CREATE POLICY tenant_isolation_roles ON app.roles
                FOR ALL
                USING (current_setting('app.user_id', true) IS NOT NULL AND current_setting('app.user_id', true) != '');
                
            CREATE POLICY tenant_isolation_audit ON audit_logs
                FOR ALL
                USING (current_setting('app.user_id', true) IS NOT NULL AND current_setting('app.user_id', true) != '');
        ");
    }

    public function down(): void
    {
        $this->execute("
            DROP POLICY IF EXISTS tenant_isolation_audit ON audit_logs;
            DROP POLICY IF EXISTS tenant_isolation_roles ON app.roles;
            DROP POLICY IF EXISTS tenant_isolation_users ON app.users;
            
            ALTER TABLE audit_logs DISABLE ROW LEVEL SECURITY;
        ");
        
        $this->table('app.user_roles')->drop()->save();
        $this->table('app.users')->drop()->save();
        $this->table('app.roles')->drop()->save();
    }
}
