import React, { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import apiClient, { unwrapList } from '../../../lib/apiClient';
import { layerApi } from '../api/layerApi';

interface Props {
    layerId: number;
}

export function LayerPermissionMatrix({ layerId }: Props) {
    const [permissions, setPermissions] = useState<Record<number, any>>({});
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<{ kind: 'success' | 'danger'; text: string } | null>(null);

    const { data: roles, isLoading: loadingRoles } = useQuery({
        queryKey: ['roles'],
        queryFn: async () => {
            const res = await apiClient.get('/roles/lookup').catch(() => ({ data: [] }));
            return unwrapList(res);
        }
    });

    const { data: initialPermissions, isLoading: loadingPerms } = useQuery({
        queryKey: ['layer_permissions', layerId],
        queryFn: () => layerApi.getPermissions(layerId),
        enabled: !!layerId,
    });

    useEffect(() => {
        if (!initialPermissions) return;
        const permsObj: Record<number, any> = {};
        for (const p of initialPermissions) {
            permsObj[p.role_id] = { ...p };
        }
        setPermissions(permsObj);
    }, [initialPermissions]);

    const handleCheck = (roleId: number, field: string, checked: boolean) => {
        setPermissions(prev => {
            const current = prev[roleId] || { role_id: roleId, can_view: false, can_create: false, can_update: false, can_delete: false, can_approve: false };
            return {
                ...prev,
                [roleId]: { ...current, [field]: checked }
            };
        });
    };

    const handleSave = async () => {
        if (!layerId) return;
        setSaving(true);
        setMessage(null);
        try {
            const permsArray = Object.values(permissions);
            await layerApi.savePermissions(layerId, permsArray);
            setMessage({ kind: 'success', text: 'Permissions saved successfully.' });
            setTimeout(() => setMessage(null), 3000);
        } catch (err: any) {
            setMessage({ kind: 'danger', text: err.response?.data?.message || 'Failed to save permissions.' });
        } finally {
            setSaving(false);
        }
    };

    if (loadingRoles || loadingPerms) return <div>Loading permissions...</div>;

    return (
        <div className="card mt-4">
            <div className="card-header">
                <h5 className="mb-0">Role Permissions</h5>
            </div>
            <div className="card-body">
                <p className="text-muted small">Configure which roles have access to this layer.</p>
                {message && (
                    <div className={`alert alert-${message.kind} p-2 mb-3 small`}>
                        {message.text}
                    </div>
                )}
                <div className="table-responsive">
                    <table className="table table-sm table-bordered table-hover">
                        <thead className="bg-light">
                            <tr>
                                <th>Role</th>
                                <th className="text-center">View</th>
                                <th className="text-center">Create</th>
                                <th className="text-center">Update</th>
                                <th className="text-center">Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(roles || []).map((role: any) => {
                                const p = permissions[role.id] || {};
                                return (
                                    <tr key={role.id}>
                                        <td>{role.name} <span className="text-muted small">({role.code})</span></td>
                                        <td className="text-center">
                                            <input 
                                                type="checkbox" 
                                                className="form-check-input" 
                                                checked={!!p.can_view}
                                                onChange={(e) => handleCheck(role.id, 'can_view', e.target.checked)}
                                            />
                                        </td>
                                        <td className="text-center">
                                            <input 
                                                type="checkbox" 
                                                className="form-check-input" 
                                                checked={!!p.can_create}
                                                onChange={(e) => handleCheck(role.id, 'can_create', e.target.checked)}
                                            />
                                        </td>
                                        <td className="text-center">
                                            <input 
                                                type="checkbox" 
                                                className="form-check-input" 
                                                checked={!!p.can_update}
                                                onChange={(e) => handleCheck(role.id, 'can_update', e.target.checked)}
                                            />
                                        </td>
                                        <td className="text-center">
                                            <input 
                                                type="checkbox" 
                                                className="form-check-input" 
                                                checked={!!p.can_delete}
                                                onChange={(e) => handleCheck(role.id, 'can_delete', e.target.checked)}
                                            />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="text-end mt-3">
                    <button 
                        className="btn btn-sm btn-primary" 
                        onClick={handleSave} 
                        disabled={saving || !layerId}
                    >
                        {saving ? 'Saving...' : 'Save Permissions'}
                    </button>
                </div>
            </div>
        </div>
    );
}
