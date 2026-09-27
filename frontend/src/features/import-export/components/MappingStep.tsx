import React, { useEffect, useMemo, useState } from 'react';
import { importApi, type ImportJob, type ImportPreview } from '../api/importApi';
import { errorText } from '../errorText';
import { fieldApi } from '../../layers/api/fieldApi';

/**
 * TASK-128 step 3 — field mapping and the validation run.
 *
 * Validation is deliberately triggered from here, at the end of the step that
 * configures it, so the review step is reached only with results in hand. That
 * is what makes the commit step unreachable before validation rather than
 * merely disabled after it.
 *
 * The source columns come from the staged rows: the server exposes no column
 * list, and a file with no data rows still has to be reviewable, so this falls
 * back to the declared mapping's own keys.
 */
export const MappingStep: React.FC<{
    job: ImportJob;
    onValidated: (job: ImportJob) => void;
}> = ({ job, onValidated }) => {
    const [mapping, setMapping] = useState<Record<string, string>>({});
    const [sourceColumns, setSourceColumns] = useState<string[]>([]);
    const [targets, setTargets] = useState<Array<{ field_name: string; field_label?: string | null }>>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // A FEATURE import validates against a layer's attributes, so the target
    // field list is the layer's. Other targets have no layer to map onto.
    useEffect(() => {
        if (job.target_entity !== 'FEATURE' || job.target_layer_id == null) {
            setTargets([]);
            return;
        }
        let cancelled = false;
        fieldApi
            .getAll(job.target_layer_id)
            .then((fields) => {
                if (cancelled) return;
                // Every writable field is offered as a target. The schema has no
                // "system field" flag, and inventing one here would silently
                // hide a legitimate target; the backend rejects a mapping it
                // does not accept, with a reason the operator can act on.
                setTargets(fields.map((f) => ({ field_name: f.field_name, field_label: f.field_label })));
            })
            .catch(() => {
                if (!cancelled) setTargets([]);
            });
        return () => {
            cancelled = true;
        };
    }, [job.target_entity, job.target_layer_id]);

    useEffect(() => {
        if (!job.declared_crs) return;
        let cancelled = false;
        importApi
            .preview(job.id, 1, 50)
            .then((res: ImportPreview) => {
                if (cancelled) return;
                const names = new Set<string>();
                for (const row of res.rows) {
                    for (const key of Object.keys(row.values ?? {})) names.add(key);
                }
                setSourceColumns(Array.from(names));
            })
            .catch(() => {
                if (!cancelled) setSourceColumns([]);
            });
        return () => {
            cancelled = true;
        };
    }, [job.id, job.declared_crs]);

    const effectiveColumns = useMemo(
        () => (sourceColumns.length > 0 ? sourceColumns : Object.keys(job.field_mapping ?? {})),
        [sourceColumns, job.field_mapping],
    );

    const mapColumn = (source: string, target: string) => {
        setMapping((prev) => {
            const next = { ...prev, [source]: target };
            if (!target) delete next[source];
            return next;
        });
    };

    const saveAndValidate = async () => {
        setBusy(true);
        setError(null);
        try {
            if (Object.keys(mapping).length > 0) {
                await importApi.setMapping(job.id, {
                    declared_crs: job.declared_crs as string,
                    field_mapping: mapping,
                });
            }
            await importApi.validate(job.id);
            // Re-read rather than patching a local copy: the counts that gate the
            // commit step come from the server and must not be guessed here.
            onValidated(await importApi.get(job.id));
        } catch (err: any) {
            setError(errorText(err, 'Validation failed'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="mapping-step-heading" data-testid="step-mapping">
            <h2 id="mapping-step-heading">Field mapping</h2>
            <p>
                Map each column in the file onto a field of the target. Unmapped columns are ignored. Declared
                CRS: <strong>{job.declared_crs}</strong>.
            </p>

            {effectiveColumns.length === 0 ? (
                <p>No columns were detected in the file.</p>
            ) : (
                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <thead>
                        <tr>
                            <th scope="col">File column</th>
                            <th scope="col">Target field</th>
                        </tr>
                    </thead>
                    <tbody>
                        {effectiveColumns.map((col) => (
                            <tr key={col}>
                                <th scope="row">{col}</th>
                                <td>
                                    <select
                                        aria-label={`Target field for ${col}`}
                                        data-testid={`mapping-${col}`}
                                        value={mapping[col] ?? ''}
                                        onChange={(e) => mapColumn(col, e.target.value)}
                                        disabled={busy}
                                    >
                                        <option value="">Ignore this column</option>
                                        {targets.map((t) => (
                                            <option key={t.field_name} value={t.field_name}>
                                                {t.field_label ? `${t.field_label} (${t.field_name})` : t.field_name}
                                            </option>
                                        ))}
                                    </select>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {error ? <p role="alert">{error}</p> : null}

            <button type="button" data-testid="validate-button" onClick={saveAndValidate} disabled={busy}>
                {busy ? 'Validating…' : 'Validate rows'}
            </button>
        </section>
    );
};
