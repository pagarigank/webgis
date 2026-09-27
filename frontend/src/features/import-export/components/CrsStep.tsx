import React, { useEffect, useMemo, useState } from 'react';
import { importApi, type CrsOption, type ImportJob } from '../api/importApi';
import { errorText } from '../errorText';
import { canLeaveCrsStep } from './stepModel';

/**
 * TASK-128 step 2 — CRS declaration.
 *
 * The whole point of this step is that it cannot be passed over. The backend
 * stores a declared CRS and never infers one, so a file whose CRS the operator
 * cannot state must stop here rather than be placed on a guessed datum.
 *
 * The suggested CRS is displayed as a suggestion to confirm, never pre-selected.
 * Pre-selecting it would be exactly the silent inference the policy forbids, and
 * it would also make the step passable without the operator engaging.
 */
export const CrsStep: React.FC<{
    job: ImportJob;
    onDeclared: (job: ImportJob) => void;
}> = ({ job, onDeclared }) => {
    const [options, setOptions] = useState<CrsOption[]>([]);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [choice, setChoice] = useState<string>('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        importApi
            .crsList()
            .then((list) => {
                if (!cancelled) setOptions(list);
            })
            .catch((err: any) => {
                if (!cancelled) setLoadError(err?.message || 'Failed to load the CRS list');
            });
        return () => {
            cancelled = true;
        };
    }, []);

    const canSubmit = canLeaveCrsStep(choice) && !saving;

    // Historical datums are offered but marked: importing onto Luzon 1911 or
    // WGS 84 (transit) is legitimate for archival data and silently discouraged
    // for new work, so the choice stays available.
    const grouped = useMemo(
        () => ({
            current: options.filter((c) => !c.is_historical),
            historical: options.filter((c) => c.is_historical),
        }),
        [options],
    );

    const submit = async () => {
        if (!canLeaveCrsStep(choice)) return;
        setSaving(true);
        setError(null);
        try {
            const updated = await importApi.setMapping(job.id, { declared_crs: choice.trim() });
            onDeclared(updated);
        } catch (err: any) {
            setError(errorText(err, 'Failed to record the declared CRS'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <section aria-labelledby="crs-step-heading" data-testid="step-crs">
            <h2 id="crs-step-heading">Coordinate reference system</h2>
            <p>
                Every import states the CRS its coordinates are written in. Nothing is guessed, and this step
                cannot be skipped.
            </p>

            {job.suggested_crs ? (
                <p data-testid="crs-suggestion">
                    This file suggests <strong>{job.suggested_crs}</strong>. Confirm it only if that matches the
                    survey it came from.
                </p>
            ) : (
                <p>No CRS could be suggested from the file, so the declared value must be chosen by hand.</p>
            )}

            {loadError ? <p role="alert">Could not load the CRS list: {loadError}</p> : null}

            <label htmlFor="declared-crs">Declared CRS</label>
            <select
                id="declared-crs"
                data-testid="declared-crs"
                value={choice}
                onChange={(e) => setChoice(e.target.value)}
                disabled={saving}
            >
                <option value="">Select the CRS the file is written in…</option>
                <optgroup label="Current">
                    {grouped.current.map((c) => (
                        <option key={c.srid} value={c.code}>
                            {c.code} — {c.name}
                        </option>
                    ))}
                </optgroup>
                <optgroup label="Historical">
                    {grouped.historical.map((c) => (
                        <option key={c.srid} value={c.code}>
                            {c.code} — {c.name} (historical)
                        </option>
                    ))}
                </optgroup>
            </select>

            {error ? <p role="alert">{error}</p> : null}

            <button
                type="button"
                data-testid="crs-continue"
                onClick={submit}
                disabled={!canSubmit}
                title={canLeaveCrsStep(choice) ? undefined : 'Select a CRS to continue'}
            >
                {saving ? 'Saving…' : 'Continue to fields'}
            </button>
        </section>
    );
};
