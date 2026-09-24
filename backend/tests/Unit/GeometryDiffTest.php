<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Parcels\Domain\VersionDiffService;
use PHPUnit\Framework\TestCase;

/**
 * TASK-105 — Geometry diff unit tests (pure domain, known-answer vectors).
 *
 * ACs covered here:
 *  - A moved vertex is identified (same index, different coordinates).
 *  - Added and removed vertices are reported with their ring index.
 *  - Identical rings produce an empty diff.
 *  - Exterior-ring extraction handles Polygon/MultiPolygon and drops the
 *    closing point.
 */
class GeometryDiffTest extends TestCase
{
    private VersionDiffService $svc;

    protected function setUp(): void
    {
        $this->svc = new VersionDiffService();
    }

    public function testIdenticalRingsProduceEmptyDiff(): void
    {
        $ring = [[121.0, 14.5], [121.01, 14.5], [121.01, 14.51]];
        $d = $this->svc->diffGeometry($ring, $ring);
        $this->assertNotNull($d);
        $this->assertSame([], $d['added']);
        $this->assertSame([], $d['removed']);
        $this->assertSame([], $d['moved']);
        $this->assertSame(3, $d['old_vertex_count']);
    }

    public function testMovedVertexIsIdentifiedWithFromAndTo(): void
    {
        $old = [[121.0, 14.5], [121.01, 14.5], [121.01, 14.51]];
        $new = [[121.0, 14.5], [121.02, 14.5], [121.01, 14.51]]; // vertex 1 moved east

        $d = $this->svc->diffGeometry($old, $new);
        $this->assertCount(1, $d['moved']);
        $this->assertSame(1, $d['moved'][0]['index']);
        $this->assertSame([121.01, 14.5], $d['moved'][0]['from']);
        $this->assertSame([121.02, 14.5], $d['moved'][0]['to']);
        $this->assertSame([], $d['added']);
        $this->assertSame([], $d['removed']);
    }

    public function testAddedAndRemovedVerticesAreReported(): void
    {
        $old = [[121.0, 14.5], [121.01, 14.5]];
        $new = [[121.0, 14.5], [121.005, 14.5], [121.01, 14.5]];

        // Pairing is by ring position: inserting a vertex shifts the tail, so
        // index 1 reads as moved and the new tail vertex as added.
        $d = $this->svc->diffGeometry($old, $new);
        $this->assertCount(1, $d['added']);
        $this->assertSame(2, $d['added'][0]['index']);
        $this->assertSame([121.01, 14.5], $d['added'][0]['point']);
        $this->assertSame([], $d['removed']);
        $this->assertCount(1, $d['moved']);

        // And the reverse direction reports a removal.
        $d2 = $this->svc->diffGeometry($new, $old);
        $this->assertCount(1, $d2['removed']);
        $this->assertSame(2, $d2['removed'][0]['index']);
        $this->assertSame([121.01, 14.5], $d2['removed'][0]['point']);
    }

    public function testGeometryAddedAndRemovedWhole(): void
    {
        $ring = [[121.0, 14.5], [121.01, 14.5], [121.01, 14.51]];

        $added = $this->svc->diffGeometry(null, $ring);
        $this->assertSame(0, $added['old_vertex_count']);
        $this->assertSame(3, $added['new_vertex_count']);
        $this->assertCount(3, $added['added']);

        $removed = $this->svc->diffGeometry($ring, null);
        $this->assertSame(3, $removed['old_vertex_count']);
        $this->assertSame(0, $removed['new_vertex_count']);
        $this->assertCount(3, $removed['removed']);
    }

    public function testExteriorRingExtractionDropsClosingPoint(): void
    {
        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[[121.0, 14.5], [121.01, 14.5], [121.01, 14.51], [121.0, 14.5]]],
        ];
        $ring = $this->svc->exteriorRing($polygon);
        $this->assertCount(3, $ring);
        $this->assertSame([121.0, 14.5], $ring[0]);
    }

    public function testExteriorRingHandlesMultiPolygon(): void
    {
        $mp = [
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[121.0, 14.5], [121.01, 14.5], [121.01, 14.51], [121.0, 14.5]]],
            ],
        ];
        $ring = $this->svc->exteriorRing($mp);
        $this->assertCount(3, $ring);
    }

    public function testFieldDiffListsChangedFieldsOnly(): void
    {
        $old = ['lot_number' => 'A-1', 'remarks' => 'same', 'tax_declaration_no' => null];
        $new = ['lot_number' => 'A-2', 'remarks' => 'same', 'tax_declaration_no' => 'TD-1'];

        $d = $this->svc->diff($old, $new, null, null);
        $fields = array_column($d['fields'], 'field');
        $this->assertContains('lot_number', $fields);
        $this->assertContains('tax_declaration_no', $fields);
        $this->assertNotContains('remarks', $fields);

        $lot = $d['fields'][array_search('lot_number', $fields, true)];
        $this->assertSame('A-1', $lot['old']);
        $this->assertSame('A-2', $lot['new']);
    }
}
