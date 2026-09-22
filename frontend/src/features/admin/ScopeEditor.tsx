import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient, { unwrapList } from '../../lib/apiClient';
import { MapPicker } from '../../components/common/MapPicker';

const EMPTY_ARRAY: any[] = [];

export function ScopeEditor() {
  const queryClient = useQueryClient();
  const { data: users, isLoading } = useQuery({
    queryKey: ['admin_users'],
    queryFn: () => apiClient.get('/users').then(unwrapList)
  });

  const [selectedUser, setSelectedUser] = useState<any>(null);

  const { data: scopes, isLoading: isLoadingScopes } = useQuery({
    queryKey: ['user_scopes', selectedUser?.id],
    queryFn: () =>
      apiClient.get(`/users/${selectedUser.id}/scopes`).then((res: any) => {
        if (Array.isArray(res)) return res;
        if (Array.isArray(res?.scopes)) return res.scopes;
        if (Array.isArray(res?.data)) return res.data;
        return [];
      }),
    enabled: !!selectedUser
  });

  const updateScopes = useMutation({
    mutationFn: (newScopes: any[]) => apiClient.put(`/users/${selectedUser.id}/scopes`, { scopes: newScopes }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['user_scopes', selectedUser.id] });
      alert('Scopes updated successfully.');
    }
  });

  if (isLoading) return <div>Loading users...</div>;

  return (
    <div className="grid-2-cols">
      <div style={{ borderRight: '1px solid var(--border-color)', paddingRight: '1rem' }}>
        <h2 className="mb-4">Users (Select to edit scopes)</h2>
        <ul style={{ listStyle: 'none', padding: 0, margin: 0, height: '600px', overflowY: 'auto' }}>
          {users?.map((u: any) => (
            <li 
              key={u.id}
              className={`list-item ${selectedUser?.id === u.id ? 'selected' : ''}`}
              onClick={() => setSelectedUser(u)}
            >
              <strong>{u.username}</strong>
              <div className="text-muted text-sm">{u.full_name}</div>
            </li>
          ))}
        </ul>
      </div>
      
      <div>
        {selectedUser ? (
          <div>
            <h2 className="mb-4">Scopes for {selectedUser.username}</h2>
            {isLoadingScopes ? <div>Loading scopes...</div> : (
              <ScopeManager 
                scopes={scopes || EMPTY_ARRAY} 
                onSave={(s) => updateScopes.mutate(s)} 
                isSaving={updateScopes.isPending}
              />
            )}
          </div>
        ) : (
          <div className="text-muted">Select a user to edit scopes.</div>
        )}
      </div>
    </div>
  );
}

function ScopeManager({ scopes, onSave, isSaving }: { scopes: any[], onSave: (s: any[]) => void, isSaving: boolean }) {
  const [localScopes, setLocalScopes] = useState<any[]>(scopes);

  React.useEffect(() => {
    setLocalScopes(scopes);
  }, [scopes]);

  const addScope = () => setLocalScopes([...localScopes, { type: 'GLOBAL', access: 'VIEW', code: '*' }]);
  const updateScope = (idx: number, updates: any) => {
    const next = [...localScopes];
    next[idx] = { ...next[idx], ...updates };
    setLocalScopes(next);
  };
  const removeScope = (idx: number) => setLocalScopes(localScopes.filter((_, i) => i !== idx));

  return (
    <div>
      {localScopes.map((scope, idx) => (
        <div key={idx} className="card mb-4" style={{ background: 'var(--bg-surface-hover)' }}>
          <div className="flex gap-4 mb-2">
            <select value={scope.type} onChange={e => updateScope(idx, { type: e.target.value })} className="form-select" style={{ width: 'auto' }}>
              <option value="GLOBAL">GLOBAL</option>
              <option value="REGION">REGION</option>
              <option value="CUSTOM_AREA">CUSTOM_AREA</option>
            </select>
            <select value={scope.access} onChange={e => updateScope(idx, { access: e.target.value })} className="form-select" style={{ width: 'auto' }}>
              <option value="VIEW">VIEW</option>
              <option value="EDIT">EDIT</option>
              <option value="APPROVE">APPROVE</option>
            </select>
            {scope.type !== 'CUSTOM_AREA' && (
              <input 
                type="text" 
                value={scope.code} 
                onChange={e => updateScope(idx, { code: e.target.value })} 
                placeholder="Code (e.g. *, 01)"
                className="form-input"
              />
            )}
            <button onClick={() => removeScope(idx)} className="btn btn-danger">Remove</button>
          </div>
          
          {scope.type === 'CUSTOM_AREA' && (
            <div className="mt-4">
              <h4 className="mb-2">Custom Area Map Picker</h4>
              <MapPicker 
                initialGeoJson={scope.geom ? JSON.parse(scope.geom) : null} 
                onChange={(geoJson) => updateScope(idx, { geom: JSON.stringify(geoJson) })} 
              />
            </div>
          )}
        </div>
      ))}
      <div className="flex gap-4 mt-4" style={{ borderTop: '1px solid var(--border-color)', paddingTop: '1rem' }}>
        <button onClick={addScope} className="btn btn-ghost">Add Scope</button>
        <button onClick={() => onSave(localScopes)} disabled={isSaving} className="btn btn-primary">
          {isSaving ? 'Saving...' : 'Save Scopes'}
        </button>
      </div>
    </div>
  );
}
