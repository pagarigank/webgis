// @ts-nocheck
import React, { useState, useEffect } from 'react';
import { useForm, Controller } from 'react-hook-form';
import { Modal } from '../../../components/dialogs/Modal';
import { FieldRenderer } from '../../../components/forms/FieldRenderer';
import type { LayerField } from '../types';
import { layerApi } from '../api/layerApi';

interface FeatureEditorProps {
    open: boolean;
    onClose: () => void;
    onSave: () => void;
    mode: 'create' | 'edit';
    feature: {
        id: string;
        status: string;
        psgc_barangay?: string;
        provenance?: string;
        version: number;
        attributes: Record<string, unknown>;
    } | null;
    layerId: number;
    fields: LayerField[];
    canViewPII: boolean;
    saving: boolean;
    error: string | null;
    setError: (e: string | null) => void;
}

export function FeatureEditor({
    open,
    onClose,
    onSave,
    mode,
    feature,
    layerId,
    fields,
    canViewPII,
    saving,
    error,
    setError,
}: FeatureEditorProps) {
    const title = mode === 'create' ? 'Create Feature' : `Edit Feature #${feature?.id}`;
    const { control, handleSubmit, reset, setValue, watch, formState: { errors } } = useForm({
        defaultValues: feature ? { ...feature.attributes } : {},
    });

    // Watch all field values and sync to form
    const allValues = watch();

    useEffect(() => {
        if (open && feature) {
            reset(feature.attributes);
        }
    }, [open, feature, reset]);

    const onSubmit = async (data: Record<string, unknown>) => {
        if (!feature || mode === 'create' && !layerId) return;
        setError(null);

        try {
            if (mode === 'create') {
                await layerApi.createFeature(layerId, {
                    geometry: (feature as any).geometry ?? { type: 'Point', coordinates: [0, 0] },
                    attributes: data,
                    psgc_barangay: feature.psgc_barangay,
                    provenance: feature.provenance,
                    status: 'ACTIVE',
                });
            } else {
                await layerApi.updateFeature(
                    layerId,
                    feature.id,
                    { attributes: data, status: feature.status },
                    feature.version,
                );
            }
            onSave();
            onClose();
            reset();
        } catch (err: any) {
            const msg = err?.response?.data?.message ?? err?.message ?? 'Save failed';
            setError(msg);
        }
    };

    const handleClose = () => {
        setError(null);
        reset();
        onClose();
    };

    const getFieldValue = (fieldName: string) => {
        const val = allValues[fieldName];
        if (val === '' || val === undefined || val === null) {
            const field = fields.find(f => f.field_name === fieldName);
            if (field) {
                if (field.field_type === 'boolean') return false;
                if (field.field_type === 'multi_select') return [];
            }
            return '';
        }
        return val;
    };

    return (
        <Modal open={open} onClose={handleClose} title={title}>
            {error && (
                <div className="alert alert-danger mb-3" style={{ fontSize: 13 }}>
                    {error}
                </div>
            )}

            <form onSubmit={handleSubmit(onSubmit)}>
                {fields.map((field) => (
                    <Controller
                        key={field.field_name}
                        name={field.field_name}
                        control={control}
                        defaultValue={field.default_value ?? ''}
                        render={({ field: fieldProps, fieldState }) => (
                            <FieldRenderer
                                field={field}
                                canViewPII={canViewPII}
                                value={getFieldValue(field.field_name)}
                                onChange={(v) => fieldProps.onChange(v)}
                                onBlur={fieldProps.onBlur}
                                error={fieldState.error}
                                disabled={saving}
                            />
                        )}
                    />
                ))}

                <div className="d-flex justify-content-end gap-2 mt-3">
                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={handleClose}
                        disabled={saving}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={saving}
                    >
                        {saving ? 'Saving...' : mode === 'create' ? 'Create' : 'Save Changes'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
