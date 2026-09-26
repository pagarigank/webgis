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

// ─── Org-type badge ───────────────────────────────────────────────────────────
const ORG_TYPE_COLORS: Record<string, { bg: string; color: string }> = {
  GOVERNMENT: { bg: 'rgba(37,99,235,0.10)', color: '#1d4ed8' },
  PRIVATE:    { bg: 'rgba(245,158,11,0.10)', color: '#b45309' },
  ACADEMIC:   { bg: 'rgba(139,92,246,0.10)', color: '#7c3aed' },
  NGO:        { bg: 'rgba(34,197,94,0.10)', color: '#15803d' },
};

function OrgTypeBadge({ type }: { type: string }) {
  const s = ORG_TYPE_COLORS[type] ?? { bg: 'var(--bg-surface-alt)', color: 'var(--text-muted)' };
  return (
    <span style={{ fontSize: '0.6875rem', fontWeight: 600, padding: '2px 8px', borderRadius: 99, background: s.bg, color: s.color, letterSpacing: '0.03em' }}>
      {type}
    </span>
  );
}

// ─── Main ─────────────────────────────────────────────────────────────────────
export function OrganizationsManager() {
  const queryClient = useQueryClient();
  const { toasts, show } = useToast();

  const { data: orgs, isLoading } = useQuery({
    queryKey: ['admin_organizations'],
    queryFn: () => apiClient.get('/organizations').then(unwrapList),
  });

  const [selectedOrg, setSelectedOrg] = useState<any>(null);
  const [isCreating, setIsCreating] = useState(false);

  const createOrg = useMutation({
    mutationFn: (data: any) => apiClient.post('/organizations', data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['admin_organizations'] }); setIsCreating(false); show('Organization created.'); },
    onError: (err: any) => show('Failed: ' + (err.response?.data?.message ?? err.message), 'error'),
  });

  const updateOrg = useMutation({
    mutationFn: (data: { id: number; payload: any }) => apiClient.put(`/organizations/${data.id}`, data.payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['admin_organizations'] }); show('Organization updated.'); },
    onError: (err: any) => show('Failed: ' + (err.response?.data?.message ?? err.message), 'error'),
  });

  const deactivateOrg = useMutation({
    mutationFn: (id: number) => apiClient.post(`/organizations/${id}/deactivate`),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['admin_organizations'] }); setSelectedOrg(null); show('Organization deactivated.'); },
    onError: (err: any) => show('Failed: ' + (err.response?.data?.message ?? err.message), 'error'),
  });

  const orgInitials = (o: any) => (o.code || o.name || '?').slice(0, 2).toUpperCase();

  return (
    <>
      <ToastContainer toasts={toasts} />
      <div className="admin-split">
        {/* ── Left: list ── */}
        <div className="admin-list-panel">
          <div className="admin-list-header">
            <h3>Organizations {orgs ? `(${orgs.length})` : ''}</h3>
            <button className="btn btn-primary btn-sm" onClick={() => { setIsCreating(true); setSelectedOrg(null); }}>
              + New
            </button>
          </div>
          <div className="admin-list-body">
            {isLoading && <div style={{ padding: '1.5rem', textAlign: 'center', color: 'var(--text-muted)', fontSize: '0.875rem' }}>Loading…</div>}
            {orgs?.map((org: any) => (
              <div
                key={org.id}
                className={`admin-list-item${selectedOrg?.id === org.id && !isCreating ? ' selected' : ''}`}
                onClick={() => { setSelectedOrg(org); setIsCreating(false); }}
              >
                <div className="admin-list-item-avatar" style={{ borderRadius: 8 }}>{orgInitials(org)}</div>
                <div className="admin-list-item-info">
                  <div className="admin-list-item-name">{org.name}</div>
                  <div className="admin-list-item-meta">{org.code}</div>
                </div>
                <OrgTypeBadge type={org.org_type} />
              </div>
            ))}
          </div>
        </div>

        {/* ── Right: form ── */}
        {isCreating && (
          <OrganizationForm onSave={(d: any) => createOrg.mutate(d)} isSaving={createOrg.isPending} onCancel={() => setIsCreating(false)} />
        )}
        {selectedOrg && !isCreating && (
          <OrganizationForm
            org={selectedOrg}
            onSave={(d: any) => updateOrg.mutate({ id: selectedOrg.id, payload: d })}
            isSaving={updateOrg.isPending}
            onDeactivate={() => { if (confirm(`Deactivate "${selectedOrg.name}"?`)) deactivateOrg.mutate(selectedOrg.id); }}
          />
        )}
        {!selectedOrg && !isCreating && (
          <div className="admin-empty-state">
            <div className="admin-empty-state-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <h4>No organization selected</h4>
            <p>Select an organization to edit, or create a new one.</p>
          </div>
        )}
      </div>
    </>
  );
}

// ─── Form ─────────────────────────────────────────────────────────────────────
function OrganizationForm({ org, onSave, isSaving, onCancel, onDeactivate }: any) {
  const [formData, setFormData] = useState({
    code: org?.code || '',
    name: org?.name || '',
    org_type: org?.org_type || 'GOVERNMENT',
    psgc_code: org?.psgc_code || '',
  });

  const set = (k: string, v: any) => setFormData(p => ({ ...p, [k]: v }));
  const handleSubmit = (e: React.FormEvent) => { e.preventDefault(); onSave(formData); };

  return (
    <form onSubmit={handleSubmit} className="admin-form-panel">
      <div className="admin-form-header">
        <h3>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
          {org ? `Edit — ${org.name}` : 'Create Organization'}
        </h3>
        {org && <OrgTypeBadge type={org.org_type} />}
      </div>

      <div className="admin-form-body">
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
          <div className="form-group">
            <label className="form-label">Code <span className="form-required">*</span></label>
            <input required type="text" className="form-input" value={formData.code}
              onChange={e => set('code', e.target.value)} disabled={!!org}
              style={{ fontFamily: 'var(--font-mono)', textTransform: 'uppercase' }} />
            {org && <span className="form-hint">Code cannot be changed.</span>}
          </div>
          <div className="form-group">
            <label className="form-label">Type <span className="form-required">*</span></label>
            <select required className="form-select" value={formData.org_type} onChange={e => set('org_type', e.target.value)}>
              <option value="GOVERNMENT">Government</option>
              <option value="PRIVATE">Private</option>
              <option value="ACADEMIC">Academic</option>
              <option value="NGO">NGO</option>
            </select>
          </div>
        </div>

        <div className="form-group">
          <label className="form-label">Name <span className="form-required">*</span></label>
          <input required type="text" className="form-input" value={formData.name}
            onChange={e => set('name', e.target.value)} placeholder="Full organization name" />
        </div>

        <div className="form-group">
          <label className="form-label">PSGC Code <span className="form-hint" style={{ display: 'inline', marginLeft: 4 }}>(optional)</span></label>
          <input type="text" className="form-input" value={formData.psgc_code}
            onChange={e => set('psgc_code', e.target.value)} placeholder="e.g. 035401000"
            style={{ fontFamily: 'var(--font-mono)' }} />
          <span className="form-hint">Philippine Standard Geographic Code of the area of jurisdiction.</span>
        </div>
      </div>

      <div className="admin-form-footer">
        <div style={{ display: 'flex', gap: '0.5rem' }}>
          <button type="submit" className="btn btn-primary" disabled={isSaving}>
            {isSaving ? 'Saving…' : org ? 'Save Changes' : 'Create Organization'}
          </button>
          {onCancel && <button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button>}
        </div>
        {onDeactivate && org?.status === 'ACTIVE' && (
          <button type="button" className="btn btn-danger btn-sm" onClick={onDeactivate}>Deactivate</button>
        )}
      </div>
    </form>
  );
}
