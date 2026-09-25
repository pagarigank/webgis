import apiClient from '../../../lib/apiClient';

/**
 * TASK-100/103 — workflow transitions API (api.md §8.1).
 *
 * GET /parcels/{id}/transitions returns every transition defined from the
 * parcel's current state annotated with `allowed` for the caller. The UI must
 * render only rows with allowed=true (FR-103: unavailable actions are absent);
 * the server re-validates every POST anyway (FR-136).
 */
export interface WorkflowAction {
    action_code: string;
    to_state: string;
    required_permission: string;
    requires_reason: boolean;
    requires_comment: boolean;
    has_guard: boolean;
    allowed: boolean;
}

export interface WorkflowActionsPayload {
    parcel_id: string;
    actions: WorkflowAction[];
}

export interface TransitionHistoryRow {
    action_code: string;
    from_state: string;
    to_state: string;
    reason: string | null;
    comment: string | null;
    accepted_computation_id: number | null;
    accepted_td_revision: number | null;
    acted_at: string;
    actor: string | null;
}

export interface TransitionResult {
    parcel_id: string;
    from_state: string;
    to_state: string;
    action_code: string;
}

export const transitionsApi = {
    available: async (parcelId: string): Promise<WorkflowActionsPayload> => {
        return (await apiClient.get(`/parcels/${parcelId}/transitions`)) as WorkflowActionsPayload;
    },

    history: async (parcelId: string): Promise<{ parcel_id: string; history: TransitionHistoryRow[] }> => {
        return (await apiClient.get(`/parcels/${parcelId}/transitions/history`)) as {
            parcel_id: string;
            history: TransitionHistoryRow[];
        };
    },

    transition: async (
        parcelId: string,
        body: { action: string; reason?: string; comment?: string },
    ): Promise<TransitionResult> => {
        return (await apiClient.post(`/parcels/${parcelId}/transitions`, body)) as TransitionResult;
    },
};

/**
 * Build the POST /transitions body for an action, enforcing the per-action
 * reason/comment rules server-side-identically (FR-137): a required reason or
 * comment that is missing/blank throws before any request is sent, so the UI
 * can never silently fire a transition the engine would have to reject.
 */
export function buildTransitionPayload(
    action: WorkflowAction,
    input: { reason?: string; comment?: string },
): { action: string; reason?: string; comment?: string } {
    const reason = (input.reason ?? '').trim();
    const comment = (input.comment ?? '').trim();

    if (action.requires_reason && reason === '') {
        throw new Error(`A reason is required for ${action.action_code}.`);
    }
    if (action.requires_comment && comment === '') {
        throw new Error(`A comment is required for ${action.action_code}.`);
    }

    return {
        action: action.action_code,
        ...(reason !== '' ? { reason } : {}),
        ...(comment !== '' ? { comment } : {}),
    };
}
