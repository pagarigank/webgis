<?php
declare(strict_types=1);

namespace App\Parcels\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * TASK-104 — Merged history timeline API.
 *
 * GET /parcels/{id}/timeline
 *   Merges parcel_versions, audit.audit_logs, and approval_actions
 *   (via the workflow instance) into one chronological stream —
 *   "everything ever done to parcel X" (FR-147), newest first.
 *
 * GET /parcels/{id}/timeline/export
 *   The same bundle as a downloadable JSON file (audit.export style export;
 *   the stream itself is read-only data, so no PII redaction is needed here —
 *   versions/audit/workflow rows are already application-shaped).
 */
final class HistoryTimelineController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function timeline(Request $request, Response $response, array $args): Response
    {
        $pid = $this->uuid($args, 'id');
        $this->assertParcelExists($pid);

        $events = $this->buildTimeline($pid);

        return Envelope::success($response, [
            'parcel_id' => $pid,
            'count'     => count($events),
            'events'    => $events,
        ], 200);
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $pid = $this->uuid($args, 'id');
        $this->assertParcelExists($pid);

        $events = $this->buildTimeline($pid);

        $payload = json_encode([
            'parcel_id'  => $pid,
            'exported_at' => date('c'),
            'event_count' => count($events),
            'events'     => $events,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', "attachment; filename=\"parcel-{$pid}-history.json\"");
    }

    /**
     * @return array<int, array{kind:string,at:string,actor:?string,action:string,detail:array,misc:array}>
     */
    private function buildTimeline(string $pid): array
    {
        $events = [];

        // 1. Versions (TASK-069): every geometry/status/key-attribute change.
        $v = $this->pdo->prepare(
            'SELECT pv.version, pv.status, pv.geometry_source, pv.change_summary,
                    pv.change_reason, pv.changed_by, pv.changed_at, u.username
             FROM audit.parcel_versions pv
             LEFT JOIN app.users u ON u.id = pv.changed_by
             WHERE pv.parcel_id = :pid
             ORDER BY pv.changed_at ASC'
        );
        $v->execute([':pid' => $pid]);
        foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $events[] = [
                'kind'   => 'VERSION',
                'at'     => (string) $r['changed_at'],
                'actor'  => $r['username'],
                'action' => sprintf('Version %d recorded (%s)', (int) $r['version'], (string) $r['status']),
                'detail' => [
                    'version'        => (int) $r['version'],
                    'status'         => $r['status'],
                    'geometry_source'=> $r['geometry_source'],
                    'change_summary' => $r['change_summary'],
                    'change_reason'  => $r['change_reason'],
                ],
                'misc'   => [],
            ];
        }

        // 2. Audit rows (TASK-025): every mutation with actor, old/new, reason.
        $a = $this->pdo->prepare(
            'SELECT al.action, al.reason, al.old_values, al.new_values, al.occurred_at,
                    al.user_id, u.username
             FROM audit.audit_logs al
             LEFT JOIN app.users u ON u.id = al.user_id
             WHERE al.entity_type = \'app.parcels\' AND al.entity_id = :pid
             ORDER BY al.occurred_at ASC'
        );
        $a->execute([':pid' => $pid]);
        foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $events[] = [
                'kind'   => 'AUDIT',
                'at'     => (string) $r['occurred_at'],
                'actor'  => $r['username'],
                'action' => (string) $r['action'],
                'detail' => [
                    'reason' => $r['reason'],
                ],
                'misc'   => [
                    'old_values' => $this->decodeJson($r['old_values']),
                    'new_values' => $this->decodeJson($r['new_values']),
                ],
            ];
        }

        // 3. Workflow transitions (TASK-100): approval_actions via the instance.
        $w = $this->pdo->prepare(
            'SELECT aa.action_code, aa.from_state, aa.to_state, aa.reason, aa.comment,
                    aa.accepted_computation_id, aa.acted_at, u.username
             FROM app.approval_actions aa
             JOIN app.workflow_instances wi ON wi.id = aa.instance_id
             LEFT JOIN app.users u ON u.id = aa.actor_id
             WHERE wi.entity_type = \'PARCEL\' AND wi.entity_id = :pid
             ORDER BY aa.acted_at ASC'
        );
        $w->execute([':pid' => $pid]);
        foreach ($w->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $events[] = [
                'kind'   => 'WORKFLOW',
                'at'     => (string) $r['acted_at'],
                'actor'  => $r['username'],
                'action' => sprintf('%s (%s -> %s)', $r['action_code'], $r['from_state'], $r['to_state']),
                'detail' => [
                    'action_code'             => $r['action_code'],
                    'from_state'              => $r['from_state'],
                    'to_state'                => $r['to_state'],
                    'reason'                  => $r['reason'],
                    'comment'                 => $r['comment'],
                    'accepted_computation_id' => $r['accepted_computation_id'] !== null ? (int) $r['accepted_computation_id'] : null,
                ],
                'misc'   => [],
            ];
        }

        // Merge chronologically, newest first. actor as tiebreaker for a
        // stable, reproducible order.
        usort($events, fn (array $x, array $y) => [$y['at'], $x['kind'], $x['action']] <=> [$x['at'], $y['kind'], $y['action']]);

        return $events;
    }

    /** @return array<string,mixed>|null */
    private function decodeJson(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function assertParcelExists(string $pid): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM app.parcels WHERE id = :pid AND deleted_at IS NULL');
        $stmt->execute([':pid' => $pid]);
        if ($stmt->fetchColumn() === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
    }

    private function uuid(array $args, string $key): string
    {
        $val = $args[$key] ?? '';
        if (!is_string($val) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid parcel id', 400);
        }
        return strtolower($val);
    }
}
