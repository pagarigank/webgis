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
import {
    AREA_UNITS,
    PROVENANCE_LABELS,
    PROVENANCE_VALUES,
    PSGC_DIGIT_HINT,
    PSGC_FIELDS,
    SURVEY_DERIVED_PROVENANCE,
} from '../components/badges';
import { ParcelField } from '../components/ParcelField';

/**
 * TASK-072 — "New parcel" flow (frontend.md §20). Draw a polygon on the map
 * (`draw.create`/`draw.update`), watch the live readout (vertices · perimeter ·
 * area), then fill the attribute form and Save draft. The provenance selector
 * defaults to MANUAL_DRAWING, or DIGITIZED_FROM_IMAGERY while the satellite
 * basemap is active, with a persistent inline notice. Survey-derived options
 * stay disabled until survey data (survey_plan_id) is attached — then a
 * justification (change_reason) is required. Geometry is optional: a parcel
 * may be created without one (ParcelController::create allows `geometry: null`).
 *
 * Field order and markup match the editor's Information tab, since both describe
 * the same attributes.
 */

const PSGC_PATTERN = /^\d{9,12}$/;

/** Blank is allowed — the codes are optional — but a partial code is not. */
function psgcRule(value: string): true | string {
    const v = value.trim();
    if (v === '') return true;
    return PSGC_PATTERN.test(v) || `Enter a ${PSGC_DIGIT_HINT} PSGC code, or leave blank.`;
}

type CreateIssues = Partial<Record<keyof CreateFormValues, string>>;

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

/**
 * Per-field checks, so a mistake is reported on the field that caused it.
 *
 * These previously ran inside `submit` and surfaced as one generic banner above
 * the Save button, which gave the operator no clue which input was wrong. The
 * form has no resolver, so the messages are derived from the current values and
 * revealed once a submit has been attempted — they then clear as the operator
 * types, without any manual error bookkeeping.
 */
function validateCreate(values: CreateFormValues): CreateIssues {
    const issues: CreateIssues = {};

    if (values.parcel_code.trim() === '') {
        issues.parcel_code = 'A parcel code is required.';
    }

    const area = values.source_area_sqm;
    if (area !== '' && !(Number.isFinite(Number(area)) && Number(area) >= 0)) {
        issues.source_area_sqm = 'Enter a non-negative number, or leave blank.';
    }

    for (const name of ['psgc_province', 'psgc_municipality', 'psgc_barangay'] as const) {
        const verdict = psgcRule(values[name]);
        if (verdict !== true) issues[name] = verdict;
    }

    return issues;
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
    const [attempted, setAttempted] = useState(false);

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
                    label: PROVENANCE_LABELS[v] ?? v,
                    disabled: needsSurvey && !surveyAttached,
                };
            }),
        [surveyAttached],
    );

    const fieldErrors: CreateIssues = attempted ? validateCreate(watch()) : {};
    const areaUnitLabel = AREA_UNITS.find((u) => u.value === watch('source_area_unit'))?.label ?? 'm²';

    const submit = handleSubmit(async (values) => {
        setSaving(true);
        setError(null);
        setMessage(null);

        // Reveal the per-field messages on the first attempt, then keep them
        // live — they clear themselves as the operator corrects each field.
        setAttempted(true);
        if (Object.keys(validateCreate(values)).length > 0) {
            setSaving(false);
            return;
        }

        // Cross-field rules: these depend on a combination of inputs, so they
        // are reported in the banner above Save rather than on one field.
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
            parcel_code: values.parcel_code.trim(),
            provenance: values.provenance,
            lot_number: values.lot_number.trim() || null,
            block_number: values.block_number.trim() || null,
            title_number_ref: values.title_number_ref.trim() || null,
            tax_declaration_no: values.tax_declaration_no.trim() || null,
            source_area_sqm: values.source_area_sqm === '' ? null : Number(values.source_area_sqm),
            source_area_unit: values.source_area_unit,
            psgc_barangay: PSGC_PATTERN.test(values.psgc_barangay.trim()) ? values.psgc_barangay.trim() : null,
            psgc_municipality: PSGC_PATTERN.test(values.psgc_municipality.trim()) ? values.psgc_municipality.trim() : null,
            psgc_province: PSGC_PATTERN.test(values.psgc_province.trim()) ? values.psgc_province.trim() : null,
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
                                <div className="row g-3">
                                    <ParcelField
                                        id="create-parcel-code"
                                        label="Parcel code"
                                        required
                                        error={fieldErrors.parcel_code}
                                        className="col-12"
                                    >
                                        <Controller
                                            name="parcel_code"
                                            control={control}
                                            render={({ field: f }) => (
                                                <input
                                                    id="create-parcel-code"
                                                    {...f}
                                                    className={`form-control ${fieldErrors.parcel_code ? 'is-invalid' : ''}`}
                                                    placeholder="e.g. PRC-2026-0001"
                                                    aria-invalid={fieldErrors.parcel_code ? true : undefined}
                                                    aria-describedby={
                                                        fieldErrors.parcel_code ? 'create-parcel-code-error' : undefined
                                                    }
                                                    data-testid="parcel-create-code"
                                                />
                                            )}
                                        />
                                    </ParcelField>

                                    <ParcelField id="create-lot" label="Lot number" className="col-6">
                                        <Controller
                                            name="lot_number"
                                            control={control}
                                            render={({ field: f }) => (
                                                <input id="create-lot" {...f} className="form-control" data-testid="parcel-create-lot" />
                                            )}
                                        />
                                    </ParcelField>

                                    <ParcelField id="create-block" label="Block number" className="col-6">
                                        <Controller
                                            name="block_number"
                                            control={control}
                                            render={({ field: f }) => (
                                                <input
                                                    id="create-block"
                                                    {...f}
                                                    className="form-control"
                                                    data-testid="parcel-create-block"
                                                />
                                            )}
                                        />
                                    </ParcelField>

                                    <ParcelField id="create-title" label="Title reference" className="col-6">
                                        <Controller
                                            name="title_number_ref"
                                            control={control}
                                            render={({ field: f }) => (
                                                <input
                                                    id="create-title"
                                                    {...f}
                                                    className="form-control"
                                                    data-testid="parcel-create-title"
                                                />
                                            )}
                                        />
                                    </ParcelField>

                                    <ParcelField id="create-td" label="Tax declaration no." className="col-6">
                                        <Controller
                                            name="tax_declaration_no"
                                            control={control}
                                            render={({ field: f }) => (
                                                <input
                                                    id="create-td"
                                                    {...f}
                                                    className="form-control"
                                                    data-testid="parcel-create-td"
                                                />
                                            )}
                                        />
                                    </ParcelField>

                                    {/* The stored number is whatever unit is selected beside it;
                                        the backend keeps `source_area_sqm` and
                                        `source_area_unit` in separate columns and converts
                                        nothing. The old "Source area (m²)" label therefore
                                        lied as soon as the operator picked hectares. */}
                                    <ParcelField
                                        id="create-area"
                                        label="Source area"
                                        hint={`Entered in ${areaUnitLabel}.`}
                                        error={fieldErrors.source_area_sqm}
                                        className="col-6"
                                    >
                                        <Controller
                                            name="source_area_sqm"
                                            control={control}
                                            render={({ field: f }) => (
                                                <input
                                                    id="create-area"
                                                    {...f}
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    className={`form-control ${fieldErrors.source_area_sqm ? 'is-invalid' : ''}`}
                                                    aria-invalid={fieldErrors.source_area_sqm ? true : undefined}
                                                    aria-describedby={
                                                        fieldErrors.source_area_sqm ? 'create-area-error' : 'create-area-hint'
                                                    }
                                                    data-testid="parcel-create-area-sqm"
                                                />
                                            )}
                                        />
                                    </ParcelField>

                                    <ParcelField id="create-area-unit" label="Unit" className="col-6">
                                        <Controller
                                            name="source_area_unit"
                                            control={control}
                                            render={({ field: f }) => (
                                                <select
                                                    id="create-area-unit"
                                                    {...f}
                                                    className="form-select"
                                                    data-testid="parcel-create-area-unit"
                                                >
                                                    {AREA_UNITS.map((u) => (
                                                        <option key={u.value} value={u.value}>
                                                            {u.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                        />
                                    </ParcelField>

                                    {PSGC_FIELDS.map((p) => {
                                        const name = p.name as keyof CreateFormValues;
                                        const message = fieldErrors[name];
                                        return (
                                            <ParcelField
                                                key={p.name}
                                                id={`create-${p.name}`}
                                                label={
                                                    <>
                                                        PSGC — {p.label}{' '}
                                                        <span className="badge bg-light text-dark border">
                                                            {PSGC_DIGIT_HINT}
                                                        </span>
                                                    </>
                                                }
                                                error={message}
                                                className="col-6"
                                            >
                                                <Controller
                                                    name={p.name}
                                                    control={control}
                                                    render={({ field: f }) => (
                                                        <input
                                                            id={`create-${p.name}`}
                                                            {...f}
                                                            className={`form-control font-monospace ${message ? 'is-invalid' : ''}`}
                                                            placeholder={p.placeholder}
                                                            inputMode="numeric"
                                                            aria-invalid={message ? true : undefined}
                                                            aria-describedby={message ? `create-${p.name}-error` : undefined}
                                                            data-testid={p.testId}
                                                        />
                                                    )}
                                                />
                                            </ParcelField>
                                        );
                                    })}

                                    <ParcelField id="create-location" label="Location description" className="col-12">
                                        <Controller
                                            name="location_description"
                                            control={control}
                                            render={({ field: f }) => (
                                                <textarea
                                                    id="create-location"
                                                    {...f}
                                                    rows={2}
                                                    className="form-control"
                                                    data-testid="parcel-create-location"
                                                />
                                            )}
                                        />
                                    </ParcelField>

                                    <div className="col-12">
                                        <ParcelField
                                            id="create-provenance"
                                            label="Provenance (geometry source)"
                                        >
                                            <Controller
                                                name="provenance"
                                                control={control}
                                                render={({ field: f }) => (
                                                    <select
                                                        id="create-provenance"
                                                        {...f}
                                                        className="form-select"
                                                        aria-describedby="create-provenance-hint"
                                                        data-testid="parcel-create-provenance"
                                                    >
                                                        {provenanceOptions.map((o) => (
                                                            <option key={o.value} value={o.value} disabled={o.disabled}>
                                                                {o.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                )}
                                            />
                                            <div className="form-text" id="create-provenance-hint" data-testid="parcel-create-provenance-help">
                                                {basemap === 'satellite'
                                                    ? 'Digitising over imagery → DIGITIZED_FROM_IMAGERY'
                                                    : 'Drawn by hand in the map editor → MANUAL_DRAWING'}
                                            </div>
                                            {/* Only while the lockout is in effect. Once a survey
                                                plan is attached the note is stale noise under a
                                                field the operator is actively using. */}
                                            {!surveyAttached && (
                                                <div
                                                    className="alert alert-warning py-2 px-3 small mt-2 mb-0"
                                                    data-testid="parcel-create-provenance-notice"
                                                >
                                                    Survey-derived options are unavailable until a survey plan is
                                                    attached; choosing one then requires a recorded justification.
                                                </div>
                                            )}
                                        </ParcelField>
                                        {surveyDerived && (
                                            <div className="form-text text-danger mt-1" data-testid="parcel-create-survey-note">
                                                Survey-derived provenance — record a justification below.
                                            </div>
                                        )}
                                    </div>

                                    <ParcelField
                                        id="create-survey-plan"
                                        label="Survey plan ID"
                                        hint="Numeric ID of an existing survey plan. Required to unlock survey-derived provenance."
                                        className="col-12"
                                    >
                                        <Controller
                                            name="survey_plan_id"
                                            control={control}
                                            render={({ field: f }) => (
                                                <input
                                                    id="create-survey-plan"
                                                    {...f}
                                                    type="number"
                                                    min="1"
                                                    className="form-control"
                                                    placeholder="required for survey-derived provenance"
                                                    aria-describedby="create-survey-plan-hint"
                                                    data-testid="parcel-create-survey-plan"
                                                />
                                            )}
                                        />
                                    </ParcelField>

                                    {surveyDerived && (
                                        <ParcelField
                                            id="create-justification"
                                            label="Justification"
                                            required
                                            className="col-12"
                                        >
                                            <Controller
                                                name="justification"
                                                control={control}
                                                render={({ field: f }) => (
                                                    <textarea
                                                        id="create-justification"
                                                        {...f}
                                                        rows={2}
                                                        className="form-control"
                                                        placeholder="Why is this parcel survey-derived?"
                                                        data-testid="parcel-create-justification"
                                                    />
                                                )}
                                            />
                                        </ParcelField>
                                    )}

                                    <ParcelField id="create-remarks" label="Remarks" className="col-12">
                                        <Controller
                                            name="remarks"
                                            control={control}
                                            render={({ field: f }) => (
                                                <textarea
                                                    id="create-remarks"
                                                    {...f}
                                                    rows={3}
                                                    className="form-control"
                                                    data-testid="parcel-create-remarks"
                                                />
                                            )}
                                        />
                                    </ParcelField>

                                    {error && (
                                        <div className="col-12">
                                            <div className="alert alert-danger py-2 px-3 small mb-0" data-testid="parcel-create-error">
                                                {error}
                                            </div>
                                        </div>
                                    )}
                                    {message && (
                                        <div className="col-12">
                                            <div
                                                className="alert alert-success py-2 px-3 small mb-0"
                                                data-testid="parcel-create-message"
                                            >
                                                {message}
                                            </div>
                                        </div>
                                    )}

                                    <div className="col-12 d-flex gap-2">
                                        <button
                                            type="submit"
                                            className="btn btn-primary"
                                            disabled={saving}
                                            data-testid="parcel-create-save-draft"
                                        >
                                            {saving ? 'Saving…' : 'Save draft'}
                                        </button>
                                        <Link to="/parcels" className="btn btn-outline-secondary">
                                            Cancel
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
