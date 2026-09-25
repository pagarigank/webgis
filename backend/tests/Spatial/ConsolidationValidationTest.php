<?php
declare(strict_types=1);

namespace Tests\Spatial;

use App\Parcels\Domain\ConsolidationValidator;
use Tests\TestCase;

/**
 * TASK-113 — Consolidation validation VR-40…VR-44 (specification.md §5.4,
 * architecture.md §18.4).
 *
 * One targeted fixture per rule; read-only (nothing written, nothing
 * auto-corrected).
 */
class ConsolidationValidationTest extends TestCase
{
    /** Two touching 107.7 m × 99.8 m squares sharing the x = 121.0010 edge. */
    private const A = 'POLYGON((121.0000 14.6000, 121.0010 14.6000, 121.0010 14.6009, 121.0000 14.6009, 121.0000 14.6000))';
    private const B = 'POLYGON((121.0010 14.6000, 121.0020 14.6000, 121.0020 14.6009, 121.0010 14.6009, 121.0010 14.6000))';
    /** Same as B but shifted +0.0005° east — a ~54 m gap from A. */
    private const B_GAP = 'POLYGON((121.0015 14.6000, 121.0025 14.6000, 121.0025 14.6009, 121.0015 14.6009, 121.0015 14.6000))';
    /** Same as B but shifted -0.0005° west — a ~54 m band overlapping A. */
    private const B_OVERLAP = 'POLYGON((121.0005 14.6000, 121.0015 14.6000, 121.0015 14.6009, 121.0005 14.6009, 121.0005 14.6000))';

    private ConsolidationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ConsolidationValidator($this->pdo());
    }

    public function testTwoTouchingSquaresPassAllChecks(): void
    {
        $r = $this->validator->validateConsolidation([self::A, self::B]);

        $this->assertTrue($r['passed'], json_encode($r['checks'], JSON_THROW_ON_ERROR));
        $statuses = array_column($r['checks'], 'status', 'rule');
        foreach (['VR-40', 'VR-41', 'VR-42', 'VR-43', 'VR-44'] as $rule) {
            $this->assertSame('pass', $statuses[$rule] ?? null, "expected $rule to pass");
        }
        $this->assertSame([], $r['warnings']);
        $this->assertSame(1, $r['union_part_count']);
        $this->assertSame('ST_Polygon', $r['union_geometry_type']);
        $this->assertCount(2, $r['parents']);
        $this->assertGreaterThan(10000.0, (float) $r['union_area_sqm']);
    }

    /** VR-40 — fewer than two parents cannot consolidate. */
    public function testVr40RejectsSingleParent(): void
    {
        $r = $this->validator->validateConsolidation([self::A]);
        $this->assertFalse($r['passed']);
        $c = $this->check($r, 'VR-40');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('at least 2', $c['message']);
    }

    /** VR-40 geometry facet — an invalid parent fails the rule. */
    public function testVr40RejectsInvalidParentGeometry(): void
    {
        $bowTie = 'POLYGON((121.0005 14.6000, 121.0010 14.6009, 121.0010 14.6000, 121.0005 14.6009, 121.0005 14.6000))';
        $r = $this->validator->validateConsolidation([self::A, $bowTie]);
        $c = $this->check($r, 'VR-40');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('ST_IsValid', $c['message']);
    }

    /**
     * VR-41 — overlapping parents are a blocking error. Note the union is
     * still contiguous and the sum vs union area may pass, so the fixture
     * isolates VR-41 from VR-42/VR-43.
     */
    public function testVr41OverlappingParentsBlock(): void
    {
        $r = $this->validator->validateConsolidation([self::A, self::B_OVERLAP]);

        $this->assertSame('fail', $this->check($r, 'VR-41')['status']);
        $this->assertNotEmpty($r['pairwise_overlaps']);
        $this->assertGreaterThan(ConsolidationValidator::OVERLAP_EPSILON_SQM, $r['pairwise_overlaps'][0]['overlap_area_sqm']);
        $this->assertSame(0, $r['pairwise_overlaps'][0]['i']);
        $this->assertSame(1, $r['pairwise_overlaps'][0]['j']);
        // Isolation proof: union is still one contiguous polygon.
        $this->assertSame('pass', $this->check($r, 'VR-43')['status']);
    }

    /**
     * VR-42 — a gap above ε blocks. The union of disjoint parents always
     * equals Σ parent areas, so the gap is measured as the parents' convex
     * hull minus the parent sum (~5,390 m² for the ~54 m gap below).
     */
    public function testVr42GapBetweenParentsBlocks(): void
    {
        $r = $this->validator->validateConsolidation([self::A, self::B_GAP]);

        $c = $this->check($r, 'VR-42');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('Gap', $c['message']);
        $this->assertSame('pass', $this->check($r, 'VR-41')['status'], 'no overlap in the gap fixture');
        $sumAreas = array_sum(array_column($r['parents'], 'area_sqm'));
        $this->assertGreaterThan(ConsolidationValidator::GAP_EPSILON_SQM, (float) $r['hull_area_sqm'] - $sumAreas);
    }

    /**
     * VR-43 — a non-contiguous union blocks unless multipart is explicitly
     * allowed.
     */
    public function testVr43MultipartUnionBlockedUnlessAllowed(): void
    {
        $blocked = $this->validator->validateConsolidation([self::A, self::B_GAP], false);
        $this->assertSame('fail', $this->check($blocked, 'VR-43')['status']);
        $this->assertSame(2, $blocked['union_part_count']);

        $allowed = $this->validator->validateConsolidation([self::A, self::B_GAP], true);
        $this->assertSame('pass', $this->check($allowed, 'VR-43')['status']);
        $this->assertSame(2, $allowed['union_part_count']);
        $this->assertFalse($allowed['passed'], 'VR-42 still blocks the same fixture');
    }

    /**
     * VR-44 — all inputs must share one SRID. Parent B arrives as EWKT in
     * EPSG:3857 (EWKT parsing is supported so the mismatch is observable);
     * every other fixture is 4326.
     */
    public function testVr44SridMismatchIsReported(): void
    {
        $b3857 = 'SRID=3857;POLYGON((13478000 1602000, 13478100 1602000, 13478100 1602100, 13478000 1602100, 13478000 1602000))';
        $r = $this->validator->validateConsolidation([self::A, $b3857]);

        $c = $this->check($r, 'VR-44');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('SRID', $c['message']);
    }

    /** Unparseable input is reported, never corrected. */
    public function testUnparseableParentIsReportedNotCorrected(): void
    {
        $r = $this->validator->validateConsolidation([self::A, 'NOT A GEOMETRY']);
        $this->assertFalse($r['passed']);
        $this->assertStringContainsString('parent #1', $this->check($r, 'VR-40')['message']);
    }

    public function testValidatorWritesNothing(): void
    {
        $pdo = $this->pdo();
        $count = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM app.parcels')->fetchColumn();
        $before = $count();

        $this->validator->validateConsolidation([self::A, self::B]);

        $this->assertSame($before, $count());
    }

    private function check(array $result, string $rule): array
    {
        foreach ($result['checks'] as $c) {
            if ($c['rule'] === $rule) {
                return $c;
            }
        }
        $this->fail("Rule $rule missing from checks");
    }
}
