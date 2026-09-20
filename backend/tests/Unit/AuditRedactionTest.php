<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\PiiPolicy;
use PHPUnit\Framework\TestCase;

class AuditRedactionTest extends TestCase
{
    public function testNonPiiTableIsUntouched(): void
    {
        $values = ['parcel_code' => 'TEST-001', 'psgc_barangay' => '041005001'];
        $result = PiiPolicy::redact('app.parcels', $values);
        $this->assertSame($values, $result);
    }

    public function testPiiFieldIsRedacted(): void
    {
        $values = [
            'username'      => 'alice',
            'email'         => 'alice@example.com',
            'password_hash' => '$argon2id$abc123',
            'full_name'     => 'Alice Smith',
        ];
        $result = PiiPolicy::redact('app.users', $values);

        // Non-PII field must be unchanged
        $this->assertSame('alice', $result['username']);

        // PII fields must be redacted
        $this->assertStringStartsWith('[REDACTED:', $result['email']);
        $this->assertStringStartsWith('[REDACTED:', $result['password_hash']);
        $this->assertStringStartsWith('[REDACTED:', $result['full_name']);

        // Redacted value must NOT contain the original PII
        $this->assertStringNotContainsString('alice@example.com', $result['email']);
        $this->assertStringNotContainsString('Smith', $result['full_name']);
    }

    public function testNullValuesArrayIsReturnedAsNull(): void
    {
        $this->assertNull(PiiPolicy::redact('app.users', null));
    }

    public function testNullFieldValueIsNotRedacted(): void
    {
        $values = ['email' => null, 'username' => 'bob'];
        $result = PiiPolicy::redact('app.users', $values);

        // Null field stays null (nothing to hash)
        $this->assertNull($result['email']);
        $this->assertSame('bob', $result['username']);
    }

    public function testRedactionIsDeterministic(): void
    {
        $values1 = ['email' => 'charlie@example.com'];
        $values2 = ['email' => 'charlie@example.com'];

        $r1 = PiiPolicy::redact('app.users', $values1);
        $r2 = PiiPolicy::redact('app.users', $values2);

        // Same input → same redacted output (allows change detection)
        $this->assertSame($r1['email'], $r2['email']);
    }

    public function testDifferentValuesProduceDifferentHashes(): void
    {
        $r1 = PiiPolicy::redact('app.users', ['email' => 'a@example.com']);
        $r2 = PiiPolicy::redact('app.users', ['email' => 'b@example.com']);

        $this->assertNotSame($r1['email'], $r2['email']);
    }

    public function testPartyPiiFieldsAreRedacted(): void
    {
        $values = [
            'given_name'  => 'Juan',
            'family_name' => 'dela Cruz',
            'tin'         => '123-456-789',
            'role'        => 'OWNER',
        ];
        $result = PiiPolicy::redact('app.parties', $values);

        $this->assertStringStartsWith('[REDACTED:', $result['given_name']);
        $this->assertStringStartsWith('[REDACTED:', $result['family_name']);
        $this->assertStringStartsWith('[REDACTED:', $result['tin']);
        $this->assertSame('OWNER', $result['role']); // non-PII preserved
    }
}
