import { useCallback, useState } from 'react';
import { useNavigate, useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { parcelApi } from '../api/parcelApi';
import { InformationTab } from '../components/InformationTab';
import { SurveyPlanTab } from '../components/SurveyPlanTab';
import { SplitTab } from '../components/SplitTab';
import { ConsolidationTab } from '../components/ConsolidationTab';
import { LineageTab } from '../components/LineageTab';
import { HistoryTab } from '../components/HistoryTab';
import { TechnicalDescriptionTab } from '../../survey/components/TechnicalDescriptionTab';
import { TiePointTab } from '../../survey/components/TiePointTab';
import { ComputationPanel } from '../../survey/components/ComputationPanel';
import { ValidationPanel } from '../../survey/components/ValidationPanel';
import { TitleTab } from '../components/TitleTab';
import { DocumentsTab } from '../components/DocumentsTab';
import { WorkflowActionBar } from '../components/WorkflowActionBar';
import { ParcelPreviewMap } from '../components/ParcelPreviewMap';
import { ParcelMeta } from '../components/badges';
import { Modal } from '../../../components/dialogs/Modal';
import { ErrorBoundary } from '../../../components/ErrorBoundary';
import { useUnsavedChangesGuard } from '../hooks/useUnsavedChangesGuard';
import { useAuth } from '../../../auth/useAuth';
import { hasPermission, permissions } from '../../../auth/permissions';

/**
 * TASK-071 — parcel editor shell. Tabbed editing per frontend.md §7 with a
 * persistent right-hand map preview and a sticky status/action bar.
 */

export const PARCEL_TABS = [
    { key: 'information', label: 'Info' },
    { key: 'survey',      label: 'Survey' },
    { key: 'title',       label: 'Title' },
    { key: 'tiepoint',   label: 'Tie Point' },
    { key: 'techdesc',   label: 'Tech Desc' },
    { key: 'computation',label: 'Computation' },
    { key: 'validation', label: 'Validation' },
    { key: 'split',      label: 'Split' },
    { key: 'consolidate',label: 'Consolidate' },
    { key: 'lineage',    label: 'Lineage' },
    { key: 'documents',  label: 'Documents' },
    { key: 'history',    label: 'History' },
] as const;

export type ParcelTabKey = (typeof PARCEL_TABS)[number]['key'];

const SAVE_IDLE    = 'idle'    as const;
const SAVE_SAVING  = 'saving'  as const;
const SAVE_SAVED   = 'saved'   as const;
type SaveState = typeof SAVE_IDLE | typeof SAVE_SAVING | typeof SAVE_SAVED;

/* ─── Spinner (inline keyframes via style tag) ──────────────────────── */
const Spinner: React.FC = () => (
    <div
        style={{
            width: 16,
            height: 16,
            border: '2px solid var(--blue-100)',
            borderTopColor: 'var(--blue-500)',
            borderRadius: '50%',
            animation: 'spin 0.7s linear infinite',
            flexShrink: 0,
        }}
    />
);

export function ParcelEditorPage() {
    const { id = '', tab = 'information' } = useParams();
    const navigate = useNavigate();
    const { me } = useAuth();

    const activeTab: ParcelTabKey = PARCEL_TABS.some((t) => t.key === tab)
        ? (tab as ParcelTabKey)
        : 'information';

    const { data: parcel, isLoading, isError, refetch } = useQuery({
        queryKey: ['parcel', id],
        queryFn: () => parcelApi.getById(id),
        enabled: id.length > 0,
    });

    const [saveState, setSaveState] = useState<SaveState>(SAVE_IDLE);
    const [savedAt, setSavedAt] = useState<string>('');
    const [formDirty, setFormDirty] = useState(false);
    const [conflictMessage, setConflictMessage] = useState<string | null>(null);

    const dirty = Boolean(formDirty);
    const { blockedHref, reset } = useUnsavedChangesGuard(dirty);

    const nowLabel = useCallback(() =>
        new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
    []);

    const handleDirtyChange = useCallback((dirtyFlag: boolean) => {
        setFormDirty(dirtyFlag);
        if (dirtyFlag && saveState === SAVE_SAVED) setSaveState(SAVE_IDLE);
    }, [saveState]);

    const canEdit = me != null && hasPermission(me, permissions.parcelUpdate);

    const handleSaveFromBar = useCallback(() => {
        const form = document.getElementById('parcel-information-form') as HTMLFormElement | null;
        if (form) form.requestSubmit();
    }, []);

    return (
        <div>
            {/* ── Page header ── */}
            <div className="page-header">
                <div style={{ minWidth: 0 }}>
                    <div className="flex items-center gap-2 mb-1 flex-wrap">
                        <h2 className="page-title" style={{ margin: 0 }}>
                            {parcel?.parcel_code ?? (isLoading ? 'Loading…' : 'Parcel')}
                        </h2>
                        {parcel && <ParcelMeta parcel={parcel} />}
                    </div>
                    <p className="page-subtitle">
                        Parcel editor — switch tabs below to edit each section.
                    </p>
                </div>
                <div className="flex gap-2 items-center" style={{ flexShrink: 0 }}>
                    <Link to="/parcels" className="btn btn-ghost btn-sm">← Back to list</Link>
                </div>
            </div>

            {isLoading && (
                <div className="alert alert-light" style={{ display: 'flex', alignItems: 'center', gap: '0.625rem' }}>
                    <Spinner />
                    Loading parcel…
                </div>
            )}
            {isError && (
                <div className="alert alert-danger" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <span>Could not load this parcel.</span>
                    <button className="btn btn-danger btn-sm" onClick={() => void refetch()}>Retry</button>
                </div>
            )}

            {parcel && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 340px', gap: '1.25rem', alignItems: 'start' }}>
                    {/* ── Left: tabs + content ── */}
                    <div>
                        {/* Tab bar */}
                        <div style={{
                            background: 'var(--bg-surface)',
                            border: '1px solid var(--border-color)',
                            borderBottom: 'none',
                            borderRadius: 'var(--radius-lg) var(--radius-lg) 0 0',
                            padding: '0.25rem 0.5rem 0',
                            display: 'flex',
                            gap: 0,
                            overflowX: 'auto',
                            scrollbarWidth: 'none',
                        }}>
                            {PARCEL_TABS.map((t) => (
                                <Link
                                    key={t.key}
                                    to={`/parcels/${id}/${t.key}`}
                                    className={`tab-item${activeTab === t.key ? ' active' : ''}`}
                                    data-testid={`parcel-tab-${t.key}`}
                                    style={{ fontSize: '0.8125rem', padding: '0.5rem 0.75rem' }}
                                >
                                    {t.label}
                                </Link>
                            ))}
                        </div>

                        {/* Tab content */}
                        <div style={{
                            background: 'var(--bg-surface)',
                            border: '1px solid var(--border-color)',
                            borderTop: 'none',
                            borderRadius: '0 0 var(--radius-lg) var(--radius-lg)',
                            padding: '1.5rem',
                        }}>
                            {/* key={activeTab} resets the boundary on tab switch, so a
                                crash in one tab does not follow the user to the next. */}
                            <ErrorBoundary
                                key={activeTab}
                                label={PARCEL_TABS.find((t) => t.key === activeTab)?.label ?? 'This tab'}
                            >
                            {activeTab === 'information' && (
                                <InformationTab
                                    parcel={parcel}
                                    editing={canEdit}
                                    onDirtyChange={handleDirtyChange}
                                    onSave={async (values) => {
                                        const SURVEY_DERIVED = new Set([
                                            'SURVEY_COORDINATES',
                                            'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
                                            'TRANSFORMED_FROM_HISTORICAL_SURVEY',
                                        ]);
                                        const surveyDerived = SURVEY_DERIVED.has(values.provenance);
                                        if (surveyDerived && values.justification.trim() === '') {
                                            setConflictMessage('Switching to survey-derived provenance requires a recorded justification.');
                                            setSaveState(SAVE_IDLE);
                                            return;
                                        }
                                        const patch: Record<string, unknown> = {
                                            lot_number: values.lot_number.trim() || null,
                                            block_number: values.block_number.trim() || null,
                                            title_number_ref: values.title_number_ref.trim() || null,
                                            tax_declaration_no: values.tax_declaration_no.trim() || null,
                                            source_area_sqm: values.source_area_sqm === '' ? null : Number(values.source_area_sqm),
                                            source_area_unit: values.source_area_unit,
                                            location_description: values.location_description.trim() || null,
                                            remarks: values.remarks.trim() || null,
                                            provenance: values.provenance,
                                        };
                                        if (values.justification.trim() !== '') {
                                            patch.change_reason = values.justification.trim();
                                        }
                                        for (const psgc of ['psgc_barangay', 'psgc_municipality', 'psgc_province'] as const) {
                                            const v = values[psgc].trim();
                                            patch[psgc] = /^\d{9,12}$/.test(v) ? v : null;
                                        }
                                        setSaveState(SAVE_SAVING);
                                        setConflictMessage(null);
                                        try {
                                            await parcelApi.update(id, patch, parcel.version);
                                            setFormDirty(false);
                                            setSaveState(SAVE_SAVED);
                                            setSavedAt(nowLabel());
                                            await refetch();
                                        } catch (err) {
                                            setSaveState(SAVE_IDLE);
                                            if (axios.isAxiosError(err)) {
                                                const data = err.response?.data as { success?: boolean; message?: string; detail?: string } | undefined;
                                                setConflictMessage(data?.detail ?? data?.message ?? err.message);
                                            } else {
                                                setConflictMessage('Save failed.');
                                            }
                                        }
                                    }}
                                />
                            )}
                            {activeTab === 'survey' && (
                                <SurveyPlanTab parcel={parcel} onUpdated={() => void refetch()} />
                            )}
                            {activeTab === 'tiepoint' && <TiePointTab parcelId={id} />}
                            {activeTab === 'techdesc' && <TechnicalDescriptionTab parcelId={id} />}
                            {activeTab === 'computation' && (
                                <ComputationPanel parcelId={id} parcel={parcel} onAccepted={() => void refetch()} />
                            )}
                            {activeTab === 'validation' && (
                                <ValidationPanel
                                    parcelId={id}
                                    parcel={parcel}
                                    onSubmitted={() => void refetch()}
                                    onNavigateTab={(targetTab) => {
                                        navigate(`/parcels/${id}/${targetTab}`);
                                    }}
                                />
                            )}
                            {activeTab === 'split'      && <SplitTab parcel={parcel} />}
                            {activeTab === 'consolidate' && <ConsolidationTab initialParcelId={id} />}
                            {activeTab === 'lineage'    && <LineageTab parcelId={id} parcelCode={parcel.parcel_code} />}
                            {activeTab === 'history'    && <HistoryTab parcelId={id} currentVersion={parcel?.version} />}
                            {activeTab === 'title' && <TitleTab parcelId={id} />}
                            {activeTab === 'documents' && <DocumentsTab parcelId={id} />}
                            </ErrorBoundary>
                        </div>
                    </div>

                    {/* ── Right: map preview ── */}
                    <div className="card" style={{ position: 'sticky', top: 'calc(var(--header-height) + 1rem)' }}>
                        <div className="card-header">
                            <span>Map Preview</span>
                            {!parcel.geometry && (
                                <span className="badge badge-yellow">No geometry</span>
                            )}
                        </div>
                        <div style={{ height: 440, borderRadius: '0 0 var(--radius-lg) var(--radius-lg)', overflow: 'hidden' }}>
                            <ParcelPreviewMap parcel={parcel} />
                        </div>
                    </div>
                </div>
            )}

            {/* ── Sticky status bar ── */}
            {parcel && (
                <div
                    className="status-bar"
                    data-testid="parcel-status-bar"
                    style={{ marginTop: '0.5rem' }}
                >
                    <div className="status-bar-item">
                        <span className="status-bar-label">Status</span>
                        <span
                            className={`status-badge status-${parcel.status}`}
                            data-testid="parcel-status-text"
                        >
                            {parcel.status.replace(/_/g, ' ')}
                        </span>
                    </div>

                    <div className="status-bar-item">
                        <span className="status-bar-label">Provenance</span>
                        <span
                            className="status-bar-value text-sm"
                            data-testid="parcel-provenance-text"
                            style={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                        >
                            {parcel.provenance?.replace(/_/g, ' ') ?? '—'}
                        </span>
                    </div>

                    <div className="status-bar-item">
                        <span className="status-bar-label">Version</span>
                        <span className="status-bar-value font-mono text-sm" data-testid="parcel-version-text">
                            v{parcel.version}
                        </span>
                    </div>

                    <div className="status-bar-spacer" />

                    <span data-testid="parcel-save-state" className="text-sm">
                        {saveState === SAVE_SAVING && <span className="text-muted">Saving…</span>}
                        {saveState === SAVE_SAVED  && <span className="text-success font-semibold">✓ Saved {savedAt}</span>}
                        {saveState === SAVE_IDLE && dirty && (
                            <span style={{ color: 'var(--brand-warning)', fontWeight: 600 }}>● Unsaved changes</span>
                        )}
                        {saveState === SAVE_IDLE && !dirty && (
                            <span className="text-muted">No local changes</span>
                        )}
                    </span>

                    {/* TASK-103 — workflow action bar */}
                    <WorkflowActionBar parcelId={id} status={parcel.status} disabled={dirty} />

                    {canEdit && activeTab === 'information' && dirty && (
                        <button
                            className="btn btn-sm btn-primary"
                            onClick={handleSaveFromBar}
                            data-testid="parcel-editor-save"
                        >
                            Save changes
                        </button>
                    )}
                </div>
            )}

            {/* ── Guards & dialogs ── */}
            {dirty && blockedHref && (
                <Modal open onClose={() => reset()} title="Unsaved changes">
                    <p className="mb-3">You have unsaved changes in this parcel. Leave without saving?</p>
                    <div className="flex justify-end gap-2">
                        <button className="btn btn-ghost btn-sm" onClick={() => reset()}>Stay</button>
                        <button
                            className="btn btn-sm btn-danger"
                            data-testid="parcel-guard-leave"
                            onClick={() => {
                                setFormDirty(false);
                                reset();
                                setSaveState(SAVE_IDLE);
                                navigate(blockedHref);
                            }}
                        >
                            Leave without saving
                        </button>
                    </div>
                </Modal>
            )}

            {conflictMessage && (
                <Modal open onClose={() => setConflictMessage(null)} title="Save failed">
                    <p className="mb-1">{conflictMessage}</p>
                    <p className="text-sm text-muted mb-3">
                        Reload the parcel to pick up the latest version before saving again.
                    </p>
                    <div className="flex justify-end">
                        <button
                            className="btn btn-sm btn-primary"
                            data-testid="parcel-conflict-reload"
                            onClick={() => { setConflictMessage(null); void refetch(); }}
                        >
                            Reload parcel
                        </button>
                    </div>
                </Modal>
            )}
        </div>
    );
}

function ComingSoon({ tabLabel }: { tabLabel: string }) {
    return (
        <div style={{ padding: '3rem 1rem', textAlign: 'center', color: 'var(--text-muted)' }}>
            <div style={{ fontSize: '2rem', marginBottom: '0.75rem' }}>🚧</div>
            <h5 style={{ margin: '0 0 0.375rem' }}>{tabLabel}</h5>
            <p style={{ margin: 0, fontSize: '0.875rem' }}>This section arrives in a later phase-task.</p>
        </div>
    );
}
