import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient from '../../lib/apiClient';
import type { BasemapProvider } from '../map/types';
import { BasemapForm } from './BasemapForm';

export function BasemapsManager() {
    const queryClient = useQueryClient();
    const [editingProvider, setEditingProvider] = useState<BasemapProvider | null>(null);
    const [isCreating, setIsCreating] = useState(false);

    const { data: providers = [], isLoading } = useQuery<BasemapProvider[]>({
        queryKey: ['admin_basemaps'],
        queryFn: () => apiClient.get('/admin/basemaps').then((res: any) => res)
    });

    const createMutation = useMutation({
        mutationFn: (data: any) => apiClient.post('/admin/basemaps', data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin_basemaps'] });
            setIsCreating(false);
        }
    });

    const updateMutation = useMutation({
        mutationFn: (data: { id: number, payload: any }) => apiClient.put(`/admin/basemaps/${data.id}`, data.payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin_basemaps'] });
            setEditingProvider(null);
        }
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => apiClient.delete(`/admin/basemaps/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin_basemaps'] });
        }
    });

    if (isLoading) return <div>Loading basemaps...</div>;

    return (
        <div>
            <div className="d-flex justify-content-between align-items-center mb-4">
                <h2>Basemap Providers</h2>
                <button className="btn btn-primary" onClick={() => setIsCreating(true)} disabled={isCreating || !!editingProvider}>
                    Add Basemap
                </button>
            </div>
            
            {isCreating && (
                <BasemapForm 
                    onSave={(data) => createMutation.mutate(data)} 
                    onCancel={() => setIsCreating(false)} 
                    isSaving={createMutation.isPending} 
                />
            )}
            
            {editingProvider && !isCreating && (
                <BasemapForm 
                    provider={editingProvider}
                    onSave={(data) => updateMutation.mutate({ id: editingProvider.id, payload: data })} 
                    onCancel={() => setEditingProvider(null)} 
                    isSaving={updateMutation.isPending} 
                />
            )}

            <table className="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>License</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {providers.map(provider => (
                        <tr key={provider.id}>
                            <td>{provider.code}</td>
                            <td>{provider.name}</td>
                            <td>{provider.provider_type}</td>
                            <td>{provider.license_type}</td>
                            <td>
                                {provider.is_enabled ? 
                                    <span className="badge bg-success">Enabled</span> : 
                                    <span className="badge bg-secondary">Disabled</span>
                                }
                            </td>
                            <td>
                                <button className="btn btn-sm btn-outline-primary me-2" onClick={() => {
                                    setIsCreating(false);
                                    setEditingProvider(provider);
                                }}>Edit</button>
                                <button className="btn btn-sm btn-outline-danger" onClick={() => {
                                    if (confirm('Are you sure you want to delete this basemap?')) {
                                        deleteMutation.mutate(provider.id);
                                    }
                                }}>Delete</button>
                            </td>
                        </tr>
                    ))}
                    {providers.length === 0 && (
                        <tr>
                            <td colSpan={6} className="text-center text-muted py-4">No basemap providers found.</td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}
