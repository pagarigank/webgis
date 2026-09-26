import React, { useState, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient, { unwrapList } from '../../lib/apiClient';
import { allPermissionCodes } from '../../auth/permissions';
import type { PermissionCode } from '../../auth/permissions';

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

// ─── System badge ─────────────────────────────────────────────────────────────
function SystemBadge() {
  return (
    <span style={{ fontSize: '0.6875rem', fontWeight: 600, padding: '2px 8px', borderRadius: 99, background: 'rgba(139,92,246,0.10)', color: '#7c3aed' }}>
      SYSTEM
    </span>
  );
}

// ─── Main ─────────────────────────────────────────────────────────────────────
export function RolesManager() {
  const queryClient = useQueryClient();
  const { toasts, show } = useToast();

  const { data: roles, isLoading } = useQuery({
    queryKey: ['admin_roles'],
    queryFn: () => apiClient.get('/roles').then(unwrapList),
  });

  const [isCreating, setIsCreating] = useState(false);
  const [selectedRole, setSelectedRole] = useState<any>(null);

  const createRole = useMutation({
    mutationFn: (data: any) => apiClient.post('/roles', data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['admin_roles'] }); setIsCreating(false); show('Role created.'); },
    onError: (err: any) => show('Failed: ' + (err.response?.data?.message ?? err.message), 'error'),
  });

  const updateRole = useMutation({
    mutationFn: (data: { id: number; payload: any }) => apiClient.put(`/roles/${data.id}`, data.payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['admin_roles'] }); show('Role details updated.'); },
    onError: (err: any) => show('Failed: ' + (err.response?.data?.message ?? err.message), 'error'),
  });

  const updatePermissions = useMutation({
    mutationFn: (data: { id: number; permissions: PermissionCode[] }) =>
      apiClient.put(`/roles/${data.id}/permissions`, { permissions: data.permissions }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['admin_roles'] }); show('Permissions saved.'); },
    onError: (err: any) => show('Failed: ' + (err.response?.data?.message ?? err.message), 'error'),
  });

  const roleInitials = (r: any) => (r.code || r.name || '?').slice(0, 2).toUpperCase();

  return (
    <>
      <ToastContainer toasts={toasts} />
      <div className="admin-split">
        {/* ── Left: list ── */}
        <div className="admin-list-panel">
          <div className="admin-list-header">
            <h3>Roles {roles ? `(${roles.length})` : ''}</h3>
            <button className="btn btn-primary btn-sm" onClick={() => { setIsCreating(true); setSelectedRole(null); }}>
              + New
            </button>
          </div>
          <div className="admin-list-body">
            {isLoading && <div style={{ padding: '1.5rem', textAlign: 'center', color: 'var(--text-muted)', fontSize: '0.875rem' }}>Loading…</div>}
            {roles?.map((role: any) => (
              <div
                key={role.id}
                className={`admin-list-item${selectedRole?.id === role.id && !isCreating ? ' selected' : ''}`}
                onClick={() => { setSelectedRole(role); setIsCreating(false); }}
              >
                <div className="admin-list-item-avatar" style={{ borderRadius: 8, background: role.is_system ? 'linear-gradient(135deg,hsl(258,60%,80%),hsl(258,70%,65%))' : undefined }}>
                  {roleInitials(role)}
                </div>
                <div className="admin-list-item-info">
                  <div className="admin-list-item-name">{role.name}</div>
                  <div className="admin-list-item-meta" style={{ fontFamily: 'var(--font-mono)' }}>{role.code}</div>
                </div>
                {role.is_system && <SystemBadge />}
              </div>
            ))}
          </div>
        </div>

        {/* ── Right: form + permission matrix ── */}
        {isCreating && (
          <RoleForm onSave={(d: any) => createRole.mutate(d)} isSaving={createRole.isPending} onCancel={() => setIsCreating(false)} />
        )}
        {selectedRole && !isCreating && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
            <RoleForm
              role={selectedRole}
              onSave={(d: any) => updateRole.mutate({ id: selectedRole.id, payload: d })}
              isSaving={updateRole.isPending}
            />
            <PermissionMatrix
              role={selectedRole}
              onSave={perms => updatePermissions.mutate({ id: selectedRole.id, permissions: perms })}
              isSaving={updatePermissions.isPending}
            />
          </div>
        )}
        {!selectedRole && !isCreating && (
          <div className="admin-empty-state">
            <div className="admin-empty-state-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <h4>No role selected</h4>
            <p>Select a role to edit its details and permissions, or create a new one.</p>
          </div>
        )}
      </div>
    </>
  );
}

// ─── Role Form ────────────────────────────────────────────────────────────────
function RoleForm({ role, onSave, isSaving, onCancel }: any) {
  const [formData, setFormData] = useState({
    code: role?.code || '',
    name: role?.name || '',
    description: role?.description || '',
  });
  const set = (k: string, v: string) => setFormData(p => ({ ...p, [k]: v }));
  const handleSubmit = (e: React.FormEvent) => { e.preventDefault(); onSave(formData); };

  return (
    <form onSubmit={handleSubmit} className="admin-form-panel">
      <div className="admin-form-header">
        <h3>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          {role ? `Edit — ${role.name}` : 'Create New Role'}
        </h3>
        {role?.is_system && <SystemBadge />}
      </div>

      <div className="admin-form-body">
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
          <div className="form-group">
            <label className="form-label">Role Code <span className="form-required">*</span></label>
            <input required type="text" className="form-input" value={formData.code}
              onChange={e => set('code', e.target.value)} disabled={!!role?.is_system}
              placeholder="e.g. assessor.clerk"
              style={{ fontFamily: 'var(--font-mono)' }} />
            {role?.is_system && <span className="form-hint">System role codes are locked.</span>}
          </div>
          <div className="form-group">
            <label className="form-label">Display Name <span className="form-required">*</span></label>
            <input required type="text" className="form-input" value={formData.name}
              onChange={e => set('name', e.target.value)} placeholder="e.g. Assessor Clerk" />
          </div>
        </div>
        <div className="form-group">
          <label className="form-label">Description <span className="form-required">*</span></label>
          <textarea required className="form-textarea" value={formData.description}
            onChange={e => set('description', e.target.value)}
            placeholder="Describe the purpose and scope of this role…" />
        </div>
      </div>

      <div className="admin-form-footer">
        <div style={{ display: 'flex', gap: '0.5rem' }}>
          <button type="submit" className="btn btn-primary" disabled={isSaving}>
            {isSaving ? 'Saving…' : role ? 'Save Details' : 'Create Role'}
          </button>
          {onCancel && <button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button>}
        </div>
      </div>
    </form>
  );
}

// ─── Permission Matrix ────────────────────────────────────────────────────────
function PermissionMatrix({ role, onSave, isSaving }: { role: any; onSave: (p: PermissionCode[]) => void; isSaving: boolean }) {
  const [selected, setSelected] = useState<Set<PermissionCode>>(new Set(role.permissions.map((p: any) => p.code)));

  React.useEffect(() => {
    setSelected(new Set(role.permissions.map((p: any) => p.code)));
  }, [role.id, role.permissions]);

  const toggle = (code: PermissionCode) => {
    setSelected(prev => {
      const next = new Set(prev);
      if (next.has(code)) next.delete(code); else next.add(code);
      return next;
    });
  };

  // Group by prefix
  const groups = allPermissionCodes.reduce((acc, code) => {
    const prefix = code.split('.')[0];
    if (!acc[prefix]) acc[prefix] = [];
    acc[prefix].push(code);
    return acc;
  }, {} as Record<string, PermissionCode[]>);

  const totalSelected = selected.size;
  const totalAvailable = allPermissionCodes.length;

  return (
    <div className="admin-form-panel">
      <div className="admin-form-header">
        <h3>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
          Permissions
        </h3>
        <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
          {totalSelected} / {totalAvailable} granted
        </span>
      </div>

      <div className="admin-form-body">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '0.75rem' }}>
          {Object.entries(groups).map(([prefix, codes]) => (
            <div key={prefix} className="perm-group">
              <div className="perm-group-header">
                {prefix}
                <span style={{ marginLeft: '0.5rem', fontWeight: 400, opacity: 0.7 }}>
                  {codes.filter(c => selected.has(c)).length}/{codes.length}
                </span>
              </div>
              {codes.map(code => (
                <label key={code} className="perm-item">
                  <input
                    type="checkbox"
                    checked={selected.has(code)}
                    onChange={() => toggle(code)}
                  />
                  <span className="perm-item-label">{code.split('.').slice(1).join('.')}</span>
                </label>
              ))}
            </div>
          ))}
        </div>
      </div>

      <div className="admin-form-footer">
        <div />
        <button className="btn btn-primary" disabled={isSaving} onClick={() => onSave(Array.from(selected))}>
          {isSaving ? 'Saving…' : 'Save Permissions'}
        </button>
      </div>
    </div>
  );
}
