/**
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { WorkflowActionBar, selectableActions } from './WorkflowActionBar';
import type { WorkflowAction } from '../api/transitionsApi';

afterEach(cleanup);

/**
 * TASK-103 — component test for the workflow action bar. The bar must render
 * ONLY the transitions the server marks allowed (FR-103: unavailable actions
 * are absent, never disabled) and the confirm button must stay disabled until
 * a required reason/comment is non-blank (FR-137, the TASK-103 AC).
 */
vi.mock('../api/transitionsApi', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../api/transitionsApi')>();
    return {
        ...actual,
        transitionsApi: {
            ...actual.transitionsApi,
            available: vi.fn(),
            transition: vi.fn(),
        },
    };
});

import { transitionsApi } from '../api/transitionsApi';

function action(overrides: Partial<WorkflowAction> = {}): WorkflowAction {
    return {
        action_code: 'SUBMIT',
        to_state: 'SUBMITTED',
        required_permission: 'parcel.submit',
        requires_reason: false,
        requires_comment: false,
        has_guard: false,
        allowed: true,
        ...overrides,
    };
}

function renderBar(actions: WorkflowAction[]) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    vi.mocked(transitionsApi.available).mockResolvedValue({ parcel_id: 'p1', actions });
    return render(
        <QueryClientProvider client={client}>
            <WorkflowActionBar parcelId="p1" status="DRAFT" />
        </QueryClientProvider>,
    );
}

describe('WorkflowActionBar', () => {
    it('renders only allowed actions as buttons', async () => {
        renderBar([
            action({ action_code: 'SUBMIT' }),
            action({ action_code: 'APPROVE', allowed: false }),
            action({ action_code: 'ARCHIVE', allowed: true, requires_reason: true }),
        ]);
        await waitFor(() => expect(screen.getByTestId('workflow-action-SUBMIT')).toBeDefined());
        expect(screen.getByTestId('workflow-action-ARCHIVE')).toBeDefined();
        // Unavailable transition is ABSENT from the DOM, not disabled.
        expect(screen.queryByTestId('workflow-action-APPROVE')).toBeNull();
    });

    it('shows an empty state when no actions are allowed', async () => {
        renderBar([action({ allowed: false }), action({ action_code: 'APPROVE', allowed: false })]);
        await waitFor(() => expect(screen.getByText('No workflow actions available')).toBeDefined());
    });

    it('keeps confirm disabled until the required reason is filled (RETURN)', async () => {
        renderBar([action({ action_code: 'RETURN', to_state: 'RETURNED', requires_reason: true })]);
        await waitFor(() => expect(screen.getByTestId('workflow-action-RETURN')).toBeDefined());

        fireEvent.click(screen.getByTestId('workflow-action-RETURN'));
        const confirm = screen.getByTestId('workflow-action-confirm') as HTMLButtonElement;
        expect(confirm.disabled).toBe(true);

        // Typing a reason enables the confirm button.
        const reason = screen.getByTestId('workflow-reason-input') as HTMLTextAreaElement;
        fireEvent.change(reason, { target: { value: 'geometry fixed' } });
        await waitFor(() => expect((screen.getByTestId('workflow-action-confirm') as HTMLButtonElement).disabled).toBe(false));
    });

    it('requires no prompt for actions without reason/comment (START_REVIEW)', async () => {
        renderBar([action({ action_code: 'START_REVIEW', to_state: 'UNDER_REVIEW' })]);
        await waitFor(() => expect(screen.getByTestId('workflow-action-START_REVIEW')).toBeDefined());

        fireEvent.click(screen.getByTestId('workflow-action-START_REVIEW'));
        expect((screen.getByTestId('workflow-action-confirm') as HTMLButtonElement).disabled).toBe(false);
    });

    it('selectableActions export filters disallowed rows', () => {
        expect(selectableActions([action({ allowed: false }), action({ action_code: 'VERIFY', allowed: true })]).map((a) => a.action_code)).toEqual(['VERIFY']);
    });
});
