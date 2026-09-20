import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient from '../../lib/apiClient';
import { allPermissionCodes } from '../../auth/permissions';
import type { PermissionCode } from '../../auth/permissions';

export function RolesManager() {
  const queryClient = useQueryClient();
  const { data: roles, isLoading } = useQuery({
    queryKey: ['admin_roles'],
    queryFn: () => apiClient.get('/roles').then(res => res.data.data)
  });

  const [isCreating, setIsCreating] = useState(false);
  const [selectedRole, setSelectedRole] = useState<any>(null);

  const createRole = useMutation({
    mutationFn: (data: any) => apiClient.post('/roles', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin_roles'] });
      setIsCreating(false);
      alert('Role created successfully.');
    }
  });

  const updateRole = useMutation({
    mutationFn: (data: { id: number; payload: any }) => apiClient.put(`/roles/${data.id}`, data.payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin_roles'] });
      alert('Role details updated.');
    }
  });

  const updatePermissions = useMutation({
    mutationFn: (data: { id: number; permissions: PermissionCode[] }) => 
      apiClient.put(`/roles/${data.id}/permissions`, { permissions: data.permissions }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin_roles'] });
      alert('Permissions updated.');
    }
  });

  if (isLoading) return <div>Loading roles...</div>;

  return (
    <div className="grid-2-cols">
      <div style={{ borderRight: '1px solid var(--border-color)', paddingRight: '1rem' }}>
        <div className="flex justify-between items-center mb-4">
          <h2 style={{ margin: 0 }}>Roles</h2>
          <button onClick={() => { setIsCreating(true); setSelectedRole(null); }} className="btn btn-primary">
            + New
          </button>
        </div>
        <ul style={{ listStyle: 'none', padding: 0, margin: 0, height: '600px', overflowY: 'auto' }}>
          {roles?.map((role: any) => (
            <li 
              key={role.id}
              className={`list-item ${selectedRole?.id === role.id ? 'selected' : ''}`}
              onClick={() => { setSelectedRole(role); setIsCreating(false); }}
            >
              <strong>{role.name}</strong>
              <div className="text-muted text-sm">{role.code} {role.is_system ? '(System)' : ''}</div>
            </li>
          ))}
        </ul>
      </div>
      
      <div>
        {isCreating && (
          <RoleForm 
            onSave={(data: any) => createRole.mutate(data)} 
            isSaving={createRole.isPending} 
            onCancel={() => setIsCreating(false)} 
          />
        )}
        {selectedRole && !isCreating && (
          <div>
            <RoleForm 
              role={selectedRole}
              onSave={(data: any) => updateRole.mutate({ id: selectedRole.id, payload: data })}
              isSaving={updateRole.isPending}
            />
            <hr style={{ margin: '2rem 0', borderColor: 'var(--border-color)' }} />
            <h2 className="mb-4">Permission Matrix: {selectedRole.name}</h2>
            <PermissionMatrix 
              role={selectedRole} 
              onSave={(perms) => updatePermissions.mutate({ id: selectedRole.id, permissions: perms })} 
              isSaving={updatePermissions.isPending}
            />
          </div>
        )}
        {!selectedRole && !isCreating && (
          <div className="text-muted">Select a role to edit or create a new one.</div>
        )}
      </div>
    </div>
  );
}

function RoleForm({ role, onSave, isSaving, onCancel }: any) {
  const [formData, setFormData] = useState({
    code: role?.code || '',
    name: role?.name || '',
    description: role?.description || ''
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSave(formData);
  };

  return (
    <form onSubmit={handleSubmit} style={{ maxWidth: '500px' }}>
      <h2 className="mb-4">{role ? 'Edit Role Details' : 'Create Role'}</h2>
      <div className="form-group">
        <label className="form-label">Role Code</label>
        <input required type="text" className="form-input" value={formData.code} onChange={e => setFormData({...formData, code: e.target.value})} disabled={!!role?.is_system} />
      </div>
      <div className="form-group">
        <label className="form-label">Name</label>
        <input required type="text" className="form-input" value={formData.name} onChange={e => setFormData({...formData, name: e.target.value})} />
      </div>
      <div className="form-group">
        <label className="form-label">Description</label>
        <textarea required className="form-input" value={formData.description} onChange={e => setFormData({...formData, description: e.target.value})} />
      </div>
      <div className="flex gap-4 mt-4">
        <button type="submit" disabled={isSaving} className="btn btn-primary" style={{ flex: 1 }}>
          {isSaving ? 'Saving...' : 'Save Details'}
        </button>
        {onCancel && (
          <button type="button" onClick={onCancel} className="btn btn-ghost">Cancel</button>
        )}
      </div>
    </form>
  );
}

function PermissionMatrix({ role, onSave, isSaving }: { role: any, onSave: (p: PermissionCode[]) => void, isSaving: boolean }) {
  const [selected, setSelected] = useState<Set<PermissionCode>>(new Set(role.permissions.map((p: any) => p.code)));

  // Update local state when role changes
  React.useEffect(() => {
    setSelected(new Set(role.permissions.map((p: any) => p.code)));
  }, [role.id, role.permissions]);

  const toggle = (code: PermissionCode) => {
    const next = new Set(selected);
    if (next.has(code)) next.delete(code);
    else next.add(code);
    setSelected(next);
  };

  // Group permissions by prefix
  const groups = allPermissionCodes.reduce((acc, code) => {
    const prefix = code.split('.')[0];
    if (!acc[prefix]) acc[prefix] = [];
    acc[prefix].push(code);
    return acc;
  }, {} as Record<string, PermissionCode[]>);

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(250px, 1fr))', gap: '1rem', marginBottom: '1.5rem' }}>
        {Object.entries(groups).map(([prefix, codes]) => (
          <div key={prefix} style={{ border: '1px solid var(--border-color)', padding: '1rem', borderRadius: '8px' }}>
            <h4 style={{ textTransform: 'capitalize', borderBottom: '1px solid var(--border-color)', paddingBottom: '0.5rem', marginBottom: '0.75rem' }}>{prefix}</h4>
            {codes.map(code => (
              <label key={code} className="flex items-center gap-2" style={{ cursor: 'pointer', padding: '0.25rem 0' }}>
                <input 
                  type="checkbox" 
                  checked={selected.has(code)} 
                  onChange={() => toggle(code)}
                  disabled={role.is_system && code === 'system.config'} // Just an example of preventing lockout
                />
                <span className="text-sm font-mono">{code}</span>
              </label>
            ))}
          </div>
        ))}
      </div>
      <button 
        onClick={() => onSave(Array.from(selected))}
        disabled={isSaving}
        className="btn btn-primary"
      >
        {isSaving ? 'Saving...' : 'Save Permissions'}
      </button>
    </div>
  );
}
