import { useMemo, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { operationsApi, extractApiError, SPLIT_METHODS, GEOMETRY_CHILD_METHODS } from '../api/operationsApi';
import type { SplitBody, SplitPayload, ValidationCheck, ValidationWarning } from '../api/operationsApi';
import type { Parcel } from '../types';
import { SplitLineMap } from './SplitLineMap';
import { ValidationBlock } from './ValidationBlock';

/**
 * TASK-116 — split tab (architecture.md §18.3, api.md §8.2).
 *
 * AC: commit is enabled ONLY from a successful preview of the CURRENT inputs;
 * editing anything invalidates the preview. The gating logic lives in pure
 * helpers (canCommitSplit/previewStateKey) so the contract is unit-testable
 * without DOM. The split line is drawn on an interactive map with snapping
 * to the parcel's vertices (default: the bbox midline).
 */

export interface SplitFormState {
    method: string;
    /** Vertical offset (°) from the bbox midline; 0 = snapped to the midline. */
    midlineOffsetDeg: number;
    lotA: string;
    lotB: string;
    /** Technical-description ids for the TECHNICAL_DESCRIPTION method. */
    tdA: string;
    tdB: string;
    reason: string;
}

export const initialSplitForm: SplitFormState = {
    method: 'MAP_SPLIT_LINE',
    midlineOffsetDeg: 0,
    lotA: '',
    lotB: '',
    tdA: '',
    tdB: '',
    reason: '',
};

/** Exterior ring coordinates of a Polygon/MultiPolygon geometry. */
export function ringOf(geometry: GeoJSON.GeometryObject | null): [number, number][] {
    if (!geometry) return [];
    if (geometry.type === 'Polygon') return geometry.coordinates[0] as [number, number][];
    if (geometry.type === 'MultiPolygon') return geometry.coordinates[0][0] as [number, number][];
    return [];
}

/**
 * The default split line: the parcel bbox midline (vertical), extended past
 * the bbox so the cut always crosses the polygon, offset by midlineOffsetDeg.
 * Snapping to an exact vertex is the map's job; this pure function stays the
 * source of truth for what is sent.
 */
export function buildSplitLine(parcel: Parcel, offsetDeg: number): [number, number][] {
    const coords = ringOf(parcel.geometry);
    if (coords.length === 0) {
        return [
            [0, -0.001],
            [0, 0.001],
        ];
    }
    let minLng = Infinity;
    let minLat = Infinity;
    let maxLng = -Infinity;
    let maxLat = -Infinity;
    for (const [lng, lat] of coords) {
        if (lng < minLng) minLng = lng;
        if (lat < minLat) minLat = lat;
        if (lng > maxLng) maxLng = lng;
        if (lat > maxLat) maxLat = lat;
    }
    const mid = (minLng + maxLng) / 2 + offsetDeg;
    const pad = Math.max((maxLat - minLat) * 0.05, 0.0005);
    return [
        [mid, minLat - pad],
        [mid, maxLat + pad],
    ];
}

/** Build the POST /split body from the form state (unit-tested). */
export function buildSplitBody(parcel: Parcel, s: SplitFormState, lineCoords: [number, number][] | null): SplitBody {
    const body: SplitBody = {
        method: s.method,
        children: [{ lot_number: s.lotA.trim() || undefined }, { lot_number: s.lotB.trim() || undefined }],
        reason: s.reason.trim() || undefined,
    };
    if (s.method === 'MAP_SPLIT_LINE') {
        body.split_line = { type: 'LineString', coordinates: lineCoords ?? buildSplitLine(parcel, s.midlineOffsetDeg) };
    } else if (GEOMETRY_CHILD_METHODS.has(s.method)) {
        // Children carry pasted GeoJSON (survey/import outputs).
        const parsedA = tryParseGeometry(s.lotA);
        const parsedB = tryParseGeometry(s.tdB);
        body.children = [
            { ...(parsedA ? { geometry: parsedA } : { lot_number: s.lotA.trim() || undefined }) },
            { ...(parsedB ? { geometry: parsedB } : { lot_number: s.tdB.trim() || undefined }) },
        ];
    } else if (s.method === 'TECHNICAL_DESCRIPTION') {
        body.children = [
            { ...(s.tdA.trim() !== '' ? { technical_description_id: Number(s.tdA) } : {}), lot_number: s.lotA.trim() || undefined },
            { ...(s.tdB.trim() !== '' ? { technical_description_id: Number(s.tdB) } : {}), lot_number: s.lotB.trim() || undefined },
        ];
    }
    return body;
}

function tryParseGeometry(raw: string): GeoJSON.GeometryObject | null {
    if (!raw.trim().startsWith('{')) return null;
    try {
        const parsed = JSON.parse(raw) as GeoJSON.GeometryObject;
        return parsed && typeof parsed.type === 'string' ? parsed : null;
    } catch {
        return null;
    }
}

/** Stable fingerprint of the form state — the preview-invalidation key. */
export function previewStateKey(s: SplitFormState, lineCoords: [number, number][] | null): string {
    return JSON.stringify({ s, lineCoords });
}

/**
 * AC gate: commit is enabled only from a successful preview whose key still
 * matches the current inputs (editing invalidates the preview).
 */
export function canCommitSplit(preview: SplitPayload | null, previewKey: string, currentKey: string): boolean {
    return preview !== null && preview.validation.passed && previewKey === currentKey;
}

interface SplitTabProps {
    parcel: Parcel;
}

export function SplitTab({ parcel }: SplitTabProps) {
    const queryClient = useQueryClient();
    const [form, setForm] = useState<SplitFormState>(initialSplitForm);
    const [lineCoords, setLineCoords] = useState<[number, number][] | null>(null);
    const [preview, setPreview] = useState<SplitPayload | null>(null);
    const [previewKey, setPreviewKey] = useState('');
    const [error, setError] = useState<{ code: string; message: string; failures: ValidationCheck[]; warnings: ValidationWarning[] } | null>(null);
    const [committed, setCommitted] = useState<SplitPayload | null>(null);

    const currentKey = previewStateKey(form, lineCoords);
    const hasGeometry = parcel.geometry != null;

    const set = <K extends keyof SplitFormState>(key: K, value: SplitFormState[K]) => {
        setForm((f) => ({ ...f, [key]: value }));
    };

    const previewMutation = useMutation({
        mutationFn: () => operationsApi.splitPreview(parcel.id, buildSplitBody(parcel, form, lineCoords)),
        onSuccess: (data) => {
            setError(null);
            setPreview(data);
            setPreviewKey(previewStateKey(form, lineCoords));
        },
        onError: (err) => setError(extractApiError(err)),
    });

    const commitMutation = useMutation({
        mutationFn: (payload: { body: SplitBody; key: string }) =>
            operationsApi.splitCommit(parcel.id, payload.body, parcel.version, `split-${parcel.id}-${payload.key}`),
        onSuccess: (data) => {
            setError(null);
            setCommitted(data);
            setPreview(null);
            void queryClient.invalidateQueries({ queryKey: ['parcel', parcel.id] });
            void queryClient.invalidateQueries({ queryKey: ['parcels'] });
        },
        onError: (err) => setError(extractApiError(err)),
    });

    const commitEnabled = useMemo(
        () => canCommitSplit(preview, previewKey, currentKey) && !commitMutation.isPending,
        [preview, previewKey, currentKey, commitMutation.isPending],
    );

    if (committed) {
        return (
            <div className="text-center py-5" data-testid="split-success">
                <h5 className="text-success mb-3">Split committed</h5>
                <p className="text-muted">
                    Operation #{committed.operation_id} — {committed.children.length} children created from{' '}
                    <strong>{parcel.parcel_code}</strong> (now SUPERSEDED).
                </p>
                <ul className="list-group mx-auto" style={{ maxWidth: 480 }}>
                    {committed.children.map((c) => (
                        <li key={c.temp_id} className="list-group-item d-flex justify-content-between">
                            <span>{c.lot_number ?? c.temp_id}</span>
                            <span className="small text-muted">
                                {c.parcel_id ? <>{c.parcel_id.slice(0, 8)}…</> : '—'}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        );
    }

    return (
        <div data-testid="split-tab">
            <div className="row g-4">
                <div className="col-lg-5">
                    <div className="mb-3">
                        <label className="form-label small fw-semibold" htmlFor="split-method">
                            Method
                        </label>
                        <select
                            id="split-method"
                            className="form-select"
                            data-testid="split-method"
                            value={form.method}
                            onChange={(e) => set('method', e.target.value)}
                        >
                            {SPLIT_METHODS.map((m) => (
                                <option key={m.value} value={m.value}>
                                    {m.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {form.method === 'MAP_SPLIT_LINE' && (
                        <>
                            <label className="form-label small fw-semibold" htmlFor="split-offset">
                                Midline offset (°, snapped to the bbox midline at 0)
                            </label>
                            <input
                                id="split-offset"
                                type="number"
                                step="0.0001"
                                className="form-control form-control-sm"
                                data-testid="split-offset"
                                value={form.midlineOffsetDeg}
                                onChange={(e) => set('midlineOffsetDeg', Number(e.target.value))}
                            />
                            <p className="small text-muted mt-1">
                                Draw on the map: the first two clicks set the line; clicking near a parcel vertex snaps to it.
                            </p>
                        </>
                    )}

                    {form.method === 'TECHNICAL_DESCRIPTION' && (
                        <div className="row g-2 mb-3">
                            <div className="col-6">
                                <label className="form-label small" htmlFor="split-td-a">
                                    Child 1 TD id
                                </label>
                                <input id="split-td-a" data-testid="split-td-a" className="form-control form-control-sm" value={form.tdA} onChange={(e) => set('tdA', e.target.value)} />
                            </div>
                            <div className="col-6">
                                <label className="form-label small" htmlFor="split-td-b">
                                    Child 2 TD id
                                </label>
                                <input id="split-td-b" data-testid="split-td-b" className="form-control form-control-sm" value={form.tdB} onChange={(e) => set('tdB', e.target.value)} />
                            </div>
                        </div>
                    )}

                    {GEOMETRY_CHILD_METHODS.has(form.method) && (
                        <div className="mb-3">
                            <label className="form-label small" htmlFor="split-geom-a">
                                Child 1 geometry (GeoJSON)
                            </label>
                            <textarea id="split-geom-a" data-testid="split-geom-a" className="form-control form-control-sm font-monospace" rows={4} value={form.lotA} onChange={(e) => set('lotA', e.target.value)} />
                            <label className="form-label small mt-2" htmlFor="split-geom-b">
                                Child 2 geometry (GeoJSON)
                            </label>
                            <textarea id="split-geom-b" data-testid="split-geom-b" className="form-control form-control-sm font-monospace" rows={4} value={form.tdB} onChange={(e) => set('tdB', e.target.value)} />
                        </div>
                    )}

                    {form.method !== 'SURVEY_GEOMETRY' && form.method !== 'IMPORTED_GEOMETRY' && (
                        <div className="row g-2 mb-3">
                            <div className="col-6">
                                <label className="form-label small" htmlFor="split-lot-a">
                                    Child 1 lot number
                                </label>
                                <input id="split-lot-a" data-testid="split-lot-a" className="form-control form-control-sm" value={form.lotA} onChange={(e) => set('lotA', e.target.value)} />
                            </div>
                            <div className="col-6">
                                <label className="form-label small" htmlFor="split-lot-b">
                                    Child 2 lot number
                                </label>
                                <input id="split-lot-b" data-testid="split-lot-b" className="form-control form-control-sm" value={form.lotB} onChange={(e) => set('lotB', e.target.value)} />
                            </div>
                        </div>
                    )}

                    <button
                        type="button"
                        className="btn btn-outline-primary btn-sm"
                        data-testid="split-preview"
                        disabled={!hasGeometry || previewMutation.isPending}
                        onClick={() => previewMutation.mutate()}
                    >
                        {previewMutation.isPending ? 'Previewing…' : 'Preview split'}
                    </button>
                    {!hasGeometry && (
                        <p className="small text-danger mt-2" data-testid="split-no-geometry">
                            This parcel has no computed geometry yet — compute and accept a survey first.
                        </p>
                    )}
                </div>

                <div className="col-lg-7">
                    {form.method === 'MAP_SPLIT_LINE' ? (
                        <SplitLineMap
                            parcel={parcel}
                            line={lineCoords ?? buildSplitLine(parcel, form.midlineOffsetDeg)}
                            onChange={setLineCoords}
                        />
                    ) : (
                        <div className="border rounded bg-light d-flex align-items-center justify-content-center" style={{ minHeight: 300 }}>
                            <span className="text-muted small">The split-line map applies to the MAP_SPLIT_LINE method.</span>
                        </div>
                    )}
                </div>
            </div>

            {error && (
                <div className="alert alert-danger mt-3" data-testid="split-error">
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

            {preview && (
                <div className="mt-4" data-testid="split-preview-panel">
                    <h6>Preview — nothing is written until commit</h6>
                    <table className="table table-sm align-middle" data-testid="split-child-table">
                        <thead>
                            <tr>
                                <th>Child</th>
                                <th>Lot</th>
                                <th className="text-end">Area (m²)</th>
                                <th className="text-end">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            {preview.children.map((c) => (
                                <tr key={c.temp_id}>
                                    <td>{c.temp_id}</td>
                                    <td>{c.lot_number ?? '—'}</td>
                                    <td className="text-end">{c.area_sqm.toLocaleString(undefined, { maximumFractionDigits: 2 })}</td>
                                    <td className="text-end">{c.share_pct?.toFixed(1) ?? '—'}%</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {preview.area_reconciliation && (
                        <p className="small text-muted" data-testid="split-reconciliation">
                            Reconciliation: children {preview.area_reconciliation.children_sum_sqm.toLocaleString()} m² vs parent{' '}
                            {preview.area_reconciliation.parent_sqm?.toLocaleString() ?? '—'} m² (
                            {preview.area_reconciliation.difference_pct?.toFixed(3) ?? '—'}%).
                        </p>
                    )}
                    <ValidationBlock checks={preview.validation.checks} warnings={preview.validation.warnings} />

                    <div className="mt-3">
                        <label className="form-label small fw-semibold" htmlFor="split-reason">
                            Reason (required for commit)
                        </label>
                        <input
                            id="split-reason"
                            data-testid="split-reason"
                            className="form-control form-control-sm"
                            value={form.reason}
                            onChange={(e) => set('reason', e.target.value)}
                            placeholder="e.g. Subdivision per plan Psd-000001"
                        />
                        <button
                            type="button"
                            className="btn btn-primary btn-sm mt-2"
                            data-testid="split-commit"
                            disabled={!commitEnabled || form.reason.trim() === ''}
                            onClick={() => commitMutation.mutate({ body: buildSplitBody(parcel, form, lineCoords), key: previewKey })}
                        >
                            Commit split
                        </button>
                        {!commitEnabled && (
                            <p className="small text-muted mt-1" data-testid="split-commit-gate">
                                {preview === null
                                    ? 'Run a successful preview first.'
                                    : previewKey !== currentKey
                                      ? 'Inputs changed since the preview — re-run it.'
                                      : 'Waiting for a passing preview.'}
                            </p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
