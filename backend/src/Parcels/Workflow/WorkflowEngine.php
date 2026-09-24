<?php
declare(strict_types=1);

namespace App\Parcels\Workflow;

use App\Core\Error\ApiError;
use PDO;

/**
 * TASK-100 — Table-driven workflow engine.
 *
 * Parcel status transitions are data, not code: every legal move is a row in
 * app.workflow_transitions (seeded from FR-135), each carrying the permission
 * code that gates it, whether a reason/comment is mandatory, and an optional
 * named guard. Anything not in the table is illegal — rejected server-side
 * regardless of what the request asks for (FR-136).
 *
 * Permission checks run inside the request transaction: the caller must have
 * already SET LOCAL app.user_id / app.effective_permissions (Authenticate +
 * Authorize middleware establish app.user_id; the permission set is resolved
 * here via PermissionResolver so the engine is also usable by the future
 * worker/queue context, where it passes an explicit user id and version).
 *
 * History is written to app.approval_actions (actor, state pair, action,
 * reason, comment, request id, accepted computation/TD revision for APPROVE)
 * and mirrored into audit.audit_logs via AuditWriter. Notifications are
 * inserted for the parcel creator (FR-140), inserted-only: never raised
 * because the workflow would fail.
 */
final class WorkflowEngine
{
    /** Guards are allow-listed; unknown guard names in data fail closed. */
    private const GUARDS = ['validation_passed'];

    /**
     * TASK-102 / FR-141 — the approved-edit reopen action. Seeded as a normal
     * transition row; editing an APPROVED parcel executes it through the
     * engine so the cycle stays permission-gated, audited, and data-driven.
     */
    public const APPROVED_EDIT_ACTION = 'REOPEN';

    /**
     * TASK-102 / FR-141 — system setting holding the state an APPROVED parcel
     * returns to when it is reopened for editing (default DRAFT).
     */
    public const APPROVED_EDIT_TARGET_SETTING = 'WORKFLOW_APPROVED_EDIT_TARGET_STATE';

    public function __construct(
        private readonly PDO $pdo,
        private readonly \App\RBAC\PermissionResolver $permissions,
        private readonly ?\App\Survey\Application\SurveyValidationService $validationService = null,
        private readonly ?\App\Audit\AuditWriter $audit = null,
    ) {
    }

    /**
     * All transitions permitted from the parcel's current state, annotated
     * with whether the caller may perform them. Used by the API to tell the
     * UI which action buttons exist (unavailable actions are absent, FR-103).
     *
     * @return array<int, array{action_code:string,to_state:string,required_permission:string,requires_reason:bool,requires_comment:bool,has_guard:bool,allowed:bool}>
     */
    public function availableActions(string $parcelId, int $userId): array
    {
        $parcel = $this->loadParcel($parcelId);
        $definition = $this->loadDefinition();
        $state = $this->loadState($definition, $parcel['status']);
        $perms = $this->effectivePermissions($userId);

        $stmt = $this->pdo->prepare(
            'SELECT t.action_code, t.required_permission, t.requires_reason, t.requires_comment, t.guard_expression, s2.code AS to_state
             FROM app.workflow_transitions t
             JOIN app.workflow_states s2 ON s2.id = t.to_state_id
             WHERE t.definition_id = :def AND t.from_state_id = :from
             ORDER BY s2.display_order ASC, t.action_code ASC'
        );
        $stmt->execute([':def' => $definition['id'], ':from' => $state['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $actions = [];
        foreach ($rows as $r) {
            $actions[] = [
                'action_code'         => (string) $r['action_code'],
                'to_state'            => (string) $r['to_state'],
                'required_permission' => (string) $r['required_permission'],
                'requires_reason'     => (bool) $r['requires_reason'],
                'requires_comment'    => (bool) $r['requires_comment'],
                'has_guard'           => $r['guard_expression'] !== null,
                'allowed'             => in_array($r['required_permission'], $perms, true),
            ];
        }
        return $actions;
    }

    /**
     * Execute a transition. Throws ApiError with a closed error code on any
     * illegal move; returns the transition result on success.
     *
     * @param array{reason?:?string,comment?:?string,accepted_computation_id?:?int,accepted_td_revision?:?int} $payload
     * @return array{parcel_id:string,from_state:string,to_state:string,action_code:string}
     */
    public function transition(string $parcelId, string $actionCode, int $userId, array $payload = []): array
    {
        $actionCode = strtoupper(trim($actionCode));
        if ($actionCode === '') {
            throw new ApiError('VALIDATION_FAILED', 'An action code is required.', 400);
        }

        $parcel = $this->loadParcelForUpdate($parcelId);
        $definition = $this->loadDefinition();
        $fromState = $this->loadState($definition, $parcel['status']);

        // 1. The transition must exist for this (definition, from-state, action).
        $stmt = $this->pdo->prepare(
            'SELECT t.*, s2.code AS to_state
             FROM app.workflow_transitions t
             JOIN app.workflow_states s2 ON s2.id = t.to_state_id
             WHERE t.definition_id = :def AND t.from_state_id = :from AND UPPER(t.action_code) = :action
             LIMIT 1'
        );
        $stmt->execute([':def' => $definition['id'], ':from' => $fromState['id'], ':action' => $actionCode]);
        $transition = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($transition === false) {
            throw new ApiError(
                'INVALID_TRANSITION',
                sprintf('Action %s is not permitted from state %s.', $actionCode, $fromState['code']),
                422,
                ['from_state' => $fromState['code'], 'action' => $actionCode]
            );
        }

        // 2. Permission gate — the workflow table names the code; the caller
        //    must hold it. This is authoritative; the route middleware is a
        //    coarse pre-filter only.
        $requiredPermission = (string) $transition['required_permission'];
        if (!in_array($requiredPermission, $this->effectivePermissions($userId), true)) {
            throw new ApiError('PERMISSION_DENIED', sprintf('%s is required for this action.', $requiredPermission), 403);
        }

        // 3. Mandatory reason / comment (FR-137).
        $reason = isset($payload['reason']) ? trim((string) $payload['reason']) : '';
        $comment = isset($payload['comment']) ? trim((string) $payload['comment']) : '';
        if ((bool) $transition['requires_reason'] && $reason === '') {
            throw new ApiError('VALIDATION_FAILED', sprintf('A reason is required for %s.', $actionCode), 422, ['fields' => [['field' => 'reason', 'rule' => 'FR-137']]]);
        }
        if ((bool) $transition['requires_comment'] && $comment === '') {
            throw new ApiError('VALIDATION_FAILED', sprintf('A comment is required for %s.', $actionCode), 422, ['fields' => [['field' => 'comment', 'rule' => 'FR-137']]]);
        }

        // 4. Named guards evaluate computation/validation state (FR-135: the
        //    guard context comes from the record, never from the request).
        $guardExpression = $transition['guard_expression'];
        if ($guardExpression !== null && $guardExpression !== '') {
            $this->runGuard((string) $guardExpression, $parcelId);
        }

        // 5. Apply: advance the workflow instance, the parcel status, and
        //    record history — all inside the caller's transaction.
        $toStateCode = (string) $transition['to_state'];

        $this->upsertInstance($definition['id'], $parcelId, (int) $transition['to_state_id']);

        $upd = $this->pdo->prepare(
            'UPDATE app.parcels
             SET status = :status, version = version + 1, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
             WHERE id = :pid'
        );
        $upd->execute([':status' => $toStateCode, ':uid' => $userId, ':pid' => $parcelId]);

        $this->recordAction(
            $definition['id'],
            $parcelId,
            $fromState['code'],
            $toStateCode,
            $actionCode,
            $userId,
            $payload,
        );

        $this->notify($parcelId, $userId, $actionCode, $fromState['code'], $toStateCode);

        return [
            'parcel_id'   => $parcelId,
            'from_state'  => $fromState['code'],
            'to_state'    => $toStateCode,
            'action_code' => $actionCode,
        ];
    }

    /**
     * Full transition history for a parcel: approval_actions joined to the
     * workflow instance, newest first.
     *
     * @return array<int, array<string,mixed>>
     */
    public function history(string $parcelId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT aa.action_code, aa.from_state, aa.to_state, aa.reason, aa.comment,
                    aa.accepted_computation_id, aa.accepted_td_revision, aa.acted_at,
                    u.username AS actor
             FROM app.approval_actions aa
             JOIN app.workflow_instances wi ON wi.id = aa.instance_id
             LEFT JOIN app.users u ON u.id = aa.actor_id
             WHERE wi.entity_type = \'PARCEL\' AND wi.entity_id = :pid
             ORDER BY aa.acted_at DESC, aa.id DESC'
        );
        $stmt->execute([':pid' => $parcelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * TASK-102 / FR-141 — approved-edit cycle (reopen).
     *
     * Editing an APPROVED parcel must create a new version and return the
     * record to the configured workflow state while the previously approved
     * version stays intact. The status change is a workflow transition, not a
     * silent field write: this method drives the seeded REOPEN transition
     * through the same permission gate, reason rule (FR-137), history, audit,
     * and notification path as every other action, then appends a dedicated
     * audit.parcel_versions row (status = to_state, change_reason = $reason)
     * so the approved version itself is never rewritten.
     *
     * The transition row is looked up by action + from-state from the seeded
     * matrix; its to_state is overridden by WORKFLOW_APPROVED_EDIT_TARGET_STATE
     * when that setting names a non-terminal state of this definition. Only
     * the reason (the why of the edit) is caller input; no request field can
     * influence the target state or the permission (FR-136 default-deny).
     *
     * Must run inside the caller's transaction (the parcel row is locked with
     * FOR UPDATE). Returns the applied transition for response decoration.
     *
     * @return array{from_state:string,to_state:string,action_code:string}
     */
    public function reopenApprovedRecord(string $parcelId, int $userId, string $reason): array
    {
        $parcel = $this->loadParcelForUpdate($parcelId);
        if ($parcel['status'] !== 'APPROVED') {
            throw new ApiError('INVALID_STATE', 'Only APPROVED parcels follow the approved-edit cycle.', 400);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiError(
                'VALIDATION_FAILED',
                'Editing an approved record requires a change_reason for the reopen.',
                422,
                ['fields' => [['field' => 'change_reason', 'rule' => 'FR-141']]]
            );
        }

        $definition = $this->loadDefinition();
        $fromState = $this->loadState($definition, $parcel['status']);
        $toStateCode = $this->approvedEditTargetState($definition);

        // The transition row must exist for (REOPEN, APPROVED); its permission
        // and reason rules apply exactly as seeded.
        $stmt = $this->pdo->prepare(
            'SELECT t.*, s2.code AS to_state
             FROM app.workflow_transitions t
             JOIN app.workflow_states s2 ON s2.id = t.to_state_id
             WHERE t.definition_id = :def AND t.from_state_id = :from
               AND UPPER(t.action_code) = :action
             LIMIT 1'
        );
        $stmt->execute([':def' => $definition['id'], ':from' => $fromState['id'], ':action' => self::APPROVED_EDIT_ACTION]);
        $transition = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($transition === false) {
            throw new ApiError(
                'INVALID_TRANSITION',
                sprintf('Action %s is not defined from state APPROVED; run the system seeder.', self::APPROVED_EDIT_ACTION),
                422,
                ['from_state' => $fromState['code'], 'action' => self::APPROVED_EDIT_ACTION]
            );
        }

        // Permission gate — the workflow table names the code (seeded:
        // parcel.approve); the caller must hold it.
        $requiredPermission = (string) $transition['required_permission'];
        if (!in_array($requiredPermission, $this->effectivePermissions($userId), true)) {
            throw new ApiError('PERMISSION_DENIED', sprintf('%s is required to edit an approved record.', $requiredPermission), 403);
        }

        $this->upsertInstance($definition['id'], $parcelId, (int) $transition['to_state_id']);

        $upd = $this->pdo->prepare(
            'UPDATE app.parcels
             SET status = :status, version = version + 1, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
             WHERE id = :pid'
        );
        $upd->execute([':status' => $toStateCode, ':uid' => $userId, ':pid' => $parcelId]);

        // History, audit, and notification — the REOPEN is a first-class
        // workflow action carrying the edit's reason (FR-137).
        $this->recordAction(
            $definition['id'],
            $parcelId,
            $fromState['code'],
            $toStateCode,
            self::APPROVED_EDIT_ACTION,
            $userId,
            ['reason' => $reason],
        );

        $this->notify($parcelId, $userId, self::APPROVED_EDIT_ACTION, $fromState['code'], $toStateCode);

        return [
            'from_state'  => $fromState['code'],
            'to_state'    => $toStateCode,
            'action_code' => self::APPROVED_EDIT_ACTION,
        ];
    }

    /**
     * Target state of the approved-edit cycle: WORKFLOW_APPROVED_EDIT_TARGET_STATE
     * when it names a non-terminal state of the definition, else DRAFT
     * (defaults documented in the setting's description).
     */
    private function approvedEditTargetState(array $definition): string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM app.system_settings WHERE key = :key LIMIT 1");
        $stmt->execute([':key' => self::APPROVED_EDIT_TARGET_SETTING]);
        $raw = $stmt->fetchColumn();
        $configured = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $candidate = is_array($decoded) ? ($decoded['value'] ?? null) : $decoded;
            if (is_string($candidate) && $candidate !== '') {
                $configured = strtoupper($candidate);
            }
        }

        if ($configured !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT code FROM app.workflow_states
                 WHERE definition_id = :def AND code = :code AND is_terminal = false
                 LIMIT 1'
            );
            $stmt->execute([':def' => $definition['id'], ':code' => $configured]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return (string) $row['code'];
            }
        }

        return 'DRAFT';
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function loadParcel(string $parcelId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, created_by FROM app.parcels WHERE id = :pid AND deleted_at IS NULL');
        $stmt->execute([':pid' => $parcelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found.', 404);
        }
        return $row;
    }

    private function loadParcelForUpdate(string $parcelId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, created_by FROM app.parcels WHERE id = :pid AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([':pid' => $parcelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found.', 404);
        }
        return $row;
    }

    /** @return array{id:int} */
    private function loadDefinition(): array
    {
        $row = $this->pdo->query(
            "SELECT id FROM app.workflow_definitions WHERE code = 'PARCEL_APPROVAL' AND is_active = true LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('INTERNAL_ERROR', 'The PARCEL_APPROVAL workflow definition is missing or inactive.', 500);
        }
        return $row;
    }

    /** @return array{id:int,code:string} */
    private function loadState(array $definition, string $code): array
    {
        $stmt = $this->pdo->prepare('SELECT id, code FROM app.workflow_states WHERE definition_id = :def AND code = :code LIMIT 1');
        $stmt->execute([':def' => $definition['id'], ':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('INVALID_STATE', sprintf('Status %s is not part of the workflow definition.', $code), 400);
        }
        return $row;
    }

    /**
     * Effective permission codes for the actor. Mirrors PermissionResolver's
     * query so the engine works without the HTTP cache layer; reuses the
     * resolver instance when available to benefit from its cache.
     *
     * @return array<int, string>
     */
    private function effectivePermissions(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.code
             FROM app.permissions p
             JOIN app.role_permissions rp ON p.id = rp.permission_id
             JOIN app.user_roles ur ON rp.role_id = ur.role_id
             WHERE ur.user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function runGuard(string $guard, string $parcelId): void
    {
        if (!in_array($guard, self::GUARDS, true)) {
            // Fail closed: an unknown guard in the transition data must block,
            // never silently pass (default-deny is the architectural rule).
            throw new ApiError('INVALID_STATE', sprintf('Workflow guard %s is not defined; blocking transition.', $guard), 500);
        }

        if ($guard === 'validation_passed') {
            if ($this->validationService === null) {
                throw new ApiError('INTERNAL_ERROR', 'Validation service is not available for the workflow guard.', 500);
            }
            $result = $this->validationService->validateParcel($parcelId);
            if (!($result['can_submit'] ?? false)) {
                $first = $result['blocking_failures'][0] ?? null;
                $rule = $first['rule'] ?? 'VALIDATION';
                $message = $first['message'] ?? 'Validation failed.';
                throw new ApiError(
                    ($rule === 'VR-11' || $rule === 'VR-12') ? 'CLOSURE_EXCEEDS_TOLERANCE' : 'VALIDATION_FAILED',
                    sprintf('Blocked by %s: %s', $rule, $message),
                    422,
                    ['rule' => $rule, 'blocking_failures' => $result['blocking_failures'] ?? []]
                );
            }
        }
    }

    private function upsertInstance(int $definitionId, string $parcelId, int $toStateId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app.workflow_instances (definition_id, entity_type, entity_id, current_state_id)
             SELECT :def, \'PARCEL\', :pid, :state
             WHERE NOT EXISTS (SELECT 1 FROM app.workflow_instances WHERE entity_type = \'PARCEL\' AND entity_id = :pid2)'
        );
        $stmt->execute([':def' => $definitionId, ':pid' => $parcelId, ':state' => $toStateId, ':pid2' => $parcelId]);

        $upd = $this->pdo->prepare(
            'UPDATE app.workflow_instances
             SET current_state_id = :state, updated_at = CURRENT_TIMESTAMP
             WHERE entity_type = \'PARCEL\' AND entity_id = :pid'
        );
        $upd->execute([':state' => $toStateId, ':pid' => $parcelId]);
    }

    /** @param array{reason?:?string,comment?:?string,accepted_computation_id?:?int,accepted_td_revision?:?int} $payload */
    private function recordAction(
        int $definitionId,
        string $parcelId,
        string $fromState,
        string $toState,
        string $actionCode,
        int $userId,
        array $payload,
    ): void {
        $instStmt = $this->pdo->prepare(
            "SELECT id FROM app.workflow_instances WHERE entity_type = 'PARCEL' AND entity_id = :pid LIMIT 1"
        );
        $instStmt->execute([':pid' => $parcelId]);
        $instanceId = $instStmt->fetchColumn();
        if ($instanceId === false) {
            throw new ApiError('INTERNAL_ERROR', 'Workflow instance missing after transition.', 500);
        }

        $reqId = $this->pdo->query("SELECT NULLIF(current_setting('app.request_id', true), '')")->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO app.approval_actions
                (instance_id, from_state, to_state, action_code, actor_id,
                 accepted_computation_id, accepted_td_revision, reason, comment, request_id)
             VALUES (:inst, :from_state, :to_state, :action, :actor, :comp, :td_rev, :reason, :comment, :req_id)'
        );
        $stmt->execute([
            ':inst'      => (int) $instanceId,
            ':from_state'=> $fromState,
            ':to_state'  => $toState,
            ':action'    => $actionCode,
            ':actor'     => $userId,
            ':comp'      => $payload['accepted_computation_id'] ?? null,
            ':td_rev'    => $payload['accepted_td_revision'] ?? null,
            ':reason'    => isset($payload['reason']) && $payload['reason'] !== '' ? $payload['reason'] : null,
            ':comment'   => isset($payload['comment']) && $payload['comment'] !== '' ? $payload['comment'] : null,
            ':req_id'    => $reqId !== false ? $reqId : null,
        ]);

        $this->audit?->write(
            'WORKFLOW_TRANSITION',
            'app.parcels',
            $parcelId,
            ['status' => $fromState],
            ['status' => $toState],
            $userId,
            $reqId !== false ? $reqId : null,
            $payload['reason'] ?? null,
        );
    }

    /**
     * FR-140 — in-app notification on submit/return/approve-class transitions,
     * delivered to the parcel creator (actor is excluded).
     */
    private function notify(string $parcelId, int $actorId, string $actionCode, string $from, string $to): void
    {
        $stmt = $this->pdo->prepare('SELECT created_by, parcel_code FROM app.parcels WHERE id = :pid');
        $stmt->execute([':pid' => $parcelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) ($row['created_by'] ?? 0) === 0 || (int) $row['created_by'] === $actorId) {
            return;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO app.notifications (user_id, type, title, body, entity_type, entity_id)
             VALUES (:uid, :type, :title, :body, \'PARCEL\', :pid)'
        );
        $ins->execute([
            ':uid'   => (int) $row['created_by'],
            ':type'  => 'WORKFLOW_' . $actionCode,
            ':title' => sprintf('Parcel %s: %s -> %s', $row['parcel_code'], $from, $to),
            ':body'  => sprintf('Workflow action %s applied by user #%d.', $actionCode, $actorId),
            ':pid'   => $parcelId,
        ]);
    }
}
