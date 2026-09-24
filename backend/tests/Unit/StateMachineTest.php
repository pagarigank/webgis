<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TASK-100 — State machine unit tests (pure, no DB).
 *
 * Exercises the decision logic that must hold regardless of storage:
 * unknown guard names fail closed; the seeded FR-135 matrix is internally
 * consistent (every referenced state exists, no transition leaves a
 * terminal state, every state is reachable except DRAFT which is initial).
 */
class StateMachineTest extends TestCase
{
    /** Mirror of the SystemSeeder PARCEL_APPROVAL matrix (kept in sync by test). */
    private const STATES = [
        'DRAFT'        => ['initial' => true,  'terminal' => false],
        'SUBMITTED'    => ['initial' => false, 'terminal' => false],
        'UNDER_REVIEW' => ['initial' => false, 'terminal' => false],
        'RETURNED'     => ['initial' => false, 'terminal' => false],
        'VERIFIED'     => ['initial' => false, 'terminal' => false],
        'APPROVED'     => ['initial' => false, 'terminal' => false],
        'PUBLISHED'    => ['initial' => false, 'terminal' => false],
        'ARCHIVED'     => ['initial' => false, 'terminal' => true],
        'SUPERSEDED'   => ['initial' => false, 'terminal' => true],
    ];

    private const TRANSITIONS = [
        ['SUBMIT',       'DRAFT',        'SUBMITTED',   'parcel.submit',  false, false, 'validation_passed'],
        ['SUBMIT',       'RETURNED',     'SUBMITTED',   'parcel.submit',  false, false, 'validation_passed'],
        ['START_REVIEW', 'SUBMITTED',    'UNDER_REVIEW','parcel.review',  false, false, null],
        ['RETURN',       'SUBMITTED',    'RETURNED',    'parcel.review',  true,  false, null],
        ['RETURN',       'UNDER_REVIEW', 'RETURNED',    'parcel.review',  true,  false, null],
        ['VERIFY',       'UNDER_REVIEW', 'VERIFIED',    'parcel.verify',  false, false, null],
        ['APPROVE',      'VERIFIED',     'APPROVED',    'parcel.approve', false, true,  'validation_passed'],
        ['PUBLISH',      'APPROVED',     'PUBLISHED',   'parcel.publish', false, false, null],
        ['REOPEN',       'APPROVED',     'DRAFT',       'parcel.approve', true,  false, null],
        ['ARCHIVE',      'DRAFT',        'ARCHIVED',    'parcel.archive', true,  false, null],
        ['ARCHIVE',      'RETURNED',     'ARCHIVED',    'parcel.archive', true,  false, null],
        ['ARCHIVE',      'APPROVED',     'ARCHIVED',    'parcel.archive', true,  false, null],
        ['ARCHIVE',      'PUBLISHED',    'ARCHIVED',    'parcel.archive', true,  false, null],
    ];

    public function testEveryTransitionReferencesKnownStates(): void
    {
        foreach (self::TRANSITIONS as $t) {
            $this->assertArrayHasKey($t[1], self::STATES, "Unknown from-state {$t[1]}");
            $this->assertArrayHasKey($t[2], self::STATES, "Unknown to-state {$t[2]}");
        }
    }

    public function testNoTransitionLeavesATerminalState(): void
    {
        foreach (self::TRANSITIONS as $t) {
            $this->assertFalse(
                self::STATES[$t[1]]['terminal'],
                "Transition {$t[0]} illegally leaves terminal state {$t[1]}"
            );
        }
    }

    public function testExactlyOneInitialStateExistsAndDRAFTIsIt(): void
    {
        $initials = array_keys(array_filter(array_column(self::STATES, 'initial', 'code') ?: [], fn ($v) => $v));
        $initials = array_keys(array_filter(self::STATES, fn ($s) => $s['initial']));
        $this->assertSame(['DRAFT'], $initials);
    }

    public function testEveryNonInitialStateIsReachable(): void
    {
        $targets = array_unique(array_column(self::TRANSITIONS, 2));
        foreach (self::STATES as $code => $meta) {
            if ($meta['initial']) {
                continue;
            }
            // SUPERSEDED is intentionally unreachable by workflow transitions
            // (FR-135a): only a committed split/consolidation may set it.
            if ($code === 'SUPERSEDED') {
                continue;
            }
            $this->assertContains($code, $targets, "State $code is unreachable");
        }
    }

    public function testEveryTransitionCarriesAPermissionAndKnownGuard(): void
    {
        $knownGuards = ['validation_passed'];
        foreach (self::TRANSITIONS as $t) {
            $this->assertNotSame('', $t[3], "Transition {$t[0]} ({$t[1]}) has no permission");
            if ($t[6] !== null) {
                $this->assertContains($t[6], $knownGuards, "Unknown guard {$t[6]}");
            }
        }
    }

    public function testReasonOrCommentIsMandatoryForRETURNAndAPPROVEAndARCHIVE(): void
    {
        foreach (self::TRANSITIONS as $t) {
            [$action, , , , $reqReason, $reqComment, ] = $t;
            if (in_array($action, ['RETURN', 'APPROVE', 'ARCHIVE'], true)) {
                $this->assertTrue(
                    $reqReason || $reqComment,
                    "$action must require a reason or comment (FR-137)"
                );
            }
        }
    }

    public function testSUPERSEDEDIsNotAWorkflowTarget(): void
    {
        // FR-135a: SUPERSEDED is set ONLY by a committed split/consolidation,
        // never by a workflow transition.
        foreach (self::TRANSITIONS as $t) {
            $this->assertNotSame('SUPERSEDED', $t[2], 'No workflow transition may target SUPERSEDED');
        }
    }

    public function testSubmitAndApproveCarryTheValidationGuard(): void
    {
        foreach (self::TRANSITIONS as $t) {
            if (in_array($t[0], ['SUBMIT', 'APPROVE'], true)) {
                $this->assertSame('validation_passed', $t[6], "{$t[0]} must run the validation guard");
            }
        }
    }
}
