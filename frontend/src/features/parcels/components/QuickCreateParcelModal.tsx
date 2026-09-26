import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { controlPointApi, type ControlPoint } from '../../control-points/api/controlPointApi';
import { parcelApi } from '../api/parcelApi';
import { surveyApi } from '../../survey/api/surveyApi';
import { BearingInput, type BearingValue } from '../../survey/components/BearingInput';
import { TraversePreviewMap } from '../../survey/components/TraversePreviewMap';
import type { TechnicalDescriptionCourse } from '../../survey/api/surveyApi';
import { ANGELES_CITY_CENTER } from '../../../lib/crs';

// ─── Types ────────────────────────────────────────────────────────────────────

interface CourseRow {
  id: string; // local uuid key
  from: string;
  to: string;
  bearing: BearingValue;
  distance: string;
  unit: 'm' | 'lk';
}

type Step = 1 | 2 | 3;

interface QuickCreateParcelModalProps {
  onClose: () => void;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function uid(): string {
  return Math.random().toString(36).slice(2, 10);
}

function distanceToMetres(val: string, unit: 'lk' | 'm'): number | null {
  const n = parseFloat(val);
  if (!isFinite(n) || n <= 0) return null;
  return unit === 'lk' ? n * 0.201168 : n;
}

/** Convert course rows into the shape TraversePreviewMap expects. */
function rowsToPreviewCourses(rows: CourseRow[]): TechnicalDescriptionCourse[] {
  return rows
    .filter((r) => r.bearing.azimuth_dd != null && distanceToMetres(r.distance, r.unit) != null)
    .map((r, i) => ({
      id: i + 1,
      technical_description_id: 0,
      seq: i + 1,
      from_point_label: r.from || String(i + 1),
      to_point_label: r.to || String(i + 2),
      bearing: {
        quadrant: r.bearing.quadrant ?? null,
        deg: r.bearing.deg ?? null,
        min: r.bearing.min ?? null,
        sec: r.bearing.sec ?? null,
        azimuth_dd: r.bearing.azimuth_dd ?? null,
        normalized: r.bearing.bearingStr ?? null,
      },
      distance: {
        value: parseFloat(r.distance),
        unit: r.unit,
        meters: distanceToMetres(r.distance, r.unit),
      },
      extraction_method: 'MANUALLY_ENTERED',
      confidence: null,
      is_confirmed: false,
      remarks: null,
    }));
}

// ─── Overlay map (Step 3) ─────────────────────────────────────────────────────

function OverlayMap({ geom }: { geom: GeoJSON.Polygon | null }) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<maplibregl.Map | null>(null);

  useEffect(() => {
    if (!containerRef.current || mapRef.current) return;
    const map = new maplibregl.Map({
      container: containerRef.current,
      style: 'https://demotiles.maplibre.org/style.json',
      center: ANGELES_CITY_CENTER,
      zoom: 14,
    });
    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    map.on('load', () => {
      map.addSource('parcel-preview', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
      map.addLayer({ id: 'parcel-fill', type: 'fill', source: 'parcel-preview', paint: { 'fill-color': '#2563eb', 'fill-opacity': 0.35 } });
      map.addLayer({ id: 'parcel-line', type: 'line', source: 'parcel-preview', paint: { 'line-color': '#1d4ed8', 'line-width': 2.5 } });
    });
    mapRef.current = map;
    return () => { map.remove(); mapRef.current = null; };
  }, []);

  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;
    const src = map.getSource('parcel-preview') as maplibregl.GeoJSONSource | undefined;
    if (!src) return;
    if (!geom) {
      src.setData({ type: 'FeatureCollection', features: [] });
      return;
    }
    src.setData({ type: 'FeatureCollection', features: [{ type: 'Feature', geometry: geom, properties: {} }] });
    // Fit to bounds
    const coords = geom.coordinates[0] as [number, number][];
    if (coords.length >= 2) {
      const bounds = coords.reduce(
        (b, c) => b.extend(c as maplibregl.LngLatLike),
        new maplibregl.LngLatBounds(coords[0], coords[0]),
      );
      map.fitBounds(bounds, { padding: 60, maxZoom: 18 });
    }
  }, [geom]);

  return <div ref={containerRef} style={{ height: 280, borderRadius: 8, overflow: 'hidden' }} />;
}

// ─── Main Modal ───────────────────────────────────────────────────────────────

export function QuickCreateParcelModal({ onClose }: QuickCreateParcelModalProps) {
  const navigate = useNavigate();

  // ── Step state ─────────────────────────────────────────────────────────────
  const [step, setStep] = useState<Step>(1);

  // ── Step 1 — BLLM picker ───────────────────────────────────────────────────
  const [bllmQuery, setBllmQuery] = useState('');
  const [bllmList, setBllmList] = useState<ControlPoint[]>([]);
  const [bllmLoading, setBllmLoading] = useState(false);
  const [selectedBllm, setSelectedBllm] = useState<ControlPoint | null>(null);

  // ── Step 2 — Courses ───────────────────────────────────────────────────────
  const [hasTieLine, setHasTieLine] = useState(true);
  const [tieLine, setTieLine] = useState({
    bearing: { quadrant: 'NE', deg: 0, min: 0, sec: 0 } as BearingValue,
    distance: '',
    unit: 'm' as 'm' | 'lk'
  });
  const [courses, setCourses] = useState<CourseRow[]>([
    { id: uid(), from: '1', to: '2', bearing: { quadrant: 'NE', deg: 0, min: 0, sec: 0 }, distance: '', unit: 'm' },
  ]);
  const [pobLabel, setPobLabel] = useState('1');

  // ── Step 3 — Parcel metadata & computed geometry ───────────────────────────
  const [parcelCode, setParcelCode] = useState('');
  const [computedGeom, setComputedGeom] = useState<GeoJSON.Polygon | null>(null);
  const [computing, setComputing] = useState(false);
  const [computeError, setComputeError] = useState<string | null>(null);

  // ── Submission ─────────────────────────────────────────────────────────────
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  // ── BLLM search (debounced) ────────────────────────────────────────────────
  useEffect(() => {
    if (step !== 1) return;
    setBllmLoading(true);
    const timer = setTimeout(async () => {
      try {
        const res = await controlPointApi.list({
          type: 'BLLM',
          q: bllmQuery.trim() || undefined,
          limit: 20,
        });
        setBllmList(res.data);
      } catch {
        setBllmList([]);
      } finally {
        setBllmLoading(false);
      }
    }, 300);
    return () => clearTimeout(timer);
  }, [bllmQuery, step]);

  // ── Preview courses (for TraversePreviewMap in step 2) ────────────────────
  const previewCourses = useMemo(() => rowsToPreviewCourses(courses), [courses]);

  // ── Course row helpers ────────────────────────────────────────────────────
  const addRow = useCallback(() => {
    setCourses((prev) => {
      const last = prev[prev.length - 1];
      const nextSeq = prev.length + 1;
      return [
        ...prev,
        {
          id: uid(),
          from: last?.to ?? String(nextSeq),
          to: String(nextSeq + 1),
          bearing: { quadrant: 'NE', deg: 0, min: 0, sec: 0 },
          distance: '',
          unit: 'm',
        },
      ];
    });
  }, []);

  const removeRow = useCallback((id: string) => {
    setCourses((prev) => prev.filter((r) => r.id !== id));
  }, []);

  const updateRow = useCallback(<K extends keyof CourseRow>(id: string, key: K, value: CourseRow[K]) => {
    setCourses((prev) => prev.map((r) => (r.id === id ? { ...r, [key]: value } : r)));
  }, []);

  // ── Step 2 → 3: compute geometry client-side from traverse ────────────────
  const handleComputePreview = useCallback(async () => {
    setComputeError(null);
    setComputedGeom(null);

    if (!selectedBllm) return;

    const validCourses = previewCourses.filter((c) => c.bearing.azimuth_dd != null && (c.distance.meters ?? 0) > 0);
    if (validCourses.length < 3) {
      setComputeError('At least 3 valid courses are required to form a polygon.');
      return;
    }

    // Use BLLM lat/lon as origin for WGS84 approximation
    const lat0 = selectedBllm.latitude ?? 15.136;  // default to Angeles City
    const lon0 = selectedBllm.longitude ?? 120.589;

    // Compute vertices in local plane (metres), then convert to WGS84
    const metersPerDegLat = 111_132;
    const metersPerDegLon = 111_132 * Math.cos((lat0 * Math.PI) / 180);

    let pobN = 0;
    let pobE = 0;

    // Offset by Tie Line
    if (hasTieLine && tieLine.bearing.azimuth_dd != null && distanceToMetres(tieLine.distance, tieLine.unit) != null) {
      const az = tieLine.bearing.azimuth_dd;
      const dist = distanceToMetres(tieLine.distance, tieLine.unit)!;
      const azRad = (az * Math.PI) / 180;
      pobN = dist * Math.cos(azRad);
      pobE = dist * Math.sin(azRad);
    }

    const pobLat = lat0 + pobN / metersPerDegLat;
    const pobLon = lon0 + pobE / metersPerDegLon;

    let curN = 0;
    let curE = 0;
    const ring: [number, number][] = [[pobLon, pobLat]];

    for (const c of validCourses) {
      const az = c.bearing.azimuth_dd!;
      const dist = c.distance.meters!;
      const azRad = (az * Math.PI) / 180;
      curN += dist * Math.cos(azRad);
      curE += dist * Math.sin(azRad);
      ring.push([pobLon + curE / metersPerDegLon, pobLat + curN / metersPerDegLat]);
    }
    // Close the ring
    ring.push(ring[0]);

    setComputedGeom({ type: 'Polygon', coordinates: [ring] });
    setStep(3);
  }, [selectedBllm, previewCourses, hasTieLine, tieLine]);

  // ── Final save: create parcel + TD + courses ───────────────────────────────
  const handleSave = useCallback(async () => {
    if (!parcelCode.trim()) { setSaveError('Parcel code is required.'); return; }
    if (!selectedBllm) { setSaveError('No BLLM selected.'); return; }
    setSaving(true);
    setSaveError(null);

    try {
      // 1. Create the parcel (geometry optional — we pass computed polygon)
      const parcel = await parcelApi.create({
        parcel_code: parcelCode.trim(),
        provenance: 'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
        psgc_barangay: selectedBllm.psgc_barangay ?? undefined,
        geometry: computedGeom,
      });

      // 2. Create a TD shell
      const td = await surveyApi.createForParcel(parcel.id, {
        source_type: 'MANUALLY_ENTERED',
        bearing_reference: 'GRID',
        distance_unit: courses[0]?.unit ?? 'm',
        point_of_beginning_label: pobLabel || '1',
        is_current: true,
      });

      // 3. Add tie line if enabled
      let seq = 1;
      if (hasTieLine && tieLine.bearing.azimuth_dd != null && distanceToMetres(tieLine.distance, tieLine.unit) != null) {
        await surveyApi.addCourse(td.id, {
          seq: seq++,
          from_point_label: selectedBllm.point_name,
          to_point_label: pobLabel || '1',
          quadrant: tieLine.bearing.quadrant ?? undefined,
          deg: tieLine.bearing.deg ?? undefined,
          min: tieLine.bearing.min ?? undefined,
          sec: tieLine.bearing.sec ?? undefined,
          distance: parseFloat(tieLine.distance),
          unit: tieLine.unit,
        });
      }

      // Add boundary courses
      for (let i = 0; i < previewCourses.length; i++) {
        const c = previewCourses[i];
        if (c.bearing.azimuth_dd == null || !c.distance.meters) continue;
        await surveyApi.addCourse(td.id, {
          seq: seq++,
          from_point_label: c.from_point_label,
          to_point_label: c.to_point_label,
          quadrant: c.bearing.quadrant ?? undefined,
          deg: c.bearing.deg ?? undefined,
          min: c.bearing.min ?? undefined,
          sec: c.bearing.sec ?? undefined,
          distance: c.distance.value ?? c.distance.meters,
          unit: c.distance.unit,
        });
      }

      // 4. Navigate to the full editor
      navigate(`/parcels/${parcel.id}/information`);
      onClose();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to create parcel.';
      setSaveError(msg);
    } finally {
      setSaving(false);
    }
  }, [parcelCode, selectedBllm, computedGeom, courses, previewCourses, pobLabel, hasTieLine, tieLine, navigate, onClose]);

  // ── Backdrop close on Escape ───────────────────────────────────────────────
  useEffect(() => {
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [onClose]);

  // ─────────────────────────────────────────────────────────────────────────
  return (
    <>
      {/* Backdrop */}
      <div
        onClick={onClose}
        style={{
          position: 'fixed', inset: 0,
          background: 'rgba(0,0,0,0.55)',
          backdropFilter: 'blur(3px)',
          zIndex: 1040,
          animation: 'qcpm-fade-in 0.15s ease',
        }}
      />

      {/* Modal */}
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="qcpm-title"
        data-testid="quick-create-parcel-modal"
        style={{
          position: 'fixed',
          inset: 0,
          zIndex: 1050,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          padding: '1rem',
          pointerEvents: 'none',
        }}
      >
        <div
          onClick={(e) => e.stopPropagation()}
          style={{
            pointerEvents: 'all',
            background: 'var(--bg-surface)',
            borderRadius: 16,
            boxShadow: '0 24px 64px rgba(0,108,140,0.18)',
            width: '100%',
            maxWidth: step === 2 ? 860 : 560,
            maxHeight: '90vh',
            display: 'flex',
            flexDirection: 'column',
            animation: 'qcpm-slide-up 0.2s cubic-bezier(0.16,1,0.3,1)',
            overflow: 'hidden',
          }}
        >
          {/* ── Header ── */}
          <div style={{
            padding: '1.125rem 1.5rem',
            borderBottom: '1px solid var(--border-color)',
            display: 'flex',
            alignItems: 'center',
            gap: '1rem',
          }}>
            {/* Step indicator */}
            <div style={{ display: 'flex', gap: 6 }}>
              {([1, 2, 3] as Step[]).map((s) => (
                <div key={s} style={{
                  width: 28, height: 28, borderRadius: '50%',
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 12, fontWeight: 700,
                  background: s === step ? 'var(--brand-primary)' : s < step ? 'var(--cerulean-100)' : 'var(--bg-surface-alt)',
                  color: s <= step ? '#fff' : 'var(--text-muted)',
                  border: s === step ? '2px solid var(--brand-primary)' : '2px solid var(--border-color)',
                  transition: 'all 0.2s',
                }}>
                  {s < step ? '✓' : s}
                </div>
              ))}
            </div>

            <div style={{ flex: 1 }}>
              <h2 id="qcpm-title" style={{ margin: 0, fontSize: '1rem', fontWeight: 700, color: 'var(--text-primary)' }}>
                {step === 1 && 'New Parcel — Select BLLM'}
                {step === 2 && 'New Parcel — Enter Technical Description'}
                {step === 3 && 'New Parcel — Confirm & Save'}
              </h2>
              <p style={{ margin: 0, fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: 2 }}>
                {step === 1 && 'Choose the BLLM control point this parcel is tied to.'}
                {step === 2 && 'Enter bearing and distance for each course. Preview updates live.'}
                {step === 3 && 'Review the computed polygon, enter the parcel code, then save.'}
              </p>
            </div>

            <button
              onClick={onClose}
              data-testid="qcpm-close"
              style={{
                background: 'none', border: 'none', cursor: 'pointer',
                color: 'var(--text-muted)', fontSize: 20, lineHeight: 1, padding: '0 4px',
              }}
              aria-label="Close"
            >×</button>
          </div>

          {/* ── Body ── */}
          <div style={{ overflowY: 'auto', padding: '1.25rem 1.5rem', flex: 1 }}>

            {/* ━━━ STEP 1 — BLLM Picker ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
            {step === 1 && (
              <div>
                <div style={{ marginBottom: '0.75rem' }}>
                  <label htmlFor="qcpm-bllm-q" className="form-label">Search BLLM</label>
                  <input
                    id="qcpm-bllm-q"
                    data-testid="qcpm-bllm-search"
                    className="form-input"
                    type="text"
                    placeholder="Type a point name, e.g. BLLM 1…"
                    value={bllmQuery}
                    onChange={(e) => setBllmQuery(e.target.value)}
                    autoFocus
                  />
                </div>

                {bllmLoading && (
                  <p style={{ color: 'var(--text-muted)', fontSize: '0.8125rem' }}>Searching…</p>
                )}

                {!bllmLoading && bllmList.length === 0 && (
                  <p style={{ color: 'var(--text-muted)', fontSize: '0.8125rem' }}>
                    No BLLM points found. Try a different search term.
                  </p>
                )}

                <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {bllmList.map((pt) => (
                    <button
                      key={pt.id}
                      data-testid={`qcpm-bllm-${pt.id}`}
                      onClick={() => setSelectedBllm((prev) => (prev?.id === pt.id ? null : pt))}
                      style={{
                        textAlign: 'left',
                        padding: '0.625rem 0.875rem',
                        borderRadius: 10,
                        border: selectedBllm?.id === pt.id
                          ? '2px solid var(--brand-primary)'
                          : '1px solid var(--border-color)',
                        background: selectedBllm?.id === pt.id
                          ? 'var(--cerulean-50)'
                          : 'var(--bg-surface)',
                        cursor: 'pointer',
                        transition: 'all 0.15s',
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 8 }}>
                        <span style={{ fontWeight: 600, fontSize: '0.875rem', color: 'var(--text-primary)' }}>
                          {pt.point_name}
                        </span>
                        <span style={{
                          fontSize: '0.6875rem', fontWeight: 600, letterSpacing: '0.04em',
                          padding: '1px 7px', borderRadius: 99,
                          background: pt.status === 'VERIFIED' ? 'rgba(34,197,94,0.15)' : 'rgba(245,158,11,0.15)',
                          color: pt.status === 'VERIFIED' ? '#22c55e' : '#f59e0b',
                        }}>
                          {pt.status}
                        </span>
                      </div>
                      <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: 2, fontFamily: 'var(--font-mono, monospace)' }}>
                        {pt.latitude != null && pt.longitude != null
                          ? `${pt.latitude.toFixed(6)}° N, ${pt.longitude.toFixed(6)}° E`
                          : pt.northing != null
                          ? `N ${pt.northing?.toLocaleString()} E ${pt.easting?.toLocaleString()} (PPCS)`
                          : 'No coordinates'}
                        {pt.survey_reference && (
                          <span style={{ marginLeft: 8, opacity: 0.7 }}>· {pt.survey_reference}</span>
                        )}
                      </div>
                    </button>
                  ))}
                </div>
              </div>
            )}

            {/* ━━━ STEP 2 — Courses ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
            {step === 2 && (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: '1.25rem' }}>

                {/* Left: course table */}
                <div>
                  {/* Tie Line Section */}
                  <div style={{ marginBottom: '1.25rem', padding: '0.75rem', background: 'var(--cerulean-50)', border: '1px solid var(--cerulean-200)', borderRadius: 10 }}>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                      <div style={{ fontSize: '0.8125rem', fontWeight: 600, color: 'var(--brand-primary)' }}>
                        Tie Line (from {selectedBllm?.point_name || 'BLLM'} to POB)
                      </div>
                      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.75rem', cursor: 'pointer', color: 'var(--text-primary)' }}>
                        <input type="checkbox" checked={hasTieLine} onChange={e => setHasTieLine(e.target.checked)} />
                        Include
                      </label>
                    </div>

                    {hasTieLine && (
                      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                        <div style={{ flex: 1 }}>
                          <BearingInput
                            value={tieLine.bearing}
                            onChange={(b) => setTieLine(prev => ({ ...prev, bearing: b }))}
                          />
                        </div>
                        <div style={{ width: 110 }}>
                          <div style={{ display: 'flex', gap: 4 }}>
                            <input
                              className="form-input"
                              type="number" min="0.001" step="0.001"
                              style={{ flex: 1, fontFamily: 'var(--font-mono)', textAlign: 'right' }}
                              value={tieLine.distance}
                              onChange={(e) => setTieLine(prev => ({ ...prev, distance: e.target.value }))}
                              placeholder="0.000"
                            />
                            <select
                              className="form-select"
                              style={{ width: 48, padding: '4px 4px' }}
                              value={tieLine.unit}
                              onChange={(e) => setTieLine(prev => ({ ...prev, unit: e.target.value as 'm' | 'lk' }))}
                            >
                              <option value="m">m</option>
                              <option value="lk">lk</option>
                            </select>
                          </div>
                        </div>
                      </div>
                    )}
                  </div>

                  {/* POB label */}
                  <div style={{ marginBottom: '0.875rem', display: 'flex', alignItems: 'center', gap: 8 }}>
                    <label htmlFor="qcpm-pob" className="form-label" style={{ margin: 0, whiteSpace: 'nowrap' }}>
                      Point of Beginning
                    </label>
                    <input
                      id="qcpm-pob"
                      data-testid="qcpm-pob"
                      className="form-input"
                      style={{ maxWidth: 80, fontFamily: 'var(--font-mono)', textAlign: 'center' }}
                      value={pobLabel}
                      onChange={(e) => setPobLabel(e.target.value)}
                    />
                    <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                      Tied to: <strong style={{ color: 'var(--text-primary)' }}>{selectedBllm?.point_name}</strong>
                    </span>
                  </div>

                  {/* Course rows */}
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                    {courses.map((row, idx) => (
                      <div
                        key={row.id}
                        data-testid={`qcpm-course-${idx}`}
                        style={{
                          background: 'var(--bg-surface)',
                          border: '1px solid var(--border-color)',
                          borderRadius: 10,
                          padding: '0.625rem 0.75rem',
                        }}
                      >
                        {/* Row header: seq + from/to labels */}
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 8 }}>
                          <span style={{
                            width: 22, height: 22, borderRadius: '50%', flexShrink: 0,
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                            background: 'var(--brand-primary)', color: '#fff',
                            fontSize: '0.6875rem', fontWeight: 700,
                          }}>{idx + 1}</span>
                          <input
                            aria-label="From point"
                            className="form-input"
                            style={{ width: 52, fontFamily: 'var(--font-mono)', textAlign: 'center', padding: '2px 6px', fontSize: 12 }}
                            value={row.from}
                            onChange={(e) => updateRow(row.id, 'from', e.target.value)}
                            placeholder="From"
                          />
                          <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>→</span>
                          <input
                            aria-label="To point"
                            className="form-input"
                            style={{ width: 52, fontFamily: 'var(--font-mono)', textAlign: 'center', padding: '2px 6px', fontSize: 12 }}
                            value={row.to}
                            onChange={(e) => updateRow(row.id, 'to', e.target.value)}
                            placeholder="To"
                          />
                          <div style={{ flex: 1 }} />
                          {courses.length > 1 && (
                            <button
                              type="button"
                              aria-label="Remove course"
                              data-testid={`qcpm-remove-course-${idx}`}
                              onClick={() => removeRow(row.id)}
                              style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444', fontSize: 16, lineHeight: 1 }}
                            >×</button>
                          )}
                        </div>

                        {/* Bearing + Distance */}
                        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                          <div style={{ flex: 1 }}>
                            <div style={{ fontSize: '0.6875rem', color: 'var(--text-muted)', marginBottom: 4, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Bearing</div>
                            <BearingInput
                              value={row.bearing}
                              onChange={(b) => updateRow(row.id, 'bearing', b)}
                            />
                          </div>
                          <div style={{ width: 110 }}>
                            <div style={{ fontSize: '0.6875rem', color: 'var(--text-muted)', marginBottom: 4, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Distance</div>
                            <div style={{ display: 'flex', gap: 4 }}>
                              <input
                                aria-label="Distance value"
                                data-testid={`qcpm-dist-${idx}`}
                                className="form-input"
                                type="number"
                                min="0.001"
                                step="0.001"
                                style={{ flex: 1, fontFamily: 'var(--font-mono)', textAlign: 'right' }}
                                value={row.distance}
                                onChange={(e) => updateRow(row.id, 'distance', e.target.value)}
                                placeholder="0.000"
                              />
                              <select
                                aria-label="Distance unit"
                                className="form-select"
                                style={{ width: 48, padding: '4px 4px' }}
                                value={row.unit}
                                onChange={(e) => updateRow(row.id, 'unit', e.target.value as 'm' | 'lk')}
                              >
                                <option value="m">m</option>
                                <option value="lk">lk</option>
                              </select>
                            </div>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>

                  <button
                    type="button"
                    data-testid="qcpm-add-course"
                    onClick={addRow}
                    style={{
                      marginTop: 10,
                      background: 'none',
                      border: '1px dashed var(--border-strong)',
                      borderRadius: 10,
                      width: '100%',
                      padding: '0.5rem',
                      color: 'var(--text-muted)',
                      cursor: 'pointer',
                      fontSize: '0.8125rem',
                      transition: 'all 0.15s',
                    }}
                    onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--bg-surface-hover)')}
                    onMouseLeave={(e) => (e.currentTarget.style.background = 'none')}
                  >
                    + Add course
                  </button>
                </div>

                {/* Right: live traverse preview */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  <div style={{ fontSize: '0.6875rem', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--text-muted)' }}>
                    Live Preview
                  </div>
                  <TraversePreviewMap courses={previewCourses} pobLabel={pobLabel || '1'} />

                  {/* Course count feedback */}
                  <div style={{
                    padding: '0.5rem 0.75rem',
                    background: 'var(--bg-surface-alt)',
                    border: '1px solid var(--border-color)',
                    borderRadius: 8,
                    fontSize: '0.75rem',
                    color: 'var(--text-muted)',
                  }}>
                    <strong style={{ color: 'var(--text-primary)' }}>{previewCourses.length}</strong> valid course{previewCourses.length !== 1 ? 's' : ''}
                    {' · '}
                    <strong style={{ color: 'var(--text-primary)' }}>{selectedBllm?.point_name}</strong>
                  </div>

                  {computeError && (
                    <div style={{ padding: '0.5rem 0.75rem', background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.3)', borderRadius: 8, fontSize: '0.75rem', color: '#f87171' }}>
                      {computeError}
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* ━━━ STEP 3 — Confirm ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
            {step === 3 && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                {/* Map overlay */}
                <OverlayMap geom={computedGeom} />

                {/* Summary cards */}
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                  <div style={{ padding: '0.625rem 0.875rem', background: 'var(--bg-surface-alt)', borderRadius: 10, border: '1px solid var(--border-color)' }}>
                    <div style={{ fontSize: '0.6875rem', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)', marginBottom: 4 }}>BLLM</div>
                    <div style={{ fontWeight: 600, fontSize: '0.875rem', color: 'var(--text-primary)' }}>{selectedBllm?.point_name}</div>
                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontFamily: 'var(--font-mono)' }}>
                      {selectedBllm?.latitude?.toFixed(6)}° N · {selectedBllm?.longitude?.toFixed(6)}° E
                    </div>
                  </div>
                  <div style={{ padding: '0.625rem 0.875rem', background: 'var(--bg-surface-alt)', borderRadius: 10, border: '1px solid var(--border-color)' }}>
                    <div style={{ fontSize: '0.6875rem', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)', marginBottom: 4 }}>Courses</div>
                    <div style={{ fontWeight: 600, fontSize: '0.875rem', color: 'var(--text-primary)' }}>{previewCourses.length} courses</div>
                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                      POB: <span style={{ fontFamily: 'var(--font-mono)' }}>{pobLabel || '1'}</span>
                    </div>
                  </div>
                </div>

                {/* Parcel code input */}
                <div>
                  <label htmlFor="qcpm-parcel-code" className="form-label">
                    Parcel Code <span style={{ color: '#ef4444' }}>*</span>
                  </label>
                  <input
                    id="qcpm-parcel-code"
                    data-testid="qcpm-parcel-code"
                    className="form-input"
                    style={{ fontFamily: 'var(--font-mono)', letterSpacing: '0.05em' }}
                    placeholder="e.g. PRC-2026-0001"
                    value={parcelCode}
                    onChange={(e) => setParcelCode(e.target.value)}
                    autoFocus
                  />
                  <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: 4 }}>
                    All other fields (title, TD no., owner, etc.) can be filled in the editor after saving.
                  </p>
                </div>

                {saveError && (
                  <div data-testid="qcpm-save-error" style={{ padding: '0.625rem 0.875rem', background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.3)', borderRadius: 10, fontSize: '0.8125rem', color: '#f87171' }}>
                    {saveError}
                  </div>
                )}
              </div>
            )}
          </div>

          {/* ── Footer ── */}
          <div style={{
            padding: '0.875rem 1.5rem',
            borderTop: '1px solid var(--border-color)',
            display: 'flex',
            justifyContent: 'space-between',
            gap: 8,
          }}>
            <div>
              {step > 1 && (
                <button
                  data-testid="qcpm-back"
                  className="btn btn-ghost btn-sm"
                  onClick={() => setStep((s) => (s - 1) as Step)}
                  disabled={saving || computing}
                >
                  ← Back
                </button>
              )}
            </div>

            <div style={{ display: 'flex', gap: 8 }}>
              <button className="btn btn-ghost btn-sm" onClick={onClose} disabled={saving}>
                Cancel
              </button>

              {step === 1 && (
                <button
                  data-testid="qcpm-next-1"
                  className="btn btn-primary btn-sm"
                  disabled={!selectedBllm}
                  onClick={() => setStep(2)}
                >
                  Next: Add courses →
                </button>
              )}

              {step === 2 && (
                <button
                  data-testid="qcpm-compute"
                  className="btn btn-primary btn-sm"
                  disabled={computing || previewCourses.length < 3}
                  onClick={handleComputePreview}
                >
                  {computing ? 'Computing…' : 'Compute polygon →'}
                </button>
              )}

              {step === 3 && (
                <button
                  data-testid="qcpm-save"
                  className="btn btn-primary btn-sm"
                  disabled={saving || !parcelCode.trim()}
                  onClick={handleSave}
                  style={{ minWidth: 110 }}
                >
                  {saving ? 'Saving…' : '💾 Save & open'}
                </button>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Keyframe animations */}
      <style>{`
        @keyframes qcpm-fade-in { from { opacity: 0 } to { opacity: 1 } }
        @keyframes qcpm-slide-up { from { opacity: 0; transform: translateY(20px) scale(0.97) } to { opacity: 1; transform: translateY(0) scale(1) } }
      `}</style>
    </>
  );
}
