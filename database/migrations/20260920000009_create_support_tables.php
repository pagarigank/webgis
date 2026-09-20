<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSupportTables extends AbstractMigration
{
    public function up(): void
    {
        // 1. Documents
        $documents = $this->table('app.documents', ['id' => false, 'primary_key' => ['id']]);
        $documents
            ->addColumn('id', 'uuid')
            ->addColumn('storage_key', 'string', ['limit' => 200])
            ->addColumn('original_filename', 'string', ['limit' => 255])
            ->addColumn('doc_type', 'string', ['limit' => 40])
            ->addColumn('mime_type', 'string', ['limit' => 120])
            ->addColumn('byte_size', 'biginteger')
            ->addColumn('sha256', 'binary')
            ->addColumn('access_level', 'string', ['limit' => 24, 'default' => 'INTERNAL'])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('page_count', 'integer', ['null' => true])
            ->addColumn('uploaded_by', 'biginteger')
            ->addColumn('uploaded_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addForeignKey('uploaded_by', 'app.users', 'id')
            ->addIndex(['storage_key'], ['unique' => true])
            ->addIndex(['sha256'], ['unique' => true])
            ->create();
        
        $this->execute("ALTER TABLE app.documents ADD CONSTRAINT ck_access_level CHECK (access_level IN ('PUBLIC','INTERNAL','RESTRICTED','SENSITIVE_PERSONAL'))");

        $docLinks = $this->table('app.document_links', ['id' => false, 'primary_key' => ['id']]);
        $docLinks
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('document_id', 'uuid')
            ->addColumn('entity_type', 'string', ['limit' => 40])
            ->addColumn('entity_id', 'string', ['limit' => 64])
            ->addColumn('link_role', 'string', ['limit' => 40, 'default' => 'SUPPORTING'])
            ->addColumn('linked_by', 'biginteger', ['null' => true])
            ->addColumn('linked_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('document_id', 'app.documents', 'id')
            ->addIndex(['document_id', 'entity_type', 'entity_id', 'link_role'], ['unique' => true])
            ->addIndex(['entity_type', 'entity_id'])
            ->create();

        // 2. Workflows
        $wfDefs = $this->table('app.workflow_definitions', ['id' => false, 'primary_key' => ['id']]);
        $wfDefs
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('code', 'string', ['limit' => 40])
            ->addColumn('entity_type', 'string', ['limit' => 40])
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $wfStates = $this->table('app.workflow_states', ['id' => false, 'primary_key' => ['id']]);
        $wfStates
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('definition_id', 'biginteger')
            ->addColumn('code', 'string', ['limit' => 24])
            ->addColumn('name', 'string', ['limit' => 80])
            ->addColumn('is_initial', 'boolean', ['default' => false])
            ->addColumn('is_terminal', 'boolean', ['default' => false])
            ->addColumn('display_order', 'integer', ['default' => 0])
            ->addForeignKey('definition_id', 'app.workflow_definitions', 'id')
            ->addIndex(['definition_id', 'code'], ['unique' => true])
            ->create();

        $wfTransitions = $this->table('app.workflow_transitions', ['id' => false, 'primary_key' => ['id']]);
        $wfTransitions
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('definition_id', 'biginteger')
            ->addColumn('from_state_id', 'biginteger')
            ->addColumn('to_state_id', 'biginteger')
            ->addColumn('action_code', 'string', ['limit' => 40])
            ->addColumn('required_permission', 'string', ['limit' => 80])
            ->addColumn('requires_reason', 'boolean', ['default' => false])
            ->addColumn('requires_comment', 'boolean', ['default' => false])
            ->addColumn('guard_expression', 'text', ['null' => true])
            ->addForeignKey('definition_id', 'app.workflow_definitions', 'id')
            ->addForeignKey('from_state_id', 'app.workflow_states', 'id')
            ->addForeignKey('to_state_id', 'app.workflow_states', 'id')
            ->addIndex(['definition_id', 'from_state_id', 'action_code'], ['unique' => true])
            ->create();

        $wfInstances = $this->table('app.workflow_instances', ['id' => false, 'primary_key' => ['id']]);
        $wfInstances
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('definition_id', 'biginteger')
            ->addColumn('entity_type', 'string', ['limit' => 40])
            ->addColumn('entity_id', 'string', ['limit' => 64])
            ->addColumn('current_state_id', 'biginteger')
            ->addColumn('assigned_to', 'biginteger', ['null' => true])
            ->addColumn('due_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('definition_id', 'app.workflow_definitions', 'id')
            ->addForeignKey('current_state_id', 'app.workflow_states', 'id')
            ->addForeignKey('assigned_to', 'app.users', 'id')
            ->addIndex(['entity_type', 'entity_id'], ['unique' => true])
            ->create();

        $approvalActions = $this->table('app.approval_actions', ['id' => false, 'primary_key' => ['id']]);
        $approvalActions
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('instance_id', 'biginteger')
            ->addColumn('from_state', 'string', ['limit' => 24, 'null' => true])
            ->addColumn('to_state', 'string', ['limit' => 24])
            ->addColumn('action_code', 'string', ['limit' => 40])
            ->addColumn('actor_id', 'biginteger')
            ->addColumn('accepted_computation_id', 'biginteger', ['null' => true])
            ->addColumn('accepted_td_revision', 'integer', ['null' => true])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('comment', 'text', ['null' => true])
            ->addColumn('acted_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('request_id', 'string', ['limit' => 40, 'null' => true])
            ->addForeignKey('instance_id', 'app.workflow_instances', 'id')
            ->addForeignKey('actor_id', 'app.users', 'id')
            ->addForeignKey('accepted_computation_id', 'app.parcel_computations', 'id')
            ->create();

        // 3. Audit Logs (Partitioned)
        $this->execute("
            CREATE TABLE audit.audit_logs (
              id bigint GENERATED ALWAYS AS IDENTITY,
              occurred_at timestamptz NOT NULL DEFAULT now(),
              user_id bigint, 
              username_snapshot varchar(120),
              action varchar(60) NOT NULL, 
              entity_type varchar(40) NOT NULL, 
              entity_id varchar(64),
              old_values jsonb, 
              new_values jsonb, 
              changed_fields text[],
              reason text, 
              ip inet, 
              user_agent text, 
              request_id varchar(40),
              PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at);
        ");
        $this->execute("CREATE INDEX brin_audit_time ON audit.audit_logs USING BRIN (occurred_at)");
        $this->execute("CREATE INDEX idx_audit_entity ON audit.audit_logs (entity_type, entity_id, occurred_at DESC)");
        $this->execute("CREATE INDEX idx_audit_user ON audit.audit_logs (user_id, occurred_at DESC)");
        
        // Create an initial partition for a wide range to cover immediate usage
        // In production, the worker would create smaller range partitions (e.g., monthly)
        $this->execute("
            CREATE TABLE audit.audit_logs_y2020_to_y2030 
            PARTITION OF audit.audit_logs 
            FOR VALUES FROM ('2020-01-01') TO ('2031-01-01');
        ");

        // 4. Basemap Providers
        $basemaps = $this->table('app.basemap_providers', ['id' => false, 'primary_key' => ['id']]);
        $basemaps
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('code', 'string', ['limit' => 40])
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('provider_type', 'string', ['limit' => 20])
            ->addColumn('service_url', 'text', ['null' => true])
            ->addColumn('url_template', 'text', ['null' => true])
            ->addColumn('layer_name', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('matrix_set', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('format', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('srid', 'integer', ['default' => 3857])
            ->addColumn('attribution_html', 'text')
            ->addColumn('attribution_url', 'text', ['null' => true])
            ->addColumn('license_type', 'string', ['limit' => 28])
            ->addColumn('license_reference', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('license_expires_on', 'date', ['null' => true])
            ->addColumn('license_notes', 'text', ['null' => true])
            ->addColumn('requires_api_key', 'boolean', ['default' => false])
            ->addColumn('api_key_env_name', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('proxy_required', 'boolean', ['default' => false])
            ->addColumn('cache_ttl_seconds', 'integer', ['default' => 0])
            ->addColumn('min_zoom', 'integer', ['default' => 0, 'null' => true])
            ->addColumn('max_zoom', 'integer', ['default' => 19, 'null' => true])
            ->addColumn('is_enabled', 'boolean', ['default' => false])
            ->addColumn('is_default', 'boolean', ['default' => false])
            ->addColumn('display_order', 'integer', ['default' => 100])
            // Since there's no native array column in Phinx for all cases, we define it as jsonb or raw postgres type later?
            // "allowed_role_ids bigint[]"
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $this->execute("ALTER TABLE app.basemap_providers ADD COLUMN bounds geometry(Polygon, 4326)");
        $this->execute("ALTER TABLE app.basemap_providers ADD COLUMN allowed_role_ids bigint[]");
        $this->execute("ALTER TABLE app.basemap_providers ADD CONSTRAINT ck_provider_type CHECK (provider_type IN ('XYZ','TMS','WMS','WMTS','VECTOR_TILE','LOCAL_ORTHOPHOTO'))");
        $this->execute("ALTER TABLE app.basemap_providers ADD CONSTRAINT ck_license_type CHECK (license_type IN ('OPEN_ODBL','COMMERCIAL_WEB','GOVERNMENT_GRANT','ORGANIZATION_OWNED','UNLICENSED'))");
        $this->execute("ALTER TABLE app.basemap_providers ADD CONSTRAINT ck_license_enabled CHECK (NOT (is_enabled AND license_type = 'UNLICENSED'))");
        $this->execute("ALTER TABLE app.basemap_providers ADD CONSTRAINT ck_attribution CHECK (NOT is_enabled OR length(attribution_html) > 0)");

        // 5. I/O (Import/Export Jobs)
        $importJobs = $this->table('app.import_jobs', ['id' => false, 'primary_key' => ['id']]);
        $importJobs
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('source_format', 'string', ['limit' => 20])
            ->addColumn('source_document_id', 'uuid')
            ->addColumn('source_filename', 'string', ['limit' => 255])
            ->addColumn('target_entity', 'string', ['limit' => 40])
            ->addColumn('target_layer_id', 'biginteger', ['null' => true])
            ->addColumn('declared_crs_id', 'biginteger', ['null' => true])
            ->addColumn('transformation_id', 'biginteger', ['null' => true])
            ->addColumn('field_mapping', 'jsonb', ['null' => true])
            ->addColumn('options', 'jsonb', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'UPLOADED'])
            ->addColumn('total_rows', 'integer', ['default' => 0, 'null' => true])
            ->addColumn('valid_rows', 'integer', ['default' => 0, 'null' => true])
            ->addColumn('invalid_rows', 'integer', ['default' => 0, 'null' => true])
            ->addColumn('validation_result', 'jsonb', ['null' => true])
            ->addColumn('error_report', 'jsonb', ['null' => true])
            ->addColumn('created_by', 'biginteger')
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('committed_by', 'biginteger', ['null' => true])
            ->addColumn('committed_at', 'timestamp', ['null' => true])
            ->addForeignKey('source_document_id', 'app.documents', 'id')
            ->addForeignKey('target_layer_id', 'app.gis_layers', 'id')
            ->addForeignKey('declared_crs_id', 'ref.crs_registry', 'id')
            ->addForeignKey('transformation_id', 'app.coordinate_transformations', 'id')
            ->create();

        $this->execute("ALTER TABLE app.import_jobs ADD CONSTRAINT ck_src_format CHECK (source_format IN ('GEOJSON','CSV','KML','SHAPEFILE','GEOPACKAGE','DXF'))");
        $this->execute("ALTER TABLE app.import_jobs ADD CONSTRAINT ck_imp_status CHECK (status IN ('UPLOADED','MAPPED','VALIDATED','COMMITTED','FAILED','CANCELLED'))");

        $importRows = $this->table('staging.import_job_rows', ['id' => false, 'primary_key' => ['id']]);
        $importRows
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('job_id', 'biginteger')
            ->addColumn('row_number', 'integer')
            ->addColumn('raw', 'jsonb', ['null' => true])
            ->addColumn('normalized', 'jsonb', ['null' => true])
            ->addColumn('validation', 'jsonb', ['null' => true])
            ->addColumn('is_valid', 'boolean', ['default' => false])
            ->addColumn('action', 'string', ['limit' => 12, 'default' => 'INSERT'])
            ->addForeignKey('job_id', 'app.import_jobs', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->execute("ALTER TABLE staging.import_job_rows ADD COLUMN geom geometry(Geometry, 4326)");

        $exportJobs = $this->table('app.export_jobs', ['id' => false, 'primary_key' => ['id']]);
        $exportJobs
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('format', 'string', ['limit' => 20])
            ->addColumn('query_spec', 'jsonb')
            ->addColumn('target_crs_id', 'biginteger', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'QUEUED'])
            ->addColumn('document_id', 'uuid', ['null' => true])
            ->addColumn('row_count', 'integer', ['null' => true])
            ->addColumn('requested_by', 'biginteger')
            ->addColumn('requested_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('completed_at', 'timestamp', ['null' => true])
            ->addForeignKey('target_crs_id', 'ref.crs_registry', 'id')
            ->addForeignKey('document_id', 'app.documents', 'id')
            ->create();

        // 6. Misc Settings and Locks
        $notifications = $this->table('app.notifications', ['id' => false, 'primary_key' => ['id']]);
        $notifications
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger')
            ->addColumn('type', 'string', ['limit' => 40])
            ->addColumn('title', 'string', ['limit' => 160])
            ->addColumn('body', 'text', ['null' => true])
            ->addColumn('entity_type', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('entity_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('read_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'app.users', 'id', ['delete' => 'CASCADE'])
            ->create();

        $editLocks = $this->table('app.edit_locks', ['id' => false, 'primary_key' => ['entity_type', 'entity_id']]);
        $editLocks
            ->addColumn('entity_type', 'string', ['limit' => 40])
            ->addColumn('entity_id', 'string', ['limit' => 64])
            ->addColumn('user_id', 'biginteger')
            ->addColumn('acquired_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('heartbeat_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('expires_at', 'timestamp')
            ->create();

        $systemSettings = $this->table('app.system_settings', ['id' => false, 'primary_key' => ['key']]);
        $systemSettings
            ->addColumn('key', 'string', ['limit' => 80])
            ->addColumn('value', 'jsonb')
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();
    }

    public function down(): void
    {
        $this->table('app.system_settings')->drop()->save();
        $this->table('app.edit_locks')->drop()->save();
        $this->table('app.notifications')->drop()->save();
        $this->table('app.export_jobs')->drop()->save();
        $this->table('staging.import_job_rows')->drop()->save();
        $this->table('app.import_jobs')->drop()->save();
        $this->table('app.basemap_providers')->drop()->save();
        
        $this->execute("DROP TABLE IF EXISTS audit.audit_logs");
        
        $this->table('app.approval_actions')->drop()->save();
        $this->table('app.workflow_instances')->drop()->save();
        $this->table('app.workflow_transitions')->drop()->save();
        $this->table('app.workflow_states')->drop()->save();
        $this->table('app.workflow_definitions')->drop()->save();
        $this->table('app.document_links')->drop()->save();
        $this->table('app.documents')->drop()->save();
    }
}
