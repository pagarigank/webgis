<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use PDO;
use PDOException;

class ParcelSchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->getAppInstance()->getContainer()->get(PDO::class);
        $this->pdo->exec("SET app.user_id = '1'"); // Bypass RLS for test
    }

    public function testParcelSelfRelationshipFails(): void
    {
        // Insert a parcel
        $parcelId = $this->pdo->query("SELECT gen_random_uuid()")->fetchColumn();
        $this->pdo->exec("
            INSERT INTO app.parcels (id, parcel_code, status, geometry_source) 
            VALUES ('{$parcelId}', 'TEST_PARCEL_" . uniqid() . "', 'DRAFT', 'MANUAL_DRAWING')
        ");

        $this->expectException(PDOException::class);
        // Migration 20260925000001 adds the VR-45 cycle-guard trigger, which
        // fires before the ck_no_self CHECK backstop.
        $this->expectExceptionMessageMatches('/VR-45/');

        // Try to insert a self-referencing relationship
        $this->pdo->exec("
            INSERT INTO app.parcel_relationships (parent_parcel_id, child_parcel_id, relationship_type, effective_date) 
            VALUES ('{$parcelId}', '{$parcelId}', 'SUBDIVISION', CURRENT_DATE)
        ");
    }

    public function testDeferredForeignKeyPreventsInvalidReferences(): void
    {
        // Technical description should fail if parcel does not exist
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/fk_td_parcel/');
        
        $invalidParcelId = $this->pdo->query("SELECT gen_random_uuid()")->fetchColumn();

        $this->pdo->exec("
            INSERT INTO app.technical_descriptions (parcel_id, revision, source_type)
            VALUES ('{$invalidParcelId}', 1, 'MANUALLY_ENTERED')
        ");
    }

    public function testParcelUniqueConstraint(): void
    {
        $parcelCode = 'TEST_UNIQUE_' . uniqid();
        $parcelId = $this->pdo->query("SELECT gen_random_uuid()")->fetchColumn();
        
        $this->pdo->exec("
            INSERT INTO app.parcels (id, parcel_code) 
            VALUES ('{$parcelId}', '{$parcelCode}')
        ");

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/duplicate key value violates unique constraint/');

        $parcelId2 = $this->pdo->query("SELECT gen_random_uuid()")->fetchColumn();
        $this->pdo->exec("
            INSERT INTO app.parcels (id, parcel_code) 
            VALUES ('{$parcelId2}', '{$parcelCode}')
        ");
    }
}
