<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\Bearing;
use App\Survey\Domain\CourseValidator;
use App\Survey\Domain\Distance;
use App\Survey\Domain\Parser\TechnicalDescriptionParser;
use PHPUnit\Framework\TestCase;

/**
 * TASK-080 / TASK-083 — Technical description domain rules and workflow verification.
 */
class TechnicalDescriptionTest extends TestCase
{
    private CourseValidator $validator;
    private TechnicalDescriptionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CourseValidator();
        $this->parser = new TechnicalDescriptionParser();
    }

    public function testCourseOrderAndSequentialRenumbering(): void
    {
        $courses = [
            ['seq' => 1, 'bearing' => "N 25°30'00\" E", 'distance' => 45.20, 'unit' => 'm'],
            ['seq' => 2, 'bearing' => "S 64°30'00\" E", 'distance' => 30.00, 'unit' => 'm'],
            ['seq' => 3, 'bearing' => "S 25°30'00\" W", 'distance' => 45.20, 'unit' => 'm'],
            ['seq' => 4, 'bearing' => "N 64°30'00\" W", 'distance' => 30.00, 'unit' => 'm'],
        ];

        $val = $this->validator->validate($courses);
        $this->assertTrue($val['valid']);
        $this->assertCount(4, $val['validated_courses']);
        $this->assertSame(1, $val['validated_courses'][0]['seq']);
        $this->assertSame(4, $val['validated_courses'][3]['seq']);
    }

    public function testPrematureConfirmationRefusesUnresolvedCourses(): void
    {
        // One resolved course, one unresolved course
        $courses = [
            ['seq' => 1, 'bearing' => "N 25°30'00\" E", 'distance' => 45.20, 'unit' => 'm'],
            ['seq' => 2, 'bearing' => 'corrupted_bearing', 'distance' => 30.00, 'unit' => 'm'],
        ];

        $val = $this->validator->validate($courses);
        $this->assertFalse($val['valid'], 'Confirmation must be refused when courses are unresolved');
        $this->assertNotEmpty($val['errors']);
    }

    public function testStagingWorkflowFromParseToValidation(): void
    {
        $text = <<<EOT
Beginning at point 1, being S. 45 deg. 12' E., 120.50 m. from BLLM No. 1;
thence N. 25 deg. 30' E., 45.20 m. to point 2;
thence S. 64 deg. 30' E., 30.00 m. to point 3;
thence S. 25 deg. 30' W., 45.20 m. to point 4;
thence N. 64 deg. 30' W., 30.00 m. to point 1.
EOT;

        $staged = $this->parser->parse($text);
        $this->assertSame('PARSED', $staged['parser_status']);

        // Convert staged courses to validation format
        $coursesForValidation = array_map(function ($c) {
            return [
                'seq' => $c['seq'],
                'bearing' => $c['bearing']['original'],
                'distance' => $c['distance']['value'],
                'unit' => $c['distance']['unit'],
            ];
        }, $staged['courses']);

        $val = $this->validator->validate($coursesForValidation);
        $this->assertTrue($val['valid'], 'Staged valid text must pass course validation before confirmation');
        $this->assertEmpty($val['errors']);
    }
}
