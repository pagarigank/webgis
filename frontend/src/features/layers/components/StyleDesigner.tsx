import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { styleApi } from '../api/styleApi';
import type { LayerStyle, LayerStyleRule } from '../types';

interface Props {
    layerId: number;
}

export function StyleDesigner({ layerId }: Props) {
    const queryClient = useQueryClient();
    const [editingStyle, setEditingStyle] = useState<Partial<LayerStyle> | null>(null);

    const { data: styles = [], isLoading } = useQuery({
        queryKey: ['layer_styles', layerId],
        queryFn: () => styleApi.getAll(layerId)
    });

    const createMutation = useMutation({
        mutationFn: (data: Partial<LayerStyle>) => styleApi.create(layerId, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['layer_styles', layerId] });
            setEditingStyle(null);
        }
    });

    const updateMutation = useMutation({
        mutationFn: (data: { id: number, payload: Partial<LayerStyle> }) => styleApi.update(layerId, data.id, data.payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['layer_styles', layerId] });
            setEditingStyle(null);
        }
    });

    if (isLoading) return <div>Loading styles...</div>;

    const handleSave = (styleData: Partial<LayerStyle>) => {
        if (styleData.id) {
            updateMutation.mutate({ id: styleData.id, payload: styleData });
        } else {
            createMutation.mutate(styleData);
        }
    };

    return (
        <div className="card mt-4">
            <div className="card-header d-flex justify-content-between align-items-center">
                <h5 className="mb-0">Styles</h5>
                <button 
                    className="btn btn-sm btn-primary" 
                    onClick={() => setEditingStyle({ 
                        style_type: 'SINGLE', 
                        is_active: true, 
                        default_rule: { fill: '#cccccc', stroke: '#000000', opacity: 0.8 },
                        rules: []
                    })}
                >
                    Create Style
                </button>
            </div>
            <div className="card-body">
                {editingStyle ? (
                    <StyleEditorForm 
                        initialData={editingStyle} 
                        onSave={handleSave} 
                        onCancel={() => setEditingStyle(null)}
                        isSaving={createMutation.isPending || updateMutation.isPending}
                    />
                ) : (
                    <div className="table-responsive">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th>Active</th>
                                    <th>Type</th>
                                    <th>Attribute</th>
                                    <th>Rules</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {styles.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-center text-muted">No styles defined yet.</td>
                                    </tr>
                                )}
                                {styles.map(s => (
                                    <tr key={s.id}>
                                        <td>{s.is_active ? <span className="badge bg-success">Active</span> : '-'}</td>
                                        <td>{s.style_type}</td>
                                        <td>{s.attribute_field || '-'}</td>
                                        <td>{s.rules?.length || 0}</td>
                                        <td>
                                            <button className="btn btn-sm btn-outline-secondary" onClick={() => setEditingStyle(s)}>Edit</button>
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

function StyleEditorForm({ initialData, onSave, onCancel, isSaving }: {
    initialData: Partial<LayerStyle>;
    onSave: (data: Partial<LayerStyle>) => void;
    onCancel: () => void;
    isSaving: boolean;
}) {
    const [styleData, setStyleData] = useState<Partial<LayerStyle>>(initialData);

    const updateDefaultRule = (key: keyof LayerStyleRule, value: any) => {
        setStyleData(prev => ({
            ...prev,
            default_rule: { ...prev.default_rule, [key]: value }
        }));
    };

    const addRule = () => {
        setStyleData(prev => ({
            ...prev,
            rules: [...(prev.rules || []), { value: '', fill: '#ff0000', stroke: '#000000' }]
        }));
    };

    const updateRule = (index: number, key: keyof LayerStyleRule, value: any) => {
        const newRules = [...(styleData.rules || [])];
        newRules[index] = { ...newRules[index], [key]: value };
        setStyleData(prev => ({ ...prev, rules: newRules }));
    };

    const removeRule = (index: number) => {
        const newRules = [...(styleData.rules || [])];
        newRules.splice(index, 1);
        setStyleData(prev => ({ ...prev, rules: newRules }));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        onSave(styleData);
    };

    return (
        <form onSubmit={handleSubmit} className="border p-3 rounded bg-light">
            <h6>{styleData.id ? 'Edit Style' : 'New Style'}</h6>
            <div className="row g-3 mb-3">
                <div className="col-md-4">
                    <label className="form-label small">Style Type</label>
                    <select 
                        className="form-select form-select-sm"
                        value={styleData.style_type}
                        onChange={e => setStyleData({...styleData, style_type: e.target.value as any})}
                    >
                        <option value="SINGLE">Single Symbol</option>
                        <option value="CATEGORIZED">Categorized</option>
                        <option value="GRADUATED">Graduated</option>
                    </select>
                </div>
                
                {styleData.style_type !== 'SINGLE' && (
                    <div className="col-md-4">
                        <label className="form-label small">Attribute Field</label>
                        <input 
                            type="text" 
                            className="form-control form-control-sm"
                            value={styleData.attribute_field || ''}
                            onChange={e => setStyleData({...styleData, attribute_field: e.target.value})}
                            required
                        />
                    </div>
                )}
                
                <div className="col-md-4 d-flex align-items-end">
                    <div className="form-check form-switch mb-1">
                        <input 
                            className="form-check-input" 
                            type="checkbox" 
                            id="chkActiveStyle"
                            checked={styleData.is_active}
                            onChange={e => setStyleData({...styleData, is_active: e.target.checked})}
                        />
                        <label className="form-check-label small" htmlFor="chkActiveStyle">Set as Active Style</label>
                    </div>
                </div>
            </div>

            <div className="card mb-3">
                <div className="card-header py-1 bg-secondary text-white small">Default Rule</div>
                <div className="card-body py-2">
                    <div className="row g-2">
                        <div className="col-md-4">
                            <label className="form-label small mb-0">Fill Color</label>
                            <input 
                                type="color" 
                                className="form-control form-control-color w-100 p-0" 
                                value={styleData.default_rule?.fill || '#cccccc'}
                                onChange={e => updateDefaultRule('fill', e.target.value)}
                            />
                        </div>
                        <div className="col-md-4">
                            <label className="form-label small mb-0">Stroke Color</label>
                            <input 
                                type="color" 
                                className="form-control form-control-color w-100 p-0" 
                                value={styleData.default_rule?.stroke || '#000000'}
                                onChange={e => updateDefaultRule('stroke', e.target.value)}
                            />
                        </div>
                        <div className="col-md-4">
                            <label className="form-label small mb-0">Opacity</label>
                            <input 
                                type="range" 
                                className="form-range" 
                                min="0" max="1" step="0.1"
                                value={styleData.default_rule?.opacity ?? 0.8}
                                onChange={e => updateDefaultRule('opacity', parseFloat(e.target.value))}
                            />
                        </div>
                    </div>
                </div>
            </div>

            {styleData.style_type !== 'SINGLE' && (
                <div className="card mb-3">
                    <div className="card-header py-1 d-flex justify-content-between align-items-center bg-secondary text-white small">
                        <span>Rules</span>
                        <button type="button" className="btn btn-sm btn-light py-0" onClick={addRule}>Add Rule</button>
                    </div>
                    <div className="card-body p-0">
                        <table className="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>{styleData.style_type === 'CATEGORIZED' ? 'Value' : 'Min - Max'}</th>
                                    <th>Fill</th>
                                    <th>Stroke</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {(styleData.rules || []).length === 0 && (
                                    <tr><td colSpan={4} className="text-center text-muted small py-2">No rules. Fallback to default rule.</td></tr>
                                )}
                                {(styleData.rules || []).map((rule, idx) => (
                                    <tr key={idx}>
                                        <td>
                                            {styleData.style_type === 'CATEGORIZED' ? (
                                                <input type="text" className="form-control form-control-sm" value={rule.value || ''} onChange={e => updateRule(idx, 'value', e.target.value)} placeholder="Category value" />
                                            ) : (
                                                <div className="input-group input-group-sm">
                                                    <input type="number" className="form-control" value={rule.min ?? ''} onChange={e => updateRule(idx, 'min', parseFloat(e.target.value))} placeholder="Min" />
                                                    <span className="input-group-text">-</span>
                                                    <input type="number" className="form-control" value={rule.max ?? ''} onChange={e => updateRule(idx, 'max', parseFloat(e.target.value))} placeholder="Max" />
                                                </div>
                                            )}
                                        </td>
                                        <td style={{width: '60px'}}>
                                            <input type="color" className="form-control form-control-color p-0 w-100" value={rule.fill || '#cccccc'} onChange={e => updateRule(idx, 'fill', e.target.value)} />
                                        </td>
                                        <td style={{width: '60px'}}>
                                            <input type="color" className="form-control form-control-color p-0 w-100" value={rule.stroke || '#000000'} onChange={e => updateRule(idx, 'stroke', e.target.value)} />
                                        </td>
                                        <td style={{width: '40px'}}>
                                            <button type="button" className="btn btn-sm btn-outline-danger py-0 px-1" onClick={() => removeRule(idx)}>&times;</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="d-flex justify-content-end gap-2">
                <button type="button" className="btn btn-sm btn-secondary" onClick={onCancel} disabled={isSaving}>Cancel</button>
                <button type="submit" className="btn btn-sm btn-primary" disabled={isSaving}>{isSaving ? 'Saving...' : 'Save Style'}</button>
            </div>
        </form>
    );
}
