import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { parcelApi } from '../api/parcelApi';
import { useParcelSelection } from '../ParcelSelectionContext';
import { operationsApi, extractApiError } from '../api/operationsApi';
import type { ConsolidateBody, ConsolidationPayload, ValidationCheck, ValidationWarning } from '../api/operationsApi';
import { StatusBadge } from './badges';
import { ValidationBlock } from './ValidationBlock';
import { ConsolidationMap } from './ConsolidationMap';

/**
 * TASK-117 — consolidation tab (architecture.md §18.4, api.md §8.3).
 *
 * Multi-select parents by list/search, validation panel where blocking
 * failures name the offending parcels (the AC: "blocking failures highlight
 * the offending parcels on the map rather than showing a generic error" —
 * failures carry parent indexes, which the map renders), union preview with
 * area comparison, new-parcel form, commit with reason.
 */

export interface ConsolidationFormState {
    lotNumber: string;
    remarks: string;
    allowMultipart: boolean;
    reason: string;
}

export const initialConsolidationForm: ConsolidationFormState = {
    lotNumber: '',
    remarks: '',
    allowMultipart: false,
    reason: '',
};

/**
 * Build the POST /parcels/consolidate body. Parent ids are the CURRENT
 * selection order (first input seeds the new code server-side); versions come
 * from the fetched rows at preview/commit time.
 */
export function buildConsolidateBody(ids: string[], versions: Record<string, number>, s: ConsolidationFormState, forCommit: boolean): ConsolidateBody {
    return {
        parent_parcel_ids: ids,
        ...(forCommit ? { parent_versions: versions } : {}),
        new_parcel: { lot_number: s.lotNumber.trim() || null, remarks: s.remarks.trim() || null },
        allow_multipart: s.allowMultipart,
        reason: s.reason.trim() || undefined,
    };
}

/** Extract parent indexes (0-based) named in blocking failure messages ("#0 × #1"). */
export function offendingParents(failures: ValidationCheck[]): number[] {
    const out = new Set<number>();
    for (const f of failures) {
        for (const m of f.message.matchAll(/#(\d+)/g)) {
            out.add(Number(m[1]));
        }
    }
    return [...out].sort((a, b) => a - b);
}

/** The fields a parent row needs to render, preview, and version-check. */
export type ConsolidationParentRow = {
    id: string;
    parcel_code: string;
    status: string;
    geometry: GeoJSON.GeometryObject | null;
    version: number;
};

/**
 * TASK-104b - seed the parent set from parcels picked on the map.
 *
 * The map selection is inserted first and the tab's own parcel is appended if
 * it is not already in the set, so the first entry stays the primary parent
 * that the backend uses to seed the new parcel code. De-duplicated so a parcel
 * picked on the map and then opened directly is not consolidated with itself.
 */
export function seedParentSelection(mapIds: string[], initialParcelId?: string): string[] {
    const out: string[] = [];
    for (const id of mapIds) if (!out.includes(id)) out.push(id);
    if (initialParcelId && !out.includes(initialParcelId)) out.push(initialParcelId);
    return out;
}

/**
 * TASK-104b - build the parent rows and their If-Match versions.
 *
 * Parents picked on the map are not on the 25-row candidate page, so their rows
 * and versions must come from a direct fetch. Both sources are merged here, and
 * an id missing from BOTH is dropped rather than rendered as a broken parent.
 */
export function mergeParentRows(
    listed: readonly { id: string; parcel_code: string; status: string; geometry: GeoJSON.GeometryObject | null; version: number }[],
    fetched: readonly { id: string; parcel_code: string; status: string; geometry: GeoJSON.GeometryObject | null; version: number }[],
    selected: readonly string[],
): { rows: ConsolidationParentRow[]; versions: Record<string, number>; unresolved: string[] } {
    const byId = new Map<string, ConsolidationParentRow>();
    for (const p of [...listed, ...fetched]) byId.set(p.id, { ...p });

    const rows: ConsolidationParentRow[] = [];
    const versions: Record<string, number> = {};
    const unresolved: string[] = [];
    for (const id of selected) {
        const row = byId.get(id);
        if (!row) {
            unresolved.push(id);
            continue;
        }
        rows.push(row);
        if (row.version > 0) versions[id] = row.version;
    }
    return { rows, versions, unresolved };
}

export function canCommitConsolidation(preview: ConsolidationPayload | null): boolean {
    return preview !== null && preview.validation.passed;
}

interface ConsolidationTabProps {
    /** The parcel the tab is opened from — preselected as the first parent. */
    initialParcelId?: string;
}

export function ConsolidationTab({ initialParcelId }: ConsolidationTabProps) {
    const queryClient = useQueryClient();
    // TASK-104b: parcels multi-selected on the map seed the parent set. This
    // reads the context once at mount rather than continuously, so the user's
    // own checkbox edits are not overwritten by later map activity.
    const { selection: mapSelection } = useParcelSelection();
    const [selected, setSelected] = useState<string[]>(() => seedParentSelection(mapSelection.map((p) => p.id), initialParcelId));
    const [form, setForm] = useState<ConsolidationFormState>(initialConsolidationForm);
    const [preview, setPreview] = useState<ConsolidationPayload | null>(null);
    const [error, setError] = useState<{ code: string; message: string; failures: ValidationCheck[]; warnings: ValidationWarning[] } | null>(null);
    const [committed, setCommitted] = useState<ConsolidationPayload | null>(null);
    const [search, setSearch] = useState('');

    // Candidate parents: active parcels (server already hides SUPERSEDED/ARCHIVED by default).
    const { data: candidates } = useQuery({
        queryKey: ['parcels', 'consolidation-candidates', search],
        queryFn: () => parcelApi.list({ q: search || undefined, limit: 25 }),
    });

    const candidatesById = useMemo(() => {
        const map = new Map<string, { id: string; parcel_code: string; status: string; geometry: GeoJSON.GeometryObject | null }>();
        for (const p of candidates?.data ?? []) {
            map.set(p.id, { id: p.id, parcel_code: p.parcel_code, status: p.status, geometry: p.geometry });
        }
        return map;
    }, [candidates]);

    // Parents picked on the map are not on the 25-row candidate page, so their
    // row and version have to be fetched directly. Without this the commit body
    // would carry an incomplete `parent_versions` map and the backend would
    // reject the request with 428/409 even though the preview passed.
    const missingIds = useMemo(
        () => selected.filter((id) => !candidatesById.has(id)),
        [selected, candidatesById],
    );

    const { data: fetchedParents } = useQuery({
        queryKey: ['parcels', 'consolidation-parents', missingIds],
        queryFn: () => Promise.all(missingIds.map((id) => parcelApi.getById(id))),
        enabled: missingIds.length > 0,
    });

    // Live rows for the selected parents (rendering) and their versions
    // (If-Match material for commit).
    const { rows: selectedRows, versions: versionMap, unresolved } = useMemo(
        () => mergeParentRows(candidates?.data ?? [], fetchedParents ?? [], selected),
        [candidates, fetchedParents, selected],
    );

    /** Every parent must carry a version before commit is trustworthy. */
    const versionsResolved = selected.length > 0 && unresolved.length === 0 && selected.every((id) => (versionMap[id] ?? 0) > 0);

    const previewMutation = useMutation({
        mutationFn: () => operationsApi.consolidatePreview(buildConsolidateBody(selected, versionMap, form, false)),
        onSuccess: (data) => {
            setError(null);
            setPreview(data);
        },
        onError: (err) => setError(extractApiError(err)),
    });

    const commitMutation = useMutation({
        mutationFn: () => operationsApi.consolidateCommit(buildConsolidateBody(selected, versionMap, form, true), `consolidation-${selected.join('-')}`),
        onSuccess: (data) => {
            setError(null);
            setCommitted(data);
            setPreview(null);
            void queryClient.invalidateQueries({ queryKey: ['parcels'] });
            void queryClient.invalidateQueries({ queryKey: ['parcel'] });
        },
        onError: (err) => setError(extractApiError(err)),
    });

    const offending = error !== null ? offendingParents(error.failures) : preview && !preview.validation.passed ? offendingParents(preview.validation.checks.filter((c) => c.status === 'fail')) : [];

    if (committed) {
        return (
            <div className="text-center py-5" data-testid="consolidation-success">
                <h5 className="text-success mb-3">Consolidation committed</h5>
                <p className="text-muted">
                    New parcel <strong>{committed.result.parcel_code}</strong> ({committed.result.area_sqm?.toLocaleString()} m²) —{' '}
                    {committed.parents_after.length} parents superseded. Operation #{committed.operation_id}.
                </p>
            </div>
        );
    }

    return (
        <div data-testid="consolidation-tab">
            <div className="row g-4">
                <div className="col-lg-5">
                    <label className="form-label" htmlFor="consolidation-search">
                        Find parcels
                    </label>
                    <input
                        id="consolidation-search"
                        data-testid="consolidation-search"
                        className="form-input mb-2"
                        placeholder="Search code, lot, location…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <ul className="list-group mb-3" style={{ maxHeight: 260, overflowY: 'auto' }} data-testid="consolidation-candidates">
                        {(candidates?.data ?? []).map((p) => (
                            <li key={p.id} className="list-group-item py-2">
                                <div className="form-check">
                                    <input
                                        className="form-check-input"
                                        type="checkbox"
                                        id={`cand-${p.id}`}
                                        checked={selected.includes(p.id)}
                                        onChange={(e) => {
                                            setPreview(null);
                                            setSelected((s) => (e.target.checked ? [...s, p.id] : s.filter((x) => x !== p.id)));
                                        }}
                                    />
                                    <label className="form-check-label small" htmlFor={`cand-${p.id}`}>
                                        <strong>{p.parcel_code}</strong> {p.lot_number != null && <span className="text-muted">· lot {p.lot_number}</span>}
                                    </label>
                                </div>
                            </li>
                        ))}
                        {(candidates?.data ?? []).length === 0 && <li className="list-group-item small text-muted">No parcels found.</li>}
                    </ul>

                    <p className="small text-muted" data-testid="consolidation-selection-count">
                        {selected.length} selected — first input seeds the new parcel code.
                    </p>

                    {selected.length > 0 && (
                        <ul className="small mb-3" data-testid="consolidation-picked-parents">
                            {selected.map((id, i) => {
                                const row = selectedRows.find((r) => r.id === id);
                                return (
                                    <li key={id}>
                                        <span className="badge bg-light text-dark border me-1">#{i}</span>{' '}
                                        <span className="font-mono">{row?.parcel_code ?? id}</span>{' '}
                                        <span className="text-muted">
                                            {row ? row.status : 'loading…'} · v{versionMap[id] ?? '—'}
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {selected.length > 0 && !versionsResolved && (
                        <p className="small text-danger" data-testid="consolidation-versions-pending">
                            Still loading the current version of every parent — commit unlocks once they resolve.
                        </p>
                    )}

                    <div className="mb-2">
                        <label className="form-label" htmlFor="consolidation-lot">
                            New parcel lot number
                        </label>
                        <input id="consolidation-lot" data-testid="consolidation-lot" className="form-input" value={form.lotNumber} onChange={(e) => setForm((f) => ({ ...f, lotNumber: e.target.value }))} />
                    </div>
                    <div className="mb-2">
                        <label className="form-label" htmlFor="consolidation-remarks">
                            New parcel remarks
                        </label>
                        <input id="consolidation-remarks" data-testid="consolidation-remarks" className="form-input" value={form.remarks} onChange={(e) => setForm((f) => ({ ...f, remarks: e.target.value }))} />
                    </div>
                    <div className="form-check mb-2">
                        <input
                            className="form-check-input"
                            type="checkbox"
                            id="consolidation-multipart"
                            data-testid="consolidation-multipart"
                            checked={form.allowMultipart}
                            onChange={(e) => setForm((f) => ({ ...f, allowMultipart: e.target.checked }))}
                        />
                        <label className="form-check-label" htmlFor="consolidation-multipart">
                            Allow multipart union (non-contiguous consolidation)
                        </label>
                    </div>
                    <div className="mb-2">
                        <label className="form-label" htmlFor="consolidation-reason">
                            Reason (required for commit)
                        </label>
                        <input id="consolidation-reason" data-testid="consolidation-reason" className="form-input" value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} />
                    </div>

                    <button
                        type="button"
                        className="btn btn-secondary btn-sm me-2"
                        data-testid="consolidation-preview"
                        disabled={selected.length < 2 || previewMutation.isPending}
                        onClick={() => previewMutation.mutate()}
                    >
                        {previewMutation.isPending ? 'Validating…' : 'Preview consolidation'}
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        data-testid="consolidation-commit"
                        disabled={!canCommitConsolidation(preview) || form.reason.trim() === '' || commitMutation.isPending || !versionsResolved}
                        onClick={() => commitMutation.mutate()}
                    >
                        Commit consolidation
                    </button>
                    {preview && !preview.validation.passed && (
                        <p className="small text-muted mt-1" data-testid="consolidation-commit-gate">
                            Commit unlocks only from a passing preview.
                        </p>
                    )}
                </div>

                <div className="col-lg-7">
                    <ConsolidationMap parcels={selectedRows} offendingIndexes={offending} />
                    {preview && (
                        <div className="mt-3" data-testid="consolidation-preview-panel">
                            <h6>Union preview</h6>
                            <p className="small mb-1">
                                New parcel: <strong>{preview.result.parcel_code ?? '(generated at commit)'}</strong> · union area{' '}
                                {preview.result.area_sqm?.toLocaleString() ?? '—'} m²
                            </p>
                            <p className="small text-muted" data-testid="consolidation-reconciliation">
                                Σ parents {preview.area_reconciliation.parents_sum_sqm.toLocaleString()} m² vs union {preview.area_reconciliation.union_sqm?.toLocaleString() ?? '—'} m² (difference {preview.area_reconciliation.difference_sqm.toLocaleString()} m²).
                            </p>
                            <ul className="list-unstyled small mb-2">
                                {preview.parents_after.map((p, i) => (
                                    <li key={p.id}>
                                        <span className="badge bg-light text-dark border me-1">#{i}</span>
                                        <strong>{p.parcel_code}</strong> → <StatusBadge status={p.status} />
                                    </li>
                                ))}
                            </ul>
                            <ValidationBlock checks={preview.validation.checks} warnings={preview.validation.warnings} />
                        </div>
                    )}
                </div>
            </div>

            {error && (
                <div className="alert alert-danger mt-3" data-testid="consolidation-error">
                    <strong>{error.code}:</strong> {error.message}
                    {error.failures.length > 0 && (
                        <ul className="mb-0 mt-2 small">
                            {error.failures.map((f, i) => (
                                <li key={i}>
                                    <span className="badge bg-danger me-1">{f.rule}</span>
                                    {f.message}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
