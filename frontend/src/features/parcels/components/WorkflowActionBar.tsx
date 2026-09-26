import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { transitionsApi, buildTransitionPayload } from '../api/transitionsApi';
import type { WorkflowAction } from '../api/transitionsApi';

/**
 * TASK-103 — workflow action bar (FR-103, FR-136, FR-137).
 *
 * Renders one button per transition the server reports as allowed for the
 * current caller from the parcel's current state. Unavailable transitions are
 * absent from the DOM entirely (never rendered disabled) — the allowed set is
 * server truth, and the server re-validates every POST regardless.
 *
 * Actions that require a reason or comment (FR-137) open a modal whose
 * confirm button stays disabled until the required input is non-blank, and
 * the payload builder refuses to send a request before that (the AC: "a
 * return requires a reason before the request is sent").
 */

/** Human labels for the seeded FR-135 action codes; unknown codes title-case. */
const ACTION_LABELS: Record<string, string> = {
    SUBMIT: 'Submit for review',
    START_REVIEW: 'Start review',
    RETURN: 'Return to editor',
    VERIFY: 'Verify',
    APPROVE: 'Approve',
    PUBLISH: 'Publish',
    ARCHIVE: 'Archive',
    REOPEN: 'Reopen for editing',
};

export function actionLabel(actionCode: string): string {
    return ACTION_LABELS[actionCode] ?? actionCode.charAt(0) + actionCode.slice(1).toLowerCase();
}

/** Pure helper for tests/UI: only actions the caller may perform. */
export function selectableActions(actions: WorkflowAction[]): WorkflowAction[] {
    return actions.filter((a) => a.allowed);
}

interface WorkflowActionBarProps {
    parcelId: string;
    /** Current parcel status, shown in the modal ("DRAFT → SUBMITTED"). */
    status: string;
    /** Disable actions while local edits are unsaved (version would move). */
    disabled?: boolean;
}

export function WorkflowActionBar({ parcelId, status, disabled = false }: WorkflowActionBarProps) {
    const queryClient = useQueryClient();
    const [pending, setPending] = useState<WorkflowAction | null>(null);
    const [reason, setReason] = useState('');
    const [comment, setComment] = useState('');
    const [error, setError] = useState<string | null>(null);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['parcel', parcelId, 'transitions'],
        queryFn: () => transitionsApi.available(parcelId),
        enabled: parcelId.length > 0,
    });

    const transition = useMutation({
        mutationFn: (action: WorkflowAction) =>
            transitionsApi.transition(parcelId, buildTransitionPayload(action, { reason, comment })),
        onSuccess: () => {
            closePrompt();
            // frontend.md §11 — workflow transitions invalidate the list too.
            void queryClient.invalidateQueries({ queryKey: ['parcel', parcelId] });
            void queryClient.invalidateQueries({ queryKey: ['parcels'] });
            void queryClient.invalidateQueries({ queryKey: ['review-inbox'] });
        },
        onError: (err) => {
            const axiosErr = err as { response?: { data?: { error?: { message?: string } } }; message?: string };
            setError(axiosErr.response?.data?.error?.message ?? axiosErr.message ?? 'Workflow action failed.');
        },
    });

    const closePrompt = () => {
        setPending(null);
        setReason('');
        setComment('');
        setError(null);
    };

    const openPrompt = (action: WorkflowAction) => {
        setError(null);
        setReason('');
        setComment('');
        setPending(action);
    };

    const actions = selectableActions(data?.actions ?? []);

    // Reason/comment fields the pending action demands (FR-137).
    const needsReason = pending?.requires_reason ?? false;
    const needsComment = pending?.requires_comment ?? false;
    const ready =
        pending != null &&
        (!needsReason || reason.trim() !== '') &&
        (!needsComment || comment.trim() !== '');

    if (isError) {
        return (
            <span className="small text-muted" data-testid="workflow-action-bar">
                Workflow actions unavailable
            </span>
        );
    }

    return (
        <div className="d-flex gap-2 align-items-center" data-testid="workflow-action-bar">
            {isLoading && <span className="small text-muted">Actions…</span>}
            {!isLoading && actions.length === 0 && (
                <span className="small text-muted">No workflow actions available</span>
            )}
            {actions.map((action) => (
                <button
                    key={action.action_code}
                    className="btn btn-sm btn-secondary"
                    disabled={disabled || transition.isPending}
                    title={`${action.action_code}: ${status} → ${action.to_state}`}
                    data-testid={`workflow-action-${action.action_code}`}
                    onClick={() => openPrompt(action)}
                >
                    {actionLabel(action.action_code)}
                </button>
            ))}

            {pending && (
                <div
                    className="modal show d-block"
                    style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}
                    tabIndex={-1}
                    data-testid="workflow-action-modal"
                >
                    <div className="modal-dialog modal-dialog-centered">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h5 className="modal-title">{actionLabel(pending.action_code)}</h5>
                                <button
                                    type="button"
                                    className="btn-close"
                                    onClick={closePrompt}
                                    disabled={transition.isPending}
                                    aria-label="Close"
                                ></button>
                            </div>
                            <div className="modal-body">
                                {error && (
                                    <div className="alert alert-danger small" data-testid="workflow-action-error">
                                        {error}
                                    </div>
                                )}
                                <p className="small text-muted">
                                    {status} &rarr; {pending.to_state}
                                </p>
                                {needsReason && (
                                    <div className="mb-3">
                                        <label htmlFor="workflow-reason-input" className="form-label small fw-semibold">
                                            Reason <span className="text-danger">*</span>
                                        </label>
                                        <textarea
                                            id="workflow-reason-input"
                                            className="form-textarea"
                                            rows={3}
                                            value={reason}
                                            onChange={(e) => setReason(e.target.value)}
                                            disabled={transition.isPending}
                                            data-testid="workflow-reason-input"
                                        />
                                    </div>
                                )}
                                {needsComment && (
                                    <div className="mb-3">
                                        <label htmlFor="workflow-comment-input" className="form-label small fw-semibold">
                                            Comment <span className="text-danger">*</span>
                                        </label>
                                        <textarea
                                            id="workflow-comment-input"
                                            className="form-textarea"
                                            rows={3}
                                            value={comment}
                                            onChange={(e) => setComment(e.target.value)}
                                            disabled={transition.isPending}
                                            data-testid="workflow-comment-input"
                                        />
                                    </div>
                                )}
                            </div>
                            <div className="modal-footer">
                                <button
                                    type="button"
                                    className="btn btn-secondary btn-sm"
                                    onClick={closePrompt}
                                    disabled={transition.isPending}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-primary btn-sm"
                                    disabled={!ready || transition.isPending}
                                    onClick={() => transition.mutate(pending)}
                                    data-testid="workflow-action-confirm"
                                >
                                    {transition.isPending ? 'Applying…' : `Confirm ${actionLabel(pending.action_code)}`}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
