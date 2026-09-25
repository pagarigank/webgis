import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  computationApi,
  type ComputationDetail,
  type ComputationSummary,
  type ReplayResult,
} from '../api/computationApi';
import { surveyApi, type TechnicalDescription } from '../api/surveyApi';

interface ComputationPanelProps {
  parcelId: string;
  parcel?: {
    id: string;
    parcel_code?: string;
    source_area_sqm?: number | null;
    version?: number;
    geometry?: any;
  } | null;
  onAccepted?: () => void;
}

const PTM_ZONES = [
  { code: 'EPSG:3121', name: 'PRS92 / Philippines Zone I (117°E — Palawan)' },
  { code: 'EPSG:3122', name: 'PRS92 / Philippines Zone II (119°E — Ilocos, Zambales)' },
  { code: 'EPSG:3123', name: 'PRS92 / Philippines Zone III (121°E — Central Luzon, NCR, Batangas)' },
  { code: 'EPSG:3124', name: 'PRS92 / Philippines Zone IV (123°E — Bicol, Visayas)' },
  { code: 'EPSG:3125', name: 'PRS92 / Philippines Zone V (125°E — E. Mindanao, Samar)' },
  { code: 'EPSG:32651', name: 'WGS 84 / UTM Zone 51N' },
];

export const ComputationPanel: React.FC<ComputationPanelProps> = ({
  parcelId,
  parcel,
  onAccepted,
}) => {
  const [technicalDescriptions, setTechnicalDescriptions] = useState<TechnicalDescription[]>([]);
  const [selectedTdId, setSelectedTdId] = useState<number | null>(null);
  const [currentTd, setCurrentTd] = useState<TechnicalDescription | null>(null);
  const [computeCrs, setComputeCrs] = useState<string>('EPSG:3123');
  const [customCrs, setCustomCrs] = useState<string>('');

  const [loading, setLoading] = useState<boolean>(false);
  const [calculating, setCalculating] = useState<boolean>(false);
  const [accepting, setAccepting] = useState<boolean>(false);
  const [adjusting, setAdjusting] = useState<boolean>(false);
  const [replayingId, setReplayingId] = useState<number | null>(null);

  const [activeComp, setActiveComp] = useState<ComputationDetail | null>(null);
  const [history, setHistory] = useState<ComputationSummary[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  // Drawer / Modal states
  const [showTolerances, setShowTolerances] = useState<boolean>(false);
  const [tolerances, setTolerances] = useState({
    max_linear_error_m: 0.1,
    min_precision_ratio: 5000,
  });

  const [showSnapshotModal, setShowSnapshotModal] = useState<boolean>(false);
  const [snapshotJson, setSnapshotJson] = useState<string | null>(null);

  const [showAdjustModal, setShowAdjustModal] = useState<boolean>(false);
  const [adjustMethod, setAdjustMethod] = useState<'COMPASS' | 'TRANSIT'>('COMPASS');
  const [adjustNote, setAdjustNote] = useState<string>('');

  const [showAcceptModal, setShowAcceptModal] = useState<boolean>(false);
  const [acceptReason, setAcceptReason] = useState<string>('Survey technical review approved');

  const [replayModalResult, setReplayModalResult] = useState<ReplayResult | null>(null);

  // Load Technical Descriptions and Computation History
  const loadInitialData = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const [tdList, compList] = await Promise.all([
        surveyApi.listByParcel(parcelId),
        computationApi.listForParcel(parcelId),
      ]);

      setTechnicalDescriptions(tdList);
      setHistory(compList);

      if (tdList.length > 0) {
        const current = tdList.find((t) => t.is_current) || tdList[0];
        setSelectedTdId(current.id);
        const tdDetail = await surveyApi.getById(current.id);
        setCurrentTd(tdDetail.data);
      }

      if (compList.length > 0) {
        // Load the latest computation into active view
        const latestDetail = await computationApi.get(compList[0].id);
        setActiveComp(latestDetail);
      }
    } catch (err: any) {
      setError(err?.response?.data?.error?.message || err?.message || 'Failed to load survey data.');
    } finally {
      setLoading(false);
    }
  }, [parcelId]);

  useEffect(() => {
    loadInitialData();
  }, [loadInitialData]);

  // Handle TD revision change
  const handleTdChange = async (tdId: number) => {
    try {
      setSelectedTdId(tdId);
      const detail = await surveyApi.getById(tdId);
      setCurrentTd(detail.data);
    } catch {
      setError('Failed to load selected technical description.');
    }
  };

  // Suggest CRS zone based on parcel preview or tie point
  const handleSuggestZone = async () => {
    try {
      let lon = 121.0; // default Central Luzon
      if (currentTd?.tie_points?.[0]?.as_used_easting) {
        // if coordinates available
      }
      const suggestion = await computationApi.suggestZone(lon);
      setComputeCrs(suggestion.recommended_crs_code);
      setSuccessMsg(`Suggested: ${suggestion.recommended_zone} (${suggestion.recommended_crs_code})`);
      setTimeout(() => setSuccessMsg(null), 4000);
    } catch {
      // fallback
      setComputeCrs('EPSG:3123');
    }
  };

  // Run Computation
  const handleCompute = async () => {
    if (!selectedTdId) {
      setError('Please select a Technical Description revision.');
      return;
    }

    const crs = computeCrs === 'CUSTOM' ? customCrs : computeCrs;
    if (!crs) {
      setError('Please select or specify a Compute CRS.');
      return;
    }

    try {
      setCalculating(true);
      setError(null);
      setSuccessMsg(null);

      const result = await computationApi.calculate(parcelId, {
        technical_description_id: selectedTdId,
        compute_crs: crs,
        tolerances,
      });

      setActiveComp(result);
      setSuccessMsg('Computation completed successfully.');

      // Refresh history
      const compList = await computationApi.listForParcel(parcelId);
      setHistory(compList);
    } catch (err: any) {
      setError(err?.response?.data?.error?.message || err?.message || 'Computation failed.');
    } finally {
      setCalculating(false);
    }
  };

  // Run Adjustment
  const handleExecuteAdjustment = async () => {
    if (!activeComp) return;
    try {
      setAdjusting(true);
      setError(null);
      const adjusted = await computationApi.adjust(activeComp.computation_id, adjustMethod, {
        note: adjustNote,
      });
      setActiveComp(adjusted);
      setShowAdjustModal(false);
      setAdjustNote('');
      setSuccessMsg(`Traverse adjusted using ${adjustMethod} rule.`);

      const compList = await computationApi.listForParcel(parcelId);
      setHistory(compList);
    } catch (err: any) {
      setError(err?.response?.data?.error?.message || err?.message || 'Adjustment failed.');
    } finally {
      setAdjusting(false);
    }
  };

  // View Input Snapshot
  const handleViewSnapshot = async () => {
    if (!activeComp) return;
    try {
      const snap = await computationApi.getSnapshot(activeComp.computation_id);
      setSnapshotJson(JSON.stringify(snap, null, 2));
      setShowSnapshotModal(true);
    } catch {
      setError('Failed to fetch computation input snapshot.');
    }
  };

  // Accept Computation -> Parcel Geometry
  const handleAcceptComputation = async () => {
    if (!activeComp) return;
    try {
      setAccepting(true);
      setError(null);
      await computationApi.acceptComputation(
        parcelId,
        activeComp.computation_id,
        acceptReason
      );
      setShowAcceptModal(false);
      setSuccessMsg('Computation accepted! Parcel geometry updated to COMPUTED_FROM_TECHNICAL_DESCRIPTION.');
      if (onAccepted) onAccepted();

      // Refresh history
      const compList = await computationApi.listForParcel(parcelId);
      setHistory(compList);
    } catch (err: any) {
      setError(err?.response?.data?.error?.message || err?.message || 'Failed to accept computation.');
    } finally {
      setAccepting(false);
    }
  };

  // Replay Computation
  const handleReplay = async (id: number) => {
    try {
      setReplayingId(id);
      const replay = await computationApi.replay(id);
      setReplayModalResult(replay);
    } catch (err: any) {
      setError('Replay failed: ' + (err?.response?.data?.error?.message || err?.message));
    } finally {
      setReplayingId(null);
    }
  };

  // Select historical computation
  const handleSelectHistoryComp = async (id: number) => {
    try {
      setLoading(true);
      const detail = await computationApi.get(id);
      setActiveComp(detail);
    } catch {
      setError('Failed to load computation details.');
    } finally {
      setLoading(false);
    }
  };

  // SVG Geometry Calculation
  const svgPreview = useMemo(() => {
    if (!activeComp || !activeComp.vertices || activeComp.vertices.length < 3) {
      return null;
    }

    const verts = activeComp.vertices;
    let minE = Infinity, maxE = -Infinity, minN = Infinity, maxN = -Infinity;

    for (const v of verts) {
      if (v.easting < minE) minE = v.easting;
      if (v.easting > maxE) maxE = v.easting;
      if (v.northing < minN) minN = v.northing;
      if (v.northing > maxN) maxN = v.northing;
    }

    const w = Math.max(maxE - minE, 20);
    const h = Math.max(maxN - minN, 20);
    const pad = Math.max(w, h) * 0.15;

    const viewBox = `${minE - pad} ${-(maxN + pad)} ${w + pad * 2} ${h + pad * 2}`;
    const pts = verts.map((v) => `${v.easting},${-v.northing}`).join(' ');

    return { viewBox, pts, verts, minE, minN, w, h };
  }, [activeComp]);

  const courseCount = currentTd?.courses?.length || 0;
  const primaryTp = currentTd?.tie_points?.[0];

  if (loading && !activeComp) {
    return (
      <div className="card shadow-sm p-4 text-center text-muted">
        <div className="spinner-border spinner-border-sm me-2 text-primary" role="status" />
        Loading survey computation module...
      </div>
    );
  }

  return (
    <div className="d-flex flex-column gap-3 pb-5">
      {/* Notifications */}
      {error && (
        <div className="alert alert-danger alert-dismissible fade show mb-0 py-2 small shadow-sm" role="alert">
          <strong>Validation / Error:</strong> {error}
          <button type="button" className="btn-close" onClick={() => setError(null)} />
        </div>
      )}
      {successMsg && (
        <div className="alert alert-success alert-dismissible fade show mb-0 py-2 small shadow-sm" role="alert">
          <strong>Success:</strong> {successMsg}
          <button type="button" className="btn-close" onClick={() => setSuccessMsg(null)} />
        </div>
      )}

      {/* 1. Header & Input Summary Block */}
      <div className="card shadow-sm border-0">
        <div className="card-header bg-white border-bottom py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            <h5 className="mb-0 fw-bold text-dark">Traverse Computation Engine</h5>
            <span className="small text-muted">Pure planar coordinate geometry, closure analysis, and parcel acceptance</span>
          </div>

          <div className="d-flex align-items-center gap-2">
            <button
              className="btn btn-sm btn-outline-secondary"
              onClick={() => setShowTolerances(!showTolerances)}
              title="Configure survey closure limits"
            >
              <i className="bi bi-sliders me-1" /> Tolerances
            </button>
            <button
              className="btn btn-sm btn-primary px-3 fw-semibold shadow-sm"
              onClick={handleCompute}
              disabled={calculating || courseCount < 3}
            >
              {calculating ? (
                <>
                  <span className="spinner-border spinner-border-sm me-1" /> Computing…
                </>
              ) : (
                <>
                  <i className="bi bi-play-circle-fill me-1" /> Run Computation
                </>
              )}
            </button>
          </div>
        </div>

        {/* Tolerances Drawer */}
        {showTolerances && (
          <div className="bg-light border-bottom p-3">
            <div className="row g-3 align-items-center">
              <div className="col-sm-5">
                <label className="form-label small fw-semibold mb-1">Max Linear Error (meters)</label>
                <input
                  type="number"
                  step="0.001"
                  className="form-control form-control-sm"
                  value={tolerances.max_linear_error_m}
                  onChange={(e) => setTolerances({ ...tolerances, max_linear_error_m: parseFloat(e.target.value) || 0.1 })}
                />
              </div>
              <div className="col-sm-5">
                <label className="form-label small fw-semibold mb-1">Min Relative Precision Denominator (1:N)</label>
                <input
                  type="number"
                  step="500"
                  className="form-control form-control-sm"
                  value={tolerances.min_precision_ratio}
                  onChange={(e) => setTolerances({ ...tolerances, min_precision_ratio: parseInt(e.target.value, 10) || 5000 })}
                />
              </div>
              <div className="col-sm-2 d-flex align-items-end">
                <button className="btn btn-sm btn-outline-secondary w-100" onClick={() => setShowTolerances(false)}>
                  Close
                </button>
              </div>
            </div>
            <div className="small text-muted mt-2">
              VR-11 requires relative precision ≥ 1:5000 for secondary classification (1:10000 for primary).
            </div>
          </div>
        )}

        <div className="card-body p-3">
          <div className="row g-3">
            {/* Technical Description revision selector */}
            <div className="col-md-4">
              <label className="form-label small fw-semibold text-muted mb-1">Technical Description</label>
              <select
                className="form-select form-select-sm"
                value={selectedTdId || ''}
                onChange={(e) => handleTdChange(Number(e.target.value))}
              >
                {technicalDescriptions.map((td) => (
                  <option key={td.id} value={td.id}>
                    Rev {td.revision} ({td.status}) — {td.parser_status} {td.is_current ? '★ current' : ''}
                  </option>
                ))}
              </select>
              <div className="d-flex justify-content-between mt-1 text-muted small">
                <span>Courses: <strong>{courseCount}</strong></span>
                <span>Ref: <strong>{currentTd?.bearing_reference || 'GRID'}</strong></span>
                <span>Unit: <strong>{currentTd?.distance_unit || 'm'}</strong></span>
              </div>
            </div>

            {/* Compute CRS Selector */}
            <div className="col-md-5">
              <label className="form-label small fw-semibold text-muted mb-1 d-flex justify-content-between">
                <span>Compute CRS (Projected)</span>
                <button
                  type="button"
                  className="btn btn-link p-0 text-decoration-none small text-primary"
                  onClick={handleSuggestZone}
                >
                  <i className="bi bi-geo-alt me-1" /> Suggest Zone
                </button>
              </label>
              <select
                className="form-select form-select-sm"
                value={computeCrs}
                onChange={(e) => setComputeCrs(e.target.value)}
              >
                {PTM_ZONES.map((z) => (
                  <option key={z.code} value={z.code}>
                    {z.name}
                  </option>
                ))}
                <option value="CUSTOM">Custom SRID / Authority Code...</option>
              </select>
              {computeCrs === 'CUSTOM' && (
                <input
                  type="text"
                  className="form-control form-control-sm mt-1"
                  placeholder="e.g. EPSG:3123 or 3123"
                  value={customCrs}
                  onChange={(e) => setCustomCrs(e.target.value)}
                />
              )}
            </div>

            {/* Tie Point Status Block */}
            <div className="col-md-3">
              <label className="form-label small fw-semibold text-muted mb-1">Tie Point Monument</label>
              <div className="border rounded p-2 bg-light small">
                {primaryTp ? (
                  <>
                    <div className="fw-semibold text-dark text-truncate">{primaryTp.adhoc_name || 'Control Point'}</div>
                    <div className="d-flex justify-content-between mt-1">
                      <span className="text-muted">Status:</span>
                      <span className={`badge ${primaryTp.as_used_status === 'VERIFIED' ? 'bg-success' : 'bg-warning text-dark'}`}>
                        {primaryTp.as_used_status}
                      </span>
                    </div>
                  </>
                ) : (
                  <div className="text-muted italic">Assumed local origin (500000, 1000000)</div>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* 2. Active Computation Results */}
      {activeComp && (
        <>
          {/* Warnings Banner if any */}
          {activeComp.warnings && activeComp.warnings.length > 0 && (
            <div className="alert alert-warning py-2 px-3 mb-0 shadow-sm">
              <div className="fw-bold small d-flex align-items-center gap-1 mb-1">
                <i className="bi bi-exclamation-triangle-fill text-warning" /> Survey Rule Alerts ({activeComp.warnings.length}):
              </div>
              <ul className="mb-0 ps-3 small">
                {activeComp.warnings.map((w, idx) => (
                  <li key={idx}>
                    <span className="badge bg-secondary me-1">{w.rule}</span>
                    {w.message}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Metrics & Preview Grid */}
          <div className="row g-3">
            {/* Closure Block */}
            <div className="col-lg-4">
              <div className="card shadow-sm h-100 border-0">
                <div className="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                  <span className="fw-bold text-dark small">Traverse Closure</span>
                  <span
                    className={`badge ${
                      activeComp.closure.status === 'WITHIN_TOLERANCE'
                        ? 'bg-success'
                        : 'bg-danger'
                    }`}
                  >
                    {activeComp.closure.status}
                  </span>
                </div>
                <div className="card-body p-3">
                  <div className="d-flex justify-content-between py-1 border-bottom">
                    <span className="text-muted small">Linear Error (LE):</span>
                    <span className="fw-bold text-dark">{activeComp.closure.linear_error_m.toFixed(4)} m</span>
                  </div>
                  <div className="d-flex justify-content-between py-1 border-bottom">
                    <span className="text-muted small">Relative Precision:</span>
                    <span className="fw-bold text-primary font-monospace">{activeComp.closure.relative_precision}</span>
                  </div>
                  <div className="d-flex justify-content-between py-1 border-bottom">
                    <span className="text-muted small">Closure ΔE / ΔN:</span>
                    <span className="small text-dark font-monospace">
                      {activeComp.closure.delta_e.toFixed(4)} / {activeComp.closure.delta_n.toFixed(4)} m
                    </span>
                  </div>
                  <div className="d-flex justify-content-between py-1 border-bottom">
                    <span className="text-muted small">Error Azimuth:</span>
                    <span className="text-dark small">
                      {activeComp.closure.error_azimuth_dd !== null ? `${activeComp.closure.error_azimuth_dd.toFixed(2)}°` : '—'}
                    </span>
                  </div>
                  <div className="d-flex justify-content-between py-1">
                    <span className="text-muted small">Total Perimeter:</span>
                    <span className="text-dark small">{activeComp.closure.perimeter_m.toFixed(3)} m</span>
                  </div>
                  {activeComp.adjustment_method && (
                    <div className="mt-2 p-1 bg-light rounded text-center small text-primary fw-semibold">
                      Adjusted via {activeComp.adjustment_method} Rule
                    </div>
                  )}
                </div>
              </div>
            </div>

            {/* Area Comparison Block */}
            <div className="col-lg-4">
              <div className="card shadow-sm h-100 border-0">
                <div className="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                  <span className="fw-bold text-dark small">Planar Area Comparison</span>
                  <span className="badge bg-secondary">Plane Shoelace</span>
                </div>
                <div className="card-body p-3">
                  <div className="d-flex justify-content-between py-1 border-bottom">
                    <span className="text-muted small">Computed Area (Shoelace):</span>
                    <span className="fw-bold text-success">{activeComp.area.computed_sqm.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} m²</span>
                  </div>
                  <div className="d-flex justify-content-between py-1 border-bottom">
                    <span className="text-muted small">PostGIS Planar Area:</span>
                    <span className="text-dark small">{activeComp.area.postgis_sqm.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} m²</span>
                  </div>
                  <div className="d-flex justify-content-between py-1 border-bottom">
                    <span className="text-muted small">Claimed / Source Area:</span>
                    <span className="text-dark small">
                      {activeComp.area.source_sqm !== null
                        ? `${activeComp.area.source_sqm.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} m²`
                        : 'Not specified'}
                    </span>
                  </div>
                  {activeComp.area.difference_sqm !== null && (
                    <div className="d-flex justify-content-between py-1 border-bottom">
                      <span className="text-muted small">Difference vs Source:</span>
                      <span className={`small fw-bold ${Math.abs(activeComp.area.difference_pct || 0) > 0.01 ? 'text-danger' : 'text-success'}`}>
                        {activeComp.area.difference_sqm > 0 ? '+' : ''}{activeComp.area.difference_sqm.toFixed(2)} m² ({activeComp.area.difference_pct?.toFixed(4)}%)
                      </span>
                    </div>
                  )}

                  {/* Mandatory Validation Aid Note */}
                  <div className="alert alert-light border border-info-subtle mt-2 p-2 mb-0 small text-muted">
                    <i className="bi bi-info-circle text-info me-1" />
                    <strong>Validation Aid:</strong> {activeComp.area.note || 'Area comparison is a validation aid, not a determination of correctness.'}
                  </div>
                </div>
              </div>
            </div>

            {/* Geometry Preview Block */}
            <div className="col-lg-4">
              <div className="card shadow-sm h-100 border-0">
                <div className="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                  <span className="fw-bold text-dark small">Traverse Shape Preview</span>
                  <span className="badge bg-light text-dark">{activeComp.compute_crs}</span>
                </div>
                <div className="card-body p-2 d-flex align-items-center justify-content-center bg-white" style={{ minHeight: 200 }}>
                  {svgPreview ? (
                    <svg
                      viewBox={svgPreview.viewBox}
                      className="w-100 h-100"
                      style={{ maxHeight: 200 }}
                      preserveAspectRatio="xMidYMid meet"
                    >
                      <polygon
                        points={svgPreview.pts}
                        fill="#0d6efd"
                        fillOpacity="0.15"
                        stroke="#0d6efd"
                        strokeWidth={Math.max(svgPreview.w, svgPreview.h) * 0.012}
                        strokeLinejoin="round"
                      />
                      {svgPreview.verts.map((v, i) => (
                        <g key={i}>
                          <circle
                            cx={v.easting}
                            cy={-v.northing}
                            r={Math.max(svgPreview.w, svgPreview.h) * 0.02}
                            fill={i === 0 ? '#198754' : '#0d6efd'}
                            stroke="#fff"
                            strokeWidth={Math.max(svgPreview.w, svgPreview.h) * 0.005}
                          />
                        </g>
                      ))}
                    </svg>
                  ) : (
                    <div className="text-muted small">No geometry preview available</div>
                  )}
                </div>
              </div>
            </div>
          </div>

          {/* 3. Action Toolbar */}
          <div className="card shadow-sm border-0 bg-white p-3">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2">
              <div className="d-flex gap-2">
                <button
                  className="btn btn-sm btn-outline-primary"
                  onClick={handleViewSnapshot}
                  title="View immutable snapshot of inputs used for this calculation"
                >
                  <i className="bi bi-file-earmark-code me-1" /> View Input Snapshot
                </button>
                <button
                  className="btn btn-sm btn-outline-secondary"
                  onClick={() => setShowAdjustModal(true)}
                  title="Apply Compass or Transit rule adjustment"
                >
                  <i className="bi bi-tools me-1" /> Adjust Traverse…
                </button>
              </div>

              <div>
                <button
                  className="btn btn-sm btn-success fw-semibold shadow-sm px-3"
                  onClick={() => setShowAcceptModal(true)}
                  disabled={accepting}
                >
                  <i className="bi bi-check2-circle me-1" /> Accept Computation → Parcel Geometry
                </button>
              </div>
            </div>
          </div>

          {/* 4. Calculated Coordinates Table */}
          <div className="card shadow-sm border-0">
            <div className="card-header bg-white py-2 d-flex justify-content-between align-items-center">
              <span className="fw-bold text-dark small">Calculated Vertex Coordinates ({activeComp.vertices.length})</span>
              <span className="text-muted small font-monospace">Engine: {activeComp.engine_version}</span>
            </div>
            <div className="table-responsive">
              <table className="table table-hover table-sm align-middle mb-0 small">
                <thead className="table-light">
                  <tr>
                    <th style={{ width: 50 }}>#</th>
                    <th>Corner</th>
                    <th>Easting (m)</th>
                    <th>Northing (m)</th>
                    <th>Latitude</th>
                    <th>Longitude</th>
                    <th>ΔE (m)</th>
                    <th>ΔN (m)</th>
                  </tr>
                </thead>
                <tbody>
                  {activeComp.vertices.map((v, i) => (
                    <tr key={i} className={i === 0 ? 'table-light fw-semibold' : ''}>
                      <td>{v.seq}</td>
                      <td>
                        <span className="badge bg-primary-subtle text-primary border border-primary-subtle">
                          {v.label}
                        </span>
                      </td>
                      <td className="font-monospace">{v.easting.toFixed(4)}</td>
                      <td className="font-monospace">{v.northing.toFixed(4)}</td>
                      <td className="font-monospace">{v.latitude !== null ? v.latitude.toFixed(7) : '—'}</td>
                      <td className="font-monospace">{v.longitude !== null ? v.longitude.toFixed(7) : '—'}</td>
                      <td className="font-monospace text-muted">{v.delta_e > 0 ? `+${v.delta_e.toFixed(4)}` : v.delta_e.toFixed(4)}</td>
                      <td className="font-monospace text-muted">{v.delta_n > 0 ? `+${v.delta_n.toFixed(4)}` : v.delta_n.toFixed(4)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}

      {/* 5. Computation Run History Table */}
      <div className="card shadow-sm border-0">
        <div className="card-header bg-white py-2 d-flex justify-content-between align-items-center">
          <span className="fw-bold text-dark small">Computation Run History ({history.length})</span>
          <span className="text-muted small">Historical audit log of calculated traverses</span>
        </div>
        <div className="table-responsive">
          <table className="table table-hover table-sm align-middle mb-0 small">
            <thead className="table-light">
              <tr>
                <th>ID</th>
                <th>Computed At</th>
                <th>Method</th>
                <th>CRS</th>
                <th>Linear Error</th>
                <th>Precision</th>
                <th>Area (m²)</th>
                <th>Status</th>
                <th>By</th>
                <th className="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              {history.length === 0 ? (
                <tr>
                  <td colSpan={10} className="text-center py-3 text-muted">
                    No calculations recorded yet. Click <strong>Run Computation</strong> to begin.
                  </td>
                </tr>
              ) : (
                history.map((h) => (
                  <tr key={h.id} className={activeComp?.computation_id === h.id ? 'table-primary-subtle' : ''}>
                    <td className="font-monospace">#{h.id}</td>
                    <td>{new Date(h.computed_at).toLocaleString()}</td>
                    <td>
                      <span className="badge bg-light text-dark border">
                        {h.adjustment_method ? `ADJ: ${h.adjustment_method}` : h.method}
                      </span>
                    </td>
                    <td className="font-monospace small">{h.compute_crs}</td>
                    <td className="font-monospace">{h.linear_error_m.toFixed(4)}m</td>
                    <td className="font-monospace fw-semibold">{h.relative_precision}</td>
                    <td>{h.computed_area_sqm.toLocaleString(undefined, { maximumFractionDigits: 2 })}</td>
                    <td>
                      <span className={`badge ${h.closure_status === 'WITHIN_TOLERANCE' ? 'bg-success' : 'bg-danger'}`}>
                        {h.closure_status}
                      </span>
                    </td>
                    <td>{h.computed_by_name}</td>
                    <td className="text-end">
                      <div className="btn-group btn-group-sm">
                        <button
                          className="btn btn-outline-secondary btn-sm"
                          onClick={() => handleSelectHistoryComp(h.id)}
                          title="Inspect this computation"
                        >
                          View
                        </button>
                        <button
                          className="btn btn-outline-info btn-sm"
                          onClick={() => handleReplay(h.id)}
                          disabled={replayingId === h.id}
                          title="Verify mathematical replay determinism"
                        >
                          {replayingId === h.id ? 'Replaying…' : 'Replay'}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Snapshot Modal */}
      {showSnapshotModal && (
        <div className="modal show d-block bg-dark bg-opacity-50" tabIndex={-1} role="dialog">
          <div className="modal-dialog modal-lg modal-dialog-scrollable">
            <div className="modal-content">
              <div className="modal-header">
                <h6 className="modal-title fw-bold">Immutable Computation Input Snapshot</h6>
                <button type="button" className="btn-close" onClick={() => setShowSnapshotModal(false)} />
              </div>
              <div className="modal-body p-0">
                <pre className="bg-dark text-light p-3 m-0 small font-monospace" style={{ maxHeight: 450 }}>
                  {snapshotJson}
                </pre>
              </div>
              <div className="modal-footer">
                <button type="button" className="btn btn-sm btn-secondary" onClick={() => setShowSnapshotModal(false)}>
                  Close
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Adjust Modal */}
      {showAdjustModal && (
        <div className="modal show d-block bg-dark bg-opacity-50" tabIndex={-1} role="dialog">
          <div className="modal-dialog">
            <div className="modal-content">
              <div className="modal-header">
                <h6 className="modal-title fw-bold">Adjust Traverse Closure Error</h6>
                <button type="button" className="btn-close" onClick={() => setShowAdjustModal(false)} />
              </div>
              <div className="modal-body">
                <p className="small text-muted mb-3">
                  Distributes the linear closing error across courses according to Philippine standard survey procedures, producing a linked new computation record.
                </p>
                <div className="mb-3">
                  <label className="form-label small fw-semibold">Adjustment Method</label>
                  <select
                    className="form-select form-select-sm"
                    value={adjustMethod}
                    onChange={(e) => setAdjustMethod(e.target.value as any)}
                  >
                    <option value="COMPASS">Compass Rule (Bowditch) — Proportional to course length</option>
                    <option value="TRANSIT">Transit Rule — Proportional to latitude/departure</option>
                  </select>
                </div>
                <div className="mb-3">
                  <label className="form-label small fw-semibold">Adjustment Remarks / Notes</label>
                  <textarea
                    className="form-control form-control-sm"
                    rows={2}
                    placeholder="e.g. Adjusted closing error of 0.035m via Bowditch Compass rule prior to boundary registration."
                    value={adjustNote}
                    onChange={(e) => setAdjustNote(e.target.value)}
                  />
                </div>
              </div>
              <div className="modal-footer">
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setShowAdjustModal(false)}>
                  Cancel
                </button>
                <button
                  type="button"
                  className="btn btn-sm btn-primary"
                  onClick={handleExecuteAdjustment}
                  disabled={adjusting}
                >
                  {adjusting ? 'Applying…' : 'Apply Adjustment'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Accept Computation Modal */}
      {showAcceptModal && (
        <div className="modal show d-block bg-dark bg-opacity-50" tabIndex={-1} role="dialog">
          <div className="modal-dialog">
            <div className="modal-content">
              <div className="modal-header">
                <h6 className="modal-title fw-bold text-success">Accept Computation → Parcel Geometry</h6>
                <button type="button" className="btn-close" onClick={() => setShowAcceptModal(false)} />
              </div>
              <div className="modal-body">
                <div className="alert alert-info py-2 small mb-3">
                  <i className="bi bi-info-circle-fill me-1" />
                  Accepting this computation will set the parcel’s official geometry to the computed polygon and update provenance to <strong>COMPUTED_FROM_TECHNICAL_DESCRIPTION</strong>.
                </div>
                <p className="small text-muted mb-2">
                  This action increments the parcel version from <strong>v{parcel?.version ?? 1}</strong> to <strong>v{(parcel?.version ?? 1) + 1}</strong> and records a snapshot in the parcel version history.
                </p>
                <div className="mb-3">
                  <label className="form-label small fw-semibold">Change Reason (Required for Audit Log)</label>
                  <input
                    type="text"
                    className="form-control form-control-sm"
                    value={acceptReason}
                    onChange={(e) => setAcceptReason(e.target.value)}
                    placeholder="e.g. Survey technical review approved by geodetic engineer"
                  />
                </div>
              </div>
              <div className="modal-footer">
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setShowAcceptModal(false)}>
                  Cancel
                </button>
                <button
                  type="button"
                  className="btn btn-sm btn-success fw-semibold"
                  onClick={handleAcceptComputation}
                  disabled={accepting || !acceptReason.trim()}
                >
                  {accepting ? 'Accepting…' : 'Confirm & Accept'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Replay Result Modal */}
      {replayModalResult && (
        <div className="modal show d-block bg-dark bg-opacity-50" tabIndex={-1} role="dialog">
          <div className="modal-dialog">
            <div className="modal-content">
              <div className="modal-header">
                <h6 className="modal-title fw-bold">Mathematical Replay Verification</h6>
                <button type="button" className="btn-close" onClick={() => setReplayModalResult(null)} />
              </div>
              <div className="modal-body">
                <div className={`alert ${replayModalResult.matches_original ? 'alert-success' : 'alert-danger'} py-2 small mb-3`}>
                  <strong>Determinism Check:</strong>{' '}
                  {replayModalResult.matches_original
                    ? '100% Deterministic match! All coordinates reproduced exactly from stored input snapshot.'
                    : 'Discrepancy detected between recomputed and stored coordinates.'}
                </div>
                <table className="table table-sm small mb-0">
                  <tbody>
                    <tr>
                      <td className="text-muted">Computation ID:</td>
                      <td className="font-monospace">#{replayModalResult.computation_id}</td>
                    </tr>
                    <tr>
                      <td className="text-muted">Original Linear Error:</td>
                      <td className="font-monospace">{replayModalResult.original_closure.linear_error_m.toFixed(4)} m</td>
                    </tr>
                    <tr>
                      <td className="text-muted">Replayed Linear Error:</td>
                      <td className="font-monospace">{replayModalResult.replayed_closure.linear_error_m.toFixed(4)} m</td>
                    </tr>
                    <tr>
                      <td className="text-muted">Perimeter:</td>
                      <td className="font-monospace">{replayModalResult.original_closure.perimeter_m.toFixed(3)} m</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div className="modal-footer">
                <button type="button" className="btn btn-sm btn-secondary" onClick={() => setReplayModalResult(null)}>
                  Close
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
