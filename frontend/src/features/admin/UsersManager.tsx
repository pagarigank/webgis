import React, { useState, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient, { unwrapList } from '../../lib/apiClient';

// ─── Toast ────────────────────────────────────────────────────────────────────
function useToast() {
  const [toasts, setToasts] = useState<{ id: number; msg: string; type: 'success' | 'error' }[]>([]);
  const show = useCallback((msg: string, type: 'success' | 'error' = 'success') => {
    const id = Date.now();
    setToasts(p => [...p, { id, msg, type }]);
    setTimeout(() => setToasts(p => p.filter(t => t.id !== id)), 3500);
  }, []);
  return { toasts, show };
}

function ToastContainer({ toasts }: { toasts: { id: number; msg: string; type: string }[] }) {
  return (
    <div className="admin-toast-container">
      {toasts.map(t => (
        <div key={t.id} className={`admin-toast ${t.type}`}>
          {t.type === 'success'
            ? <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            : <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          }
          {t.msg}
        </div>
      ))}
    </div>
  );
}

// ─── Status Badge ─────────────────────────────────────────────────────────────
const STATUS_COLORS: Record<string, { bg: string; color: string }> = {
  ACTIVE:   { bg: 'rgba(34,197,94,0.12)', color: '#15803d' },
  INACTIVE: { bg: 'rgba(239,68,68,0.12)', color: '#b91c1c' },
  LOCKED:   { bg: 'rgba(245,158,11,0.12)', color: '#b45309' },
};

function StatusBadge({ status }: { status: string }) {
  const s = STATUS_COLORS[status] ?? { bg: 'var(--bg-surface-alt)', color: 'var(--text-muted)' };
  return (
    <span style={{ fontSize: '0.6875rem', fontWeight: 600, padding: '2px 8px', borderRadius: 99, background: s.bg, color: s.color, letterSpacing: '0.03em' }}>
      {status}
    </span>
  );
}

// ─── Main ─────────────────────────────────────────────────────────────────────
export function UsersManager() {
  const queryClient = useQueryClient();
  const { toasts, show } = useToast();

  const { data: users, isLoading } = useQuery({
    queryKey: ['admin_users'],
    queryFn: () => apiClient.get('/users').then(unwrapList),
  });

  const { data: roles } = useQuery({
    queryKey: ['admin_roles'],
    queryFn: () => apiClient.get('/roles').then(unwrapList),
  });

  const [selectedUser, setSelectedUser] = useState<any>(null);
  const [isCreating, setIsCreating] = useState(false);

  const createUser = useMutation({
    mutationFn: (data: any) => apiClient.post('/users', data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['admin_users'] }); setIsCreating(false); show('User created successfully.'); },
    onError: (err: any) => show('Failed: ' + (err.response?.data?.message ?? err.message), 'error'),
  });

  const updateUser = useMutation({
    mutationFn: (data: { id: number; payload: any }) => apiClient.put(`/users/${data.id}`, data.payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['admin_users'] }); show('User updated.'); },
    onError: (err: any) => show('Failed: ' + (err.response?.data?.message ?? err.message), 'error'),
  });

  const deactivateUser = useMutation({
    mutationFn: (id: number) => apiClient.post(`/users/${id}/deactivate`),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['admin_users'] }); setSelectedUser(null); show('User deactivated.'); },
    onError: (err: any) => show('Failed: ' + (err.response?.data?.message ?? err.message), 'error'),
  });

  const initials = (u: any) => (u.full_name || u.username || '?').split(' ').map((w: string) => w[0]).join('').slice(0, 2).toUpperCase();

  return (
    <>
      <ToastContainer toasts={toasts} />
      <div className="admin-split">
        {/* ── Left: list panel ── */}
        <div className="admin-list-panel">
          <div className="admin-list-header">
            <h3>Users {users ? `(${users.length})` : ''}</h3>
            <button
              className="btn btn-primary btn-sm"
              onClick={() => { setIsCreating(true); setSelectedUser(null); }}
            >
              + New User
            </button>
          </div>
          <div className="admin-list-body">
            {isLoading && (
              <div style={{ padding: '1.5rem', textAlign: 'center', color: 'var(--text-muted)', fontSize: '0.875rem' }}>Loading…</div>
            )}
            {users?.map((u: any) => (
              <div
                key={u.id}
                className={`admin-list-item${selectedUser?.id === u.id && !isCreating ? ' selected' : ''}`}
                onClick={() => { setSelectedUser(u); setIsCreating(false); }}
              >
                <div className="admin-list-item-avatar">{initials(u)}</div>
                <div className="admin-list-item-info">
                  <div className="admin-list-item-name">{u.username}</div>
                  <div className="admin-list-item-meta">{u.full_name || '—'}</div>
                </div>
                <StatusBadge status={u.status} />
              </div>
            ))}
          </div>
        </div>

        {/* ── Right: form panel ── */}
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
            onDeactivate={() => { if (confirm(`Deactivate "${selectedUser.username}"?`)) deactivateUser.mutate(selectedUser.id); }}
          />
        )}
        {!selectedUser && !isCreating && (
          <div className="admin-empty-state">
            <div className="admin-empty-state-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <h4>No user selected</h4>
            <p>Select a user from the list to edit, or create a new one.</p>
          </div>
        )}
      </div>
    </>
  );
}

// ─── Form ─────────────────────────────────────────────────────────────────────
function UserForm({ user, roles, onSave, isSaving, onCancel, onDeactivate }: any) {
  const [formData, setFormData] = useState({
    username: user?.username || '',
    email: user?.email || '',
    full_name: user?.full_name || '',
    password: '',
    org_id: user?.org_id || '',
    roles: user?.roles?.map((r: any) => ({ code: r.code })) || [],
    must_change_password: user?.must_change_password || false,
  });

  const set = (k: string, v: any) => setFormData(p => ({ ...p, [k]: v }));

  const toggleRole = (code: string) => {
    setFormData(p => {
      const has = p.roles.some((r: any) => r.code === code);
      return { ...p, roles: has ? p.roles.filter((r: any) => r.code !== code) : [...p.roles, { code }] };
    });
  };

  const handleSubmit = (e: React.FormEvent) => { e.preventDefault(); onSave(formData); };

  return (
    <form onSubmit={handleSubmit} className="admin-form-panel">
      <div className="admin-form-header">
        <h3>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          {user ? `Edit — ${user.username}` : 'Create New User'}
        </h3>
        {user && <StatusBadge status={user.status} />}
      </div>

      <div className="admin-form-body">
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
          <div className="form-group">
            <label className="form-label">Username <span className="form-required">*</span></label>
            <input required type="text" className="form-input" value={formData.username}
              onChange={e => set('username', e.target.value)} disabled={!!user} />
            {user && <span className="form-hint">Username cannot be changed after creation.</span>}
          </div>
          <div className="form-group">
            <label className="form-label">Email</label>
            <input type="email" className="form-input" value={formData.email}
              onChange={e => set('email', e.target.value)} placeholder="user@example.com" />
          </div>
        </div>

        <div className="form-group">
          <label className="form-label">Full Name <span className="form-required">*</span></label>
          <input required type="text" className="form-input" value={formData.full_name}
            onChange={e => set('full_name', e.target.value)} placeholder="Juan dela Cruz" />
        </div>

        {!user && (
          <div className="form-group">
            <label className="form-label">Password <span className="form-required">*</span></label>
            <input required type="password" className="form-input" value={formData.password}
              onChange={e => set('password', e.target.value)} />
          </div>
        )}

        <div className="form-group">
          <label className="form-label">Organization ID</label>
          <input type="number" className="form-input" value={formData.org_id}
            onChange={e => set('org_id', e.target.value ? parseInt(e.target.value) : '')}
            placeholder="Leave blank for no organization" />
        </div>

        {roles && roles.length > 0 && (
          <div className="form-group">
            <label className="form-label">Roles</label>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.375rem', border: '1px solid var(--border-color)', borderRadius: 'var(--radius-sm)', padding: '0.625rem', maxHeight: '160px', overflowY: 'auto', background: 'var(--bg-surface-alt)' }}>
              {roles.map((r: any) => (
                <label key={r.id} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer', padding: '0.25rem', borderRadius: 4 }}>
                  <input type="checkbox" style={{ accentColor: 'var(--brand-primary)' }}
                    checked={formData.roles.some((ur: any) => ur.code === r.code)}
                    onChange={() => toggleRole(r.code)}
                  />
                  <span style={{ fontSize: '0.8125rem' }}>{r.name}</span>
                </label>
              ))}
            </div>
          </div>
        )}

        <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer', fontSize: '0.875rem', color: 'var(--text-secondary)' }}>
          <input type="checkbox" style={{ accentColor: 'var(--brand-primary)' }}
            checked={formData.must_change_password}
            onChange={e => set('must_change_password', e.target.checked)}
          />
          Require password change on next login
        </label>
      </div>

      <div className="admin-form-footer">
        <div style={{ display: 'flex', gap: '0.5rem' }}>
          <button type="submit" className="btn btn-primary" disabled={isSaving}>
            {isSaving ? 'Saving…' : user ? 'Save Changes' : 'Create User'}
          </button>
          {onCancel && <button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button>}
        </div>
        {onDeactivate && user?.status === 'ACTIVE' && (
          <button type="button" className="btn btn-danger btn-sm" onClick={onDeactivate}>
            Deactivate
          </button>
        )}
      </div>
    </form>
  );
}
