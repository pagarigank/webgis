import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { parcelApi } from '../api/parcelApi';
import type { Parcel } from '../types';

/**
 * TASK-103 — reviewer inbox (FR-103 read side).
 *
 * The reviewer's work queue: every parcel currently sitting in SUBMITTED or
 * UNDER_REVIEW, newest activity first. Row actions happen on the parcel
 * editor, where the workflow action bar renders the caller's permitted
 * transitions (START_REVIEW / RETURN / VERIFY / APPROVE …) from server truth.
 */

export function ReviewerInboxPage() {
    // The list endpoint filters by a single status; fan one query per status
    // and merge. Both share the ['review-inbox'] prefix so workflow
    // transitions elsewhere invalidate the whole queue.
    const submitted = useQuery({
        queryKey: ['review-inbox', 'SUBMITTED'],
        queryFn: () => parcelApi.list({ status: 'SUBMITTED', limit: 100 }),
    });
    const underReview = useQuery({
        queryKey: ['review-inbox', 'UNDER_REVIEW'],
        queryFn: () => parcelApi.list({ status: 'UNDER_REVIEW', limit: 100 }),
    });

    const rows = useMemo<Parcel[]>(() => {
        const merged = [...(submitted.data?.data ?? []), ...(underReview.data?.data ?? [])];
        merged.sort((a, b) => (a.updated_at < b.updated_at ? 1 : -1));
        return merged;
    }, [submitted.data, underReview.data]);

    const loading = submitted.isLoading || underReview.isLoading;
    const error = submitted.isError || underReview.isError;

    return (
        <div className="container-fluid py-4" data-testid="reviewer-inbox">
            <div className="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h3 className="mb-0">Reviewer inbox</h3>
                    <span className="text-muted small">
                        Parcels submitted for review. Actions are on each parcel's editor page.
                    </span>
                </div>
                <Link to="/parcels" className="btn btn-outline-secondary btn-sm">
                    All parcels
                </Link>
            </div>

            {loading && <div className="alert alert-light border">Loading the review queue…</div>}
            {error && (
                <div className="alert alert-danger">
                    Could not load the review queue. <button className="btn btn-outline-danger btn-sm" onClick={() => { void submitted.refetch(); void underReview.refetch(); }}>Retry</button>
                </div>
            )}

            {!loading && !error && (
                <div className="card shadow-sm">
                    <div className="table-responsive">
                        <table className="table table-hover align-middle mb-0">
                            <thead className="table-light small">
                                <tr>
                                    <th>Parcel</th>
                                    <th>Lot</th>
                                    <th>Barangay</th>
                                    <th>Status</th>
                                    <th>Version</th>
                                    <th>Last activity</th>
                                    <th className="text-end">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-center text-muted py-4" data-testid="reviewer-inbox-empty">
                                            Nothing waiting for review.
                                        </td>
                                    </tr>
                                )}
                                {rows.map((p) => (
                                    <tr key={p.id} data-testid={`reviewer-inbox-row-${p.parcel_code}`}>
                                        <td><code>{p.parcel_code}</code></td>
                                        <td>{p.lot_number ?? '—'}</td>
                                        <td>{p.psgc_barangay_name ?? p.psgc_barangay ?? '—'}</td>
                                        <td>
                                            <span className={`badge ${p.status === 'SUBMITTED' ? 'bg-warning text-dark' : 'bg-info text-dark'}`}>
                                                {p.status}
                                            </span>
                                        </td>
                                        <td>v{p.version}</td>
                                        <td className="small text-muted">{p.updated_at ? new Date(p.updated_at).toLocaleString() : '—'}</td>
                                        <td className="text-end">
                                            <Link to={`/parcels/${p.id}`} className="btn btn-sm btn-outline-primary">
                                                Review
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
}
