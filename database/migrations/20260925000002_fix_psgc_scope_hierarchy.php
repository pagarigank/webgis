<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fixes geographic data-scope matching for the SQL RLS helpers.
 *
 * Migration 14 matched a parcel's PSGC code with
 *
 *     p_psgc LIKE ds.scope_ref_code || '%'
 *
 * which only ever works for scope_type = 'BARANGAY' (where the scope code is
 * the full 9-digit barangay code). PSGC codes do NOT nest by string prefix:
 * a municipality code is not an extension of its province code, nor a province
 * of its region. With real codes:
 *
 *   province     041000000
 *   municipality 041005000   -- not prefixed by the province code
 *   barangay     041005001   -- not prefixed by the municipality code
 *
 * so '041005001' LIKE '041000000%' and LIKE '041005000%' are both FALSE.
 * MUNICIPALITY, PROVINCE and REGION scopes therefore never matched anything:
 * every parcel outside a barangay scope was treated as out of scope, and the
 * split/consolidation data-scope assertion (FR-18.3) rejected the commit with
 * PERMISSION_DENIED for the very parcel the caller had just created.
 *
 * The authoritative answer is the PSGC hierarchy in ref.psgc_areas, so this
 * migration resolves the parcel's code to the level named by scope_type and
 * compares for equality. This matches the application-side semantics in
 * RBAC\DataScopeResolver for conforming codes, and additionally handles
 * non-conforming seed rows (e.g. SAMPLE_PROVINCE 990000000, whose children
 * are numbered under 9901xxxxx) that prefix matching can never express.
 *
 * Scope codes that are not themselves a ref.psgc_areas row keep the original
 * prefix behaviour, so partial/custom scope codes keep working.
 *
 * Grant note: the helpers are SECURITY INVOKER, so any role subject to RLS
 * needs SELECT on ref.psgc_areas in addition to its app schema grants.
 * app_rw / app_ro already hold that grant (20260920000010); custom roles must
 * be granted it too or scope evaluation fails with "permission denied for
 * schema ref".
 */
final class FixPsgcScopeHierarchy extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.fn_psgc_scope_matches(
                p_psgc           varchar,
                p_scope_type     varchar,
                p_scope_ref_code varchar
            )
            RETURNS boolean AS $$
                SELECT CASE p_scope_type
                           WHEN 'BARANGAY'     THEN area.code
                           WHEN 'MUNICIPALITY' THEN area.parent_code
                           WHEN 'PROVINCE'     THEN muni.parent_code
                           WHEN 'REGION'       THEN prov.parent_code
                       END = p_scope_ref_code
                FROM ref.psgc_areas area
                LEFT JOIN ref.psgc_areas muni ON muni.code = area.parent_code
                LEFT JOIN ref.psgc_areas prov ON prov.code = muni.parent_code
                WHERE area.code = p_psgc;
            $$ LANGUAGE sql STABLE;
            SQL);

        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.fn_user_can_see(p_user_id bigint, p_psgc varchar, p_org_id bigint)
            RETURNS boolean AS $$
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
                        (
                            ds.scope_type IN ('BARANGAY','MUNICIPALITY','PROVINCE','REGION')
                            AND p_psgc IS NOT NULL
                            AND (
                                app.fn_psgc_scope_matches(p_psgc, ds.scope_type, ds.scope_ref_code)
                                OR (
                                    NOT EXISTS (SELECT 1 FROM ref.psgc_areas s WHERE s.code = ds.scope_ref_code)
                                    AND p_psgc LIKE ds.scope_ref_code || '%'
                                )
                            )
                        )
                        OR (ds.scope_type = 'ORGANIZATION' AND p_org_id IS NOT NULL AND ds.scope_ref_code = p_org_id::varchar)
                    )
                );
            END;
            $$ LANGUAGE plpgsql STABLE;
            SQL);

        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.fn_user_can_edit(p_user_id bigint, p_psgc varchar, p_org_id bigint)
            RETURNS boolean AS $$
            BEGIN
                IF p_user_id IS NULL THEN
                    RETURN false;
                END IF;

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
                        (
                            ds.scope_type IN ('BARANGAY','MUNICIPALITY','PROVINCE','REGION')
                            AND p_psgc IS NOT NULL
                            AND (
                                app.fn_psgc_scope_matches(p_psgc, ds.scope_type, ds.scope_ref_code)
                                OR (
                                    NOT EXISTS (SELECT 1 FROM ref.psgc_areas s WHERE s.code = ds.scope_ref_code)
                                    AND p_psgc LIKE ds.scope_ref_code || '%'
                                )
                            )
                        )
                        OR (ds.scope_type = 'ORGANIZATION' AND p_org_id IS NOT NULL AND ds.scope_ref_code = p_org_id::varchar)
                    )
                );
            END;
            $$ LANGUAGE plpgsql STABLE;
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP FUNCTION IF EXISTS app.fn_psgc_scope_matches(varchar, varchar, varchar)');

        // The two functions are restored separately, not from one shared body.
        // They differ in two ways that matter for authorization: "see" has no
        // access-level filter (a VIEW scope may read), while "edit" requires an
        // EDIT/APPROVE level; and only "edit" filters its global fast path by
        // access level. Rolling both back from a single template made a GLOBAL
        // VIEW scope satisfy the edit check (privilege escalation) while
        // locking VIEW-only geographic scopes out of seeing anything.
        $this->execute(<<<SQL
            CREATE OR REPLACE FUNCTION app.fn_user_can_see(p_user_id bigint, p_psgc varchar, p_org_id bigint)
            RETURNS boolean AS \$fn\$
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
                        (ds.scope_type IN ('BARANGAY','MUNICIPALITY','PROVINCE','REGION') AND p_psgc IS NOT NULL AND p_psgc LIKE ds.scope_ref_code || '%')
                        OR (ds.scope_type = 'ORGANIZATION' AND p_org_id IS NOT NULL AND ds.scope_ref_code = p_org_id::varchar)
                    )
                );
            END;
            \$fn\$ LANGUAGE plpgsql STABLE;
            SQL);

        $this->execute(<<<SQL
            CREATE OR REPLACE FUNCTION app.fn_user_can_edit(p_user_id bigint, p_psgc varchar, p_org_id bigint)
            RETURNS boolean AS \$fn\$
            BEGIN
                IF p_user_id IS NULL THEN
                    RETURN false;
                END IF;

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
            \$fn\$ LANGUAGE plpgsql STABLE;
            SQL);
    }
}
