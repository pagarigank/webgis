import { useEffect, useRef, useMemo } from 'react';
import { useForm, Controller } from 'react-hook-form';
import type { Parcel } from '../types';
import {
    AREA_UNITS,
    PROVENANCE_HELP,
    PROVENANCE_LABELS,
    PROVENANCE_VALUES,
    PSGC_DIGIT_HINT,
    PSGC_FIELDS,
    SURVEY_DERIVED_PROVENANCE,
} from './badges';
import { ParcelField } from './ParcelField';

/**
 * Information tab (frontend.md §7): lot/block, PSGC codes, tax declaration,
 * source area + unit, location description, remarks, and the provenance
 * selector with its inline explanation. Field edits surface upward so the
 * sticky status bar can show "Unsaved changes" and drive the save action.
 *
 * TASK-072 (FR-199): survey-derived provenance options are disabled while the
 * parcel has no survey data (survey_plan_id); choosing one requires a typed
 * justification, which is sent as `change_reason` on save.
 *
 * Field order matches `ParcelCreatePage` exactly (see `PSGC_FIELDS`). The two
 * forms cover the same attributes and previously disagreed on order and column
 * widths, so an operator's muscle memory did not carry from creating a parcel
 * to editing one.
 */
export interface InformationFormValues {
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
    justification: string;
}

function toForm(p: Parcel): InformationFormValues {
    return {
        lot_number: p.lot_number ?? '',
        block_number: p.block_number ?? '',
        title_number_ref: p.title_number_ref ?? '',
        tax_declaration_no: p.tax_declaration_no ?? '',
        source_area_sqm: p.source_area_sqm == null ? '' : String(p.source_area_sqm),
        source_area_unit: p.source_area_unit ?? 'sqm',
        psgc_barangay: p.psgc_barangay ?? '',
        psgc_municipality: p.psgc_municipality ?? '',
        psgc_province: p.psgc_province ?? '',
        location_description: p.location_description ?? '',
        remarks: p.remarks ?? '',
        provenance: p.provenance,
        justification: '',
    };
}

export function InformationTab(props: {
    parcel: Parcel;
    editing: boolean;
    onDirtyChange: (dirty: boolean) => void;
    onSave: (values: InformationFormValues) => Promise<void>;
}) {
    const { parcel, editing, onDirtyChange, onSave } = props;
    const { control, handleSubmit, reset, formState, watch } = useForm<InformationFormValues>({
        defaultValues: toForm(parcel),
    });
    const provenance = watch('provenance');
    const justification = watch('justification');
    const surveyDerived = SURVEY_DERIVED_PROVENANCE.has(provenance);
    const canSurveyDerived = parcel.survey_plan_id != null;
    const provenanceOptions = useMemo(
        () =>
            PROVENANCE_VALUES.map((v) => ({
                value: v,
                label: PROVENANCE_LABELS[v] ?? v,
                disabled: SURVEY_DERIVED_PROVENANCE.has(v) && !canSurveyDerived,
            })),
        [canSurveyDerived],
    );

    // Keep the latest dirty callback without re-running the effect below.
    const onDirtyChangeRef = useRef(onDirtyChange);
    onDirtyChangeRef.current = onDirtyChange;

    useEffect(() => {
        reset(toForm(parcel));
    }, [parcel, reset]);

    // Report ONLY on real isDirty transitions so a parent-side saveState change
    // (Saving -> Saved) does not re-fire this with the pre-reset still-dirty form.
    useEffect(() => {
        onDirtyChangeRef.current(formState.isDirty);
    }, [formState.isDirty]);

    const field = (name: keyof InformationFormValues) => ({
        name,
        control,
        disabled: !editing,
    });

    const unitLabel = AREA_UNITS.find((u) => u.value === watch('source_area_unit'))?.label ?? 'm²';

    return (
        <form id="parcel-information-form" onSubmit={handleSubmit(onSave)} noValidate>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1rem' }}>
                <ParcelField id="parcel-lot" label="Lot number">
                    <Controller
                        {...field('lot_number')}
                        render={({ field: f }) => (
                            <input id="parcel-lot" {...f} className="form-input" data-testid="parcel-editor-lot" />
                        )}
                    />
                </ParcelField>

                <ParcelField id="parcel-block" label="Block number">
                    <Controller
                        {...field('block_number')}
                        render={({ field: f }) => (
                            <input id="parcel-block" {...f} className="form-input" data-testid="parcel-editor-block" />
                        )}
                    />
                </ParcelField>

                <ParcelField id="parcel-title" label="Title reference">
                    <Controller
                        {...field('title_number_ref')}
                        render={({ field: f }) => (
                            <input id="parcel-title" {...f} className="form-input" data-testid="parcel-editor-title" />
                        )}
                    />
                </ParcelField>

                <ParcelField id="parcel-td" label="Tax declaration no.">
                    <Controller
                        {...field('tax_declaration_no')}
                        render={({ field: f }) => (
                            <input id="parcel-td" {...f} className="form-input" data-testid="parcel-editor-td" />
                        )}
                    />
                </ParcelField>

                {/* The stored number is whatever unit is selected next to it — the
                    backend keeps `source_area_sqm` and `source_area_unit` as
                    separate columns and performs no conversion. The old label
                    hard-coded "m²", so choosing hectares produced a field that
                    said one thing and meant another. The unit now names itself. */}
                <ParcelField
                    id="parcel-area"
                    label="Source area"
                    hint={`Entered in ${unitLabel}.`}
                >
                    <Controller
                        {...field('source_area_sqm')}
                        render={({ field: f }) => (
                            <input
                                id="parcel-area"
                                {...f}
                                type="number"
                                step="0.0001"
                                min="0"
                                className="form-input"
                                data-testid="parcel-editor-area"
                                aria-describedby="parcel-area-hint"
                            />
                        )}
                    />
                </ParcelField>

                <ParcelField id="parcel-area-unit" label="Unit">
                    <Controller
                        {...field('source_area_unit')}
                        render={({ field: f }) => (
                            <select id="parcel-area-unit" {...f} className="form-select" data-testid="parcel-editor-area-unit">
                                {AREA_UNITS.map((u) => (
                                    <option key={u.value} value={u.value}>
                                        {u.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    />
                </ParcelField>

                {PSGC_FIELDS.map((p) => (
                    <ParcelField
                        key={p.name}
                        id={`parcel-${p.name}`}
                        label={
                            <>
                                PSGC — {p.label}{' '}
                                <span className="badge bg-light text-dark border">{PSGC_DIGIT_HINT}</span>
                            </>
                        }
                    >
                        <Controller
                            {...field(p.name)}
                            render={({ field: f }) => (
                                <input
                                    id={`parcel-${p.name}`}
                                    {...f}
                                    className="form-input font-monospace"
                                    placeholder={p.placeholder}
                                    inputMode="numeric"
                                    data-testid={p.testId.replace('parcel-create-', 'parcel-editor-')}
                                />
                            )}
                        />
                    </ParcelField>
                ))}

                <ParcelField id="parcel-location" label="Location description" className="form-section" style={{ gridColumn: '1 / -1' }}>
                    <Controller
                        {...field('location_description')}
                        render={({ field: f }) => (
                            <textarea id="parcel-location" {...f} rows={2} className="form-textarea" data-testid="parcel-editor-location" />
                        )}
                    />
                </ParcelField>

                <ParcelField
                    id="parcel-provenance"
                    label="Provenance (geometry source)"
                    className="form-section" style={{ gridColumn: '1 / -1' }}
                >
                    <Controller
                        {...field('provenance')}
                        render={({ field: f }) => (
                            <select
                                id="parcel-provenance"
                                {...f}
                                className="form-select"
                                data-testid="parcel-editor-provenance"
                                aria-describedby="parcel-provenance-help"
                            >
                                {provenanceOptions.map((o) => (
                                    <option key={o.value} value={o.value} disabled={o.disabled}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    />
                    <div className="form-text" id="parcel-provenance-help" data-testid="parcel-editor-provenance-help">
                        {PROVENANCE_HELP[provenance] ?? 'Select a provenance value.'}
                    </div>
                    {/* Only explain the lockout while the lockout is in effect. Once a
                        survey plan is attached the note is stale noise sitting under
                        a field the operator is actively using. */}
                    {!canSurveyDerived && (
                        <div className="alert alert-warning py-2 px-3 small mt-2 mb-0" data-testid="parcel-editor-survey-notice">
                            Survey-derived options are unavailable until a survey plan is attached to this parcel.
                        </div>
                    )}
                    {canSurveyDerived && surveyDerived && (
                        <div className="alert alert-warning py-2 px-3 small mt-2 mb-0">
                            Survey-derived provenance — record a justification for the change.
                        </div>
                    )}
                </ParcelField>

                {surveyDerived && (
                    <ParcelField id="parcel-justification" label="Justification" className="form-section" style={{ gridColumn: '1 / -1' }}>
                        <Controller
                            {...field('justification')}
                            render={({ field: f }) => {
                                const missing = justification.trim() === '';
                                return (
                                    <>
                                        <textarea
                                            id="parcel-justification"
                                            {...f}
                                            rows={2}
                                            className={`form-textarea ${missing ? 'error' : ''}`}
                                            placeholder="Why is this provenance survey-derived?"
                                            aria-invalid={missing || undefined}
                                            aria-describedby={missing ? 'parcel-justification-required' : undefined}
                                        />
                                        {missing && (
                                            <div
                                                className="form-error"
                                                id="parcel-justification-required"
                                                role="alert"
                                                data-testid="parcel-editor-justification-required"
                                            >
                                                Required when provenance is survey-derived.
                                            </div>
                                        )}
                                    </>
                                );
                            }}
                        />
                    </ParcelField>
                )}

                <ParcelField id="parcel-remarks" label="Remarks" className="form-section" style={{ gridColumn: '1 / -1' }}>
                    <Controller
                        {...field('remarks')}
                        render={({ field: f }) => (
                            <textarea id="parcel-remarks" {...f} rows={3} className="form-textarea" data-testid="parcel-editor-remarks" />
                        )}
                    />
                </ParcelField>
            </div>
        </form>
    );
}
