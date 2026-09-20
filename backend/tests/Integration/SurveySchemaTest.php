<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;
use PDOException;

class SurveySchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->getAppInstance()->getContainer()->get(PDO::class);
        $this->pdo->exec("SET app.user_id = '1'"); // Bypass RLS for test
    }

    public function testControlPointUniqueConstraint(): void
    {
        // Get native CRS id
        $crsId = $this->pdo->query("SELECT id FROM ref.crs_registry WHERE srid = 4326 LIMIT 1")->fetchColumn();
        $pointName = uniqid('TEST_CP_');

        // Insert first
        $this->pdo->exec("
            INSERT INTO app.survey_control_points (point_name, point_type, native_crs_id, coordinate_origin, status) 
            VALUES ('{$pointName}', 'BLLM', {$crsId}, 'GEOGRAPHIC', 'UNVERIFIED')
        ");

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/duplicate key value violates unique constraint/');

        // Insert duplicate name + crs
        $this->pdo->exec("
            INSERT INTO app.survey_control_points (point_name, point_type, native_crs_id, coordinate_origin, status) 
            VALUES ('{$pointName}', 'MBM', {$crsId}, 'PROJECTED', 'VERIFIED')
        ");
    }

    public function testTieSourceConstraint(): void
    {
        // Need a survey plan and a TD to create a tie point
        $this->pdo->exec("
            INSERT INTO app.survey_plans (plan_number, plan_type) 
            VALUES ('TEST_PLAN_TIE', 'Psd')
            ON CONFLICT DO NOTHING
        ");
        
        // Use generic random uuid for parcel id to create TD
        $parcelId = $this->pdo->query("SELECT gen_random_uuid()")->fetchColumn();
        
        $this->pdo->exec("
            INSERT INTO app.parcels (id, parcel_code) 
            VALUES ('{$parcelId}', 'TEST_PARCEL_TIE_' || md5('{$parcelId}'))
        ");
        
        $this->pdo->exec("
            INSERT INTO app.technical_descriptions (parcel_id, revision, source_type, parser_status)
            VALUES ('{$parcelId}', 1, 'MANUALLY_ENTERED', 'NOT_PARSED')
        ");
        $tdId = $this->pdo->lastInsertId();

        // Try to insert a tie point with NO control_point_id AND NO adhoc_name
        $crsId = $this->pdo->query("SELECT id FROM ref.crs_registry WHERE srid = 4326 LIMIT 1")->fetchColumn();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/ck_tie_source/');

        $this->pdo->exec("
            INSERT INTO app.tie_points (
                technical_description_id, control_point_id, adhoc_name, role, as_used_easting, as_used_northing, as_used_crs_id, as_used_status
            ) VALUES (
                {$tdId}, NULL, NULL, 'TIE', 120.0, 15.0, {$crsId}, 'UNVERIFIED'
            )
        ");
    }
}
