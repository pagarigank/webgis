<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** RFC 6238 Appendix B test vector secret (base32 "GEZDGNBV..."). */
    private const SHA1_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testGenerateSecretHasValidBase32OfExpectedLength(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $secret = Totp::generateSecret();
            $this->assertSame(32, strlen($secret));
            $this->assertTrue(Totp::isValidSecret($secret));
        }
    }

    public function testIsValidSecretAcceptsPaddingAndRejectsGarbage(): void
    {
        $this->assertTrue(Totp::isValidSecret(self::SHA1_BASE32));
        $this->assertTrue(Totp::isValidSecret(self::SHA1_BASE32 . '='));
        $this->assertFalse(Totp::isValidSecret(''));
        $this->assertFalse(Totp::isValidSecret('not base32 at all!'));
        $this->assertFalse(Totp::isValidSecret('ABCDEFGH'));
        $this->assertFalse(Totp::isValidSecret('Q====='));
    }

    public function testRfc6238Sha1Vectors(): void
    {
        // Appendix B, SHA1 row (8-digit table values truncated to our 6 digits):
        // T=59 -> 94287082 (6d: 287082), T=1111111109 -> 07081804 (6d: 081804),
        // T=2000000000 -> 69279037 (6d: 279037), T=20000000000 -> 65353130 (6d: 353130).
        $this->assertSame('287082', Totp::codeAt(self::SHA1_BASE32, 59));
        $this->assertSame('081804', Totp::codeAt(self::SHA1_BASE32, 1111111109));
        $this->assertSame('279037', Totp::codeAt(self::SHA1_BASE32, 2000000000));
        $this->assertSame('353130', Totp::codeAt(self::SHA1_BASE32, 20000000000));

        // A code from the wrong time step fails verify.
        $this->assertFalse(Totp::verify(self::SHA1_BASE32, '353130', 59));
    }

    public function testVerifyAllowsWindowOfPlusMinusOneStep(): void
    {
        $secret = Totp::generateSecret();
        $now = time();

        $this->assertTrue(Totp::verify($secret, Totp::codeAt($secret, $now), $now));
        $this->assertTrue(Totp::verify($secret, Totp::codeAt($secret, $now - 30), $now));
        $this->assertTrue(Totp::verify($secret, Totp::codeAt($secret, $now + 30), $now));
        $this->assertFalse(Totp::verify($secret, Totp::codeAt($secret, $now - 60), $now));
        $this->assertFalse(Totp::verify($secret, Totp::codeAt($secret, $now + 60), $now));

        $this->assertFalse(Totp::verify($secret, Totp::codeAt($secret, $now - 90), $now));
        $this->assertFalse(Totp::verify($secret, Totp::codeAt($secret, $now + 90), $now));
    }

    public function testVerifyRejectsWrongAndEmptyCode(): void
    {
        $secret = Totp::generateSecret();
        $now = time();
        $this->assertFalse(Totp::verify($secret, '000000', $now));
        $this->assertFalse(Totp::verify($secret, '', $now));
        $this->assertFalse(Totp::verify($secret, '12345', $now));
        $this->assertFalse(Totp::verify($secret, 'abcdef', $now));
    }
}