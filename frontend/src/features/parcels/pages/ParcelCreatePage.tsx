import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm, Controller } from 'react-hook-form';
import * as maplibregl from 'maplibre-gl';
import * as MapboxDraw from '@mapbox/mapbox-gl-draw';
import axios from 'axios';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@mapbox/mapbox-gl-draw/dist/mapbox-gl-draw.css';
import { parcelApi } from '../api/parcelApi';
import { useBasemapToggle, type BasemapKind } from '../../map/basemap';
import { polygonReadout } from '../../../lib/geometry';
import { PROVENANCE_VALUES, SURVEY_DERIVED_PROVENANCE } from '../components/badges';

/**
 * TASK-072 — "New parcel" flow (frontend.md §20). Draw a polygon on the map
 * (`draw.create`/`draw.update`), watch the live readout (vertices · perimeter ·
 * area), then fill the attribute form and Save draft. The provenance selector
 * defaults to MANUAL_DRAWING, or DIGITIZED_FROM_IMAGERY while the satellite
 * basemap is active, with a persistent inline notice. Survey-derived options
 * stay disabled until survey data (survey_plan_id) is attached — then a
 * justification (change_reason) is required. Geometry is optional: a parcel
 * may be created without one (ParcelController::create allows `geometry: null`).
 */

interface CreateFormValues {
    parcel_code: string;
    lot_number: string;
    block_number: string;
    title_number_ref: string;
    tax_declaration_no: string;
    source_area_sqm: string;
    source_area_unit: string;
    psgc_barangay: string;
    psgc_municipality: string;
    psgc_province: string;
    location_description: string;
    remarks: string;
    provenance: string;
    survey_plan_id: string;
    justification: string;
}

const basemapButtons: { kind: BasemapKind; label: string }[] = [
    { kind: 'roads', label: '🛣 Roads' },
    { kind: 'satellite', label: '🛰 Satellite' },
];

const btnStyle: React.CSSProperties = {
    background: '#fff',
    borderWidth: 1,
    borderStyle: 'solid',
    borderColor: '#d1d5db',
    borderRadius: 6,
    padding: '6px 10px',
    fontSize: 13,
    cursor: 'pointer',
    color: '#1f2937',
};

export function ParcelCreatePage() {
    const navigate = useNavigate();
    const mapContainer = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const drawRef = useRef<MapboxDraw | null>(null);
    const { basemap, setBasemap } = useBasemapToggle(mapRef.current, 'satellite');

    const [geometry, setGeometry] = useState<GeoJSON.Polygon | GeoJSON.MultiPolygon | null>(null);
    const [readout, setReadout] = useState<ReturnType<typeof polygonReadout>>(undefined);
    const [saving, setSaving] = useState(false);
    const [mapReady, setMapReady] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const { control, handleSubmit, reset, watch, setValue } = useForm<CreateFormValues>({
        defaultValues: {
            parcel_code: '',
            lot_number: '',
            block_number: '',
            title_number_ref: '',
            tax_declaration_no: '',
            source_area_sqm: '',
            source_area_unit: 'sqm',
            psgc_barangay: '',
            psgc_municipality: '',
            psgc_province: '',
            location_description: '',
            remarks: '',
            provenance: 'MANUAL_DRAWING',
            survey_plan_id: '',
            justification: '',
        },
    });
    const provenance = watch('provenance');
    const surveyPlanId = watch('survey_plan_id');
    const surveyDerived = SURVEY_DERIVED_PROVENANCE.has(provenance);

    // Default provenance follows the basemap (frontend.md §20): imagery active →
    // DIGITIZED_FROM_IMAGERY, otherwise MANUAL_DRAWING. Keep the current typed
    // value only while it stays valid for the mode.
    useEffect(() => {
        const suggested = basemap === 'satellite' ? 'DIGITIZED_FROM_IMAGERY' : 'MANUAL_DRAWING';
        setValue('provenance', suggested, { shouldValidate: false, shouldDirty: false });
    }, [basemap, setValue]);

    useEffect(() => {
        if (!mapContainer.current || mapRef.current) return;

        const map = new maplibregl.Map({
            container: mapContainer.current,
            style: 'https://demotiles.maplibre.org/style.json',
            center: [121.0, 14.5],
            zoom: 15,
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        mapRef.current = map;

        const DrawConstructor = (MapboxDraw as any).default || MapboxDraw;
        const draw = new DrawConstructor({
            displayControlsDefault: false,
            controls: { polygon: true, trash: true },
        });
        map.addControl(draw as any);
        drawRef.current = draw;

        // frontend.md §20 — the flow opens straight into polygon drawing.
        map.on('load', () => {
            draw.changeMode('draw_polygon');
            setMapReady(true);
        });

        // TASK-072 default provenance should reflect the basemap at first paint.
        const refresh = () => {
            if (!drawRef.current) return;
            const data = drawRef.current.getAll();
            const feature = (data?.features ?? []).find((f: any) => f.geometry);
            if (!feature) {
                setGeometry(null);
                setReadout(undefined);
                return;
            }
            const g = feature.geometry as GeoJSON.Polygon | GeoJSON.MultiPolygon;
            setGeometry(g);
            setReadout(polygonReadout(g));
        };

        map.on('draw.create' as any, refresh);
        map.on('draw.update' as any, refresh);
        map.on('draw.delete' as any, refresh);

        return () => {
            map.remove();
            mapRef.current = null;
            drawRef.current = null;
        };
    }, []);

    // Reflect the current provenances available — survey-derived options are
    // disabled until survey data is attached.
    const surveyAttached = surveyPlanId.trim() !== '';
    const provenanceOptions = useMemo(
        () =>
            PROVENANCE_VALUES.map((v) => {
                const needsSurvey = SURVEY_DERIVED_PROVENANCE.has(v);
                return {
                    value: v,
                    disabled: needsSurvey && !surveyAttached,
                };
            }),
        [surveyAttached],
    );

    const submit = handleSubmit(async (values) => {
        setSaving(true);
        setError(null);
        setMessage(null);

        const code = values.parcel_code.trim();
        if (code === '') {
            setError('A parcel code is required.');
            setSaving(false);
            return;
        }
        if (surveyDerived && !surveyAttached) {
            setError('Attach a survey plan before choosing a survey-derived provenance.');
            setSaving(false);
            return;
        }
        if (surveyDerived && values.justification.trim() === '') {
            setError('A recorded justification is required for survey-derived provenance.');
            setSaving(false);
            return;
        }

        const input = {
            parcel_code: code,
            provenance: values.provenance,
            lot_number: values.lot_number.trim() || null,
            block_number: values.block_number.trim() || null,
            title_number_ref: values.title_number_ref.trim() || null,
            tax_declaration_no: values.tax_declaration_no.trim() || null,
            source_area_sqm: values.source_area_sqm === '' ? null : Number(values.source_area_sqm),
            source_area_unit: values.source_area_unit,
            psgc_barangay: /^\d{10,12}$/.test(values.psgc_barangay.trim()) ? values.psgc_barangay.trim() : null,
            psgc_municipality: /^\d{10,12}$/.test(values.psgc_municipality.trim()) ? values.psgc_municipality.trim() : null,
            psgc_province: /^\d{10,12}$/.test(values.psgc_province.trim()) ? values.psgc_province.trim() : null,
            location_description: values.location_description.trim() || null,
            remarks: values.remarks.trim() || null,
            geometry,
            survey_plan_id: surveyAttached ? Number(values.survey_plan_id) : null,
            change_reason: surveyDerived ? values.justification.trim() : undefined,
        };

        try {
            const parcel = await parcelApi.create(input);
            setMessage(`Saved draft ${parcel.parcel_code} (v${parcel.version}).`);
            reset();
            navigate(`/parcels/${parcel.id}/information`);
        } catch (err) {
            if (axios.isAxiosError(err)) {
                const data = err.response?.data as { success?: boolean; message?: string; detail?: string } | undefined;
                setError(data?.detail ?? data?.message ?? err.message);
            } else {
                setError('Failed to save the parcel.');
            }
            setSaving(false);
        }
    });

    return (
        <div className="container-fluid py-4">
            <div className="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h3 className="mb-0">New parcel</h3>
                    <span className="text-muted small">Draw a polygon, then fill the details and save the draft.</span>
                </div>
                <div className="d-flex gap-2">
                    {basemapButtons.map((b) => (
                        <button
                            key={b.kind}
                            data-testid={`create-basemap-${b.kind}`}
                            onClick={() => setBasemap(b.kind)}
                            style={{ ...btnStyle, ...(basemap === b.kind ? { background: '#2563eb', color: '#fff', borderColor: '#1d4ed8' } : {}) }}
                        >
                            {b.label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="row g-4">
                <div className="col-lg-8">
                    <div className="card shadow-sm">
                        <div className="card-header py-2 small fw-semibold">Draw polygon</div>
                        <div ref={mapContainer} style={{ height: 440 }} data-testid="parcel-create-map" data-map-ready={mapReady ? 'true' : 'false'} />
                    </div>

                    <div className="card shadow-sm mt-3">
                        <div className="card-body">
                            <h6 className="text-muted mb-2">Live readout</h6>
                            {readout ? (
                                <div className="row text-center" data-testid="parcel-create-readout">
                                    <div className="col-4">
                                        <div className="fs-4 fw-semibold" data-testid="parcel-create-vertices">{readout.vertexCount}</div>
                                        <div className="small text-muted">vertices</div>
                                    </div>
                                    <div className="col-4">
                                        <div className="fs-4 fw-semibold" data-testid="parcel-create-perimeter">
                                            {readout.perimeterM.toLocaleString(undefined, { maximumFractionDigits: 1 })} m
                                        </div>
                                        <div className="small text-muted">perimeter</div>
                                    </div>
                                    <div className="col-4">
                                        <div className="fs-4 fw-semibold" data-testid="parcel-create-area">
                                            {readout.areaSqm.toLocaleString(undefined, { maximumFractionDigits: 1 })} m²
                                        </div>
                                        <div className="small text-muted">area</div>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-muted small mb-0" data-testid="parcel-create-readout">
                                    No polygon drawn yet — start clicking on the map to place vertices.
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                <div className="col-lg-4">
                    <form onSubmit={submit} noValidate>
                        <div className="card shadow-sm">
                            <div className="card-header py-2 d-flex justify-content-between align-items-center">
                                <span className="small fw-semibold">Parcel details</span>
                            </div>
                            <div className="card-body">
                                <div className="col-12 mb-3">
                                    <label className="form-label small text-muted mb-1">Parcel code</label>
                                    <Controller {...{ name: 'parcel_code', control }} render={({ field: f }) => (
                                        <input {...f} className="form-control" placeholder="e.g. PRC-2026-0001" data-testid="parcel-create-code" />
                                    )} />
                                </div>

                                <div className="row g-3 mb-3">
                                    <div className="col-6">
                                        <label className="form-label small text-muted mb-1">Lot number</label>
                                        <Controller {...{ name: 'lot_number', control }} render={({ field: f }) => (
                                            <input {...f} className="form-control" data-testid="parcel-create-lot" />
                                        )} />
                                    </div>
                                    <div className="col-6">
                                        <label className="form-label small text-muted mb-1">Block number</label>
                                        <Controller {...{ name: 'block_number', control }} render={({ field: f }) => (
                                            <input {...f} className="form-control" data-testid="parcel-create-block" />
                                        )} />
                                    </div>
                                    <div className="col-6">
                                        <label className="form-label small text-muted mb-1">Title reference</label>
                                        <Controller {...{ name: 'title_number_ref', control }} render={({ field: f }) => (
                                            <input {...f} className="form-control" data-testid="parcel-create-title" />
                                        )} />
                                    </div>
                                    <div className="col-6">
                                        <label className="form-label small text-muted mb-1">Tax declaration no.</label>
                                        <Controller {...{ name: 'tax_declaration_no', control }} render={({ field: f }) => (
                                            <input {...f} className="form-control" data-testid="parcel-create-td" />
                                        )} />
                                    </div>
                                    <div className="col-6">
                                        <label className="form-label small text-muted mb-1">Source area (m²)</label>
                                        <Controller {...{ name: 'source_area_sqm', control }} render={({ field: f }) => (
                                            <input {...f} type="number" step="0.0001" min="0" className="form-control" data-testid="parcel-create-area-sqm" />
                                        )} />
                                    </div>
                                    <div className="col-6">
                                        <label className="form-label small text-muted mb-1">Area unit</label>
                                        <Controller {...{ name: 'source_area_unit', control }} render={({ field: f }) => (
                                            <select {...f} className="form-select" data-testid="parcel-create-area-unit">
                                                <option value="sqm">sqm</option>
                                                <option value="ha">ha</option>
                                            </select>
                                        )} />
                                    </div>
                                </div>

                                <div className="mb-3">
                                    <label className="form-label small text-muted mb-1">
                                        PSGC — barangay <span className="badge bg-light text-dark border">10–12 digits</span>
                                    </label>
                                    <Controller {...{ name: 'psgc_barangay', control }} render={({ field: f }) => (
                                        <input {...f} className="form-control font-monospace" placeholder="e.g. 133901001" data-testid="parcel-create-psgc-barangay" />
                                    )} />
                                </div>
                                <div className="row g-3 mb-3">
                                    <div className="col-6">
                                        <label className="form-label small text-muted mb-1">PSGC — municipality / city</label>
                                        <Controller {...{ name: 'psgc_municipality', control }} render={({ field: f }) => (
                                            <input {...f} className="form-control font-monospace" data-testid="parcel-create-psgc-muni" />
                                        )} />
                                    </div>
                                    <div className="col-6">
                                        <label className="form-label small text-muted mb-1">PSGC — province</label>
                                        <Controller {...{ name: 'psgc_province', control }} render={({ field: f }) => (
                                            <input {...f} className="form-control font-monospace" data-testid="parcel-create-psgc-prov" />
                                        )} />
                                    </div>
                                </div>

                                <div className="mb-3">
                                    <label className="form-label small text-muted mb-1">Location description</label>
                                    <Controller {...{ name: 'location_description', control }} render={({ field: f }) => (
                                        <textarea {...f} rows={2} className="form-control" data-testid="parcel-create-location" />
                                    )} />
                                </div>

                                <div className="mb-3">
                                    <label className="form-label small text-muted mb-1">Provenance (geometry source)</label>
                                    <Controller {...{ name: 'provenance', control }} render={({ field: f }) => (
                                        <select {...f} className="form-select" data-testid="parcel-create-provenance">
                                            {provenanceOptions.map((o) => (
                                                <option key={o.value} value={o.value} disabled={o.disabled}>{o.value}</option>
                                            ))}
                                        </select>
                                    )} />
                                    <div className="form-text" data-testid="parcel-create-provenance-help">
                                        {basemap === 'satellite'
                                            ? 'Digitising over imagery → DIGITIZED_FROM_IMAGERY'
                                            : 'Drawn by hand in the map editor → MANUAL_DRAWING'}
                                    </div>
                                    <div className="alert alert-warning py-2 px-3 small mt-2 mb-0" data-testid="parcel-create-provenance-notice">
                                        Survey-derived provenance options are disabled until survey data
                                        (survey plan) is attached; switching to one then requires a recorded justification.
                                    </div>
                                    {surveyDerived && (
                                        <div className="form-text text-danger mt-1" data-testid="parcel-create-survey-note">
                                            Survey-derived provenance — attach the survey plan and record a justification below.
                                        </div>
                                    )}
                                </div>

                                <div className="mb-3">
                                        <label className="form-label small text-muted mb-1">Survey plan ID</label>
                                        <Controller {...{ name: 'survey_plan_id', control }} render={({ field: f }) => (
                                            <input {...f} type="number" min="1" className="form-control" placeholder="required for survey-derived provenance" data-testid="parcel-create-survey-plan" />
                                        )} />
                                        <div className="form-text">Attach a survey plan to unlock survey-derived provenance options.</div>
                                    </div>

                                {surveyDerived && (
                                    <div className="mb-3">
                                        <label className="form-label small text-muted mb-1">Justification</label>
                                        <Controller {...{ name: 'justification', control }} render={({ field: f }) => (
                                            <textarea {...f} rows={2} className="form-control" placeholder="Why is this parcel survey-derived?" data-testid="parcel-create-justification" />
                                        )} />
                                    </div>
                                )}

                                <div className="mb-3">
                                    <label className="form-label small text-muted mb-1">Remarks</label>
                                    <Controller {...{ name: 'remarks', control }} render={({ field: f }) => (
                                        <textarea {...f} rows={3} className="form-control" data-testid="parcel-create-remarks" />
                                    )} />
                                </div>

                                {error && (
                                    <div className="alert alert-danger py-2 px-3 small" data-testid="parcel-create-error">{error}</div>
                                )}
                                {message && (
                                    <div className="alert alert-success py-2 px-3 small" data-testid="parcel-create-message">{message}</div>
                                )}

                                <div className="d-flex gap-2">
                                    <button
                                        type="submit"
                                        className="btn btn-primary"
                                        disabled={saving}
                                        data-testid="parcel-create-save-draft"
                                    >
                                        {saving ? 'Saving…' : 'Save draft'}
                                    </button>
                                    <Link to="/parcels" className="btn btn-outline-secondary">Cancel</Link>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}