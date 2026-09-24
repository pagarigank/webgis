import React, { useEffect, useState } from 'react';
import { validationApi } from '../api/validationApi';
import type {
    ValidationResult,
    ValidationCheckItem,
    OverlappingParcelItem,
} from '../api/validationApi';
import type { Parcel } from '../../parcels/types';

interface ValidationPanelProps {
    parcelId: string;
    parcel: Pick<Parcel, 'id' | 'status'> & Partial<Parcel>;
    onSubmitted?: () => void;
    onNavigateTab?: (tab: string) => void;
}

export const ValidationPanel: React.FC<ValidationPanelProps> = ({
    parcelId,
    parcel,
    onSubmitted,
    onNavigateTab,
}) => {
    const [result, setResult] = useState<ValidationResult | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Submission modal state
    const [submitting, setSubmitting] = useState(false);
    const [showSubmitModal, setShowSubmitModal] = useState(false);
    const [submitReason, setSubmitReason] = useState('Submitting parcel for cadastral review');
    const [submitError, setSubmitError] = useState<string | null>(null);

    const loadValidation = async (revalidate = false) => {
        try {
            setLoading(true);
            setError(null);
            const data = await validationApi.getValidation(parcelId, revalidate);
            setResult(data);
        } catch (err: unknown) {
            const msg = err instanceof Error ? err.message : 'Failed to load validation report.';
            setError(msg);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        void loadValidation(false);
    }, [parcelId]);

    const handleRunValidation = () => {
        void loadValidation(true);
    };

    const handleConfirmSubmit = async () => {
        try {
            setSubmitting(true);
            setSubmitError(null);
            await validationApi.submitParcel(parcelId, submitReason);
            setShowSubmitModal(false);
            if (onSubmitted) {
                onSubmitted();
            }
            await loadValidation(true);
        } catch (err: unknown) {
            const anyErr = err as { response?: { data?: { error?: { message?: string; code?: string } } }; message?: string };
            const msg = anyErr.response?.data?.error?.message ?? anyErr.message ?? 'Submission failed.';
            setSubmitError(msg);
        } finally {
            setSubmitting(false);
        }
    };

    const getStatusBadge = (status: ValidationCheckItem['status']) => {
        switch (status) {
            case 'PASS':
                return <span className="badge bg-success" data-testid="badge-pass">PASS</span>;
            case 'WARN':
                return <span className="badge bg-warning text-dark" data-testid="badge-warn">WARN</span>;
            case 'FAIL':
                return <span className="badge bg-danger" data-testid="badge-fail">FAIL</span>;
            default:
                return <span className="badge bg-secondary">{status}</span>;
        }
    };

    const getShowMeAction = (check: ValidationCheckItem) => {
        if (!onNavigateTab) return null;

        switch (check.id) {
            case 'technical_description':
            case 'bearing_reference':
            case 'course_syntax':
            case 'course_count':
                return (
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-primary"
                        onClick={() => onNavigateTab('techdesc')}
                        data-testid={`show-me-${check.id}`}
                    >
                        Show in Technical Description &rarr;
                    </button>
                );
            case 'tie_point_found':
            case 'tie_point_verified':
                return (
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-primary"
                        onClick={() => onNavigateTab('tiepoint')}
                        data-testid={`show-me-${check.id}`}
                    >
                        Show in Tie Point &rarr;
                    </button>
                );
            case 'crs_area_of_use':
            case 'traverse_closure':
            case 'geometry_validity':
            case 'area_plausibility':
            case 'computation_exists':
                return (
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-primary"
                        onClick={() => onNavigateTab('computation')}
                        data-testid={`show-me-${check.id}`}
                    >
                        Show in Computation &rarr;
                    </button>
                );
            default:
                return null;
        }
    };

    if (loading && !result) {
        return (
            <div className="text-center py-5" data-testid="validation-loading">
                <div className="spinner-border text-primary" role="status">
                    <span className="visually-hidden">Validating parcel...</span>
                </div>
                <p className="mt-3 text-muted">Running survey validation checklist...</p>
            </div>
        );
    }

    if (error && !result) {
        return (
            <div className="alert alert-danger my-3" data-testid="validation-error">
                <h5 className="alert-heading">Validation Error</h5>
                <p>{error}</p>
                <button type="button" className="btn btn-outline-danger btn-sm" onClick={() => void loadValidation(true)}>
                    Retry Validation
                </button>
            </div>
        );
    }

    const isSubmittableStatus = parcel.status === 'DRAFT' || parcel.status === 'RETURNED';
    const canSubmit = Boolean(result?.can_submit) && isSubmittableStatus;

    return (
        <div className="validation-panel" data-testid="validation-panel">
            {/* Top Action & Summary Bar */}
            <div className="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom gap-2">
                <div>
                    <h5 className="mb-1 fw-bold">Cadastral Survey Validation</h5>
                    <p className="text-muted small mb-0">
                        Rigorous automated checks enforcing DENR Technical Regulations and Manual for Land Surveys.
                    </p>
                </div>
                <div className="d-flex gap-2 align-items-center">
                    <button
                        type="button"
                        className="btn btn-outline-secondary btn-sm"
                        onClick={handleRunValidation}
                        disabled={loading}
                        data-testid="btn-revalidate"
                    >
                        {loading ? 'Validating...' : 'Re-run Validation'}
                    </button>

                    <button
                        type="button"
                        className={`btn btn-sm ${canSubmit ? 'btn-success' : 'btn-secondary'}`}
                        disabled={!canSubmit}
                        onClick={() => setShowSubmitModal(true)}
                        data-testid="btn-submit-for-review"
                        title={
                            !isSubmittableStatus
                                ? `Parcel is currently in status ${parcel.status}; cannot re-submit.`
                                : !result?.can_submit
                                ? 'Disabled due to blocking validation failures.'
                                : 'Submit parcel for cadastral review'
                        }
                    >
                        Submit for Review &rarr;
                    </button>
                </div>
            </div>

            {/* Overall Status Banner */}
            {result && (
                <div
                    className={`alert mb-4 ${
                        result.blocking_count > 0
                            ? 'alert-danger border-danger'
                            : result.warning_count > 0
                            ? 'alert-warning border-warning'
                            : 'alert-success border-success'
                    }`}
                    data-testid="validation-status-banner"
                >
                    <div className="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h6 className="alert-heading fw-bold mb-1">
                                {result.blocking_count > 0
                                    ? `BLOCKING ERRORS (${result.blocking_count}) — Submission Disabled`
                                    : result.warning_count > 0
                                    ? `READY WITH WARNINGS (${result.warning_count}) — Allowed with Review Notes`
                                    : 'ALL VALIDATION CHECKS PASSED — Ready for Approval'}
                            </h6>
                            <p className="small mb-0">
                                {result.blocking_count > 0
                                    ? 'The parcel contains critical survey rule violations that prevent submission. Resolve all blocking errors below.'
                                    : result.warning_count > 0
                                    ? 'No blocking errors found. Warnings will be permanently carried forward to reviewers for cadastral inspection.'
                                    : 'All geometric, mathematical, and spatial constraints are fully verified.'}
                            </p>
                        </div>
                        <div className="d-flex gap-2">
                            <span className="badge bg-light text-dark border">
                                Checks: {result.checks.length}
                            </span>
                            <span className="badge bg-danger">
                                Failed: {result.blocking_count}
                            </span>
                            <span className="badge bg-warning text-dark">
                                Warnings: {result.warning_count}
                            </span>
                            <span className="badge bg-success">
                                Passed: {result.checks.length - result.blocking_count - result.warning_count}
                            </span>
                        </div>
                    </div>
                </div>
            )}

            {/* Area Comparison Card (FR-127) */}
            {result && result.area_comparison && (
                <div className="card shadow-sm mb-4 border-info" data-testid="area-comparison-card">
                    <div className="card-header bg-info bg-opacity-10 py-2 d-flex justify-content-between align-items-center">
                        <span className="fw-semibold text-info-emphasis">Area Comparison & Reconciliation</span>
                        <span className="badge bg-info text-dark">FR-127 Validation Aid</span>
                    </div>
                    <div className="card-body">
                        <div className="row g-3 text-center mb-3">
                            <div className="col-sm-4">
                                <div className="p-2 border rounded bg-light">
                                    <span className="d-block small text-muted">Source / Claimed Area</span>
                                    <span className="fs-5 fw-bold" data-testid="area-source">
                                        {result.area_comparison.source_sqm !== null
                                            ? `${result.area_comparison.source_sqm.toLocaleString(undefined, { minimumFractionDigits: 2 })} m²`
                                            : 'Not specified'}
                                    </span>
                                </div>
                            </div>
                            <div className="col-sm-4">
                                <div className="p-2 border rounded bg-light">
                                    <span className="d-block small text-muted">Computed Planar Area</span>
                                    <span className="fs-5 fw-bold text-primary" data-testid="area-computed">
                                        {result.area_comparison.computed_sqm !== null
                                            ? `${result.area_comparison.computed_sqm.toLocaleString(undefined, { minimumFractionDigits: 2 })} m²`
                                            : 'Not computed'}
                                    </span>
                                </div>
                            </div>
                            <div className="col-sm-4">
                                <div className="p-2 border rounded bg-light">
                                    <span className="d-block small text-muted">PostGIS Polygon Area</span>
                                    <span className="fs-5 fw-bold text-secondary" data-testid="area-postgis">
                                        {result.area_comparison.postgis_sqm !== null
                                            ? `${result.area_comparison.postgis_sqm.toLocaleString(undefined, { minimumFractionDigits: 2 })} m²`
                                            : 'N/A'}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Mandatory Validation Aid Note (FR-127) */}
                        <div className="alert alert-light border small mb-0 text-muted fst-italic" data-testid="area-validation-note">
                            <i className="bi bi-info-circle me-1"></i>
                            <strong>Note: </strong>{result.area_comparison.note}
                        </div>
                    </div>
                </div>
            )}

            {/* Checklist Section (FR-125, FR-126) */}
            <div className="card shadow-sm mb-4" data-testid="checklist-card">
                <div className="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span className="fw-semibold">12-Point Survey Validation Checklist</span>
                    <span className="small text-muted">DENR & Geodetic Rules (VR-01 through VR-20)</span>
                </div>
                <div className="card-body p-0">
                    <div className="table-responsive">
                        <table className="table table-hover align-middle mb-0" data-testid="checklist-table">
                            <thead className="table-light small">
                                <tr>
                                    <th style={{ width: '90px' }}>Status</th>
                                    <th style={{ width: '110px' }}>Rule ID</th>
                                    <th style={{ width: '220px' }}>Check Description</th>
                                    <th>Findings & Details</th>
                                    <th style={{ width: '180px' }} className="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {result?.checks.map((check) => (
                                    <tr
                                        key={check.id}
                                        className={
                                            check.status === 'FAIL'
                                                ? 'table-danger'
                                                : check.status === 'WARN'
                                                ? 'table-warning'
                                                : ''
                                        }
                                        data-testid={`check-row-${check.id}`}
                                    >
                                        <td>{getStatusBadge(check.status)}</td>
                                        <td>
                                            <span className="badge bg-secondary font-monospace" data-testid={`rule-id-${check.id}`}>
                                                {check.rule}
                                            </span>
                                        </td>
                                        <td>
                                            <strong className="d-block text-dark">{check.name}</strong>
                                            <span className="text-muted small text-capitalize">{check.severity}</span>
                                        </td>
                                        <td>
                                            <div className="small">{check.message}</div>
                                            {/* FR-126: Warnings and Errors expanded by default; never hidden */}
                                            {Array.isArray(check.details) && check.details.length > 0 ? (
                                                <div className="mt-1 small bg-white p-2 border rounded" data-testid={`check-details-${check.id}`}>
                                                    <ul className="mb-0 ps-3">
                                                        {check.details.map((d: any, idx: number) => (
                                                            <li key={idx}>
                                                                {d?.rule ? `[${String(d.rule)}] ` : ''}
                                                                {String(d?.message ?? (typeof d === 'string' ? d : JSON.stringify(d)))}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            ) : null}
                                        </td>
                                        <td className="text-end">
                                            {getShowMeAction(check)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Overlap Summary & Table (VR-18, TASK-097) */}
            {result?.overlap_summary && (
                <div className="card shadow-sm mb-4" data-testid="overlap-card">
                    <div className="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                        <span className="fw-semibold">Cadastral Boundary Overlap Analysis (VR-18)</span>
                        <span className={`badge ${result.overlap_summary.has_significant_overlap ? 'bg-warning text-dark' : 'bg-success'}`}>
                            {result.overlap_summary.has_significant_overlap
                                ? `${result.overlap_summary.overlapping_parcels.length} Overlapping Parcels`
                                : 'No Boundary Overlap'}
                        </span>
                    </div>
                    <div className="card-body">
                        {result.overlap_summary.overlapping_parcels.length === 0 ? (
                            <div className="alert alert-success mb-0 py-2 small" data-testid="overlap-none">
                                <i className="bi bi-check-circle me-1"></i>
                                GIST-indexed spatial query verified zero interior overlaps against active cadastral parcels.
                            </div>
                        ) : (
                            <div>
                                <div className="alert alert-warning small mb-3">
                                    <strong>Warning (VR-18): </strong>
                                    Boundary overlap detected. Overlaps exceeding the sliver threshold (0.05 m²) require review and justification before approval.
                                </div>
                                <div className="table-responsive">
                                    <table className="table table-sm table-bordered align-middle mb-0" data-testid="overlap-table">
                                        <thead className="table-light small">
                                            <tr>
                                                <th>Parcel Code</th>
                                                <th>Lot Number</th>
                                                <th>Status</th>
                                                <th>Overlap Area</th>
                                                <th>% of Subject</th>
                                                <th>Sliver?</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {result.overlap_summary.overlapping_parcels.map((p: OverlappingParcelItem) => (
                                                <tr key={p.parcel_id} data-testid={`overlap-row-${p.parcel_id}`}>
                                                    <td className="font-monospace fw-bold">{p.parcel_code}</td>
                                                    <td>{p.lot_number || '—'}</td>
                                                    <td>
                                                        <span className="badge bg-secondary">{p.status}</span>
                                                    </td>
                                                    <td className="fw-bold text-danger">
                                                        {p.overlap_area_sqm.toFixed(2)} m²
                                                    </td>
                                                    <td>{p.overlap_pct_of_subject.toFixed(2)}%</td>
                                                    <td>
                                                        {p.is_sliver ? (
                                                            <span className="badge bg-light text-muted border">Sliver (&le; 0.05m²)</span>
                                                        ) : (
                                                            <span className="badge bg-danger">Significant</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Submission Modal */}
            {showSubmitModal && (
                <div
                    className="modal show d-block"
                    style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}
                    tabIndex={-1}
                    data-testid="submit-modal"
                >
                    <div className="modal-dialog modal-dialog-centered">
                        <div className="modal-content">
                            <div className="modal-header bg-success text-white">
                                <h5 className="modal-title">Submit Parcel for Review</h5>
                                <button
                                    type="button"
                                    className="btn-close btn-close-white"
                                    onClick={() => setShowSubmitModal(false)}
                                    disabled={submitting}
                                ></button>
                            </div>
                            <div className="modal-body">
                                {submitError && (
                                    <div className="alert alert-danger small" data-testid="submit-error">
                                        {submitError}
                                    </div>
                                )}

                                <p className="small mb-3">
                                    Submitting this parcel transitions its status from <strong>{parcel.status}</strong> to <strong>SUBMITTED</strong> and freezes active edits for reviewer examination.
                                </p>

                                {result?.warning_count ? (
                                    <div className="alert alert-warning small py-2 mb-3">
                                        <strong>Notice: </strong>
                                        There are {result.warning_count} active warning(s). Per FR-126/TASK-098, these warnings will be permanently attached to the submission record for cadastral review.
                                    </div>
                                ) : null}

                                <div className="mb-3">
                                    <label htmlFor="submit-reason-input" className="form-label small fw-semibold">
                                        Submission Justification / Remarks:
                                    </label>
                                    <textarea
                                        id="submit-reason-input"
                                        className="form-control form-control-sm"
                                        rows={3}
                                        value={submitReason}
                                        onChange={(e) => setSubmitReason(e.target.value)}
                                        disabled={submitting}
                                        data-testid="submit-reason-input"
                                    />
                                </div>
                            </div>
                            <div className="modal-footer">
                                <button
                                    type="button"
                                    className="btn btn-secondary btn-sm"
                                    onClick={() => setShowSubmitModal(false)}
                                    disabled={submitting}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-success btn-sm"
                                    onClick={() => void handleConfirmSubmit()}
                                    disabled={submitting || submitReason.trim() === ''}
                                    data-testid="btn-confirm-submit"
                                >
                                    {submitting ? 'Submitting...' : 'Confirm Submission'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
