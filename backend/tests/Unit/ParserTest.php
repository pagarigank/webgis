<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\Parser\TechnicalDescriptionParser;
use PHPUnit\Framework\TestCase;

/**
 * TASK-082 — Technical description parser unit tests.
 */
class ParserTest extends TestCase
{
    private TechnicalDescriptionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TechnicalDescriptionParser();
    }

    public function testParseStandardCadastralText(): void
    {
        $text = <<<EOT
A parcel of land situated in Barangay San Antonio.
Beginning at a point marked "1" on plan, being S. 45 deg. 12' E., 120.50 m. from BLLM No. 1;
thence N. 25 deg. 30' E., 45.20 m. to point 2;
thence S. 64 deg. 30' E., 30.00 m. to point 3;
thence S. 25 deg. 30' W., 45.20 m. to point 4;
thence N. 64 deg. 30' W., 30.00 m. to point 1, which is the point of beginning.
Containing an area of 1,356.00 square meters.
EOT;

        $result = $this->parser->parse($text);

        $this->assertSame('PARSED', $result['parser_status']);
        $this->assertCount(4, $result['courses']);
        $this->assertSame('BLLM No. 1', $result['tie_point_name']);

        // Check tie line
        $this->assertNotEmpty($result['tie_lines']);
        $this->assertEqualsWithDelta(120.50, $result['tie_lines'][0]['distance']['meters'], 0.01);
        $this->assertSame('SE', $result['tie_lines'][0]['bearing']['quadrant']);

        // Check course 1
        $c1 = $result['courses'][0];
        $this->assertSame(1, $c1['seq']);
        $this->assertSame('NE', $c1['bearing']['quadrant']);
        $this->assertSame(25, $c1['bearing']['deg']);
        $this->assertSame(30, $c1['bearing']['min']);
        $this->assertEqualsWithDelta(45.20, $c1['distance']['meters'], 0.01);
        $this->assertTrue($c1['resolved']);
        $this->assertGreaterThanOrEqual(0.9, $c1['confidence']);

        // Check course 4
        $c4 = $result['courses'][3];
        $this->assertSame(4, $c4['seq']);
        $this->assertSame('NW', $c4['bearing']['quadrant']);
        $this->assertSame(64, $c4['bearing']['deg']);
        $this->assertSame(30, $c4['bearing']['min']);
        $this->assertEqualsWithDelta(30.00, $c4['distance']['meters'], 0.01);
        $this->assertTrue($c4['resolved']);

        // Check claimed area
        $this->assertEqualsWithDelta(1356.00, $result['area_sqm_claimed'], 0.01);
    }

    public function testParseShortPhrasingWithSymbols(): void
    {
        $text = <<<EOT
From BLLM #1, S 45°12' E, 120.50 m to point 1;
1-2: N 25°30'00" E, 45.20 m
2-3: S 64°30'00" E, 30.00 m
3-4: S 25°30'00" W, 45.20 m
4-1: N 64°30'00" W, 30.00 m
EOT;

        $result = $this->parser->parse($text);

        $this->assertSame('PARSED', $result['parser_status']);
        $this->assertCount(4, $result['courses']);
        $this->assertTrue($result['courses'][0]['resolved']);
        $this->assertSame(25, $result['courses'][0]['bearing']['deg']);
    }

    public function testParseNoisyOcrInputProducesPartialAndFlagsUnresolved(): void
    {
        $noisyText = <<<EOT
Beginning at point 1, from BLLM No. 1, S 45°12' E, 120.50 m;
thence N 25°30' E, 45.20 m to point 2;
thence S ??°??' E, 30.00 m to point 3;
thence S 25°30' W, 45.20 m to point 4;
thence N 64°30' W, 30.00 m to point 1.
EOT;

        $result = $this->parser->parse($noisyText);

        $this->assertSame('PARTIAL', $result['parser_status']);
        $this->assertCount(4, $result['courses']);

        // Course 2 was corrupted: bearing could not be parsed
        $c2 = $result['courses'][1];
        $this->assertFalse($c2['resolved']);
        $this->assertNull($c2['bearing']);
        $this->assertNotEmpty($c2['issues']);
        $this->assertSame('VR-01', $c2['issues'][0]['rule']);
        $this->assertLessThan(0.7, $c2['confidence']);
    }

    public function testSourceSpansAreCaptured(): void
    {
        $text = "thence N 25°30' E, 45.20 meters to point 2;";
        $result = $this->parser->parse($text);

        $this->assertCount(1, $result['courses']);
        $span = $result['courses'][0]['source_span'];
        $this->assertIsArray($span);
        $this->assertArrayHasKey('start', $span);
        $this->assertArrayHasKey('end', $span);

        $sub = substr($text, $span['start'], $span['end'] - $span['start']);
        $this->assertStringContainsString('N 25', $sub);
    }
}
