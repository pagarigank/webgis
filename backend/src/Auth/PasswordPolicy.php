<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * PasswordPolicy enforces password strength rules.
 *
 * Rules (aligned with NIST SP 800-63B and the project's security ADR):
 *  - Minimum 12 characters
 *  - Maximum 128 characters (bcrypt/Argon2 safe limit)
 *  - At least one uppercase letter
 *  - At least one lowercase letter
 *  - At least one digit
 *  - At least one special character (!@#$%^&*...)
 *  - Must not contain the username (case-insensitive)
 *  - Must not be in the breach list (common/known-bad passwords)
 */
final class PasswordPolicy
{
    private const MIN_LENGTH  = 12;
    private const MAX_LENGTH  = 128;

    /** Minimal breach list — real deployments should load from a file or API. */
    private const BREACH_LIST = [
        'password', 'password1', 'password123', '123456789012',
        'qwertyuiop12', 'admin12345678', 'letmein123456',
        'welcome12345', 'iloveyou1234', 'sunshine12345',
    ];

    /**
     * Validate a candidate password.
     *
     * @param  string $password  The plaintext password to validate.
     * @param  string $username  The user's chosen username (must not appear in the password).
     * @return array<string>     An array of violation messages; empty = valid.
     */
    public static function validate(string $password, string $username = ''): array
    {
        $violations = [];

        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $violations[] = sprintf(
                'Password must be at least %d characters long.',
                self::MIN_LENGTH
            );
        }

        if ($length > self::MAX_LENGTH) {
            $violations[] = sprintf(
                'Password must not exceed %d characters.',
                self::MAX_LENGTH
            );
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $violations[] = 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $violations[] = 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $violations[] = 'Password must contain at least one digit.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $violations[] = 'Password must contain at least one special character.';
        }

        if ($username !== '' && mb_stripos($password, $username) !== false) {
            $violations[] = 'Password must not contain your username.';
        }

        if (in_array(mb_strtolower($password), self::BREACH_LIST, true)) {
            $violations[] = 'Password appears in a list of known compromised passwords. Please choose a different one.';
        }

        return $violations;
    }

    /**
     * Returns true if the password passes all rules.
     */
    public static function isValid(string $password, string $username = ''): bool
    {
        return self::validate($password, $username) === [];
    }
}
