import { useEffect, useRef } from 'react';
import { useForm, Controller } from 'react-hook-form';
import type { Parcel } from '../types';
import { PROVENANCE_HELP, PROVENANCE_VALUES } from './badges';

/**
 * Information tab (frontend.md §7): lot/block, PSGC codes, tax declaration,
 * source area + unit, location description, remarks, and the provenance
 * selector with its inline explanation. Field edits surface upward so the
 * sticky status bar can show "Unsaved changes" and drive the save action.
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

    return (
        <form id="parcel-information-form" onSubmit={handleSubmit(onSave)} noValidate>
            <div className="row g-3">
                <div className="col-md-4">
                    <label className="form-label small text-muted mb-1">Lot number</label>
                    <Controller {...field('lot_number')} render={({ field: f }) => (
                        <input {...f} className="form-control" data-testid="parcel-editor-lot" />
                    )} />
                </div>
                <div className="col-md-4">
                    <label className="form-label small text-muted mb-1">Block number</label>
                    <Controller {...field('block_number')} render={({ field: f }) => (
                        <input {...f} className="form-control" data-testid="parcel-editor-block" />
                    )} />
                </div>
                <div className="col-md-4">
                    <label className="form-label small text-muted mb-1">Tax declaration no.</label>
                    <Controller {...field('tax_declaration_no')} render={({ field: f }) => (
                        <input {...f} className="form-control" data-testid="parcel-editor-td" />
                    )} />
                </div>

                <div className="col-md-4">
                    <label className="form-label small text-muted mb-1">Title reference</label>
                    <Controller {...field('title_number_ref')} render={({ field: f }) => (
                        <input {...f} className="form-control" data-testid="parcel-editor-title" />
                    )} />
                </div>
                <div className="col-md-4">
                    <label className="form-label small text-muted mb-1">Source area (m²)</label>
                    <Controller {...field('source_area_sqm')} render={({ field: f }) => (
                        <input {...f} type="number" step="0.0001" min="0" className="form-control" data-testid="parcel-editor-area" />
                    )} />
                </div>
                <div className="col-md-4">
                    <label className="form-label small text-muted mb-1">Area unit</label>
                    <Controller {...field('source_area_unit')} render={({ field: f }) => (
                        <select {...f} className="form-select" data-testid="parcel-editor-area-unit">
                            <option value="sqm">sqm</option>
                            <option value="ha">ha</option>
                        </select>
                    )} />
                </div>

                <div className="col-12">
                    <label className="form-label small text-muted mb-1">
                        PSGC — barangay <span className="badge bg-light text-dark border">10–12 digits</span>
                    </label>
                    <Controller {...field('psgc_barangay')} render={({ field: f }) => (
                        <input {...f} className="form-control font-monospace" placeholder="e.g. 133901001" data-testid="parcel-editor-psgc-barangay" />
                    )} />
                </div>
                <div className="col-md-6">
                    <label className="form-label small text-muted mb-1">PSGC — municipality / city</label>
                    <Controller {...field('psgc_municipality')} render={({ field: f }) => (
                        <input {...f} className="form-control font-monospace" data-testid="parcel-editor-psgc-muni" />
                    )} />
                </div>
                <div className="col-md-6">
                    <label className="form-label small text-muted mb-1">PSGC — province</label>
                    <Controller {...field('psgc_province')} render={({ field: f }) => (
                        <input {...f} className="form-control font-monospace" data-testid="parcel-editor-psgc-prov" />
                    )} />
                </div>

                <div className="col-12">
                    <label className="form-label small text-muted mb-1">Location description</label>
                    <Controller {...field('location_description')} render={({ field: f }) => (
                        <textarea {...f} rows={2} className="form-control" data-testid="parcel-editor-location" />
                    )} />
                </div>

                <div className="col-12">
                    <label className="form-label small text-muted mb-1">Provenance (geometry source)</label>
                    <Controller {...field('provenance')} render={({ field: f }) => (
                        <select {...f} className="form-select" data-testid="parcel-editor-provenance">
                            {PROVENANCE_VALUES.map((v) => (
                                <option key={v} value={v}>{v}</option>
                            ))}
                        </select>
                    )} />
                    <div className="form-text" data-testid="parcel-editor-provenance-help">
                        {PROVENANCE_HELP[provenance] ?? 'Select a provenance value.'}
                    </div>
                </div>

                <div className="col-12">
                    <label className="form-label small text-muted mb-1">Remarks</label>
                    <Controller {...field('remarks')} render={({ field: f }) => (
                        <textarea {...f} rows={3} className="form-control" data-testid="parcel-editor-remarks" />
                    )} />
                </div>
            </div>
        </form>
    );
}