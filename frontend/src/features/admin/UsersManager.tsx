import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient, { unwrapList } from '../../lib/apiClient';

export function UsersManager() {
  const queryClient = useQueryClient();
  const { data: users, isLoading } = useQuery({
    queryKey: ['admin_users'],
    queryFn: () => apiClient.get('/users').then(unwrapList)
  });

  const { data: roles } = useQuery({
    queryKey: ['admin_roles'],
    queryFn: () => apiClient.get('/roles').then(unwrapList)
  });

  const [selectedUser, setSelectedUser] = useState<any>(null);
  const [isCreating, setIsCreating] = useState(false);

  const createUser = useMutation({
    mutationFn: (data: any) => apiClient.post('/users', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin_users'] });
      setIsCreating(false);
      alert('User created successfully');
    },
    onError: (err: any) => alert('Failed to create user: ' + (err.response?.data?.message || err.message))
  });

  const updateUser = useMutation({
    mutationFn: (data: { id: number, payload: any }) => apiClient.put(`/users/${data.id}`, data.payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin_users'] });
      alert('User updated successfully');
    }
  });

  const deactivateUser = useMutation({
    mutationFn: (id: number) => apiClient.post(`/users/${id}/deactivate`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin_users'] });
      alert('User deactivated');
    }
  });

  if (isLoading) return <div>Loading users...</div>;

  return (
    <div className="grid-2-cols">
      <div style={{ borderRight: '1px solid var(--border-color)', paddingRight: '1rem' }}>
        <div className="flex justify-between items-center mb-4">
          <h2 style={{ margin: 0 }}>Users</h2>
          <button onClick={() => { setIsCreating(true); setSelectedUser(null); }} className="btn btn-primary">
            + New
          </button>
        </div>
        <ul style={{ listStyle: 'none', padding: 0, margin: 0, height: '600px', overflowY: 'auto' }}>
          {users?.map((u: any) => (
            <li 
              key={u.id}
              className={`list-item ${selectedUser?.id === u.id ? 'selected' : ''}`}
              onClick={() => { setSelectedUser(u); setIsCreating(false); }}
            >
              <strong>{u.username}</strong>
              <div className="text-muted text-sm">{u.full_name} | {u.status}</div>
            </li>
          ))}
        </ul>
      </div>
      
      <div>
        {isCreating && (
          <UserForm 
            roles={roles} 
            onSave={(data: any) => createUser.mutate(data)} 
            isSaving={createUser.isPending} 
            onCancel={() => setIsCreating(false)} 
          />
        )}
        {selectedUser && !isCreating && (
          <UserForm 
            user={selectedUser} 
            roles={roles} 
            onSave={(data: any) => updateUser.mutate({ id: selectedUser.id, payload: data })} 
            isSaving={updateUser.isPending} 
            onDeactivate={() => deactivateUser.mutate(selectedUser.id)}
          />
        )}
        {!selectedUser && !isCreating && (
          <div className="text-muted">Select a user to edit or create a new one.</div>
        )}
      </div>
    </div>
  );
}

function UserForm({ user, roles, onSave, isSaving, onCancel, onDeactivate }: any) {
  const [formData, setFormData] = useState({
    username: user?.username || '',
    email: user?.email || '',
    full_name: user?.full_name || '',
    password: '',
    org_id: user?.org_id || '',
    roles: user?.roles?.map((r: any) => ({ code: r.code })) || [],
    must_change_password: user?.must_change_password || false
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSave(formData);
  };

  const toggleRole = (code: string) => {
    setFormData(prev => {
      const has = prev.roles.some((r: any) => r.code === code);
      if (has) return { ...prev, roles: prev.roles.filter((r: any) => r.code !== code) };
      return { ...prev, roles: [...prev.roles, { code }] };
    });
  };

  return (
    <form onSubmit={handleSubmit} style={{ maxWidth: '500px' }}>
      <h2 className="mb-4">{user ? 'Edit User' : 'Create User'}</h2>
      
      <div className="form-group">
        <label className="form-label">Username</label>
        <input required type="text" className="form-input" value={formData.username} onChange={e => setFormData({...formData, username: e.target.value})} disabled={!!user} />
      </div>
      
      <div className="form-group">
        <label className="form-label">Email</label>
        <input type="email" className="form-input" value={formData.email} onChange={e => setFormData({...formData, email: e.target.value})} />
      </div>

      <div className="form-group">
        <label className="form-label">Full Name</label>
        <input required type="text" className="form-input" value={formData.full_name} onChange={e => setFormData({...formData, full_name: e.target.value})} />
      </div>

      {!user && (
        <div className="form-group">
          <label className="form-label">Password</label>
          <input required type="password" className="form-input" value={formData.password} onChange={e => setFormData({...formData, password: e.target.value})} />
        </div>
      )}

      <div className="form-group">
        <label className="form-label">Organization ID</label>
        <input type="number" className="form-input" value={formData.org_id} onChange={e => setFormData({...formData, org_id: e.target.value ? parseInt(e.target.value) : ''})} />
      </div>

      <div className="form-group">
        <label className="form-label">Roles</label>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.5rem', border: '1px solid var(--border-color)', padding: '1rem', borderRadius: '8px', maxHeight: '160px', overflowY: 'auto' }}>
          {roles?.map((r: any) => (
            <label key={r.id} className="flex items-center gap-2" style={{ cursor: 'pointer' }}>
              <input 
                type="checkbox" 
                checked={formData.roles.some((userRole: any) => userRole.code === r.code)}
                onChange={() => toggleRole(r.code)}
              />
              <span className="text-sm">{r.name}</span>
            </label>
          ))}
        </div>
      </div>

      <div className="flex gap-4 mt-4" style={{ paddingTop: '1rem', borderTop: '1px solid var(--border-color)' }}>
        <button type="submit" disabled={isSaving} className="btn btn-primary" style={{ flex: 1 }}>
          {isSaving ? 'Saving...' : 'Save'}
        </button>
        {onCancel && (
          <button type="button" onClick={onCancel} className="btn btn-ghost">Cancel</button>
        )}
        {user && user.status === 'ACTIVE' && (
          <button type="button" onClick={() => { if(confirm('Deactivate user?')) onDeactivate(); }} className="btn btn-danger">
            Deactivate
          </button>
        )}
      </div>
    </form>
  );
}
