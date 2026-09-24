import { useCallback, useState } from 'react';
import { useNavigate, useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { parcelApi } from '../api/parcelApi';
import { InformationTab } from '../components/InformationTab';
import { SurveyPlanTab } from '../components/SurveyPlanTab';
import { TechnicalDescriptionTab } from '../../survey/components/TechnicalDescriptionTab';
import { TiePointTab } from '../../survey/components/TiePointTab';
import { ParcelPreviewMap } from '../components/ParcelPreviewMap';
import { ParcelMeta } from '../components/badges';
import { Modal } from '../../../components/dialogs/Modal';
import { useUnsavedChangesGuard } from '../hooks/useUnsavedChangesGuard';
import { useAuth } from '../../../auth/useAuth';
import { hasPermission, permissions } from '../../../auth/permissions';

/**
 * TASK-071 — parcel editor shell. Tabbed editing per frontend.md §7 with a
 * persistent right-hand map preview and a sticky status/action bar that shows
 * the current status, provenance badge, version, workflow actions, and a
 * visible, explicit save state ("Saved 14:32" / "Unsaved changes").
 *
 * The active tab lives in the URL (`/parcels/:id/information`, …) so each tab
 * is independently loadable/bookmarkable. Draft edits live in this page's
 * state; a manual unsaved-changes guard (see useUnsavedChangesGuard) prompts
 * before leaving while dirty.
 */

export const PARCEL_TABS = [
    { key: 'information', label: 'Information' },
    { key: 'survey', label: 'Survey' },
    { key: 'title', label: 'Title' },
    { key: 'tiepoint', label: 'Tie point' },
    { key: 'techdesc', label: 'Technical description' },
    { key: 'computation', label: 'Computation' },
    { key: 'validation', label: 'Validation' },
    { key: 'documents', label: 'Documents' },
    { key: 'history', label: 'History' },
] as const;

export type ParcelTabKey = (typeof PARCEL_TABS)[number]['key'];

const SAVE_IDLE = 'idle' as const;
const SAVE_SAVING = 'saving' as const;
const SAVE_SAVED = 'saved' as const;
type SaveState = typeof SAVE_IDLE | typeof SAVE_SAVING | typeof SAVE_SAVED;

const SUBMIT_REASON = 'Submitted for review by editor';

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
    const canSubmit = me != null && hasPermission(me, permissions.parcelSubmit);

    const handleSaveFromBar = useCallback(() => {
        const form = document.getElementById('parcel-information-form') as HTMLFormElement | null;
        if (form) form.requestSubmit();
    }, []);

    const handleSubmitParcel = useCallback(async () => {
        if (!parcel) return;
        setSaveState(SAVE_SAVING);
        setConflictMessage(null);
        try {
            await parcelApi.update(id, { status: 'SUBMITTED', change_reason: SUBMIT_REASON }, parcel.version);
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
                setConflictMessage('Workflow action failed.');
            }
        }
    }, [id, parcel, refetch, nowLabel]);

    return (
        <div className="container-fluid py-4">
            <div className="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <div className="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h3 className="mb-0">{parcel?.parcel_code ?? 'Parcel'}</h3>
                        {parcel && <ParcelMeta parcel={parcel} />}
                    </div>
                    <span className="text-muted small">Parcel editor — switch tabs to edit each section.</span>
                </div>
                <div className="d-flex gap-2">
                    <Link to="/parcels" className="btn btn-outline-secondary btn-sm">Back to list</Link>
                </div>
            </div>

            {isLoading && <div className="alert alert-light border">Loading parcel…</div>}
            {isError && (
                <div className="alert alert-danger d-flex justify-content-between align-items-center">
                    <span>Could not load this parcel.</span>
                    <button className="btn btn-outline-danger btn-sm" onClick={() => void refetch()}>Retry</button>
                </div>
            )}

            {parcel && (
                <div className="row g-4">
                    <div className="col-lg-8">
                        <ul className="nav nav-tabs flex-wrap" role="tablist">
                            {PARCEL_TABS.map((t) => (
                                <li className="nav-item" key={t.key}>
                                    <Link
                                        to={`/parcels/${id}/${t.key}`}
                                        className={`nav-link ${activeTab === t.key ? 'active' : ''}`}
                                        data-testid={`parcel-tab-${t.key}`}
                                    >
                                        {t.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <div className="border border-top-0 rounded-bottom p-4 bg-white">
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
                                            patch[psgc] = /^\d{10,12}$/.test(v) ? v : null;
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
                            {activeTab === 'history' && <HistoryTab id={id} />}
                            {(activeTab === 'title' || activeTab === 'computation'
                                || activeTab === 'validation' || activeTab === 'documents') && (
                                <ComingSoon tabLabel={PARCEL_TABS.find((t) => t.key === activeTab)!.label} />
                            )}
                        </div>
                    </div>

                    <div className="col-lg-4">
                        <div className="card shadow-sm">
                            <div className="card-header py-2 d-flex justify-content-between align-items-center">
                                <span className="small fw-semibold">Map preview</span>
                                {parcel.geometry ? null : (
                                    <span className="badge bg-warning text-dark">no geometry</span>
                                )}
                            </div>
                            <div className="card-body p-0" style={{ height: 480 }}>
                                <ParcelPreviewMap parcel={parcel} />
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {parcel && (
                <div
                    className="position-sticky bottom-0 mt-4 bg-white border-top shadow-sm px-3 py-2 d-flex flex-wrap gap-3 align-items-center"
                    data-testid="parcel-status-bar"
                    style={{ zIndex: 1020 }}
                >
                    <span className="small text-muted">Status:</span>
                    <span data-testid="parcel-status-text">{parcel.status}</span>
                    <span className="small text-muted">Provenance:</span>
                    <span data-testid="parcel-provenance-text">{parcel.provenance}</span>
                    <span className="small text-muted">Version:</span>
                    <span data-testid="parcel-version-text">v{parcel.version}</span>
                    <span className="flex-grow-1" />
                    <span data-testid="parcel-save-state">
                        {saveState === SAVE_SAVING && <span className="text-muted">Saving…</span>}
                        {saveState === SAVE_SAVED && <span className="text-success fw-semibold">Saved {savedAt}</span>}
                        {saveState === SAVE_IDLE && dirty && <span className="text-warning fw-semibold">Unsaved changes</span>}
                        {saveState === SAVE_IDLE && !dirty && <span className="text-muted">No local changes</span>}
                    </span>
                    {canSubmit && activeTab === 'information' && !dirty && (
                        <button
                            className="btn btn-sm btn-primary"
                            onClick={() => void handleSubmitParcel()}
                            data-testid="parcel-submit-action"
                        >
                            Submit for review
                        </button>
                    )}
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

            {dirty && blockedHref && (
                <Modal open onClose={() => reset()} title="Unsaved changes">
                    <p className="mb-3">You have unsaved changes in this parcel. Leave without saving?</p>
                    <div className="d-flex justify-content-end gap-2">
                        <button className="btn btn-sm btn-outline-secondary" onClick={() => reset()}>Stay</button>
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
                    <p className="small text-muted mb-3">
                        Reload the parcel to pick up the latest version before saving again.
                    </p>
                    <div className="d-flex justify-content-end">
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
        <div className="py-5 text-center text-muted">
            <h5>{tabLabel}</h5>
            <p className="mb-0">This section arrives in a later phase-task.</p>
        </div>
    );
}

function HistoryTab({ id }: { id: string }) {
    const { data } = useQuery({
        queryKey: ['parcel', id, 'versions'],
        queryFn: () => parcelApi.listVersions(id),
        enabled: id.length > 0,
    });

    return (
        <div>
            <h6 className="text-muted mb-3">Version history</h6>
            {!data?.data?.length && <p className="text-muted">No revisions recorded yet.</p>}
            <ul className="list-group">
                {(data?.data ?? []).map((v) => (
                    <li key={v.version} className="list-group-item d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <div>
                            <div>
                                <span className="badge bg-light text-dark border me-2">v{v.version}</span>
                                <span className="small">{v.change_summary ?? 'Edit'}</span>
                            </div>
                            {v.change_reason && <div className="small text-muted">{v.change_reason}</div>}
                        </div>
                        <div className="text-end small text-muted">
                            <div>{v.status}</div>
                            {v.changed_at && <div>{new Date(v.changed_at).toLocaleString()}</div>}
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}