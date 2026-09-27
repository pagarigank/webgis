import type { ImportJob, ImportStatus } from '../api/importApi';

/**
 * TASK-128 — how far the operator may advance, derived from the job's status.
 *
 * Both TASK-128 acceptance criteria are enforced here rather than in the
 * components, so that hiding a control and permitting the action cannot drift
 * apart. The backend rejects an out-of-order call regardless; this stops the UI
 * from offering one in the first place.
 */

export const STEPS = ['upload', 'crs', 'mapping', 'preview', 'commit'] as const;
export type StepId = (typeof STEPS)[number];

export const STEP_LABELS: Record<StepId, string> = {
    upload: 'Upload',
    crs: 'CRS',
    mapping: 'Fields',
    preview: 'Review',
    commit: 'Commit',
};

/**
 * Commit is reachable only from VALIDATED.
 *
 * This is the "commit is unreachable until validation succeeds" rule. A job
 * that is UPLOADED, MAPPED, COMMITTED or CANCELLED yields -1, so the step
 * cannot be rendered, focused or reached by arrow keys.
 */
export function maxReachableStep(status: ImportStatus | null): number {
    switch (status) {
        case 'VALIDATED':
        case 'COMMITTED':
            return STEPS.indexOf('commit');
        case 'MAPPED':
            return STEPS.indexOf('preview');
        case 'UPLOADED':
            return STEPS.indexOf('crs');
        default:
            return -1;
    }
}

export function canOpenStep(step: StepId, job: ImportJob | null): boolean {
    // Upload is the entry point and needs no job. Everything after it is gated
    // on how far the server has actually got.
    if (step === 'upload') {
        return true;
    }
    return job !== null && STEPS.indexOf(step) <= maxReachableStep(job.status);
}

export function canCommit(job: ImportJob | null): boolean {
    return job !== null && job.status === 'VALIDATED';
}

/**
 * The CRS step is never skippable: mapping cannot be submitted without a
 * declared CRS, because the server has no default and will not guess one
 * (FR-252). `declaredCrs === null` therefore blocks the step regardless of the
 * status the server reports.
 */
export function canLeaveCrsStep(declaredCrs: string | null): boolean {
    return typeof declaredCrs === 'string' && declaredCrs.trim().length > 0;
}

export function isTerminal(status: ImportStatus | null): boolean {
    return status === 'COMMITTED' || status === 'CANCELLED';
}

/** Clamp a requested step to what the job allows, so a stale link cannot open it. */
export function clampStep(requested: StepId, job: ImportJob | null): StepId {
    if (canOpenStep(requested, job)) {
        return requested;
    }
    return 'upload';
}
