<?php
declare(strict_types=1);

namespace App\Auth;

use InvalidArgumentException;

/**
 * RFC 6238 TOTP (HMAC-SHA1, 30-second period, 6 digits) implemented in pure
 * PHP with no external dependency (ADR-22).
 *
 * Secret format: base32 (RFC 4648) without padding, as issued by common
 * authenticator apps. The implementation is verified against the RFC 6238
 * appendix B test vectors in tests/Unit/TotpTest.php.
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const STEP_WINDOW = 1; // ±1 step on each side

    /**
     * Verify a submitted code against the current time step, allowing a drift
     * window of ±1 step (30s) on each side.
     */
    public static function verify(string $secretBase32, string $code, ?int $now = null): bool
    {
        if (!self::isValidSecret($secretBase32)) {
            return false;
        }
        if (!preg_match('/^\d{1,8}$/', $code)) {
            return false;
        }

        $now ??= time();
        $counter = (int) floor($now / self::PERIOD);
        $code = (int) $code;

        // Check the current and neighbouring time steps. Older slaves drift;
        // authenticator apps usually keep their own clock in sync.
        for ($offset = -self::STEP_WINDOW; $offset <= self::STEP_WINDOW; $offset++) {
            if (self::generateAtCounter($secretBase32, $counter + $offset) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate that a base32 string is a plausible TOTP secret (RFC 4648
     * alphabet, length a multiple of 8, at least 16 chars = 80 bits).
     * Trailing padding ('=') is tolerated.
     */
    public static function isValidSecret(string $secret): bool
    {
        $secret = rtrim(strtoupper($secret), '=');
        if ($secret === '') {
            return false;
        }
        if (strlen($secret) % 8 !== 0) {
            return false;
        }
        if (!preg_match('/^[A-Z2-7]+$/', $secret)) {
            return false;
        }
        return strlen($secret) >= 16;
    }

    /** Generate a new random secret (160 bits) in base32 without padding. */
    public static function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = random_bytes(20);

        $out = '';
        $bitBuffer = 0;
        $bitsLeft = 0;
        foreach (str_split($bytes) as $byte) {
            $bitBuffer = ($bitBuffer << 8) | ord($byte);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $out .= $alphabet[($bitBuffer >> $bitsLeft) & 0x1F];
            }
        }
        if ($bitsLeft > 0) {
            $out .= $alphabet[($bitBuffer << (5 - $bitsLeft)) & 0x1F];
        }
        // 20 bytes = 32 base32 characters, already a multiple of 8; no padding.
        return $out;
    }

    /** The 6-digit code currently valid for a secret, optionally at a given time. */
    public static function codeAt(string $secretBase32, ?int $now = null): string
    {
        $now ??= time();
        $counter = (int) floor($now / self::PERIOD);
        return str_pad((string) self::generateAtCounter($secretBase32, $counter), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function generateAtCounter(string $secretBase32, int $counter): int
    {
        $key = self::base32Decode($secretBase32);
        $message = pack('N', $counter >> 32) . pack('N', $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $message, $key, true);

        // Dynamic truncation (RFC 4226 §5.3).
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
                | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                | (ord($hash[$offset + 3]) & 0xFF);

        return $binary % (10 ** self::DIGITS);
    }

    /** Decode base32 (RFC 4648, padding optional). */
    private static function base32Decode(string $input): string
    {
        $input = rtrim(strtoupper($input), '=');
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        $buffer = 0;
        $bitsLeft = 0;
        $out = '';
        foreach (str_split($input) as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Invalid base32 character: ' . $char);
            }
            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $out .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $out;
    }
}