<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Domain\CourseValidator;
use PHPUnit\Framework\TestCase;

/**
 * TASK-081 — Course syntax validation (VR-01...VR-09).
 */
class CourseValidatorTest extends TestCase
{
    private CourseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CourseValidator();
    }

    public function testValidCoursesPass(): void
    {
        $courses = [
            [
                'seq' => 1,
                'bearing' => "N 25°30'00\" E",
                'distance' => 45.20,
                'unit' => 'm',
            ],
            [
                'seq' => 2,
                'bearing' => "S 64°30'00\" E",
                'distance' => 30.00,
                'unit' => 'm',
            ],
            [
                'seq' => 3,
                'bearing' => "S 30°00'00\" W",
                'distance' => 40.00,
                'unit' => 'm',
            ],
        ];

        $result = $this->validator->validate($courses);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['warnings']);
    }

    public function testVr01InvalidBearingDegreesMinutesSeconds(): void
    {
        $courses = [
            [
                'seq' => 1,
                'quadrant' => 'NE',
                'deg' => 95, // Invalid > 90
                'min' => 20,
                'sec' => 0.0,
                'distance' => 10.0,
            ],
            [
                'seq' => 2,
                'quadrant' => 'SE',
                'deg' => 45,
                'min' => 65, // Invalid >= 60
                'sec' => 0.0,
                'distance' => 10.0,
            ],
            [
                'seq' => 3,
                'quadrant' => 'SW',
                'deg' => 45,
                'min' => 30,
                'sec' => 60.5, // Invalid >= 60
                'distance' => 10.0,
            ],
        ];

        $result = $this->validator->validate($courses);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThanOrEqual(3, count($result['errors']));

        $rules = array_column($result['errors'], 'rule');
        $this->assertContains('VR-01', $rules);
    }

    public function testVr03InvalidQuadrant(): void
    {
        $courses = [
            [
                'seq' => 1,
                'quadrant' => 'XX',
                'deg' => 45,
                'min' => 0,
                'sec' => 0.0,
                'distance' => 10.0,
            ],
        ];

        $result = $this->validator->validate($courses);
        $this->assertFalse($result['valid']);
        $this->assertSame('VR-03', $result['errors'][0]['rule']);
    }

    public function testVr04InvalidDistance(): void
    {
        $courses = [
            [
                'seq' => 1,
                'bearing' => "N 25°30'00\" E",
                'distance' => 0.005, // <= 0.01 m
                'unit' => 'm',
            ],
            [
                'seq' => 2,
                'bearing' => "S 64°30'00\" E",
                'distance' => -10.0, // <= 0
                'unit' => 'm',
            ],
        ];

        $result = $this->validator->validate($courses);
        $this->assertFalse($result['valid']);
        $rules = array_column($result['errors'], 'rule');
        $this->assertContains('VR-04', $rules);
    }

    public function testVr05DistanceWarningAbove5000Meters(): void
    {
        $courses = [
            [
                'seq' => 1,
                'bearing' => "N 25°30'00\" E",
                'distance' => 5200.0,
                'unit' => 'm',
            ],
        ];

        $result = $this->validator->validate($courses);
        $this->assertTrue($result['valid']); // Warning does not invalidate
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('VR-05', $result['warnings'][0]['rule']);
    }

    public function testVr06UnregisteredUnit(): void
    {
        $courses = [
            [
                'seq' => 1,
                'bearing' => "N 25°30'00\" E",
                'distance' => 50.0,
                'unit' => 'cubits',
            ],
        ];

        $result = $this->validator->validate($courses);
        $this->assertFalse($result['valid']);
        $this->assertSame('VR-06', $result['errors'][0]['rule']);
    }

    public function testVr07AmbiguousZeroOrNinetyDegrees(): void
    {
        $courses = [
            [
                'seq' => 1,
                'bearing' => "N 0°00'00\" E",
                'distance' => 20.0,
                'unit' => 'm',
            ],
        ];

        $result = $this->validator->validate($courses);
        $this->assertFalse($result['valid']);
        $this->assertSame('VR-07', $result['errors'][0]['rule']);
    }

    public function testVr08ConsecutiveCollinearCoursesWarning(): void
    {
        $courses = [
            [
                'seq' => 1,
                'bearing' => "N 45°00'00\" E",
                'distance' => 30.0,
                'unit' => 'm',
            ],
            [
                'seq' => 2,
                'bearing' => "N 45°00'00\" E", // Same bearing consecutive!
                'distance' => 25.0,
                'unit' => 'm',
            ],
        ];

        $result = $this->validator->validate($courses);
        $this->assertTrue($result['valid']);
        $warnRules = array_column($result['warnings'], 'rule');
        $this->assertContains('VR-08', $warnRules);
    }

    public function testVr09ReversedCourseWarning(): void
    {
        $courses = [
            [
                'seq' => 1,
                'bearing' => "N 45°00'00\" E", // Azimuth = 45°
                'distance' => 30.0,
                'unit' => 'm',
            ],
            [
                'seq' => 2,
                'bearing' => "S 45°00'00\" W", // Azimuth = 225° (45 + 180), same distance
                'distance' => 30.0,
                'unit' => 'm',
            ],
        ];

        $result = $this->validator->validate($courses);
        $this->assertTrue($result['valid']);
        $warnRules = array_column($result['warnings'], 'rule');
        $this->assertContains('VR-09', $warnRules);
    }
}
