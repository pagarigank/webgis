<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddScopeTypeChecks extends AbstractMigration
{
    public function change(): void
    {
        if ($this->isMigratingUp()) {
            // Migrate legacy fixture rows that used an out-of-spec 'PSGC'/'WRITE'
            // pair into the documented PROVINCE scope type (database.md §4).
            $this->execute("UPDATE app.data_scopes SET scope_type = 'PROVINCE', access_level = 'EDIT' WHERE scope_type = 'PSGC' AND access_level = 'WRITE'");

            // GLOBAL scopes have no geographic/organizational target, so the target
            // check is relaxed for that scope type. The scope_type and access_level
            // enums match database.md §4 / specification.md FR-015 (PROJECT is
            // reserved for a future project-scoped release).
            $this->execute("ALTER TABLE app.data_scopes DROP CONSTRAINT IF EXISTS ck_scope_target");
            $this->execute("ALTER TABLE app.data_scopes ADD CONSTRAINT ck_scope_target CHECK (scope_type = 'GLOBAL' OR scope_ref_code IS NOT NULL OR geom IS NOT NULL)");
            $this->execute("ALTER TABLE app.data_scopes ADD CONSTRAINT ck_scope_type CHECK (scope_type IN ('ORGANIZATION','PROVINCE','MUNICIPALITY','BARANGAY','REGION','CUSTOM_AREA','GLOBAL','PROJECT'))");
            $this->execute("ALTER TABLE app.data_scopes ADD CONSTRAINT ck_scope_access CHECK (access_level IN ('NONE','VIEW','EDIT','APPROVE'))");

            // Reconcile the RLS functions with the documented scope_type/access_level
            // vocabulary (migration 11 used legacy 'ORG'/'PSGC'/'WRITE' values that the
            // new enum checks now forbid). Geographic prefix matching now covers the
            // BARANGAY/MUNICIPALITY/PROVINCE/REGION types; write access maps to the
            // EDIT and APPROVE levels.
            $this->execute("
                CREATE OR REPLACE FUNCTION app.fn_user_can_see(p_user_id bigint, p_psgc varchar, p_org_id bigint)
                RETURNS boolean AS \$\$
                BEGIN
                    IF p_user_id IS NULL THEN
                        RETURN false;
                    END IF;

                    -- Fast path: GLOBAL scope
                    IF EXISTS (
                        SELECT 1 FROM app.data_scopes ds
                        WHERE ds.user_id = p_user_id
                        AND ds.scope_type = 'GLOBAL'
                        AND (ds.valid_from IS NULL OR ds.valid_from <= current_date)
                        AND (ds.valid_to IS NULL OR ds.valid_to >= current_date)
                    ) THEN
                        RETURN true;
                    END IF;

                    -- Geographic (PSGC prefix) and organizational match
                    RETURN EXISTS (
                        SELECT 1 FROM app.data_scopes ds
                        WHERE ds.user_id = p_user_id
                        AND (ds.valid_from IS NULL OR ds.valid_from <= current_date)
                        AND (ds.valid_to IS NULL OR ds.valid_to >= current_date)
                        AND (
                            (ds.scope_type IN ('BARANGAY','MUNICIPALITY','PROVINCE','REGION') AND p_psgc IS NOT NULL AND p_psgc LIKE ds.scope_ref_code || '%')
                            OR (ds.scope_type = 'ORGANIZATION' AND p_org_id IS NOT NULL AND ds.scope_ref_code = p_org_id::varchar)
                        )
                    );
                END;
                \$\$ LANGUAGE plpgsql STABLE;
            ");
            $this->execute("
                CREATE OR REPLACE FUNCTION app.fn_user_can_edit(p_user_id bigint, p_psgc varchar, p_org_id bigint)
                RETURNS boolean AS \$\$
                BEGIN
                    IF p_user_id IS NULL THEN
                        RETURN false;
                    END IF;

                    -- Fast path: global write
                    IF EXISTS (
                        SELECT 1 FROM app.data_scopes ds
                        WHERE ds.user_id = p_user_id
                        AND ds.access_level IN ('EDIT','APPROVE')
                        AND ds.scope_type = 'GLOBAL'
                        AND (ds.valid_from IS NULL OR ds.valid_from <= current_date)
                        AND (ds.valid_to IS NULL OR ds.valid_to >= current_date)
                    ) THEN
                        RETURN true;
                    END IF;

                    RETURN EXISTS (
                        SELECT 1 FROM app.data_scopes ds
                        WHERE ds.user_id = p_user_id
                        AND ds.access_level IN ('EDIT','APPROVE')
                        AND (ds.valid_from IS NULL OR ds.valid_from <= current_date)
                        AND (ds.valid_to IS NULL OR ds.valid_to >= current_date)
                        AND (
                            (ds.scope_type IN ('BARANGAY','MUNICIPALITY','PROVINCE','REGION') AND p_psgc IS NOT NULL AND p_psgc LIKE ds.scope_ref_code || '%')
                            OR (ds.scope_type = 'ORGANIZATION' AND p_org_id IS NOT NULL AND ds.scope_ref_code = p_org_id::varchar)
                        )
                    );
                END;
                \$\$ LANGUAGE plpgsql STABLE;
            ");
        } else {
            $this->execute("ALTER TABLE app.data_scopes DROP CONSTRAINT IF EXISTS ck_scope_access");
            $this->execute("ALTER TABLE app.data_scopes DROP CONSTRAINT IF EXISTS ck_scope_type");
            $this->execute("ALTER TABLE app.data_scopes DROP CONSTRAINT IF EXISTS ck_scope_target");
            $this->execute("ALTER TABLE app.data_scopes ADD CONSTRAINT ck_scope_target CHECK (scope_ref_code IS NOT NULL OR geom IS NOT NULL)");

            // Restore the original migration-11 RLS vocabulary
            $this->execute("
                CREATE OR REPLACE FUNCTION app.fn_user_can_see(p_user_id bigint, p_psgc varchar, p_org_id bigint)
                RETURNS boolean AS \$\$
                BEGIN
                    IF p_user_id IS NULL THEN
                        RETURN false;
                    END IF;

                    IF EXISTS (
                        SELECT 1 FROM app.data_scopes ds
                        WHERE ds.user_id = p_user_id
                        AND ds.scope_type = 'GLOBAL'
                        AND (ds.valid_from IS NULL OR ds.valid_from <= current_date)
                        AND (ds.valid_to IS NULL OR ds.valid_to >= current_date)
                    ) THEN
                        RETURN true;
                    END IF;

                    RETURN EXISTS (
                        SELECT 1 FROM app.data_scopes ds
                        WHERE ds.user_id = p_user_id
                        AND (ds.valid_from IS NULL OR ds.valid_from <= current_date)
                        AND (ds.valid_to IS NULL OR ds.valid_to >= current_date)
                        AND (
                            (ds.scope_type = 'ORG' AND p_org_id IS NOT NULL AND ds.scope_ref_code = p_org_id::varchar)
                            OR (ds.scope_type = 'PSGC' AND p_psgc IS NOT NULL AND p_psgc LIKE ds.scope_ref_code || '%')
                        )
                    );
                END;
                \$\$ LANGUAGE plpgsql STABLE;
            ");
            $this->execute("
                CREATE OR REPLACE FUNCTION app.fn_user_can_edit(p_user_id bigint, p_psgc varchar, p_org_id bigint)
                RETURNS boolean AS \$\$
                BEGIN
                    IF p_user_id IS NULL THEN
                        RETURN false;
                    END IF;

                    IF EXISTS (
                        SELECT 1 FROM app.data_scopes ds
                        WHERE ds.user_id = p_user_id
                        AND ds.access_level = 'WRITE'
                        AND ds.scope_type = 'GLOBAL'
                        AND (ds.valid_from IS NULL OR ds.valid_from <= current_date)
                        AND (ds.valid_to IS NULL OR ds.valid_to >= current_date)
                    ) THEN
                        RETURN true;
                    END IF;

                    RETURN EXISTS (
                        SELECT 1 FROM app.data_scopes ds
                        WHERE ds.user_id = p_user_id
                        AND ds.access_level = 'WRITE'
                        AND (ds.valid_from IS NULL OR ds.valid_from <= current_date)
                        AND (ds.valid_to IS NULL OR ds.valid_to >= current_date)
                        AND (
                            (ds.scope_type = 'ORG' AND p_org_id IS NOT NULL AND ds.scope_ref_code = p_org_id::varchar)
                            OR (ds.scope_type = 'PSGC' AND p_psgc IS NOT NULL AND p_psgc LIKE ds.scope_ref_code || '%')
                        )
                    );
                END;
                \$\$ LANGUAGE plpgsql STABLE;
            ");
        }
    }
}