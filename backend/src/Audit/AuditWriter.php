<?php
declare(strict_types=1);

namespace App\Audit;

use PDO;

/**
 * AuditWriter writes structured audit rows to audit.audit_logs.
 *
 * Contract (ADR-09):
 *  - Must be called from within the same database transaction as the mutation.
 *  - If the audit INSERT fails, the surrounding transaction rolls back, so
 *    the mutation is also rolled back (no silent audit loss).
 *  - PII field values are redacted before writing (see PiiPolicy).
 */
final class AuditWriter
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Record a mutation event.
     *
     * @param string                    $action    e.g. 'INSERT', 'UPDATE', 'DELETE'
     * @param string                    $table     Fully-qualified table name, e.g. 'app.parcels'
     * @param string                    $entityId  String representation of the PK
     * @param array<string,mixed>|null  $oldValues Snapshot before change (null for INSERTs)
     * @param array<string,mixed>|null  $newValues Snapshot after change (null for DELETEs)
     * @param int|null                  $userId    Authenticated user ID from app.user_id session var
     * @param string|null               $requestId Optional X-Request-ID header value
     * @param string|null               $reason    Mandatory human-readable reason for permission/scope
     *                                             changes (FR-017); nullable elsewhere
     */
    public function write(
        string $action,
        string $table,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
        ?string $requestId = null,
        ?string $reason = null,
    ): void {
        // Redact PII before persisting
        $oldValues = PiiPolicy::redact($table, $oldValues);
        $newValues = PiiPolicy::redact($table, $newValues);

        $stmt = $this->pdo->prepare("
            INSERT INTO audit.audit_logs
                (user_id, action, entity_type, entity_id, old_values, new_values, reason, request_id)
            VALUES
                (:user_id, :action, :entity_type, :entity_id,
                 :old_values::jsonb, :new_values::jsonb, :reason, :request_id)
        ");

        $stmt->execute([
            ':user_id'     => $userId,
            ':action'      => strtoupper($action),
            ':entity_type' => $table,
            ':entity_id'   => $entityId,
            ':old_values'  => $oldValues !== null ? json_encode($oldValues, JSON_THROW_ON_ERROR) : null,
            ':new_values'  => $newValues !== null ? json_encode($newValues, JSON_THROW_ON_ERROR) : null,
            ':reason'      => $reason,
            ':request_id'  => $requestId,
        ]);
    }

    /**
     * Convenience wrapper for the common case where user_id is stored in the
     * PostgreSQL session variable app.user_id.
     */
    public function writeFromSession(
        string $action,
        string $table,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $requestId = null,
        ?string $reason = null,
    ): void {
        $row = $this->pdo->query("SELECT NULLIF(current_setting('app.user_id', true), '')::bigint AS uid")->fetch();
        $userId = isset($row['uid']) ? (int) $row['uid'] : null;

        $this->write($action, $table, $entityId, $oldValues, $newValues, $userId, $requestId, $reason);
    }
}
