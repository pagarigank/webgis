import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import type { LayerField } from '../types';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { fieldApi } from '../api/fieldApi';

const fieldSchema = z.object({
    field_name: z.string().min(1, "Name is required").max(60),
    field_label: z.string().min(1, "Label is required").max(100),
    field_type: z.enum(['text', 'long_text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'dropdown', 'multi_select', 'email', 'phone', 'url', 'currency', 'reference', 'user', 'document']),
    required: z.boolean(),
    searchable: z.boolean(),
    sortable: z.boolean(),
    displayable: z.boolean(),
    editable: z.boolean(),
    is_pii: z.boolean(),
    sort_order: z.number().int()
});

type FieldFormData = z.infer<typeof fieldSchema>;

interface Props {
    layerId: number;
}

export function FieldDesigner({ layerId }: Props) {
    const queryClient = useQueryClient();
    const [editingField, setEditingField] = useState<LayerField | null>(null);
    const [isFormOpen, setIsFormOpen] = useState(false);

    const { data: fields = [], isLoading } = useQuery({
        queryKey: ['layer_fields', layerId],
        queryFn: () => fieldApi.getAll(layerId)
    });

    const createMutation = useMutation({
        mutationFn: (data: FieldFormData) => fieldApi.create(layerId, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['layer_fields', layerId] });
            closeForm();
        }
    });

    const updateMutation = useMutation({
        mutationFn: (data: { id: number, payload: FieldFormData }) => fieldApi.update(layerId, data.id, data.payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['layer_fields', layerId] });
            closeForm();
        }
    });
    
    const deleteMutation = useMutation({
        mutationFn: (id: number) => fieldApi.delete(layerId, id),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['layer_fields', layerId] });
        }
    });

    const openNewForm = () => {
        setEditingField(null);
        setIsFormOpen(true);
    };

    const openEditForm = (field: LayerField) => {
        setEditingField(field);
        setIsFormOpen(true);
    };

    const closeForm = () => {
        setIsFormOpen(false);
        setEditingField(null);
    };

    if (isLoading) return <div>Loading fields...</div>;

    return (
        <div className="card mt-4">
            <div className="card-header d-flex justify-content-between align-items-center">
                <h5 className="mb-0">Fields</h5>
                <button className="btn btn-sm btn-primary" onClick={openNewForm}>Add Field</button>
            </div>
            
            <div className="card-body">
                {isFormOpen ? (
                    <FieldForm 
                        initialData={editingField} 
                        onSubmit={(data) => {
                            if (editingField) {
                                updateMutation.mutate({ id: editingField.id, payload: data });
                            } else {
                                createMutation.mutate(data);
                            }
                        }}
                        onCancel={closeForm}
                        isSaving={createMutation.isPending || updateMutation.isPending}
                    />
                ) : (
                    <div className="table-responsive">
                        <table className="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Label</th>
                                    <th>Type</th>
                                    <th>Req</th>
                                    <th>Search</th>
                                    <th>Sort</th>
                                    <th>PII</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {fields.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="text-center text-muted">No fields defined yet.</td>
                                    </tr>
                                )}
                                {fields.map(f => (
                                    <tr key={f.id}>
                                        <td><code>{f.field_name}</code></td>
                                        <td>{f.field_label}</td>
                                        <td><span className="badge bg-secondary">{f.field_type}</span></td>
                                        <td>{f.required ? '✅' : '-'}</td>
                                        <td>{f.searchable ? '✅' : '-'}</td>
                                        <td>{f.sortable ? '✅' : '-'}</td>
                                        <td>{f.is_pii ? '⚠️' : '-'}</td>
                                        <td>
                                            <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openEditForm(f)}>Edit</button>
                                            <button 
                                                className="btn btn-sm btn-outline-danger" 
                                                onClick={() => {
                                                    if (confirm('Are you sure you want to delete this field? Data may be lost.')) {
                                                        deleteMutation.mutate(f.id);
                                                    }
                                                }}
                                            >
                                                Del
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}

function FieldForm({ initialData, onSubmit, onCancel, isSaving }: {
    initialData?: LayerField | null;
    onSubmit: (data: FieldFormData) => void;
    onCancel: () => void;
    isSaving: boolean;
}) {
    const { register, handleSubmit, formState: { errors } } = useForm<FieldFormData>({
        resolver: zodResolver(fieldSchema),
        defaultValues: {
            field_name: initialData?.field_name || '',
            field_label: initialData?.field_label || '',
            field_type: initialData?.field_type || 'text',
            required: initialData?.required || false,
            searchable: initialData?.searchable || false,
            sortable: initialData?.sortable || false,
            displayable: initialData?.displayable ?? true,
            editable: initialData?.editable ?? true,
            is_pii: initialData?.is_pii || false,
            sort_order: initialData?.sort_order || 0
        }
    });

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="border p-3 rounded bg-light">
            <h6>{initialData ? 'Edit Field' : 'New Field'}</h6>
            <div className="row g-2 mb-3">
                <div className="col-md-3">
                    <label className="form-label small">Name (db column)*</label>
                    <input type="text" className={`form-control form-control-sm ${errors.field_name ? 'is-invalid' : ''}`} {...register('field_name')} disabled={!!initialData} />
                    {errors.field_name && <div className="invalid-feedback">{errors.field_name.message as string}</div>}
                </div>
                <div className="col-md-3">
                    <label className="form-label small">Label *</label>
                    <input type="text" className={`form-control form-control-sm ${errors.field_label ? 'is-invalid' : ''}`} {...register('field_label')} />
                    {errors.field_label && <div className="invalid-feedback">{errors.field_label.message as string}</div>}
                </div>
                <div className="col-md-3">
                    <label className="form-label small">Type *</label>
                    <select className={`form-select form-select-sm ${errors.field_type ? 'is-invalid' : ''}`} {...register('field_type')} disabled={!!initialData}>
                        <option value="text">Text</option>
                        <option value="integer">Integer</option>
                        <option value="decimal">Decimal</option>
                        <option value="boolean">Boolean</option>
                        <option value="date">Date</option>
                        <option value="dropdown">Dropdown</option>
                    </select>
                </div>
                <div className="col-md-3">
                    <label className="form-label small">Sort Order</label>
                    <input type="number" className="form-control form-control-sm" {...register('sort_order', { valueAsNumber: true })} />
                </div>
            </div>
            
            <div className="row g-2 mb-3">
                <div className="col-auto"><div className="form-check form-switch"><input className="form-check-input" type="checkbox" {...register('required')} id="chkReq" /><label className="form-check-label small" htmlFor="chkReq">Required</label></div></div>
                <div className="col-auto"><div className="form-check form-switch"><input className="form-check-input" type="checkbox" {...register('searchable')} id="chkSearch" /><label className="form-check-label small" htmlFor="chkSearch">Searchable</label></div></div>
                <div className="col-auto"><div className="form-check form-switch"><input className="form-check-input" type="checkbox" {...register('sortable')} id="chkSort" /><label className="form-check-label small" htmlFor="chkSort">Sortable</label></div></div>
                <div className="col-auto"><div className="form-check form-switch"><input className="form-check-input" type="checkbox" {...register('displayable')} id="chkDisp" /><label className="form-check-label small" htmlFor="chkDisp">Displayable</label></div></div>
                <div className="col-auto"><div className="form-check form-switch"><input className="form-check-input" type="checkbox" {...register('editable')} id="chkEdit" /><label className="form-check-label small" htmlFor="chkEdit">Editable</label></div></div>
                <div className="col-auto"><div className="form-check form-switch"><input className="form-check-input text-danger" type="checkbox" {...register('is_pii')} id="chkPii" /><label className="form-check-label text-danger fw-bold small" htmlFor="chkPii">PII (Masked)</label></div></div>
            </div>
            
            <div className="d-flex justify-content-end gap-2">
                <button type="button" className="btn btn-sm btn-secondary" onClick={onCancel} disabled={isSaving}>Cancel</button>
                <button type="submit" className="btn btn-sm btn-primary" disabled={isSaving}>{isSaving ? 'Saving...' : 'Save Field'}</button>
            </div>
        </form>
    );
}
