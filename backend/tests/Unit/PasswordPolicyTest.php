<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Valid passwords
    // -----------------------------------------------------------------------

    public function testStrongPasswordPasses(): void
    {
        $v = PasswordPolicy::validate('Str0ng!PassW0rd#', 'alice');
        $this->assertSame([], $v, implode('; ', $v));
    }

    public function testMinimumLengthBoundaryPasses(): void
    {
        // Exactly 12 chars, meets all rules
        $v = PasswordPolicy::validate('Abcdef1!ghij', 'alice');
        $this->assertSame([], $v, implode('; ', $v));
    }

    // -----------------------------------------------------------------------
    // Length violations
    // -----------------------------------------------------------------------

    public function testTooShortPasswordFails(): void
    {
        $v = PasswordPolicy::validate('Short1!', 'alice');
        $this->assertNotEmpty($v);
        $this->assertStringContainsString('at least 12', $v[0]);
    }

    public function testTooLongPasswordFails(): void
    {
        $v = PasswordPolicy::validate(str_repeat('Aa1!', 33), 'alice'); // 132 chars
        $msgs = implode(' ', $v);
        $this->assertStringContainsString('not exceed 128', $msgs);
    }

    // -----------------------------------------------------------------------
    // Complexity violations (each tested independently with a 20-char base)
    // -----------------------------------------------------------------------

    public function testMissingUppercaseFails(): void
    {
        $v = PasswordPolicy::validate('all_lowercase1!xyz', 'alice');
        $msgs = implode(' ', $v);
        $this->assertStringContainsString('uppercase', $msgs);
    }

    public function testMissingLowercaseFails(): void
    {
        $v = PasswordPolicy::validate('ALL_UPPERCASE1!XYZ', 'alice');
        $msgs = implode(' ', $v);
        $this->assertStringContainsString('lowercase', $msgs);
    }

    public function testMissingDigitFails(): void
    {
        $v = PasswordPolicy::validate('NoDigitsHere!!AB', 'alice');
        $msgs = implode(' ', $v);
        $this->assertStringContainsString('digit', $msgs);
    }

    public function testMissingSpecialCharFails(): void
    {
        $v = PasswordPolicy::validate('NoSpecialChar1234', 'alice');
        $msgs = implode(' ', $v);
        $this->assertStringContainsString('special character', $msgs);
    }

    // -----------------------------------------------------------------------
    // Username containment
    // -----------------------------------------------------------------------

    public function testPasswordContainingUsernameIsRejected(): void
    {
        // password contains 'alice' verbatim
        $v = PasswordPolicy::validate('MyAlice1!SuperPass', 'alice');
        $msgs = implode(' ', $v);
        $this->assertStringContainsString('username', $msgs);
    }

    public function testPasswordContainingUsernameIsCaseInsensitive(): void
    {
        $v = PasswordPolicy::validate('MYALICE1!SuperPass', 'alice');
        $msgs = implode(' ', $v);
        $this->assertStringContainsString('username', $msgs);
    }

    public function testUsernameAbsentFromPasswordPasses(): void
    {
        $v = PasswordPolicy::validate('Str0ng!Passw0rdXY', 'alice');
        $this->assertSame([], $v, implode('; ', $v));
    }

    public function testEmptyUsernameSkipsCheck(): void
    {
        $v = PasswordPolicy::validate('Str0ng!Passw0rdXY', '');
        $this->assertSame([], $v, implode('; ', $v));
    }

    // -----------------------------------------------------------------------
    // Breach list
    // -----------------------------------------------------------------------

    public function testBreachedPasswordIsRejected(): void
    {
        $v = PasswordPolicy::validate('password123', 'alice');
        $msgs = implode(' ', $v);
        // 'password123' is also short, but breach message should appear
        $this->assertStringContainsString('compromised', $msgs);
    }

    // -----------------------------------------------------------------------
    // isValid helper
    // -----------------------------------------------------------------------

    public function testIsValidReturnsTrueForStrongPassword(): void
    {
        $this->assertTrue(PasswordPolicy::isValid('Str0ng!PassW0rd#', 'alice'));
    }

    public function testIsValidReturnsFalseForWeakPassword(): void
    {
        $this->assertFalse(PasswordPolicy::isValid('weak', 'alice'));
    }

    // -----------------------------------------------------------------------
    // Multiple violations reported simultaneously
    // -----------------------------------------------------------------------

    public function testMultipleViolationsAllReported(): void
    {
        // 'abc' — too short, no uppercase, no digit, no special char
        $v = PasswordPolicy::validate('abc', 'alice');
        $this->assertGreaterThanOrEqual(3, count($v));
    }
}
