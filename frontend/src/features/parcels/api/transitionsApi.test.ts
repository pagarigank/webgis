import { describe, it, expect } from 'vitest';
import { buildTransitionPayload } from './transitionsApi';
import type { WorkflowAction } from './transitionsApi';

/**
 * TASK-103 — pure-logic tests for the workflow action bar's payload builder
 * and allowed-action filtering (FR-137 reason/comment rules, FR-103).
 */
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

describe('buildTransitionPayload', () => {
    it('passes the action through when nothing is required', () => {
        expect(buildTransitionPayload(action(), {})).toEqual({ action: 'SUBMIT' });
    });

    it('trims and omits blank optional reason/comment', () => {
        expect(buildTransitionPayload(action(), { reason: '  ', comment: '' })).toEqual({ action: 'SUBMIT' });
    });

    it('includes trimmed reason/comment when provided', () => {
        expect(buildTransitionPayload(action(), { reason: ' ready ', comment: ' ok ' })).toEqual({
            action: 'SUBMIT',
            reason: 'ready',
            comment: 'ok',
        });
    });

    it('throws before sending when a required reason is missing', () => {
        const ret = action({ action_code: 'RETURN', requires_reason: true });
        expect(() => buildTransitionPayload(ret, {})).toThrow(/reason is required/i);
    });

    it('throws when a required reason is only whitespace', () => {
        const ret = action({ action_code: 'RETURN', requires_reason: true });
        expect(() => buildTransitionPayload(ret, { reason: '   ' })).toThrow(/reason is required/i);
    });

    it('sends when a required reason is present', () => {
        const ret = action({ action_code: 'RETURN', requires_reason: true });
        expect(buildTransitionPayload(ret, { reason: 'fix geometry' })).toEqual({
            action: 'RETURN',
            reason: 'fix geometry',
        });
    });

    it('throws before sending when a required comment is missing (APPROVE)', () => {
        const approve = action({ action_code: 'APPROVE', requires_comment: true });
        expect(() => buildTransitionPayload(approve, {})).toThrow(/comment is required/i);
    });

    it('sends when both reason and comment requirements are satisfied', () => {
        const approve = action({ action_code: 'APPROVE', requires_comment: true, requires_reason: true });
        expect(buildTransitionPayload(approve, { reason: 'r', comment: 'c' })).toEqual({
            action: 'APPROVE',
            reason: 'r',
            comment: 'c',
        });
    });
});

