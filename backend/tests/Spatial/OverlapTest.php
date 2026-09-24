<?php
declare(strict_types=1);

namespace Tests\Spatial;

use App\Parcels\Domain\OverlapDetector;
use Tests\TestCase;

class OverlapTest extends TestCase
{
    private \PDO $pdo;
    private OverlapDetector $detector;
    private array $createdParcelIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->detector = new OverlapDetector($this->pdo);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'OVERLAP_TEST_%'");
    }

    private function insertParcel(string $code, string $wkt, string $status = 'APPROVED'): string
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (
                id, parcel_code, lot_number, status, geometry_source,
                geom, created_by
            ) VALUES (
                gen_random_uuid(), :code, 'Lot-1', :status, 'MANUAL_DRAWING',
                ST_Multi(ST_SetSRID(ST_GeomFromText(:wkt), 4326)), 1
            ) RETURNING id
        ");
        $stmt->execute([
            ':code'   => $code,
            ':status' => $status,
            ':wkt'    => $wkt,
        ]);
        $id = (string) $stmt->fetchColumn();
        $this->createdParcelIds[] = $id;
        return $id;
    }

    public function testNoOverlapOnDisjointParcels(): void
    {
        // Parcel A in Manila (121.000, 14.600 to 121.001, 14.601)
        $idA = $this->insertParcel(
            'OVERLAP_TEST_A',
            'POLYGON((121.000 14.600, 121.001 14.600, 121.001 14.601, 121.000 14.601, 121.000 14.600))'
        );

        // Parcel B far away (121.010, 14.610)
        $geomB = 'POLYGON((121.010 14.610, 121.011 14.610, 121.011 14.611, 121.010 14.611, 121.010 14.610))';

        $result = $this->detector->detectOverlaps(null, $geomB);
        $this->assertFalse($result['has_overlap']);
        $this->assertSame(0.0, $result['total_overlap_area_sqm']);
        $this->assertEmpty($result['overlapping_parcels']);
    }

    public function testAdjacentTouchingParcelsDoNotCountAsOverlap(): void
    {
        // Parcel A: [121.000, 14.600] to [121.001, 14.601]
        $idA = $this->insertParcel(
            'OVERLAP_TEST_TOUCH_A',
            'POLYGON((121.000 14.600, 121.001 14.600, 121.001 14.601, 121.000 14.601, 121.000 14.600))'
        );

        // Parcel B touches East edge of Parcel A at x = 121.001
        $geomB = 'POLYGON((121.001 14.600, 121.002 14.600, 121.002 14.601, 121.001 14.601, 121.001 14.600))';

        $result = $this->detector->detectOverlaps(null, $geomB);
        // Shared line has 0 area, so no overlap
        $this->assertFalse($result['has_overlap']);
        $this->assertEmpty($result['overlapping_parcels']);
    }

    public function testTrueOverlapDetectedWithExactArea(): void
    {
        // Parcel A
        $idA = $this->insertParcel(
            'OVERLAP_TEST_OVR_A',
            'POLYGON((121.000 14.600, 121.002 14.600, 121.002 14.602, 121.000 14.602, 121.000 14.600))'
        );

        // Candidate Parcel overlaps partial interior: [121.001, 14.601] to [121.003, 14.603]
        $candidateGeom = 'POLYGON((121.001 14.601, 121.003 14.601, 121.003 14.603, 121.001 14.603, 121.001 14.601))';

        $result = $this->detector->detectOverlaps(null, $candidateGeom);

        $this->assertTrue($result['has_overlap']);
        $this->assertTrue($result['has_significant_overlap']);
        $this->assertGreaterThan(100.0, $result['total_overlap_area_sqm']);
        $this->assertCount(1, $result['overlapping_parcels']);
        $this->assertSame('OVERLAP_TEST_OVR_A', $result['overlapping_parcels'][0]['parcel_code']);
        $this->assertFalse($result['overlapping_parcels'][0]['is_sliver']);
    }

    public function testArchivedAndSupersededParcelsAreIgnored(): void
    {
        // Archived parcel
        $this->insertParcel(
            'OVERLAP_TEST_ARCHIVED',
            'POLYGON((121.000 14.600, 121.002 14.600, 121.002 14.602, 121.000 14.602, 121.000 14.600))',
            'ARCHIVED'
        );

        // Superseded parcel
        $this->insertParcel(
            'OVERLAP_TEST_SUPERSEDED',
            'POLYGON((121.000 14.600, 121.002 14.600, 121.002 14.602, 121.000 14.602, 121.000 14.600))',
            'SUPERSEDED'
        );

        $candidateGeom = 'POLYGON((121.000 14.600, 121.002 14.600, 121.002 14.602, 121.000 14.602, 121.000 14.600))';

        $result = $this->detector->detectOverlaps(null, $candidateGeom);
        $this->assertFalse($result['has_overlap']);
    }
}
