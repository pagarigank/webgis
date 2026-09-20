import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient from '../../lib/apiClient';

export function OrganizationsManager() {
  const queryClient = useQueryClient();
  const { data: orgs, isLoading } = useQuery({
    queryKey: ['admin_organizations'],
    queryFn: () => apiClient.get('/organizations').then(res => res.data.data)
  });

  const [selectedOrg, setSelectedOrg] = useState<any>(null);
  const [isCreating, setIsCreating] = useState(false);

  const createOrg = useMutation({
    mutationFn: (data: any) => apiClient.post('/organizations', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin_organizations'] });
      setIsCreating(false);
      alert('Organization created successfully');
    }
  });

  const updateOrg = useMutation({
    mutationFn: (data: { id: number, payload: any }) => apiClient.put(`/organizations/${data.id}`, data.payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin_organizations'] });
      alert('Organization updated successfully');
    }
  });

  const deactivateOrg = useMutation({
    mutationFn: (id: number) => apiClient.post(`/organizations/${id}/deactivate`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin_organizations'] });
      alert('Organization deactivated');
    }
  });

  if (isLoading) return <div>Loading organizations...</div>;

  return (
    <div className="grid-2-cols">
      <div style={{ borderRight: '1px solid var(--border-color)', paddingRight: '1rem' }}>
        <div className="flex justify-between items-center mb-4">
          <h2 style={{ margin: 0 }}>Organizations</h2>
          <button onClick={() => { setIsCreating(true); setSelectedOrg(null); }} className="btn btn-primary">
            + New
          </button>
        </div>
        <ul style={{ listStyle: 'none', padding: 0, margin: 0, height: '600px', overflowY: 'auto' }}>
          {orgs?.map((org: any) => (
            <li 
              key={org.id}
              className={`list-item ${selectedOrg?.id === org.id ? 'selected' : ''}`}
              onClick={() => { setSelectedOrg(org); setIsCreating(false); }}
            >
              <strong>{org.name}</strong>
              <div className="text-muted text-sm">{org.code} | {org.org_type} | {org.status}</div>
            </li>
          ))}
        </ul>
      </div>
      
      <div>
        {isCreating && (
          <OrganizationForm 
            onSave={(data: any) => createOrg.mutate(data)} 
            isSaving={createOrg.isPending} 
            onCancel={() => setIsCreating(false)} 
          />
        )}
        {selectedOrg && !isCreating && (
          <OrganizationForm 
            org={selectedOrg} 
            onSave={(data: any) => updateOrg.mutate({ id: selectedOrg.id, payload: data })} 
            isSaving={updateOrg.isPending} 
            onDeactivate={() => deactivateOrg.mutate(selectedOrg.id)}
          />
        )}
        {!selectedOrg && !isCreating && (
          <div className="text-muted">Select an organization to edit or create a new one.</div>
        )}
      </div>
    </div>
  );
}

function OrganizationForm({ org, onSave, isSaving, onCancel, onDeactivate }: any) {
  const [formData, setFormData] = useState({
    code: org?.code || '',
    name: org?.name || '',
    org_type: org?.org_type || 'GOVERNMENT',
    psgc_code: org?.psgc_code || ''
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSave(formData);
  };

  return (
    <form onSubmit={handleSubmit} style={{ maxWidth: '500px' }}>
      <h2 className="mb-4">{org ? 'Edit Organization' : 'Create Organization'}</h2>
      
      <div className="form-group">
        <label className="form-label">Code (Unique)</label>
        <input required type="text" className="form-input" value={formData.code} onChange={e => setFormData({...formData, code: e.target.value})} disabled={!!org} />
      </div>
      
      <div className="form-group">
        <label className="form-label">Name</label>
        <input required type="text" className="form-input" value={formData.name} onChange={e => setFormData({...formData, name: e.target.value})} />
      </div>

      <div className="form-group">
        <label className="form-label">Type</label>
        <select required className="form-select" value={formData.org_type} onChange={e => setFormData({...formData, org_type: e.target.value})}>
          <option value="GOVERNMENT">Government</option>
          <option value="PRIVATE">Private</option>
          <option value="ACADEMIC">Academic</option>
          <option value="NGO">NGO</option>
        </select>
      </div>

      <div className="form-group">
        <label className="form-label">PSGC Code (Optional)</label>
        <input type="text" className="form-input" value={formData.psgc_code} onChange={e => setFormData({...formData, psgc_code: e.target.value})} placeholder="e.g. 010000000" />
      </div>

      <div className="flex gap-4 mt-4" style={{ paddingTop: '1rem', borderTop: '1px solid var(--border-color)' }}>
        <button type="submit" disabled={isSaving} className="btn btn-primary" style={{ flex: 1 }}>
          {isSaving ? 'Saving...' : 'Save'}
        </button>
        {onCancel && (
          <button type="button" onClick={onCancel} className="btn btn-ghost">Cancel</button>
        )}
        {org && org.status === 'ACTIVE' && (
          <button type="button" onClick={() => { if(confirm('Deactivate organization?')) onDeactivate(); }} className="btn btn-danger">
            Deactivate
          </button>
        )}
      </div>
    </form>
  );
}
