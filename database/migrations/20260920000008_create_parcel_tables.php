<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateParcelTables extends AbstractMigration
{
    public function up(): void
    {
        // 1. app.parcel_operations
        $operations = $this->table('app.parcel_operations', ['id' => false, 'primary_key' => ['id']]);
        $operations
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('operation_type', 'string', ['limit' => 16])
            ->addColumn('method', 'string', ['limit' => 28])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'COMMITTED'])
            ->addColumn('inputs', 'jsonb')
            ->addColumn('parcel_snapshot', 'jsonb')
            ->addColumn('results', 'jsonb')
            ->addColumn('area_reconciliation', 'jsonb')
            ->addColumn('validation_result', 'jsonb')
            ->addColumn('reason', 'text')
            ->addColumn('source_document_id', 'uuid', ['null' => true])
            ->addColumn('performed_by', 'biginteger')
            ->addColumn('performed_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('request_id', 'string', ['limit' => 40, 'null' => true])
            ->addForeignKey('performed_by', 'app.users', 'id')
            ->create();

        $this->execute("ALTER TABLE app.parcel_operations ADD CONSTRAINT ck_op_type CHECK (operation_type IN ('SPLIT','CONSOLIDATION'))");
        $this->execute("ALTER TABLE app.parcel_operations ADD CONSTRAINT ck_op_method CHECK (method IN ('MAP_SPLIT_LINE','SURVEY_GEOMETRY','TECHNICAL_DESCRIPTION','IMPORTED_GEOMETRY'))");

        // 2. app.parcels
        $parcels = $this->table('app.parcels', ['id' => false, 'primary_key' => ['id']]);
        $parcels
            ->addColumn('id', 'uuid')
            ->addColumn('parcel_code', 'string', ['limit' => 80])
            ->addColumn('lot_number', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('block_number', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('survey_plan_id', 'biginteger', ['null' => true])
            ->addColumn('survey_type', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('title_number_ref', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('tax_declaration_no', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('source_area_sqm', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('source_area_unit', 'string', ['limit' => 12, 'default' => 'sqm', 'null' => true])
            ->addColumn('computed_area_sqm', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('psgc_barangay', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('psgc_municipality', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('psgc_province', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('location_description', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'DRAFT'])
            ->addColumn('geometry_source', 'string', ['limit' => 44, 'default' => 'MANUAL_DRAWING'])
            ->addColumn('verification_status', 'string', ['limit' => 20, 'default' => 'UNVERIFIED'])
            ->addColumn('current_computation_id', 'biginteger', ['null' => true])
            ->addColumn('org_id', 'biginteger', ['null' => true])
            ->addColumn('source_document_id', 'uuid', ['null' => true])
            ->addColumn('remarks', 'text', ['null' => true])
            ->addColumn('superseded_by_operation_id', 'biginteger', ['null' => true])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addForeignKey('survey_plan_id', 'app.survey_plans', 'id')
            ->addForeignKey('psgc_barangay', 'ref.psgc_areas', 'code')
            ->addForeignKey('psgc_municipality', 'ref.psgc_areas', 'code')
            ->addForeignKey('psgc_province', 'ref.psgc_areas', 'code')
            ->addForeignKey('current_computation_id', 'app.parcel_computations', 'id')
            ->addForeignKey('org_id', 'app.organizations', 'id')
            ->addIndex(['parcel_code'], ['unique' => true])
            ->create();

        $this->execute("ALTER TABLE app.parcels ADD COLUMN geom geometry(MultiPolygon, 4326)");
        $this->execute("CREATE INDEX gix_parcels_geom ON app.parcels USING GIST (geom)");
        $this->execute("CREATE INDEX idx_parcels_status ON app.parcels (psgc_barangay, status)");
        $this->execute("CREATE INDEX idx_parcels_lot_trgm ON app.parcels USING GIN (lot_number gin_trgm_ops)");
        $this->execute("CREATE INDEX idx_parcels_td ON app.parcels USING GIN (tax_declaration_no gin_trgm_ops)");
        $this->execute("CREATE INDEX idx_parcels_active ON app.parcels (status) WHERE deleted_at IS NULL AND status NOT IN ('SUPERSEDED','ARCHIVED')");
        
        $this->execute("ALTER TABLE app.parcels ADD CONSTRAINT ck_parcel_status CHECK (status IN ('DRAFT','SUBMITTED','UNDER_REVIEW','RETURNED','VERIFIED','APPROVED','PUBLISHED','ARCHIVED','SUPERSEDED'))");
        $this->execute("ALTER TABLE app.parcels ADD CONSTRAINT ck_parcel_geom_src CHECK (geometry_source IN ('SURVEY_COORDINATES','COMPUTED_FROM_TECHNICAL_DESCRIPTION','TRANSFORMED_FROM_HISTORICAL_SURVEY','IMPORTED_GIS','CAD_IMPORT','DIGITIZED_FROM_IMAGERY','MANUAL_DRAWING','APPROXIMATE'))");

        // Apply deferred foreign keys
        $this->execute("ALTER TABLE app.technical_descriptions ADD CONSTRAINT fk_td_parcel FOREIGN KEY (parcel_id) REFERENCES app.parcels(id) ON DELETE CASCADE");
        $this->execute("ALTER TABLE app.parcel_computations ADD CONSTRAINT fk_comp_parcel FOREIGN KEY (parcel_id) REFERENCES app.parcels(id) ON DELETE CASCADE");

        // 3. app.parcel_relationships
        $relationships = $this->table('app.parcel_relationships', ['id' => false, 'primary_key' => ['id']]);
        $relationships
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('parent_parcel_id', 'uuid')
            ->addColumn('child_parcel_id', 'uuid')
            ->addColumn('relationship_type', 'string', ['limit' => 16])
            ->addColumn('operation_id', 'biginteger', ['null' => true])
            ->addColumn('effective_date', 'date')
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('source_document_id', 'uuid', ['null' => true])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('parent_parcel_id', 'app.parcels', 'id')
            ->addForeignKey('child_parcel_id', 'app.parcels', 'id')
            ->addForeignKey('operation_id', 'app.parcel_operations', 'id')
            ->addIndex(['parent_parcel_id'])
            ->addIndex(['child_parcel_id'])
            ->addIndex(['parent_parcel_id', 'child_parcel_id', 'relationship_type', 'operation_id'], ['unique' => true])
            ->create();

        $this->execute("ALTER TABLE app.parcel_relationships ADD CONSTRAINT ck_no_self CHECK (parent_parcel_id <> child_parcel_id)");
        $this->execute("ALTER TABLE app.parcel_relationships ADD CONSTRAINT ck_rel_type CHECK (relationship_type IN ('SUBDIVISION','CONSOLIDATION','MERGER','REPLACEMENT','CORRECTION','ADJUSTMENT'))");

        // 4. audit.parcel_versions
        $versions = $this->table('audit.parcel_versions', ['id' => false, 'primary_key' => ['id']]);
        $versions
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('parcel_id', 'uuid')
            ->addColumn('version', 'integer')
            ->addColumn('snapshot', 'jsonb')
            ->addColumn('status', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('geometry_source', 'string', ['limit' => 44, 'null' => true])
            ->addColumn('change_summary', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('change_reason', 'text', ['null' => true])
            ->addColumn('changed_by', 'biginteger', ['null' => true])
            ->addColumn('changed_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('request_id', 'string', ['limit' => 40, 'null' => true])
            ->addIndex(['parcel_id', 'version'], ['unique' => true])
            ->create();

        $this->execute("ALTER TABLE audit.parcel_versions ADD COLUMN geom geometry(MultiPolygon, 4326)");

        // 5. app.parties
        $parties = $this->table('app.parties', ['id' => false, 'primary_key' => ['id']]);
        $parties
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('party_type', 'string', ['limit' => 16])
            ->addColumn('full_name_enc', 'binary')
            ->addColumn('name_search_hash', 'binary', ['null' => true])
            ->addColumn('identifiers_enc', 'binary', ['null' => true])
            ->addColumn('address_enc', 'binary', ['null' => true])
            ->addColumn('contact_enc', 'binary', ['null' => true])
            ->addColumn('is_sensitive', 'boolean', ['default' => true])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->create();
        
        $this->execute("ALTER TABLE app.parties ADD CONSTRAINT ck_party_type CHECK (party_type IN ('INDIVIDUAL','ORGANIZATION','GOVERNMENT'))");

        // 6. app.land_titles
        $landTitles = $this->table('app.land_titles', ['id' => false, 'primary_key' => ['id']]);
        $landTitles
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('title_number', 'string', ['limit' => 80])
            ->addColumn('title_type', 'string', ['limit' => 24])
            ->addColumn('title_date', 'date', ['null' => true])
            ->addColumn('registry_office', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('survey_plan_id', 'biginteger', ['null' => true])
            ->addColumn('lot_number', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('area_sqm', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('location_description', 'text', ['null' => true])
            ->addColumn('psgc_barangay', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('source_document_id', 'uuid', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'ACTIVE'])
            ->addColumn('remarks', 'text', ['null' => true])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addForeignKey('survey_plan_id', 'app.survey_plans', 'id')
            ->addForeignKey('psgc_barangay', 'ref.psgc_areas', 'code')
            ->addIndex(['title_number', 'registry_office'], ['unique' => true])
            ->create();

        // 7. app.title_parties
        $titleParties = $this->table('app.title_parties', ['id' => false, 'primary_key' => ['id']]);
        $titleParties
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('title_id', 'biginteger')
            ->addColumn('party_id', 'biginteger')
            ->addColumn('role', 'string', ['limit' => 24, 'default' => 'REGISTERED_OWNER'])
            ->addColumn('share_numerator', 'integer', ['null' => true])
            ->addColumn('share_denominator', 'integer', ['null' => true])
            ->addColumn('effective_from', 'date', ['null' => true])
            ->addColumn('effective_to', 'date', ['null' => true])
            ->addForeignKey('title_id', 'app.land_titles', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('party_id', 'app.parties', 'id')
            ->create();

        // 8. app.parcel_titles
        $parcelTitles = $this->table('app.parcel_titles', ['id' => false, 'primary_key' => ['parcel_id', 'title_id']]);
        $parcelTitles
            ->addColumn('parcel_id', 'uuid')
            ->addColumn('title_id', 'biginteger')
            ->addColumn('relationship', 'string', ['limit' => 24, 'default' => 'COVERS'])
            ->addForeignKey('parcel_id', 'app.parcels', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('title_id', 'app.land_titles', 'id')
            ->create();

        // Lineage functions
        $this->execute("
CREATE OR REPLACE FUNCTION app.fn_parcel_ancestors(p_parcel_id uuid, p_max_depth int DEFAULT 10)
RETURNS TABLE (parcel_id uuid, relationship_type varchar, operation_id bigint, depth int, path uuid[]) AS $$
BEGIN
  RETURN QUERY
  WITH RECURSIVE lineage AS (
    SELECT 
      pr.parent_parcel_id AS parcel_id, 
      pr.relationship_type, 
      pr.operation_id, 
      1 AS depth, 
      ARRAY[p_parcel_id, pr.parent_parcel_id] AS path
    FROM app.parcel_relationships pr
    WHERE pr.child_parcel_id = p_parcel_id

    UNION ALL

    SELECT 
      pr.parent_parcel_id, 
      pr.relationship_type, 
      pr.operation_id, 
      l.depth + 1, 
      l.path || pr.parent_parcel_id
    FROM app.parcel_relationships pr
    JOIN lineage l ON pr.child_parcel_id = l.parcel_id
    WHERE l.depth < p_max_depth 
      AND pr.parent_parcel_id <> ALL(l.path) -- cycle guard
  )
  SELECT l.parcel_id, l.relationship_type, l.operation_id, l.depth, l.path FROM lineage l;
END;
$$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION app.fn_parcel_descendants(p_parcel_id uuid, p_max_depth int DEFAULT 10)
RETURNS TABLE (parcel_id uuid, relationship_type varchar, operation_id bigint, depth int, path uuid[]) AS $$
BEGIN
  RETURN QUERY
  WITH RECURSIVE lineage AS (
    SELECT 
      pr.child_parcel_id AS parcel_id, 
      pr.relationship_type, 
      pr.operation_id, 
      1 AS depth, 
      ARRAY[p_parcel_id, pr.child_parcel_id] AS path
    FROM app.parcel_relationships pr
    WHERE pr.parent_parcel_id = p_parcel_id

    UNION ALL

    SELECT 
      pr.child_parcel_id, 
      pr.relationship_type, 
      pr.operation_id, 
      l.depth + 1, 
      l.path || pr.child_parcel_id
    FROM app.parcel_relationships pr
    JOIN lineage l ON pr.parent_parcel_id = l.parcel_id
    WHERE l.depth < p_max_depth 
      AND pr.child_parcel_id <> ALL(l.path) -- cycle guard
  )
  SELECT l.parcel_id, l.relationship_type, l.operation_id, l.depth, l.path FROM lineage l;
END;
$$ LANGUAGE plpgsql STABLE;
        ");
    }

    public function down(): void
    {
        $this->execute("DROP FUNCTION IF EXISTS app.fn_parcel_descendants(uuid, int)");
        $this->execute("DROP FUNCTION IF EXISTS app.fn_parcel_ancestors(uuid, int)");
        $this->table('app.parcel_titles')->drop()->save();
        $this->table('app.title_parties')->drop()->save();
        $this->table('app.land_titles')->drop()->save();
        $this->table('app.parties')->drop()->save();
        $this->table('audit.parcel_versions')->drop()->save();
        $this->table('app.parcel_relationships')->drop()->save();

        // Remove deferred foreign keys before dropping parcels
        $this->execute("ALTER TABLE app.parcel_computations DROP CONSTRAINT IF EXISTS fk_comp_parcel");
        $this->execute("ALTER TABLE app.technical_descriptions DROP CONSTRAINT IF EXISTS fk_td_parcel");

        $this->table('app.parcels')->drop()->save();
        $this->table('app.parcel_operations')->drop()->save();
    }
}
