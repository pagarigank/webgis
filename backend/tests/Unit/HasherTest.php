<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Hasher;
use PHPUnit\Framework\TestCase;

class HasherTest extends TestCase
{
    private Hasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new Hasher();
    }

    // -----------------------------------------------------------------------
    // hash()
    // -----------------------------------------------------------------------

    public function testHashProducesArgon2idHash(): void
    {
        $hash = $this->hasher->hash('Str0ng!PassW0rd#');
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testHashIsDifferentEachCall(): void
    {
        // Argon2id is salted; two hashes of the same plaintext must differ
        $h1 = $this->hasher->hash('Str0ng!PassW0rd#');
        $h2 = $this->hasher->hash('Str0ng!PassW0rd#');
        $this->assertNotSame($h1, $h2);
    }

    // -----------------------------------------------------------------------
    // verify()
    // -----------------------------------------------------------------------

    public function testVerifyReturnsTrueForCorrectPassword(): void
    {
        $hash = $this->hasher->hash('Str0ng!PassW0rd#');
        $this->assertTrue($this->hasher->verify('Str0ng!PassW0rd#', $hash));
    }

    public function testVerifyReturnsFalseForWrongPassword(): void
    {
        $hash = $this->hasher->hash('Str0ng!PassW0rd#');
        $this->assertFalse($this->hasher->verify('WrongPassword!99', $hash));
    }

    public function testVerifyReturnsFalseForEmptyString(): void
    {
        $hash = $this->hasher->hash('Str0ng!PassW0rd#');
        $this->assertFalse($this->hasher->verify('', $hash));
    }

    // -----------------------------------------------------------------------
    // verifyAndRehash()
    // -----------------------------------------------------------------------

    public function testVerifyAndRehashReturnsTrueAndNullWhenHashIsCurrentParameters(): void
    {
        // Hash made with current parameters — should NOT need rehash
        $hash = $this->hasher->hash('Str0ng!PassW0rd#');
        [$ok, $newHash] = $this->hasher->verifyAndRehash('Str0ng!PassW0rd#', $hash);

        $this->assertTrue($ok);
        $this->assertNull($newHash, 'No rehash needed for a current-algorithm hash');
    }

    public function testVerifyAndRehashReturnsFalseForWrongPassword(): void
    {
        $hash = $this->hasher->hash('Str0ng!PassW0rd#');
        [$ok, $newHash] = $this->hasher->verifyAndRehash('WrongPassword!99', $hash);

        $this->assertFalse($ok);
        $this->assertNull($newHash);
    }

    public function testVerifyAndRehashUpgradesLegacyBcryptHash(): void
    {
        // Simulate a legacy bcrypt hash (PASSWORD_BCRYPT) stored in the DB
        $legacyHash = password_hash('Str0ng!PassW0rd#', PASSWORD_BCRYPT);
        $this->assertStringStartsWith('$2y$', $legacyHash);

        [$ok, $newHash] = $this->hasher->verifyAndRehash('Str0ng!PassW0rd#', $legacyHash);

        $this->assertTrue($ok);
        $this->assertNotNull($newHash, 'Bcrypt hash must trigger rehash');
        $this->assertStringStartsWith('$argon2id$', $newHash);
    }

    public function testVerifyAndRehashUpgradesWeakArgon2idParameters(): void
    {
        // Hash with weaker parameters (lower memory / time cost)
        $weakHash = password_hash('Str0ng!PassW0rd#', PASSWORD_ARGON2ID, [
            'memory_cost' => 1024,
            'time_cost'   => 1,
            'threads'     => 1,
        ]);

        [$ok, $newHash] = $this->hasher->verifyAndRehash('Str0ng!PassW0rd#', $weakHash);

        $this->assertTrue($ok);
        $this->assertNotNull($newHash, 'Weak Argon2id parameters must trigger rehash');
        $this->assertStringStartsWith('$argon2id$', $newHash);
    }

    // -----------------------------------------------------------------------
    // needsRehash()
    // -----------------------------------------------------------------------

    public function testNeedsRehashReturnsFalseForCurrentHash(): void
    {
        $hash = $this->hasher->hash('Str0ng!PassW0rd#');
        $this->assertFalse($this->hasher->needsRehash($hash));
    }

    public function testNeedsRehashReturnsTrueForBcryptHash(): void
    {
        $hash = password_hash('Str0ng!PassW0rd#', PASSWORD_BCRYPT);
        $this->assertTrue($this->hasher->needsRehash($hash));
    }
}
