<?php
declare(strict_types=1);

namespace App\Audit\Http;

use App\Core\Http\Envelope;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuditQueryController
{
    public function __construct(private readonly PDO $pdo) {}

    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        
        $sql = "SELECT id, occurred_at, user_id, username_snapshot, action, entity_type, entity_id, changed_fields, reason, ip FROM audit.audit_logs WHERE 1=1";
        $bindings = [];

        if (!empty($params['entity_type'])) {
            $sql .= " AND entity_type = :entity_type";
            $bindings[':entity_type'] = $params['entity_type'];
        }
        if (!empty($params['entity_id'])) {
            $sql .= " AND entity_id = :entity_id";
            $bindings[':entity_id'] = (string)$params['entity_id'];
        }
        if (!empty($params['user_id'])) {
            $sql .= " AND user_id = :user_id";
            $bindings[':user_id'] = (int)$params['user_id'];
        }
        if (!empty($params['action'])) {
            $sql .= " AND action = :action";
            $bindings[':action'] = $params['action'];
        }
        if (!empty($params['from'])) {
            $sql .= " AND occurred_at >= :from";
            $bindings[':from'] = $params['from'];
        }
        if (!empty($params['to'])) {
            $sql .= " AND occurred_at <= :to";
            $bindings[':to'] = $params['to'];
        }

        $sql .= " ORDER BY occurred_at DESC LIMIT 100";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert changed_fields from PG array string to real array if needed
        foreach ($results as &$row) {
            if (is_string($row['changed_fields'])) {
                // simple trim of {}
                $fields = trim($row['changed_fields'], '{}');
                $row['changed_fields'] = $fields ? explode(',', $fields) : [];
            }
        }

        return Envelope::success($response, $results);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare("SELECT * FROM audit.audit_logs WHERE id = :id");
        $stmt->execute([':id' => (int)$args['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return Envelope::error($response, 'NOT_FOUND', 'Audit log not found', [], 404);
        }

        // PII Scrubbing
        $old = json_decode($row['old_values'] ?: '{}', true);
        $new = json_decode($row['new_values'] ?: '{}', true);

        $piiFields = ['password_hash', 'mfa_secret_enc', 'email', 'contact_number'];
        foreach ($piiFields as $field) {
            if (isset($old[$field])) $old[$field] = '***REDACTED***';
            if (isset($new[$field])) $new[$field] = '***REDACTED***';
        }

        $row['old_values'] = $old;
        $row['new_values'] = $new;
        
        if (is_string($row['changed_fields'])) {
            $fields = trim($row['changed_fields'], '{}');
            $row['changed_fields'] = $fields ? explode(',', $fields) : [];
        }

        return Envelope::success($response, $row);
    }

    public function export(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        
        $sql = "SELECT id, occurred_at, user_id, username_snapshot, action, entity_type, entity_id, reason FROM audit.audit_logs WHERE 1=1";
        $bindings = [];

        if (!empty($params['entity_type'])) {
            $sql .= " AND entity_type = :entity_type";
            $bindings[':entity_type'] = $params['entity_type'];
        }
        if (!empty($params['action'])) {
            $sql .= " AND action = :action";
            $bindings[':action'] = $params['action'];
        }
        $sql .= " ORDER BY occurred_at DESC LIMIT 1000";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Audit the export action
        $userId = $request->getAttribute('user_id'); // From AuthenticateMiddleware
        $username = $request->getAttribute('username') ?? 'unknown';
        $auditStmt = $this->pdo->prepare("INSERT INTO audit.audit_logs (user_id, username_snapshot, action, entity_type, ip) VALUES (?, ?, 'EXPORT', 'AUDIT_LOGS', ?)");
        $auditStmt->execute([$userId, $username, $_SERVER['REMOTE_ADDR'] ?? null]);

        $csv = "id,occurred_at,user_id,username_snapshot,action,entity_type,entity_id,reason\n";
        foreach ($results as $row) {
            $csv .= sprintf(
                "%d,%s,%s,%s,%s,%s,%s,%s\n",
                $row['id'],
                $row['occurred_at'],
                $row['user_id'] ?? '',
                $row['username_snapshot'] ?? '',
                $row['action'],
                $row['entity_type'],
                $row['entity_id'] ?? '',
                str_replace(',', ' ', $row['reason'] ?? '')
            );
        }

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="audit_export.csv"');
    }
}
