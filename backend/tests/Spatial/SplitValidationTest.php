<?php
declare(strict_types=1);

namespace Tests\Spatial;

use App\Parcels\Domain\SplitValidator;
use Tests\TestCase;

/**
 * TASK-111 — Split validation rules VR-35…VR-39 (specification.md §5.4,
 * architecture.md §18.3).
 *
 * One targeted fixture per rule: each test isolates a single failure mode so
 * a regression in one check is attributable. The validator is pure and
 * read-only — nothing is auto-corrected and no rows are written.
 *
 * Geometry fixtures live near 121.000 E / 14.600 N (Manila) where
 * 0.001° longitude ≈ 107.7 m and 0.0009° latitude ≈ 99.8 m, so the parent
 * square is ≈ 107.7 m × 99.8 m ≈ 10,750 m².
 */
class SplitValidationTest extends TestCase
{
    private const PARENT =
        'POLYGON((121.0000 14.6000, 121.0010 14.6000, 121.0010 14.6009, 121.0000 14.6009, 121.0000 14.6000))';
    private const LEFT_HALF =
        'POLYGON((121.0000 14.6000, 121.0005 14.6000, 121.0005 14.6009, 121.0000 14.6009, 121.0000 14.6000))';
    private const RIGHT_HALF =
        'POLYGON((121.0005 14.6000, 121.0010 14.6000, 121.0010 14.6009, 121.0005 14.6009, 121.0005 14.6000))';

    private SplitValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SplitValidator($this->pdo());
    }

    public function testCleanSplitOfSquareIntoTwoHalvesPassesAllChecks(): void
    {
        $r = $this->validator->validateSplit(self::PARENT, [self::LEFT_HALF, self::RIGHT_HALF]);

        $this->assertTrue($r['passed'], json_encode($r['checks'], JSON_THROW_ON_ERROR));
        $statuses = array_column($r['checks'], 'status', 'rule');
        foreach (['VR-35', 'VR-36', 'VR-37', 'VR-38', 'SRID_COMPAT'] as $rule) {
            $this->assertSame('pass', $statuses[$rule] ?? null, "expected $rule to pass");
        }
        $this->assertSame([], $r['warnings']);

        $this->assertCount(2, $r['children']);
        [$a, $b] = array_column($r['children'], 'area_sqm');
        $this->assertGreaterThan(4000.0, $a);
        $this->assertLessThan(7000.0, $a);
        $this->assertEqualsWithDelta($a, $b, $a * 0.02, 'halves should be near-equal in area');
        $this->assertEqualsWithDelta(50.0, $r['children'][0]['share_pct'], 1.0);

        $recon = $r['area_reconciliation'];
        $this->assertEqualsWithDelta(0.0, $recon['difference_pct'], 0.05);
    }

    /** VR-35 — a split must produce at least two children. */
    public function testVr35RejectsSplitWithSingleChild(): void
    {
        $r = $this->validator->validateSplit(self::PARENT, [self::LEFT_HALF]);

        $this->assertFalse($r['passed']);
        $c = $this->check($r, 'VR-35');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('at least 2', $c['message']);
    }

    /** VR-35 — each child must be a valid, simple, non-empty geometry. */
    public function testVr35RejectsInvalidSelfIntersectingChild(): void
    {
        $bowTie = 'POLYGON((121.0005 14.6000, 121.0010 14.6009, 121.0010 14.6000, 121.0005 14.6009, 121.0005 14.6000))';
        $r = $this->validator->validateSplit(self::PARENT, [self::LEFT_HALF, $bowTie]);

        $c = $this->check($r, 'VR-35');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('child #1', $c['message']);
        $this->assertStringContainsString('ST_IsValid', $c['message']);
    }

    /**
     * VR-36 — children must not overlap. Child B starts 0.0001° west of the
     * mid-line: the ~10.8 m × ~100 m band overlaps child A, yet A ∪ B still
     * equals the parent exactly, isolating VR-36 from VR-37.
     */
    public function testVr36RejectsOverlappingChildrenEvenWhenUnionMatchesParent(): void
    {
        $b = 'POLYGON((121.0004 14.6000, 121.0010 14.6000, 121.0010 14.6009, 121.0004 14.6009, 121.0004 14.6000))';
        $r = $this->validator->validateSplit(self::PARENT, [self::LEFT_HALF, $b]);

        $this->assertSame('fail', $this->check($r, 'VR-36')['status']);
        $this->assertSame('pass', $this->check($r, 'VR-37')['status'], 'union still matches parent');
        $this->assertNotEmpty($r['pairwise_overlaps']);
        $this->assertGreaterThan(SplitValidator::OVERLAP_EPSILON_SQM, $r['pairwise_overlaps'][0]['overlap_area_sqm']);
        $this->assertSame(0, $r['pairwise_overlaps'][0]['i']);
        $this->assertSame(1, $r['pairwise_overlaps'][0]['j']);
    }

    /**
     * VR-37 — the union of children must match the parent within ε. Child B
     * starts 0.0001° east of the mid-line, leaving a ~1,075 m² gap; the
     * children do not overlap, isolating VR-37 from VR-36.
     */
    public function testVr37RejectsGapBetweenChildren(): void
    {
        $b = 'POLYGON((121.0006 14.6000, 121.0010 14.6000, 121.0010 14.6009, 121.0006 14.6009, 121.0006 14.6000))';
        $r = $this->validator->validateSplit(self::PARENT, [self::LEFT_HALF, $b]);

        $this->assertSame('fail', $this->check($r, 'VR-37')['status']);
        $this->assertSame('pass', $this->check($r, 'VR-36')['status'], 'children do not overlap');
        $this->assertStringContainsString('gap or sliver', $this->check($r, 'VR-37')['message']);
    }

    /**
     * VR-38 — each child must meet the configurable minimum area. Splitting
     * into a ~3,230 m² and a ~7,530 m² half against a 5,000 m² minimum fails
     * exactly the small child.
     */
    public function testVr38RejectsChildBelowConfiguredMinimumArea(): void
    {
        $a = 'POLYGON((121.0000 14.6000, 121.0003 14.6000, 121.0003 14.6009, 121.0000 14.6009, 121.0000 14.6000))';
        $b = 'POLYGON((121.0003 14.6000, 121.0010 14.6000, 121.0010 14.6009, 121.0003 14.6009, 121.0003 14.6000))';

        $r = $this->validator->validateSplit(self::PARENT, [$a, $b], 5000.0);
        $c = $this->check($r, 'VR-38');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('child #0', $c['message']);
        $this->assertStringNotContainsString('child #1', $c['message'], 'only the small child is named');

        $r2 = $this->validator->validateSplit(self::PARENT, [$a, $b], 2000.0);
        $this->assertSame('pass', $this->check($r2, 'VR-38')['status'], 'same fixture passes a sane minimum');
    }

    /**
     * VR-39 — Σ child areas vs parent area is a warning that is always
     * reported, never corrected. The overlap fixture makes the children sum
     * ~10% larger than the parent.
     */
    public function testVr39WarnsWhenChildAreasDifferFromParent(): void
    {
        $b = 'POLYGON((121.0004 14.6000, 121.0010 14.6000, 121.0010 14.6009, 121.0004 14.6009, 121.0004 14.6000))';
        $r = $this->validator->validateSplit(self::PARENT, [self::LEFT_HALF, $b]);

        $rules = array_column($r['warnings'], 'message', 'rule');
        $this->assertArrayHasKey('VR-39', $rules);
        $this->assertGreaterThan(
            SplitValidator::RECONCILIATION_WARNING_PCT,
            abs((float) $r['area_reconciliation']['difference_pct'])
        );
        // Reconciliation is reported even when it passes.
        $clean = $this->validator->validateSplit(self::PARENT, [self::LEFT_HALF, self::RIGHT_HALF]);
        $this->assertNotContains('VR-39', array_column($clean['warnings'], 'rule'));
        $this->assertNotNull($clean['area_reconciliation']['children_sum_sqm']);
    }

    /** Nothing is auto-corrected: garbage input is reported, not repaired. */
    public function testUnparseableChildIsReportedNotCorrected(): void
    {
        $r = $this->validator->validateSplit(self::PARENT, [self::LEFT_HALF, 'NOT A GEOMETRY']);

        $this->assertFalse($r['passed']);
        $this->assertStringContainsString('child #1', $this->check($r, 'VR-35')['message']);
        $this->assertSame([], $r['warnings']);
    }

    /** The validator is read-only — running it mutates nothing. */
    public function testValidatorWritesNothing(): void
    {
        $pdo = $this->pdo();
        $count = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM app.parcels')->fetchColumn();
        $before = $count();

        $this->validator->validateSplit(self::PARENT, [self::LEFT_HALF, self::RIGHT_HALF]);

        $this->assertSame($before, $count());
    }

    /** @param array{checks: list<array{rule: string, status: string, message: string}>} $result */
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
