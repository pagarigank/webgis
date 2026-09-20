<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRlsPolicies extends AbstractMigration
{
    public function up(): void
    {
        // 1. Create the functions
        $this->execute("
            CREATE OR REPLACE FUNCTION app.fn_user_can_see(p_user_id bigint, p_psgc varchar, p_org_id bigint)
            RETURNS boolean AS $$
            BEGIN
                IF p_user_id IS NULL THEN
                    RETURN false;
                END IF;
                
                -- Fast path: if user is SYS_ADMIN or has global scope
                IF EXISTS (
                    SELECT 1 FROM app.data_scopes ds
                    WHERE ds.user_id = p_user_id
                    AND ds.scope_type = 'GLOBAL'
                    AND (ds.valid_from IS NULL OR ds.valid_from <= current_date)
                    AND (ds.valid_to IS NULL OR ds.valid_to >= current_date)
                ) THEN
                    RETURN true;
                END IF;

                -- PSGC and ORG match
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
            $$ LANGUAGE plpgsql STABLE;
            
            CREATE OR REPLACE FUNCTION app.fn_user_can_edit(p_user_id bigint, p_psgc varchar, p_org_id bigint)
            RETURNS boolean AS $$
            BEGIN
                IF p_user_id IS NULL THEN
                    RETURN false;
                END IF;
                
                -- Fast path: global write
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
            $$ LANGUAGE plpgsql STABLE;
        ");

        // 2. Enable RLS and Create Policies

        $tables = [
            'app.parcels' => ['psgc_barangay', 'NULL'],
            'app.gis_features' => ['psgc_barangay', 'org_id'],
            'app.land_titles' => ['psgc_barangay', 'NULL']
        ];

        foreach ($tables as $table => $cols) {
            $psgc = $cols[0];
            $org = $cols[1];
            
            // Note: policy names like app.parcels_scope_select will drop the schema part if any
            $tableName = str_replace('app.', '', $table);

            // Enable RLS
            $this->execute("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");

            // Select Policy
            $this->execute("
                CREATE POLICY {$tableName}_scope_select ON $table FOR SELECT TO public
                USING (app.fn_user_can_see(NULLIF(current_setting('app.user_id', true), '')::bigint, $psgc, $org))
            ");

            // Write Policies (INSERT, UPDATE, DELETE)
            $this->execute("
                CREATE POLICY {$tableName}_scope_insert ON $table FOR INSERT TO public
                WITH CHECK (app.fn_user_can_edit(NULLIF(current_setting('app.user_id', true), '')::bigint, $psgc, $org))
            ");

            $this->execute("
                CREATE POLICY {$tableName}_scope_update ON $table FOR UPDATE TO public
                USING (app.fn_user_can_edit(NULLIF(current_setting('app.user_id', true), '')::bigint, $psgc, $org))
            ");
            
            $this->execute("
                CREATE POLICY {$tableName}_scope_delete ON $table FOR DELETE TO public
                USING (app.fn_user_can_edit(NULLIF(current_setting('app.user_id', true), '')::bigint, $psgc, $org))
            ");
        }

        // technical_descriptions (linked to parcels)
        $this->execute("ALTER TABLE app.technical_descriptions ENABLE ROW LEVEL SECURITY");
        $this->execute("
            CREATE POLICY td_scope_select ON app.technical_descriptions FOR SELECT TO public
            USING (EXISTS (
                SELECT 1 FROM app.parcels p WHERE p.id = parcel_id AND app.fn_user_can_see(NULLIF(current_setting('app.user_id', true), '')::bigint, p.psgc_barangay, NULL)
            ))
        ");
        $this->execute("
            CREATE POLICY td_scope_insert ON app.technical_descriptions FOR INSERT TO public
            WITH CHECK (EXISTS (
                SELECT 1 FROM app.parcels p WHERE p.id = parcel_id AND app.fn_user_can_edit(NULLIF(current_setting('app.user_id', true), '')::bigint, p.psgc_barangay, NULL)
            ))
        ");
        $this->execute("
            CREATE POLICY td_scope_update ON app.technical_descriptions FOR UPDATE TO public
            USING (EXISTS (
                SELECT 1 FROM app.parcels p WHERE p.id = parcel_id AND app.fn_user_can_edit(NULLIF(current_setting('app.user_id', true), '')::bigint, p.psgc_barangay, NULL)
            ))
        ");
        $this->execute("
            CREATE POLICY td_scope_delete ON app.technical_descriptions FOR DELETE TO public
            USING (EXISTS (
                SELECT 1 FROM app.parcels p WHERE p.id = parcel_id AND app.fn_user_can_edit(NULLIF(current_setting('app.user_id', true), '')::bigint, p.psgc_barangay, NULL)
            ))
        ");

        // parties
        $this->execute("ALTER TABLE app.parties ENABLE ROW LEVEL SECURITY");
        $this->execute("
            CREATE POLICY parties_scope_select ON app.parties FOR SELECT TO public
            USING (current_setting('app.user_id', true) IS NOT NULL)
        ");
        $this->execute("
            CREATE POLICY parties_scope_all ON app.parties FOR ALL TO public
            USING (EXISTS (SELECT 1 FROM app.data_scopes WHERE user_id = NULLIF(current_setting('app.user_id', true), '')::bigint AND access_level = 'WRITE'))
        ");

        // documents
        $this->execute("ALTER TABLE app.documents ENABLE ROW LEVEL SECURITY");
        $this->execute("
            CREATE POLICY documents_scope_select ON app.documents FOR SELECT TO public
            USING (current_setting('app.user_id', true) IS NOT NULL)
        ");
        $this->execute("
            CREATE POLICY documents_scope_all ON app.documents FOR ALL TO public
            USING (uploaded_by = NULLIF(current_setting('app.user_id', true), '')::bigint OR EXISTS (SELECT 1 FROM app.data_scopes WHERE user_id = NULLIF(current_setting('app.user_id', true), '')::bigint AND access_level = 'WRITE'))
        ");

    }

    public function down(): void
    {
        $tables = ['app.parcels', 'app.gis_features', 'app.land_titles'];
        foreach ($tables as $table) {
            $tableName = str_replace('app.', '', $table);
            $this->execute("DROP POLICY IF EXISTS {$tableName}_scope_select ON $table");
            $this->execute("DROP POLICY IF EXISTS {$tableName}_scope_insert ON $table");
            $this->execute("DROP POLICY IF EXISTS {$tableName}_scope_update ON $table");
            $this->execute("DROP POLICY IF EXISTS {$tableName}_scope_delete ON $table");
            $this->execute("ALTER TABLE $table DISABLE ROW LEVEL SECURITY");
        }
        
        $this->execute("DROP POLICY IF EXISTS td_scope_select ON app.technical_descriptions");
        $this->execute("DROP POLICY IF EXISTS td_scope_insert ON app.technical_descriptions");
        $this->execute("DROP POLICY IF EXISTS td_scope_update ON app.technical_descriptions");
        $this->execute("DROP POLICY IF EXISTS td_scope_delete ON app.technical_descriptions");
        $this->execute("ALTER TABLE app.technical_descriptions DISABLE ROW LEVEL SECURITY");

        $this->execute("DROP POLICY IF EXISTS parties_scope_select ON app.parties");
        $this->execute("DROP POLICY IF EXISTS parties_scope_all ON app.parties");
        $this->execute("ALTER TABLE app.parties DISABLE ROW LEVEL SECURITY");

        $this->execute("DROP POLICY IF EXISTS documents_scope_select ON app.documents");
        $this->execute("DROP POLICY IF EXISTS documents_scope_all ON app.documents");
        $this->execute("ALTER TABLE app.documents DISABLE ROW LEVEL SECURITY");

        $this->execute("DROP FUNCTION IF EXISTS app.fn_user_can_edit(bigint, varchar, bigint)");
        $this->execute("DROP FUNCTION IF EXISTS app.fn_user_can_see(bigint, varchar, bigint)");
    }
}
