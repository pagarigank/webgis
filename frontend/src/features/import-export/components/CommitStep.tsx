import React, { useRef, useState } from 'react';
import { importApi, type ImportJob } from '../api/importApi';
import { errorText } from '../errorText';
import { canCommit, isTerminal } from './stepModel';

/**
 * TASK-128 step 5 — the explicit commit.
 *
 * The commit button is not merely disabled below VALIDATED: the step itself is
 * unreachable (see stepModel), so there is nothing to focus, click or activate
 * by keyboard before validation has produced a result. Offering a greyed-out
 * commit would let an operator assume the operation was queued.
 *
 * The Idempotency-Key is minted once per job and kept in a ref, so a retry after
 * a timeout reuses it and the server recognises the replay instead of inserting
 * the rows a second time.
 */
export const CommitStep: React.FC<{
    job: ImportJob;
    onChanged: (job: ImportJob) => void;
}> = ({ job, onChanged }) => {
    const [partial, setPartial] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<number | null>(null);

    // Minted once per job and never regenerated. A lazy ref rather than useMemo
    // because the value must be stable across renders without being recomputed
    // during one. The fallback is derived from the job id rather than the clock:
    // idempotency is scoped to the job, so a per-job key is exactly right and
    // keeps the render free of impure reads.
    const keyRef = useRef<string | null>(null);
    if (keyRef.current === null) {
        keyRef.current =
            typeof crypto !== 'undefined' && 'randomUUID' in crypto
                ? crypto.randomUUID()
                : `import-${job.id}`;
    }
    const key = keyRef.current;

    if (isTerminal(job.status)) {
        return (
            <section data-testid="step-commit">
                <h2>Import {job.status === 'COMMITTED' ? 'committed' : 'cancelled'}</h2>
                {job.status === 'COMMITTED' ? (
                    <p data-testid="commit-outcome">
                        {job.valid_rows} row{job.valid_rows === 1 ? '' : 's'} committed to{' '}
                        {job.target_entity === 'FEATURE' ? 'the layer' : job.target_entity}.
                    </p>
                ) : (
                    <p>Staged rows for this job were discarded and nothing was written.</p>
                )}
            </section>
        );
    }

    if (!canCommit(job)) {
        // Defensive: stepModel already makes this step unreachable in this
        // state, so reaching here means a caller rendered it directly.
        return (
            <section data-testid="step-commit">
                <h2>Commit</h2>
                <p role="alert">
                    This import has not been validated yet, so it cannot be committed. Run validation first.
                </p>
            </section>
        );
    }

    const commit = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await importApi.commit(job.id, partial, key);
            setResult(res.committed_rows);
            onChanged({ ...job, status: res.status, valid_rows: res.committed_rows });
        } catch (err: any) {
            setError(errorText(err, 'The commit failed'));
        } finally {
            setBusy(false);
        }
    };

    const wouldWrite = partial ? job.invalid_rows : job.total_rows;

    return (
        <section aria-labelledby="commit-step-heading" data-testid="step-commit">
            <h2 id="commit-step-heading">Commit</h2>

            <p data-testid="commit-summary">
                {job.valid_rows} of {job.total_rows} rows passed validation.
            </p>

            <label style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                <input
                    type="checkbox"
                    data-testid="commit-partial"
                    checked={partial}
                    onChange={(e) => setPartial(e.target.checked)}
                />
                Commit the valid rows and discard the rest
            </label>

            <p>
                {partial
                    ? `${wouldWrite} rows will be written; ${job.invalid_rows} will be discarded.`
                    : `${wouldWrite} rows must be written. The import will be refused while any row is invalid.`}
            </p>

            {error ? <p role="alert">{error}</p> : null}
            {result !== null ? <p data-testid="commit-result">{result} rows committed.</p> : null}

            <button type="button" data-testid="commit-button" onClick={commit} disabled={busy}>
                {busy ? 'Committing…' : 'Commit import'}
            </button>
        </section>
    );
};
