<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\ImportExport\Domain\DxfEntityScanner;
use PHPUnit\Framework\TestCase;

/**
 * TASK-125 — the pure DXF entity scanner behind the dropped-entity report.
 *
 * No GDAL is required: the scanner reads the tagged DXF byte stream directly.
 */
class DxfEntityScannerTest extends TestCase
{
    /** Two POINT entities on PARCEL plus a TEXT label and an INSERT block. */
    private const DXF = <<<'DXF'
    0
    SECTION
    2
    HEADER
    9
    TEXT
    1
    this header value must not be counted as an entity
    0
    ENDSEC
    0
    SECTION
    2
    ENTITIES
    0
    POINT
    8
    PARCEL
    10
    121.0
    20
    14.5
    0
    POINT
    8
    PARCEL
    10
    121.1
    20
    14.6
    0
    TEXT
    8
    ANNOTATIONS
    10
    121.0
    20
    14.5
    1
    Hello
    0
    INSERT
    8
    BLOCKS
    2
    BENCHMARK
    0
    ENDSEC
    0
    EOF
    DXF;

    public function testCountsEntityTypesAndLayersFromTheEntitiesSectionOnly(): void
    {
        $scan = (new DxfEntityScanner())->scan(self::DXF);

        $this->assertSame(2, $scan['entity_types']['POINT']);
        $this->assertSame(1, $scan['entity_types']['TEXT']);
        $this->assertSame(1, $scan['entity_types']['INSERT']);
        // The HEADER section's "TEXT" is a header variable, not an entity.
        $this->assertSame(4, $scan['total']);
        $this->assertEqualsCanonicalizing(['PARCEL', 'ANNOTATIONS', 'BLOCKS'], $scan['entity_layers']);
    }

    public function testDroppedReportsOnlyAnnotationBlockAndThreeDTypes(): void
    {
        $scan = (new DxfEntityScanner())->scan(self::DXF);

        $this->assertSame(['TEXT' => 1, 'INSERT' => 1], $scan['dropped']);
        $this->assertArrayNotHasKey('POINT', $scan['dropped']);
    }

    public function testEmptyInputYieldsAnEmptyReport(): void
    {
        $scan = (new DxfEntityScanner())->scan('0
SECTION
2
TABLES
0
ENDSEC
0
EOF');

        $this->assertSame([], $scan['entity_types']);
        $this->assertSame([], $scan['dropped']);
        $this->assertSame([], $scan['entity_layers']);
        $this->assertSame(0, $scan['total']);
    }
}
