<?php
declare(strict_types=1);

namespace App\Audit;

/**
 * PII fields that must be redacted to a hash (SHA-256 truncated to 16 chars).
 * Values for these columns are replaced with an HMAC of the value,
 * so the log carries NO raw PII but can still detect changes.
 */
final class PiiPolicy
{
    /**
     * Map of table_name => [column_name, ...] whose values should be redacted.
     */
    private const REDACTED_FIELDS = [
        'app.users' => ['password_hash', 'email', 'full_name', 'phone'],
        'app.parties' => ['given_name', 'middle_name', 'family_name', 'email', 'phone', 'tin'],
        'app.documents' => ['storage_path'],
    ];

    /**
     * Redact PII fields in an old_values/new_values snapshot.
     *
     * @param  array<string,mixed>|null $values
     * @return array<string,mixed>|null
     */
    public static function redact(string $table, ?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $fields = self::REDACTED_FIELDS[$table] ?? [];
        if (empty($fields)) {
            return $values;
        }

        foreach ($fields as $field) {
            if (array_key_exists($field, $values) && $values[$field] !== null) {
                $values[$field] = self::hash((string) $values[$field]);
            }
        }

        return $values;
    }

    /**
     * Returns a short, one-way hash of the value (no raw PII stored).
     */
    private static function hash(string $value): string
    {
        return '[REDACTED:' . substr(hash('sha256', $value), 0, 16) . ']';
    }
}
