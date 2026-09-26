import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { parcelApi } from '../api/parcelApi';
import { extractApiError } from '../api/operationsApi';
import { hasPermission, permissions } from '../../../auth/permissions';
import { useAuth } from '../../../auth/useAuth';
import { StatusBadge } from './badges';
import type { ParcelVersionSummary } from '../types';

/**
 * TASK-104a — version history for a parcel: the lineage list, a field/geometry
 * diff between any two versions, and a restore action.
 *
 * Restore semantics are deliberately surfaced in the UI copy: the server does
 * not rewind the parcel to the old version number. It re-applies the stored key
 * attributes onto the live row and records a NEW version, so the lineage stays
 * append-only and the restore itself is auditable. Presenting this as a
 * "rollback" would misrepresent what the ledger records.
 */

const fmtDate = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleString() : '—');

/** Snapshot values arrive as unknown; render them without throwing on objects. */
export function renderValue(v: unknown): string {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'object') return JSON.stringify(v);
    return String(v);
}

export const VERSION_KIND_LABEL: Record<string, string> = {
    VERSION: 'Version',
    WORKFLOW: 'Workflow',
    AUDIT: 'Audit',
};

export interface HistoryTabProps {
    parcelId: string;
    currentVersion?: number;
}

export function HistoryTab({ parcelId, currentVersion }: HistoryTabProps) {
    const { me } = useAuth();
    const queryClient = useQueryClient();
    const canRestore = me != null && hasPermission(me, permissions.parcelVersionRestore);

    const [compareA, setCompareA] = useState<number | null>(null);
    const [compareB, setCompareB] = useState<number | null>(null);
    const [restoreTarget, setRestoreTarget] = useState<number | null>(null);
    const [restoreReason, setRestoreReason] = useState('');
    const [view, setView] = useState<'versions' | 'timeline'>('versions');

    const enabled = parcelId.length > 0;

    const { data: versions, isLoading } = useQuery({
        queryKey: ['parcel', parcelId, 'versions'],
        queryFn: () => parcelApi.listVersions(parcelId),
        enabled,
    });

    const { data: timeline } = useQuery({
        queryKey: ['parcel', parcelId, 'timeline'],
        queryFn: () => parcelApi.timeline(parcelId),
        enabled: enabled && view === 'timeline',
    });

    const { data: compare, isFetching: comparing, error: compareError } = useQuery({
        queryKey: ['parcel', parcelId, 'compare', compareA, compareB],
        queryFn: () => parcelApi.compareVersions(parcelId, compareA!, compareB ?? undefined),
        enabled: enabled && compareA !== null,
    });

    const restore = useMutation({
        mutationFn: () => parcelApi.restoreVersion(parcelId, restoreTarget!, restoreReason),
        onSuccess: () => {
            setRestoreTarget(null);
            setRestoreReason('');
            setCompareA(null);
            void queryClient.invalidateQueries({ queryKey: ['parcel', parcelId] });
        },
    });

    const rows: ParcelVersionSummary[] = versions?.data ?? [];
    const error = restore.error ? extractApiError(restore.error) : null;

    return (
        <div data-testid="history-tab">
            <div className="flex justify-between items-center mb-4">
                <h6 className="text-muted m-0">Version history</h6>
                <div className="flex gap-2 items-center">
                    <button
                        type="button"
                        className={`btn ${view === 'versions' ? 'btn-primary' : 'btn-ghost'}`}
                        data-testid="history-view-versions"
                        onClick={() => setView('versions')}
                    >
                        Versions
                    </button>
                    <button
                        type="button"
                        className={`btn ${view === 'timeline' ? 'btn-primary' : 'btn-ghost'}`}
                        data-testid="history-view-timeline"
                        onClick={() => setView('timeline')}
                    >
                        Timeline
                    </button>
                </div>
            </div>

            {view === 'timeline' ? (
                <div data-testid="history-timeline">
                    <div className="flex justify-between items-center mb-2">
                        <span className="text-sm text-muted">
                            {timeline ? `${timeline.count} events` : 'Loading…'}
                        </span>
                        <a
                            className="btn btn-ghost"
                            data-testid="history-timeline-export"
                            href={parcelApi.timelineExportUrl(parcelId)}
                            download
                        >
                            Export JSON
                        </a>
                    </div>
                    <ul className="list-unstyled m-0">
                        {(timeline?.events ?? []).map((e, i) => (
                            <li key={`${e.kind}-${e.at}-${i}`} className="list-item" data-testid="timeline-event">
                                <div className="flex justify-between items-center gap-2">
                                    <strong className="text-sm">{e.action}</strong>
                                    <span className="text-muted text-sm">{fmtDate(e.at)}</span>
                                </div>
                                <div className="text-sm text-muted">
                                    {VERSION_KIND_LABEL[e.kind] ?? e.kind}
                                    {e.actor ? ` · ${e.actor}` : ''}
                                </div>
                            </li>
                        ))}
                        {timeline && timeline.count === 0 && <li className="text-muted">No history recorded yet.</li>}
                    </ul>
                </div>
            ) : (
                <>
                    {isLoading && <p className="text-muted">Loading versions…</p>}
                    {!isLoading && rows.length === 0 && <p className="text-muted">No revisions recorded yet.</p>}

                    {rows.length > 0 && (
                        <>
                            <ul className="list-unstyled mb-4">
                                {rows.map((v) => (
                                    <li key={v.version} className="list-item" data-testid={`history-version-${v.version}`}>
                                        <div className="flex justify-between items-center gap-3 flex-wrap">
                                            <div>
                                                <span className="font-mono text-sm me-2">v{v.version}</span>
                                                <span className="text-sm">{v.change_summary ?? 'Edit'}</span>
                                                {currentVersion != null && v.version === currentVersion && (
                                                    <span className="text-sm text-muted"> · current</span>
                                                )}
                                                {!v.has_geometry && (
                                                    <span className="text-sm text-muted"> · no geometry</span>
                                                )}
                                            </div>
                                            <div className="text-end text-sm text-muted">
                                                <StatusBadge status={v.status} />
                                                <div>{fmtDate(v.changed_at)}</div>
                                            </div>
                                        </div>
                                        {v.change_reason && <div className="text-sm text-muted">{v.change_reason}</div>}
                                        <div className="flex gap-2 mt-2">
                                            <button
                                                type="button"
                                                className={`btn ${compareA === v.version ? 'btn-primary' : 'btn-ghost'}`}
                                                data-testid={`history-compare-from-${v.version}`}
                                                onClick={() => setCompareA(v.version)}
                                            >
                                                Compare from v{v.version}
                                            </button>
                                            {compareA !== null && compareA !== v.version && (
                                                <button
                                                    type="button"
                                                    className="btn btn-ghost"
                                                    data-testid={`history-compare-to-${v.version}`}
                                                    onClick={() => setCompareB(v.version)}
                                                >
                                                    Compare to v{v.version}
                                                </button>
                                            )}
                                            {canRestore && (
                                                <button
                                                    type="button"
                                                    className="btn btn-ghost"
                                                    data-testid={`history-restore-${v.version}`}
                                                    onClick={() => setRestoreTarget(v.version)}
                                                >
                                                    Restore v{v.version}
                                                </button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>

                            {/* Restore is a new recorded version, not a rewind. */}
                            {restoreTarget !== null && (
                                <div className="list-item mb-4" data-testid="history-restore-panel">
                                    <label className="form-label" htmlFor="history-restore-reason">
                                        Reason for restoring v{restoreTarget} (recorded in the lineage)
                                    </label>
                                    <input
                                        id="history-restore-reason"
                                        data-testid="history-restore-reason"
                                        className="form-input mb-2"
                                        value={restoreReason}
                                        onChange={(e) => setRestoreReason(e.target.value)}
                                    />
                                    <p className="text-sm text-muted">
                                        This re-applies the recorded attributes and saves a new version. It does not
                                        rewind the parcel to v{restoreTarget}.
                                    </p>
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            className="btn btn-primary"
                                            data-testid="history-restore-confirm"
                                            disabled={restoreReason.trim() === '' || restore.isPending}
                                            onClick={() => restore.mutate()}
                                        >
                                            {restore.isPending ? 'Restoring…' : 'Confirm restore'}
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-ghost"
                                            data-testid="history-restore-cancel"
                                            onClick={() => {
                                                setRestoreTarget(null);
                                                setRestoreReason('');
                                            }}
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            )}

                            {compareA !== null && (
                                <div className="list-item" data-testid="history-compare-panel">
                                    <div className="flex justify-between items-center mb-2">
                                        <strong className="text-sm">
                                            {comparing
                                                ? 'Comparing…'
                                                : compareError
                                                    ? 'Comparison failed'
                                                    : `v${compare?.from_version ?? compareA} → v${compare?.to_version ?? compareB ?? 'current'}`}
                                        </strong>
                                        <button type="button" className="btn btn-ghost" onClick={() => { setCompareA(null); setCompareB(null); }}>
                                            Close
                                        </button>
                                    </div>
                                    {comparing && <p className="text-muted">Comparing…</p>}

                                    {!comparing && compareError && (
                                        <p className="text-danger m-0" data-testid="history-compare-error">
                                            {extractApiError(compareError).message}
                                        </p>
                                    )}

                                    {!comparing && !compareError && compare && (
                                        <>
                                            <h6 className="text-sm">Attribute changes</h6>
                                            {compare.field_changes.length === 0 ? (
                                                <p className="text-muted text-sm" data-testid="history-compare-no-changes">
                                                    No attribute differences.
                                                </p>
                                            ) : (
                                                <table className="data-table w-full mb-4" data-testid="history-compare-fields">
                                                    <thead>
                                                        <tr>
                                                            <th>Field</th>
                                                            <th>From v{compare.from_version}</th>
                                                            <th>To v{compare.to_version}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {compare.field_changes.map((c) => (
                                                            <tr key={c.field}>
                                                                <td className="font-mono">{c.field}</td>
                                                                <td>{renderValue(c.old)}</td>
                                                                <td>{renderValue(c.new)}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            )}

                                            <h6 className="text-sm">Geometry changes</h6>
                                            {!compare.geometry_diff ? (
                                                <p className="text-muted text-sm">No geometry change.</p>
                                            ) : (
                                                <ul className="text-sm text-muted" data-testid="history-compare-geometry">
                                                    <li>
                                                        vertices {compare.geometry_diff.old_vertex_count} →{' '}
                                                        {compare.geometry_diff.new_vertex_count}
                                                    </li>
                                                    <li>added: {compare.geometry_diff.added.length}</li>
                                                    <li>removed: {compare.geometry_diff.removed.length}</li>
                                                    <li>moved: {compare.geometry_diff.moved.length}</li>
                                                </ul>
                                            )}
                                        </>
                                    )}
                                </div>
                            )}
                        </>
                    )}
                </>
            )}

            {error && (
                <div className="list-item mt-4" role="alert" data-testid="history-error">
                    <strong className="text-danger">{error.code}:</strong> {error.message}
                </div>
            )}
        </div>
    );
}
