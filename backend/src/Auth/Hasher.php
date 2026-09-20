<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Hasher wraps PHP's password_hash / password_verify using Argon2id.
 *
 * Design decisions:
 *  - Algorithm: PASSWORD_ARGON2ID (requires PHP 7.3+, libsodium or libargon2)
 *  - Cost parameters: memory=65536 KiB, time=4, threads=1 (conservative safe defaults)
 *  - Rehash-on-login: if stored hash was made with weaker parameters or a
 *    different algorithm, verifyAndRehash() returns a fresh hash.
 *  - The class is stateless; inject it where needed.
 */
final class Hasher
{
    /**
     * Argon2id options.
     * memory_cost: 64 MiB, time_cost: 4 iterations, threads: 1.
     *
     * @var array<string, int>
     */
    private const OPTIONS = [
        'memory_cost' => 65536, // 64 MiB
        'time_cost'   => 4,
        'threads'     => 1,
    ];

    /**
     * Hash a plaintext password.
     *
     * @throws \RuntimeException if hashing fails (should never happen in practice).
     */
    public function hash(string $plaintext): string
    {
        $hash = password_hash($plaintext, PASSWORD_ARGON2ID, self::OPTIONS);

        if ($hash === false) {
            throw new \RuntimeException('password_hash() failed unexpectedly.');
        }

        return $hash;
    }

    /**
     * Verify a plaintext password against a stored hash.
     */
    public function verify(string $plaintext, string $storedHash): bool
    {
        return password_verify($plaintext, $storedHash);
    }

    /**
     * Verify and, if the stored hash needs upgrading, return a fresh hash.
     *
     * Usage in a login flow:
     *
     *   [$ok, $newHash] = $hasher->verifyAndRehash($input, $stored);
     *   if (!$ok) { // wrong password }
     *   if ($newHash !== null) { // persist $newHash to the database }
     *
     * @return array{0: bool, 1: string|null}
     *   [verified, newHashOrNull]
     */
    public function verifyAndRehash(string $plaintext, string $storedHash): array
    {
        if (!$this->verify($plaintext, $storedHash)) {
            return [false, null];
        }

        // Rehash if the stored hash uses an outdated algorithm or weaker parameters
        if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID, self::OPTIONS)) {
            return [true, $this->hash($plaintext)];
        }

        return [true, null];
    }

    /**
     * Returns true if the stored hash was made with current parameters and
     * does NOT need rehashing.
     */
    public function needsRehash(string $storedHash): bool
    {
        return password_needs_rehash($storedHash, PASSWORD_ARGON2ID, self::OPTIONS);
    }
}
