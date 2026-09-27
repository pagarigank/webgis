import React, { useCallback, useEffect, useState } from 'react';
import { importApi, type ImportJob, type TargetEntity } from '../api/importApi';
import { layerApi } from '../../layers/api/layerApi';
import { errorText } from '../errorText';
import { CrsStep } from './CrsStep';
import { MappingStep } from './MappingStep';
import { PreviewStep } from './PreviewStep';
import { CommitStep } from './CommitStep';
import { STEPS, STEP_LABELS, canOpenStep, clampStep, isTerminal, type StepId } from './stepModel';

/**
 * TASK-128 — the import wizard.
 *
 * The step list is rendered from STEPS but a step is only clickable when
 * canOpenStep allows it, and the rendered body is chosen from the same
 * predicate. Navigation state is therefore never ahead of the server's status:
 * a user who edits the URL or clicks a forward step lands on 'upload' rather
 * than on a step that has no data behind it.
 */
export const ImportWizard: React.FC = () => {
    const [job, setJob] = useState<ImportJob | null>(null);
    const [requested, setRequested] = useState<StepId>('upload');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Derived, not synchronised. Clamping here means a status change narrows the
    // step on the very next render, with no effect and no cascading render, and
    // the rendered step can never be one the job does not permit.
    const step = clampStep(requested, job);

    const goto = useCallback(
        (next: StepId) => {
            if (canOpenStep(next, job)) setRequested(next);
        },
        [job],
    );

    const cancel = async () => {
        if (!job) return;
        setBusy(true);
        try {
            await importApi.cancel(job.id);
            setJob(null);
            setRequested('upload');
        } catch (err: any) {
            setError(errorText(err, 'Failed to cancel the import'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div style={{ padding: '24px', maxWidth: '1100px', margin: '0 auto' }}>
            <h1>Import data</h1>

            <nav aria-label="Import steps">
                <ol style={{ display: 'flex', gap: '8px', listStyle: 'none', padding: 0 }}>
                    {STEPS.map((id, index) => {
                        const open = canOpenStep(id, job);
                        const current = step === id;
                        return (
                            <li key={id}>
                                <button
                                    type="button"
                                    data-testid={`step-tab-${id}`}
                                    data-state={current ? 'current' : open ? 'open' : 'locked'}
                                    aria-current={current ? 'step' : undefined}
                                    onClick={() => goto(id)}
                                    disabled={!open || current}
                                >
                                    {index + 1}. {STEP_LABELS[id]}
                                </button>
                            </li>
                        );
                    })}
                </ol>
            </nav>

            {error ? <p role="alert">{error}</p> : null}

            {step === 'upload' ? (
                <UploadStep
                    busy={busy}
                    onStarted={(created) => {
                        setJob(created);
                        setRequested('crs');
                    }}
                    onError={setError}
                    setBusy={setBusy}
                />
            ) : null}

            {/* Declaring the CRS returns the job in MAPPED; store it, because the
                step ceiling is derived from status, then advance to field mapping. */}
            {step === 'crs' && job ? (
                <CrsStep
                    job={job}
                    onDeclared={(mapped) => {
                        setJob(mapped);
                        setRequested('mapping');
                    }}
                />
            ) : null}

            {/* Validation returns the job in VALIDATED. Storing that job is what
                unlocks the commit step, and it carries the row counts the preview
                renders - keeping the MAPPED snapshot here would show stale zeros
                and keep commit locked forever. */}
            {step === 'mapping' && job ? (
                <MappingStep
                    job={job}
                    onValidated={(validated) => {
                        setJob(validated);
                        setRequested('preview');
                    }}
                />
            ) : null}

            {step === 'preview' && job ? <PreviewStep job={job} /> : null}

            {step === 'commit' && job ? <CommitStep job={job} onChanged={setJob} /> : null}

            <div style={{ marginTop: '24px', display: 'flex', gap: '8px' }}>
                {job && !isTerminal(job.status) ? (
                    <button type="button" data-testid="cancel-import" onClick={cancel} disabled={busy}>
                        Cancel import
                    </button>
                ) : null}
                {step !== 'upload' && job && !isTerminal(job.status) ? (
                    <button
                        type="button"
                        data-testid="back-button"
                        onClick={() => {
                            const i = STEPS.indexOf(step);
                            if (i > 0) goto(STEPS[i - 1]);
                        }}
                    >
                        Back
                    </button>
                ) : null}
            </div>
        </div>
    );
};

const UploadStep: React.FC<{
    busy: boolean;
    onStarted: (job: ImportJob) => void;
    onError: (message: string) => void;
    setBusy: (b: boolean) => void;
}> = ({ busy, onStarted, onError, setBusy }) => {
    const [file, setFile] = useState<File | null>(null);
    const [entity, setEntity] = useState<TargetEntity>('FEATURE');
    const [layers, setLayers] = useState<Array<{ id: number; name: string; geometry_type?: string }>>([]);
    const [layerId, setLayerId] = useState<number | ''>('');

    useEffect(() => {
        let cancelled = false;
        layerApi
            .getAll()
            .then((list: any) => {
                if (!cancelled) setLayers(Array.isArray(list) ? list : (list?.data ?? []));
            })
            .catch(() => {
                if (!cancelled) setLayers([]);
            });
        return () => {
            cancelled = true;
        };
    }, []);

    const submit = async () => {
        if (!file) return;
        setBusy(true);
        onError('');
        try {
            const created = await importApi.create({
                file,
                target_entity: entity,
                target_layer_id: entity === 'FEATURE' ? Number(layerId) || null : null,
            });
            onStarted(created);
        } catch (err: any) {
            onError(errorText(err, 'The upload failed'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="upload-step-heading" data-testid="step-upload">
            <h2 id="upload-step-heading">Choose a file</h2>

            <label htmlFor="import-file">File</label>
            <input
                id="import-file"
                data-testid="import-file"
                type="file"
                accept=".csv,.geojson,.json,.zip,.shp,.dxf"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                disabled={busy}
            />

            <label htmlFor="target-entity">Import into</label>
            <select
                id="target-entity"
                data-testid="target-entity"
                value={entity}
                onChange={(e) => setEntity(e.target.value as TargetEntity)}
                disabled={busy}
            >
                <option value="FEATURE">Layer features</option>
                <option value="PARCEL">Parcels</option>
                <option value="CONTROL_POINT">Survey control points</option>
            </select>

            {entity === 'FEATURE' ? (
                <>
                    <label htmlFor="target-layer">Target layer</label>
                    <select
                        id="target-layer"
                        data-testid="target-layer"
                        value={layerId}
                        onChange={(e) => setLayerId(e.target.value === '' ? '' : Number(e.target.value))}
                        disabled={busy}
                    >
                        <option value="">Select a layer…</option>
                        {layers.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name}
                            </option>
                        ))}
                    </select>
                </>
            ) : null}

            <button
                type="button"
                data-testid="start-import"
                onClick={submit}
                disabled={busy || !file || (entity === 'FEATURE' && !layerId)}
            >
                {busy ? 'Uploading…' : 'Start import'}
            </button>
        </section>
    );
};
