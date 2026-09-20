<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSurveyTables extends AbstractMigration
{
    public function up(): void
    {
        // 1. survey_plans
        $surveyPlans = $this->table('app.survey_plans', ['id' => false, 'primary_key' => ['id']]);
        $surveyPlans
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('plan_number', 'string', ['limit' => 80])
            ->addColumn('plan_type', 'string', ['limit' => 20])
            ->addColumn('survey_date', 'date', ['null' => true])
            ->addColumn('approved_date', 'date', ['null' => true])
            ->addColumn('approving_agency', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('surveyor_name', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('surveyor_license', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('control_reference', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('crs_id', 'biginteger', ['null' => true])
            ->addColumn('area_sqm', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('lot_count', 'integer', ['null' => true])
            ->addColumn('psgc_barangay', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('source_document_id', 'uuid', ['null' => true])
            ->addColumn('remarks', 'text', ['null' => true])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addForeignKey('crs_id', 'ref.crs_registry', 'id')
            ->addForeignKey('psgc_barangay', 'ref.psgc_areas', 'code')
            ->addIndex(['plan_number'], ['unique' => true])
            ->create();

        // 2. survey_control_points
        $controlPoints = $this->table('app.survey_control_points', ['id' => false, 'primary_key' => ['id']]);
        $controlPoints
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('point_name', 'string', ['limit' => 80])
            ->addColumn('point_type', 'string', ['limit' => 24])
            ->addColumn('monument_type', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('easting', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('northing', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('elevation', 'decimal', ['precision' => 10, 'scale' => 4, 'null' => true])
            ->addColumn('native_crs_id', 'biginteger', ['null' => true])
            ->addColumn('latitude', 'decimal', ['precision' => 12, 'scale' => 9, 'null' => true])
            ->addColumn('longitude', 'decimal', ['precision' => 12, 'scale' => 9, 'null' => true])
            ->addColumn('coordinate_origin', 'string', ['limit' => 16, 'default' => 'PROJECTED'])
            ->addColumn('datum', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('zone', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('survey_reference', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('accuracy_class', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('accuracy_value_m', 'decimal', ['precision' => 8, 'scale' => 4, 'null' => true])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'UNVERIFIED'])
            ->addColumn('psgc_barangay', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('verified_by', 'biginteger', ['null' => true])
            ->addColumn('verified_at', 'timestamp', ['null' => true])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addForeignKey('native_crs_id', 'ref.crs_registry', 'id')
            ->addForeignKey('psgc_barangay', 'ref.psgc_areas', 'code')
            ->addForeignKey('verified_by', 'app.users', 'id')
            ->addIndex(['point_name', 'native_crs_id'], ['unique' => true])
            ->create();

        $this->execute("ALTER TABLE app.survey_control_points ADD COLUMN geom geometry(Point, 4326)");
        $this->execute("CREATE INDEX gix_cp_geom ON app.survey_control_points USING GIST (geom)");
        $this->execute("CREATE INDEX idx_cp_type_status ON app.survey_control_points (point_type, status)");
        $this->execute("CREATE INDEX idx_cp_name_trgm ON app.survey_control_points USING GIN (point_name gin_trgm_ops)");

        $this->execute("ALTER TABLE app.survey_control_points ADD CONSTRAINT ck_cp_point_type CHECK (point_type IN ('BLLM','MBM','PBM','GCP','CONTROL_POINT','TIE_POINT','REFERENCE_POINT','OTHER'))");
        $this->execute("ALTER TABLE app.survey_control_points ADD CONSTRAINT ck_cp_origin CHECK (coordinate_origin IN ('PROJECTED','GEOGRAPHIC'))");
        $this->execute("ALTER TABLE app.survey_control_points ADD CONSTRAINT ck_cp_status CHECK (status IN ('UNVERIFIED','VERIFIED','DISPUTED','RETIRED'))");

        // 3. technical_descriptions
        $techDesc = $this->table('app.technical_descriptions', ['id' => false, 'primary_key' => ['id']]);
        $techDesc
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('parcel_id', 'uuid') // Note: FK to app.parcels added in TASK-019
            ->addColumn('revision', 'integer')
            ->addColumn('survey_plan_id', 'biginteger', ['null' => true])
            ->addColumn('original_text', 'text', ['null' => true])
            ->addColumn('normalized_text', 'text', ['null' => true])
            ->addColumn('source_type', 'string', ['limit' => 24])
            ->addColumn('parser_status', 'string', ['limit' => 20, 'default' => 'NOT_PARSED'])
            ->addColumn('confirmed_by', 'biginteger', ['null' => true])
            ->addColumn('confirmed_at', 'timestamp', ['null' => true])
            ->addColumn('bearing_reference', 'string', ['limit' => 12, 'default' => 'GRID'])
            ->addColumn('distance_unit', 'string', ['limit' => 12, 'default' => 'm'])
            ->addColumn('compute_crs_id', 'biginteger', ['null' => true])
            ->addColumn('point_of_beginning_label', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('survey_reference', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('source_document_id', 'uuid', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'DRAFT'])
            ->addColumn('is_current', 'boolean', ['default' => true])
            ->addColumn('calculation_version', 'integer', ['default' => 0])
            ->addColumn('version', 'integer', ['default' => 1])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'biginteger', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('survey_plan_id', 'app.survey_plans', 'id')
            ->addForeignKey('confirmed_by', 'app.users', 'id')
            ->addForeignKey('compute_crs_id', 'ref.crs_registry', 'id')
            ->addIndex(['parcel_id', 'revision'], ['unique' => true])
            ->create();

        $this->execute("CREATE UNIQUE INDEX uq_td_current ON app.technical_descriptions (parcel_id) WHERE is_current = true");
        $this->execute("ALTER TABLE app.technical_descriptions ADD CONSTRAINT ck_td_source_type CHECK (source_type IN ('MANUALLY_ENTERED','OCR_EXTRACTED','AI_EXTRACTED','IMPORTED','PASTED_TEXT'))");
        $this->execute("ALTER TABLE app.technical_descriptions ADD CONSTRAINT ck_td_parser_status CHECK (parser_status IN ('NOT_PARSED','PARSED','PARTIAL','FAILED','CONFIRMED'))");
        $this->execute("ALTER TABLE app.technical_descriptions ADD CONSTRAINT ck_td_bearing_ref CHECK (bearing_reference IN ('GRID','GEODETIC','MAGNETIC','ASSUMED'))");

        // 4. tie_points
        $tiePoints = $this->table('app.tie_points', ['id' => false, 'primary_key' => ['id']]);
        $tiePoints
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('technical_description_id', 'biginteger')
            ->addColumn('control_point_id', 'biginteger', ['null' => true])
            ->addColumn('adhoc_name', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('adhoc_easting', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('adhoc_northing', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('adhoc_crs_id', 'biginteger', ['null' => true])
            ->addColumn('role', 'string', ['limit' => 20, 'default' => 'TIE'])
            ->addColumn('as_used_easting', 'decimal', ['precision' => 14, 'scale' => 4])
            ->addColumn('as_used_northing', 'decimal', ['precision' => 14, 'scale' => 4])
            ->addColumn('as_used_crs_id', 'biginteger')
            ->addColumn('as_used_status', 'string', ['limit' => 16])
            ->addColumn('sequence', 'integer', ['default' => 1])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('created_by', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('technical_description_id', 'app.technical_descriptions', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('control_point_id', 'app.survey_control_points', 'id')
            ->addForeignKey('adhoc_crs_id', 'ref.crs_registry', 'id')
            ->addForeignKey('as_used_crs_id', 'ref.crs_registry', 'id')
            ->create();

        $this->execute("ALTER TABLE app.tie_points ADD CONSTRAINT ck_tie_role CHECK (role IN ('TIE','REFERENCE','CHECK'))");
        $this->execute("ALTER TABLE app.tie_points ADD CONSTRAINT ck_tie_source CHECK (control_point_id IS NOT NULL OR adhoc_name IS NOT NULL)");

        // 5. technical_description_courses
        $techCourses = $this->table('app.technical_description_courses', ['id' => false, 'primary_key' => ['id']]);
        $techCourses
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('technical_description_id', 'biginteger')
            ->addColumn('seq', 'integer')
            ->addColumn('course_type', 'string', ['limit' => 8, 'default' => 'LINE'])
            ->addColumn('from_point_label', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('to_point_label', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('original_bearing', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('bearing_quadrant', 'string', ['limit' => 2, 'null' => true])
            ->addColumn('deg', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'null' => true])
            ->addColumn('min', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'null' => true])
            ->addColumn('sec', 'decimal', ['precision' => 6, 'scale' => 3, 'null' => true])
            ->addColumn('bearing_type', 'string', ['limit' => 12, 'default' => 'QUADRANT'])
            ->addColumn('normalized_bearing', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('azimuth_dd', 'decimal', ['precision' => 12, 'scale' => 8, 'null' => true])
            ->addColumn('original_distance', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('original_unit', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('distance_m', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('curve_direction', 'string', ['limit' => 2, 'null' => true])
            ->addColumn('radius_m', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('arc_length_m', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('chord_length_m', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('chord_azimuth_dd', 'decimal', ['precision' => 12, 'scale' => 8, 'null' => true])
            ->addColumn('central_angle_dd', 'decimal', ['precision' => 12, 'scale' => 8, 'null' => true])
            ->addColumn('tangent_in_azimuth_dd', 'decimal', ['precision' => 12, 'scale' => 8, 'null' => true])
            ->addColumn('extraction_method', 'string', ['limit' => 20, 'default' => 'MANUALLY_ENTERED'])
            ->addColumn('confidence', 'decimal', ['precision' => 4, 'scale' => 3, 'null' => true])
            ->addColumn('source_text_span', 'jsonb', ['null' => true])
            ->addColumn('is_confirmed', 'boolean', ['default' => false])
            ->addColumn('remarks', 'text', ['null' => true])
            ->addForeignKey('technical_description_id', 'app.technical_descriptions', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['technical_description_id', 'seq'], ['unique' => true])
            ->create();

        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_type CHECK (course_type IN ('LINE','CURVE'))");
        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_quadrant CHECK (bearing_quadrant IN ('NE','SE','SW','NW'))");
        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_deg CHECK (deg BETWEEN 0 AND 360)");
        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_min CHECK (min BETWEEN 0 AND 59)");
        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_sec CHECK (sec >= 0 AND sec < 60)");
        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_bearing_type CHECK (bearing_type IN ('QUADRANT','AZIMUTH','CARDINAL'))");
        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_azimuth CHECK (azimuth_dd >= 0 AND azimuth_dd < 360)");
        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_distance CHECK (distance_m > 0)");
        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_curve_dir CHECK (curve_direction IN ('CW','CCW'))");
        $this->execute("ALTER TABLE app.technical_description_courses ADD CONSTRAINT ck_course_extraction CHECK (extraction_method IN ('MANUALLY_ENTERED','OCR_EXTRACTED','AI_EXTRACTED','IMPORTED'))");

        // 6. tie_lines
        $tieLines = $this->table('app.tie_lines', ['id' => false, 'primary_key' => ['id']]);
        $tieLines
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('technical_description_id', 'biginteger')
            ->addColumn('tie_point_id', 'biginteger')
            ->addColumn('seq', 'integer', ['default' => 1])
            ->addColumn('to_point_label', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('original_bearing', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('bearing_quadrant', 'string', ['limit' => 2, 'null' => true])
            ->addColumn('deg', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'null' => true])
            ->addColumn('min', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'null' => true])
            ->addColumn('sec', 'decimal', ['precision' => 6, 'scale' => 3, 'null' => true])
            ->addColumn('azimuth_dd', 'decimal', ['precision' => 12, 'scale' => 8, 'null' => true])
            ->addColumn('normalized_bearing', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('original_distance', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('original_unit', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('distance_m', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('extraction_method', 'string', ['limit' => 20, 'default' => 'MANUALLY_ENTERED'])
            ->addColumn('remarks', 'text', ['null' => true])
            ->addForeignKey('technical_description_id', 'app.technical_descriptions', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('tie_point_id', 'app.tie_points', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['technical_description_id', 'seq'], ['unique' => true])
            ->create();

        // 7. parcel_courses (VIEW)
        $this->execute("
            CREATE VIEW app.parcel_courses AS 
            SELECT tdc.*, td.parcel_id 
            FROM app.technical_description_courses tdc 
            JOIN app.technical_descriptions td ON tdc.technical_description_id = td.id 
            WHERE td.is_current = true;
        ");

        // 8. parcel_computations
        $computations = $this->table('app.parcel_computations', ['id' => false, 'primary_key' => ['id']]);
        $computations
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('parcel_id', 'uuid') // Note: FK deferred to TASK-019
            ->addColumn('technical_description_id', 'biginteger')
            ->addColumn('compute_crs_id', 'biginteger')
            ->addColumn('method', 'string', ['limit' => 40, 'default' => 'TRAVERSE_PLANE'])
            ->addColumn('adjustment_method', 'string', ['limit' => 24, 'null' => true])
            ->addColumn('adjustment_params', 'jsonb', ['null' => true])
            ->addColumn('base_computation_id', 'biginteger', ['null' => true])
            ->addColumn('start_easting', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('start_northing', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('close_easting', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('close_northing', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('closure_de', 'decimal', ['precision' => 12, 'scale' => 4, 'null' => true])
            ->addColumn('closure_dn', 'decimal', ['precision' => 12, 'scale' => 4, 'null' => true])
            ->addColumn('linear_error_m', 'decimal', ['precision' => 12, 'scale' => 4, 'null' => true])
            ->addColumn('error_azimuth_dd', 'decimal', ['precision' => 12, 'scale' => 8, 'null' => true])
            ->addColumn('perimeter_m', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('relative_precision_denominator', 'decimal', ['precision' => 14, 'scale' => 2, 'null' => true])
            ->addColumn('computed_area_sqm', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('postgis_area_sqm', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('source_area_sqm', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('area_diff_sqm', 'decimal', ['precision' => 18, 'scale' => 4, 'null' => true])
            ->addColumn('area_diff_pct', 'decimal', ['precision' => 10, 'scale' => 6, 'null' => true])
            ->addColumn('closure_status', 'string', ['limit' => 24])
            ->addColumn('validation_result', 'jsonb', ['null' => true])
            ->addColumn('input_snapshot', 'jsonb')
            ->addColumn('tolerances', 'jsonb')
            ->addColumn('engine_version', 'string', ['limit' => 24])
            ->addColumn('is_current', 'boolean', ['default' => false])
            ->addColumn('computed_by', 'biginteger')
            ->addColumn('computed_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('technical_description_id', 'app.technical_descriptions', 'id')
            ->addForeignKey('compute_crs_id', 'ref.crs_registry', 'id')
            ->addForeignKey('base_computation_id', 'app.parcel_computations', 'id')
            ->addForeignKey('computed_by', 'app.users', 'id')
            ->create();
        
        $this->execute("ALTER TABLE app.parcel_computations ADD COLUMN geom geometry(Polygon, 4326)");
        $this->execute("CREATE INDEX idx_comp_parcel_current ON app.parcel_computations (parcel_id) WHERE is_current = true");
        $this->execute("CREATE INDEX idx_comp_td ON app.parcel_computations (technical_description_id)");
        $this->execute("ALTER TABLE app.parcel_computations ADD CONSTRAINT ck_comp_adj CHECK (adjustment_method IN ('NONE','COMPASS','TRANSIT','CRANDALL'))");
        $this->execute("ALTER TABLE app.parcel_computations ADD CONSTRAINT ck_comp_closure CHECK (closure_status IN ('WITHIN_TOLERANCE','EXCEEDS_TOLERANCE','NOT_CLOSED','INDETERMINATE'))");

        // 9. parcel_vertices
        $vertices = $this->table('app.parcel_vertices', ['id' => false, 'primary_key' => ['id']]);
        $vertices
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('computation_id', 'biginteger')
            ->addColumn('seq', 'integer')
            ->addColumn('point_label', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('easting', 'decimal', ['precision' => 14, 'scale' => 4])
            ->addColumn('northing', 'decimal', ['precision' => 14, 'scale' => 4])
            ->addColumn('compute_crs_id', 'biginteger')
            ->addColumn('native_easting', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('native_northing', 'decimal', ['precision' => 14, 'scale' => 4, 'null' => true])
            ->addColumn('native_crs_id', 'biginteger', ['null' => true])
            ->addColumn('latitude', 'decimal', ['precision' => 12, 'scale' => 9, 'null' => true])
            ->addColumn('longitude', 'decimal', ['precision' => 12, 'scale' => 9, 'null' => true])
            ->addColumn('is_tie_vertex', 'boolean', ['default' => false])
            ->addForeignKey('computation_id', 'app.parcel_computations', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('compute_crs_id', 'ref.crs_registry', 'id')
            ->addForeignKey('native_crs_id', 'ref.crs_registry', 'id')
            ->addIndex(['computation_id', 'seq'], ['unique' => true])
            ->create();

        // 10. coordinate_transformations
        $transforms = $this->table('app.coordinate_transformations', ['id' => false, 'primary_key' => ['id']]);
        $transforms
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('entity_type', 'string', ['limit' => 40])
            ->addColumn('entity_id', 'string', ['limit' => 64])
            ->addColumn('source_crs_id', 'biginteger')
            ->addColumn('target_crs_id', 'biginteger')
            ->addColumn('method', 'string', ['limit' => 28])
            ->addColumn('parameters', 'jsonb', ['null' => true])
            ->addColumn('parameter_source', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('accuracy_m', 'decimal', ['precision' => 8, 'scale' => 4, 'null' => true])
            ->addColumn('performed_by', 'biginteger', ['null' => true])
            ->addColumn('performed_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('notes', 'text', ['null' => true])
            ->addForeignKey('source_crs_id', 'ref.crs_registry', 'id')
            ->addForeignKey('target_crs_id', 'ref.crs_registry', 'id')
            ->addForeignKey('performed_by', 'app.users', 'id')
            ->addIndex(['entity_type', 'entity_id'])
            ->create();
        
        $this->execute("ALTER TABLE app.coordinate_transformations ADD CONSTRAINT ck_trans_method CHECK (method IN ('POSTGIS_PROJ','HELMERT_7PARAM','MOLODENSKY_3PARAM','NTV2_GRID','AFFINE_LOCAL','MANUAL'))");
    }

    public function down(): void
    {
        $this->table('app.coordinate_transformations')->drop()->save();
        $this->table('app.parcel_vertices')->drop()->save();
        $this->table('app.parcel_computations')->drop()->save();
        $this->execute("DROP VIEW IF EXISTS app.parcel_courses");
        $this->table('app.tie_lines')->drop()->save();
        $this->table('app.technical_description_courses')->drop()->save();
        $this->table('app.tie_points')->drop()->save();
        $this->table('app.technical_descriptions')->drop()->save();
        $this->table('app.survey_control_points')->drop()->save();
        $this->table('app.survey_plans')->drop()->save();
    }
}
