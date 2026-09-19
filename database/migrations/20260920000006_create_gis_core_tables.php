<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateGisCoreTables extends AbstractMigration
{
    public function change(): void
    {
        // 1. gis_layers
        $gisLayers = $this->table('app.gis_layers', ['id' => false, 'primary_key' => ['id']]);
        $gisLayers
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('code', 'string', ['limit' => 60])
            ->addColumn('name', 'string', ['limit' => 160])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('group_path', 'string', ['limit' => 200, 'default' => 'Custom'])
            ->addColumn('geometry_type', 'string', ['limit' => 20])
            ->addColumn('srid', 'integer', ['default' => 4326])
            ->addColumn('source', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('render_mode', 'string', ['limit' => 12, 'default' => 'geojson'])
            ->addColumn('is_snap_target', 'boolean', ['default' => false])
            ->addColumn('is_system', 'boolean', ['default' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'ACTIVE'])
            ->addColumn('visible_default', 'boolean', ['default' => true])
            ->addColumn('opacity_default', 'decimal', ['precision' => 4, 'scale' => 3, 'default' => 1.0])
            ->addColumn('display_order', 'integer', ['default' => 100])
            ->addColumn('label_field', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('min_zoom', 'integer', ['null' => true])
            ->addColumn('max_zoom', 'integer', ['null' => true])
            ->addColumn('feature_count_cache', 'biginteger', ['default' => 0])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        if ($this->isMigratingUp()) {
            $this->execute("ALTER TABLE app.gis_layers ADD CONSTRAINT ck_geom_type CHECK (geometry_type IN ('POINT','MULTIPOINT','LINESTRING','MULTILINESTRING','POLYGON','MULTIPOLYGON','GEOMETRY'))");
            $this->execute("ALTER TABLE app.gis_layers ADD CONSTRAINT ck_render_mode CHECK (render_mode IN ('geojson','mvt','raster'))");
            $this->execute("ALTER TABLE app.gis_layers ADD CONSTRAINT ck_status CHECK (status IN ('ACTIVE','ARCHIVED'))");
            $this->execute("ALTER TABLE app.gis_layers ADD CONSTRAINT ck_opacity CHECK (opacity_default BETWEEN 0 AND 1)");
        } else {
            $this->execute("ALTER TABLE app.gis_layers DROP CONSTRAINT IF EXISTS ck_geom_type");
            $this->execute("ALTER TABLE app.gis_layers DROP CONSTRAINT IF EXISTS ck_render_mode");
            $this->execute("ALTER TABLE app.gis_layers DROP CONSTRAINT IF EXISTS ck_status");
            $this->execute("ALTER TABLE app.gis_layers DROP CONSTRAINT IF EXISTS ck_opacity");
        }

        // 2. gis_layer_fields
        $gisLayerFields = $this->table('app.gis_layer_fields', ['id' => false, 'primary_key' => ['id']]);
        $gisLayerFields
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('layer_id', 'biginteger')
            ->addColumn('field_name', 'string', ['limit' => 60])
            ->addColumn('field_label', 'string', ['limit' => 120])
            ->addColumn('field_type', 'string', ['limit' => 20])
            ->addColumn('required', 'boolean', ['default' => false])
            ->addColumn('default_value', 'jsonb', ['null' => true])
            ->addColumn('options', 'jsonb', ['null' => true])
            ->addColumn('validation_rules', 'jsonb', ['null' => true])
            ->addColumn('searchable', 'boolean', ['default' => false])
            ->addColumn('sortable', 'boolean', ['default' => false])
            ->addColumn('displayable', 'boolean', ['default' => true])
            ->addColumn('editable', 'boolean', ['default' => true])
            ->addColumn('is_pii', 'boolean', ['default' => false])
            ->addColumn('sort_order', 'integer', ['default' => 100])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addForeignKey('layer_id', 'app.gis_layers', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['layer_id', 'field_name'], ['unique' => true])
            ->create();

        if ($this->isMigratingUp()) {
            $this->execute("ALTER TABLE app.gis_layer_fields ADD CONSTRAINT ck_field_name CHECK (field_name ~ '^[a-z][a-z0-9_]{0,59}$')");
            $this->execute("ALTER TABLE app.gis_layer_fields ADD CONSTRAINT ck_field_type CHECK (field_type IN ('text','long_text','integer','decimal','boolean','date','datetime','dropdown','multi_select','email','phone','url','currency','reference','user','document'))");
        } else {
            $this->execute("ALTER TABLE app.gis_layer_fields DROP CONSTRAINT IF EXISTS ck_field_name");
            $this->execute("ALTER TABLE app.gis_layer_fields DROP CONSTRAINT IF EXISTS ck_field_type");
        }

        // 3. gis_layer_styles
        $gisLayerStyles = $this->table('app.gis_layer_styles', ['id' => false, 'primary_key' => ['id']]);
        $gisLayerStyles
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('layer_id', 'biginteger')
            ->addColumn('style_type', 'string', ['limit' => 16])
            ->addColumn('attribute_field', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('rules', 'jsonb', ['default' => '[]'])
            ->addColumn('default_rule', 'jsonb')
            ->addColumn('label_config', 'jsonb', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('layer_id', 'app.gis_layers', 'id', ['delete' => 'CASCADE'])
            ->create();

        if ($this->isMigratingUp()) {
            $this->execute("ALTER TABLE app.gis_layer_styles ADD CONSTRAINT ck_style_type CHECK (style_type IN ('SINGLE','CATEGORIZED','GRADUATED'))");
        } else {
            $this->execute("ALTER TABLE app.gis_layer_styles DROP CONSTRAINT IF EXISTS ck_style_type");
        }

        // 4. gis_features
        $gisFeatures = $this->table('app.gis_features', ['id' => false, 'primary_key' => ['id']]);
        $gisFeatures
            ->addColumn('id', 'uuid')
            ->addColumn('layer_id', 'biginteger')
            ->addColumn('attributes', 'jsonb', ['default' => '{}'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'ACTIVE'])
            ->addColumn('org_id', 'biginteger', ['null' => true])
            ->addColumn('psgc_barangay', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('provenance', 'string', ['limit' => 40, 'default' => 'MANUAL_DRAWING'])
            ->addColumn('source_document_id', 'uuid', ['null' => true])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addForeignKey('layer_id', 'app.gis_layers', 'id')
            ->addForeignKey('org_id', 'app.organizations', 'id')
            ->addForeignKey('psgc_barangay', 'ref.psgc_areas', 'code')
            ->addIndex(['psgc_barangay'])
            ->create();

        // 5. audit.gis_feature_versions
        $gisFeatureVersions = $this->table('audit.gis_feature_versions', ['id' => false, 'primary_key' => ['id']]);
        $gisFeatureVersions
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('feature_id', 'uuid')
            ->addColumn('layer_id', 'biginteger')
            ->addColumn('version', 'integer')
            ->addColumn('operation', 'string', ['limit' => 10])
            ->addColumn('attributes', 'jsonb', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('changed_by', 'biginteger', ['null' => true])
            ->addColumn('changed_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('change_reason', 'text', ['null' => true])
            ->addColumn('request_id', 'string', ['limit' => 40, 'null' => true])
            ->addIndex(['feature_id', 'version'], ['unique' => true])
            ->create();

        if ($this->isMigratingUp()) {
            $this->execute("ALTER TABLE app.gis_features ADD COLUMN geom geometry(Geometry,4326) NOT NULL");
            $this->execute("CREATE INDEX gix_features_geom ON app.gis_features USING GIST (geom)");
            $this->execute("CREATE INDEX idx_features_layer ON app.gis_features (layer_id) WHERE deleted_at IS NULL");
            $this->execute("CREATE INDEX gin_features_attrs ON app.gis_features USING GIN (attributes jsonb_path_ops)");

            $this->execute("ALTER TABLE audit.gis_feature_versions ADD COLUMN geom geometry(Geometry,4326)");
        } else {
            $this->execute("DROP INDEX IF EXISTS app.gix_features_geom");
            $this->execute("DROP INDEX IF EXISTS app.idx_features_layer");
            $this->execute("DROP INDEX IF EXISTS app.gin_features_attrs");
            $this->execute("ALTER TABLE app.gis_features DROP COLUMN IF EXISTS geom");

            $this->execute("ALTER TABLE audit.gis_feature_versions DROP COLUMN IF EXISTS geom");
        }

        // 6. Triggers
        if ($this->isMigratingUp()) {
            // Trigger 1: Enforce geometry type
            $this->execute("
                CREATE OR REPLACE FUNCTION app.fn_enforce_geometry_type()
                RETURNS trigger AS $$
                DECLARE
                    expected_geom_type varchar;
                    actual_geom_type varchar;
                BEGIN
                    SELECT geometry_type INTO expected_geom_type FROM app.gis_layers WHERE id = NEW.layer_id;
                    actual_geom_type := ST_GeometryType(NEW.geom);
                    -- actual_geom_type returns 'ST_Polygon', etc.
                    
                    IF expected_geom_type = 'GEOMETRY' THEN
                        RETURN NEW;
                    END IF;
                    
                    IF expected_geom_type = 'POINT' AND actual_geom_type != 'ST_Point' THEN
                        RAISE EXCEPTION 'Geometry type mismatch. Expected POINT, got %', actual_geom_type;
                    END IF;
                    IF expected_geom_type = 'POLYGON' AND actual_geom_type != 'ST_Polygon' THEN
                        RAISE EXCEPTION 'Geometry type mismatch. Expected POLYGON, got %', actual_geom_type;
                    END IF;
                    IF expected_geom_type = 'LINESTRING' AND actual_geom_type != 'ST_LineString' THEN
                        RAISE EXCEPTION 'Geometry type mismatch. Expected LINESTRING, got %', actual_geom_type;
                    END IF;
                    IF expected_geom_type = 'MULTIPOLYGON' AND actual_geom_type != 'ST_MultiPolygon' THEN
                        RAISE EXCEPTION 'Geometry type mismatch. Expected MULTIPOLYGON, got %', actual_geom_type;
                    END IF;
                    IF expected_geom_type = 'MULTIPOINT' AND actual_geom_type != 'ST_MultiPoint' THEN
                        RAISE EXCEPTION 'Geometry type mismatch. Expected MULTIPOINT, got %', actual_geom_type;
                    END IF;
                    IF expected_geom_type = 'MULTILINESTRING' AND actual_geom_type != 'ST_MultiLineString' THEN
                        RAISE EXCEPTION 'Geometry type mismatch. Expected MULTILINESTRING, got %', actual_geom_type;
                    END IF;
                    
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
            
            $this->execute("
                CREATE TRIGGER trg_enforce_geometry_type
                BEFORE INSERT OR UPDATE ON app.gis_features
                FOR EACH ROW EXECUTE FUNCTION app.fn_enforce_geometry_type();
            ");

            // Trigger 2: Validate Attributes
            $this->execute("
                CREATE OR REPLACE FUNCTION app.fn_validate_attributes()
                RETURNS trigger AS $$
                DECLARE
                    field_record RECORD;
                BEGIN
                    FOR field_record IN SELECT field_name, required, field_type FROM app.gis_layer_fields WHERE layer_id = NEW.layer_id LOOP
                        IF field_record.required = true THEN
                            IF NOT (NEW.attributes ? field_record.field_name) OR (NEW.attributes->>field_record.field_name) IS NULL THEN
                                RAISE EXCEPTION 'Validation failed: Required attribute % is missing.', field_record.field_name;
                            END IF;
                        END IF;
                    END LOOP;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");

            $this->execute("
                CREATE TRIGGER trg_validate_attributes
                BEFORE INSERT OR UPDATE ON app.gis_features
                FOR EACH ROW EXECUTE FUNCTION app.fn_validate_attributes();
            ");

            // Trigger 3: Write feature version
            $this->execute("
                CREATE OR REPLACE FUNCTION audit.fn_write_feature_version()
                RETURNS trigger AS $$
                DECLARE
                    op varchar(10);
                BEGIN
                    IF TG_OP = 'INSERT' THEN
                        op := 'INSERT';
                    ELSIF TG_OP = 'UPDATE' THEN
                        IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                            op := 'DELETE';
                        ELSE
                            op := 'UPDATE';
                        END IF;
                    ELSIF TG_OP = 'DELETE' THEN
                        op := 'DELETE';
                        -- Handle hard deletes as well if they occur
                        INSERT INTO audit.gis_feature_versions(feature_id, layer_id, version, operation, geom, attributes, status, changed_by, changed_at)
                        VALUES (OLD.id, OLD.layer_id, OLD.version, op, OLD.geom, OLD.attributes, OLD.status, OLD.updated_by, now());
                        RETURN OLD;
                    END IF;
                    
                    INSERT INTO audit.gis_feature_versions(feature_id, layer_id, version, operation, geom, attributes, status, changed_by, changed_at)
                    VALUES (NEW.id, NEW.layer_id, NEW.version, op, NEW.geom, NEW.attributes, NEW.status, NEW.updated_by, NEW.updated_at);
                    
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
            
            $this->execute("
                CREATE TRIGGER trg_write_feature_version
                AFTER INSERT OR UPDATE OR DELETE ON app.gis_features
                FOR EACH ROW EXECUTE FUNCTION audit.fn_write_feature_version();
            ");

        } else {
            $this->execute("DROP TRIGGER IF EXISTS trg_write_feature_version ON app.gis_features");
            $this->execute("DROP FUNCTION IF EXISTS audit.fn_write_feature_version()");
            
            $this->execute("DROP TRIGGER IF EXISTS trg_validate_attributes ON app.gis_features");
            $this->execute("DROP FUNCTION IF EXISTS app.fn_validate_attributes()");
            
            $this->execute("DROP TRIGGER IF EXISTS trg_enforce_geometry_type ON app.gis_features");
            $this->execute("DROP FUNCTION IF EXISTS app.fn_enforce_geometry_type()");
        }
    }
}
