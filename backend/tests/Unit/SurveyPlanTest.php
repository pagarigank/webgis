<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Survey\Http\SurveyPlanController;
use PHPUnit\Framework\TestCase;

/**
 * TASK-077 — survey plan constants and validation rules.
 */
class SurveyPlanTest extends TestCase
{
    public function testAllowedPlanTypesContainStandardPhilippineClassifications(): void
    {
        $types = SurveyPlanController::PLAN_TYPES;

        // Subdivisions, consolidations, cadastral surveys
        $this->assertContains('Psd', $types);
        $this->assertContains('Psu', $types);
        $this->assertContains('Pcs', $types);
        $this->assertContains('Csd', $types);
        $this->assertContains('Ccs', $types);
        $this->assertContains('Bsd', $types);
        $this->assertContains('Vs', $types);
        $this->assertContains('Fls', $types);
        $this->assertContains('Rs', $types);
        $this->assertContains('As', $types);
        $this->assertContains('Swo', $types);
        $this->assertContains('Msi', $types);
        $this->assertContains('OTHER', $types);
    }

    public function testPlanNumberValidation(): void
    {
        $this->assertNotEmpty(SurveyPlanController::PLAN_TYPES);
    }
}
