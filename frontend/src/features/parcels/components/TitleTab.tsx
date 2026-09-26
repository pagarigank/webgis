import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient from '../../../lib/apiClient';

// ─── Types ────────────────────────────────────────────────────────────────────
interface Party {
  id: number;
  party_id: number;
  full_name: string;
  party_type: string;
  role: string;
  share_numerator: number | null;
  share_denominator: number | null;
  effective_from: string | null;
}

interface LandTitle {
  id: number;
  title_number: string;
  title_type: string;
  title_date: string | null;
  registry_office: string | null;
  lot_number: string | null;
  area_sqm: number | null;
  location_description: string | null;
  psgc_barangay: string | null;
  remarks: string | null;
  status: string;
  relationship?: string;
  parties: Party[];
}

const TITLE_TYPES = ['OCT', 'TCT', 'CCT', 'CAR', 'EP', 'CLOA', 'OTHER'];
const PARTY_ROLES = ['REGISTERED_OWNER', 'CO_OWNER', 'MORTGAGEE', 'LESSEE', 'ADMINISTRATOR'];

// ─── API ──────────────────────────────────────────────────────────────────────
const titleApi = {
  list:        (parcelId: string)                   => apiClient.get(`/parcels/${parcelId}/titles`)                            as Promise<LandTitle[]>,
  create:      (parcelId: string, data: any)        => apiClient.post(`/parcels/${parcelId}/titles`, data)                     as Promise<LandTitle>,
  update:      (titleId: number, data: any)         => apiClient.put(`/titles/${titleId}`, data)                               as Promise<LandTitle>,
  unlink:      (parcelId: string, titleId: number)  => apiClient.delete(`/parcels/${parcelId}/titles/${titleId}`)              as Promise<void>,
  addParty:    (titleId: number, data: any)         => apiClient.post(`/titles/${titleId}/parties`, data)                     as Promise<any>,
  removeParty: (titleId: number, partyRowId: number) => apiClient.delete(`/titles/${titleId}/parties/${partyRowId}`)          as Promise<void>,
};

// ─── Small badge ─────────────────────────────────────────────────────────────
function TitleTypeBadge({ type }: { type: string }) {
  return (
    <span style={{ fontSize: '0.6875rem', fontWeight: 700, padding: '2px 8px', borderRadius: 99,
      background: 'rgba(37,99,235,0.10)', color: '#1d4ed8', letterSpacing: '0.04em' }}>
      {type}
    </span>
  );
}

function StatusBadge({ status }: { status: string }) {
  const ok = status === 'ACTIVE';
  return (
    <span style={{ fontSize: '0.6875rem', fontWeight: 600, padding: '2px 8px', borderRadius: 99,
      background: ok ? 'rgba(34,197,94,0.12)' : 'rgba(239,68,68,0.12)',
      color: ok ? '#15803d' : '#b91c1c' }}>
      {status}
    </span>
  );
}

// ─── Main component ───────────────────────────────────────────────────────────
export function TitleTab({ parcelId }: { parcelId: string }) {
  const qc = useQueryClient();
  const [creating, setCreating]         = useState(false);
  const [selected, setSelected]         = useState<LandTitle | null>(null);
  const [addingParty, setAddingParty]   = useState(false);
  const [error, setError]               = useState<string | null>(null);

  const { data: titles = [], isLoading } = useQuery<LandTitle[]>({
    queryKey: ['parcel-titles', parcelId],
    queryFn:  () => titleApi.list(parcelId),
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['parcel-titles', parcelId] });

  const createMut = useMutation({
    mutationFn: (data: any) => titleApi.create(parcelId, data),
    onSuccess: (t) => { invalidate(); setCreating(false); setSelected(t); },
    onError:   (e: any) => setError(e?.response?.data?.message ?? e.message),
  });

  const updateMut = useMutation({
    mutationFn: ({ id, data }: { id: number; data: any }) => titleApi.update(id, data),
    onSuccess: (t) => { invalidate(); setSelected(t); },
    onError:   (e: any) => setError(e?.response?.data?.message ?? e.message),
  });

  const unlinkMut = useMutation({
    mutationFn: (titleId: number) => titleApi.unlink(parcelId, titleId),
    onSuccess: () => { invalidate(); setSelected(null); },
    onError:   (e: any) => setError(e?.response?.data?.message ?? e.message),
  });

  const addPartyMut = useMutation({
    mutationFn: ({ titleId, data }: { titleId: number; data: any }) => titleApi.addParty(titleId, data),
    onSuccess: () => { invalidate(); setAddingParty(false);
      // refresh selected title
      if (selected) titleApi.list(parcelId).then(ts => {
        const fresh = ts.find(t => t.id === selected.id);
        if (fresh) setSelected(fresh);
      });
    },
    onError: (e: any) => setError(e?.response?.data?.message ?? e.message),
  });

  const removePartyMut = useMutation({
    mutationFn: ({ titleId, partyRowId }: { titleId: number; partyRowId: number }) =>
      titleApi.removeParty(titleId, partyRowId),
    onSuccess: () => {
      invalidate();
      if (selected) titleApi.list(parcelId).then(ts => {
        const fresh = ts.find(t => t.id === selected.id);
        if (fresh) setSelected(fresh);
      });
    },
    onError: (e: any) => setError(e?.response?.data?.message ?? e.message),
  });

  if (isLoading) return <div style={{ padding: '2rem', textAlign: 'center', color: 'var(--text-muted)' }}>Loading titles…</div>;

  return (
    <div data-testid="parcel-title-tab">
      {error && (
        <div style={{ marginBottom: '1rem', padding: '0.625rem 0.875rem', background: 'rgba(239,68,68,0.10)',
          border: '1px solid rgba(239,68,68,0.25)', borderRadius: 'var(--radius-md)', fontSize: '0.875rem', color: '#b91c1c' }}>
          {error}
          <button onClick={() => setError(null)} style={{ float: 'right', background: 'none', border: 'none', cursor: 'pointer', color: 'inherit' }}>✕</button>
        </div>
      )}

      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
        <div>
          <h4 style={{ margin: 0, fontWeight: 700 }}>Land Titles</h4>
          <p style={{ margin: '2px 0 0', fontSize: '0.8125rem', color: 'var(--text-muted)' }}>
            Certificates of Title linked to this parcel.
          </p>
        </div>
        {!creating && (
          <button className="btn btn-primary btn-sm" onClick={() => { setCreating(true); setSelected(null); }}>
            + Add Title
          </button>
        )}
      </div>

      {/* Create form */}
      {creating && (
        <TitleForm
          onSave={(data) => { setError(null); createMut.mutate(data); }}
          isSaving={createMut.isPending}
          onCancel={() => setCreating(false)}
        />
      )}

      {/* Existing titles list */}
      {titles.length === 0 && !creating ? (
        <div style={{ padding: '2.5rem', textAlign: 'center', border: '2px dashed var(--border-strong)',
          borderRadius: 'var(--radius-lg)', color: 'var(--text-muted)' }}>
          <div style={{ fontSize: '1.5rem', marginBottom: '0.5rem' }}>📜</div>
          <p style={{ margin: 0 }}>No titles linked yet. Click <strong>Add Title</strong> to register one.</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
          {titles.map(t => (
            <div key={t.id}
              style={{ border: `2px solid ${selected?.id === t.id ? 'var(--brand-primary)' : 'var(--border-color)'}`,
                borderRadius: 'var(--radius-md)', overflow: 'hidden', cursor: 'pointer',
                background: selected?.id === t.id ? 'var(--cerulean-50)' : 'var(--bg-surface)',
                transition: 'border-color 0.15s, background 0.15s' }}
              onClick={() => setSelected(selected?.id === t.id ? null : t)}
            >
              {/* Title row */}
              <div style={{ padding: '0.75rem 1rem', display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: 2 }}>
                    <TitleTypeBadge type={t.title_type} />
                    <span style={{ fontWeight: 700, fontSize: '0.9375rem' }}>{t.title_number}</span>
                    <StatusBadge status={t.status} />
                  </div>
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    {[t.registry_office, t.title_date, t.lot_number ? `Lot ${t.lot_number}` : null,
                      t.area_sqm ? `${Number(t.area_sqm).toLocaleString()} m²` : null]
                      .filter(Boolean).join(' · ')}
                  </div>
                </div>
                <div style={{ display: 'flex', gap: '0.5rem' }}>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    {t.parties.length} {t.parties.length === 1 ? 'owner' : 'owners'}
                  </span>
                  <span style={{ color: 'var(--text-muted)', fontSize: 14 }}>
                    {selected?.id === t.id ? '▲' : '▼'}
                  </span>
                </div>
              </div>

              {/* Expanded detail */}
              {selected?.id === t.id && (
                <div style={{ borderTop: '1px solid var(--border-color)', padding: '1rem' }}
                  onClick={e => e.stopPropagation()}
                >
                  <TitleDetail
                    title={t}
                    onUpdate={(data) => { setError(null); updateMut.mutate({ id: t.id, data }); }}
                    isSaving={updateMut.isPending}
                    onUnlink={() => { if (confirm(`Unlink title "${t.title_number}"?`)) { setError(null); unlinkMut.mutate(t.id); }}}
                    isUnlinking={unlinkMut.isPending}
                    onAddParty={(data) => { setError(null); addPartyMut.mutate({ titleId: t.id, data }); }}
                    isAddingParty={addPartyMut.isPending}
                    addingPartyOpen={addingParty}
                    setAddingPartyOpen={setAddingParty}
                    onRemoveParty={(partyRowId) => { setError(null); removePartyMut.mutate({ titleId: t.id, partyRowId }); }}
                  />
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Title create form ────────────────────────────────────────────────────────
function TitleForm({ onSave, isSaving, onCancel }: { onSave: (d: any) => void; isSaving: boolean; onCancel: () => void }) {
  const [f, setF] = useState({ title_number: '', title_type: 'TCT', title_date: '', registry_office: '', lot_number: '', area_sqm: '', remarks: '' });
  const set = (k: string, v: string) => setF(p => ({ ...p, [k]: v }));
  return (
    <div style={{ border: '1px solid var(--border-color)', borderRadius: 'var(--radius-md)', padding: '1.25rem',
      background: 'var(--bg-surface-alt)', marginBottom: '1rem' }}>
      <h5 style={{ margin: '0 0 1rem', fontWeight: 700 }}>Add New Title</h5>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
        <div className="form-group">
          <label className="form-label">Title Number <span className="form-required">*</span></label>
          <input required className="form-input" value={f.title_number} onChange={e => set('title_number', e.target.value)} placeholder="e.g. TCT-T-12345" />
        </div>
        <div className="form-group">
          <label className="form-label">Title Type</label>
          <select className="form-select" value={f.title_type} onChange={e => set('title_type', e.target.value)}>
            {TITLE_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
          </select>
        </div>
        <div className="form-group">
          <label className="form-label">Title Date</label>
          <input type="date" className="form-input" value={f.title_date} onChange={e => set('title_date', e.target.value)} />
        </div>
        <div className="form-group">
          <label className="form-label">Registry Office</label>
          <input className="form-input" value={f.registry_office} onChange={e => set('registry_office', e.target.value)} placeholder="e.g. Angeles City" />
        </div>
        <div className="form-group">
          <label className="form-label">Lot Number</label>
          <input className="form-input" value={f.lot_number} onChange={e => set('lot_number', e.target.value)} />
        </div>
        <div className="form-group">
          <label className="form-label">Area (m²)</label>
          <input type="number" step="0.0001" className="form-input" value={f.area_sqm} onChange={e => set('area_sqm', e.target.value)} />
        </div>
      </div>
      <div className="form-group">
        <label className="form-label">Remarks</label>
        <textarea className="form-textarea" rows={2} value={f.remarks} onChange={e => set('remarks', e.target.value)} />
      </div>
      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <button className="btn btn-ghost btn-sm" type="button" onClick={onCancel}>Cancel</button>
        <button className="btn btn-primary btn-sm" type="button" disabled={isSaving || !f.title_number.trim()}
          onClick={() => onSave({ ...f, area_sqm: f.area_sqm ? parseFloat(f.area_sqm) : null })}>
          {isSaving ? 'Saving…' : 'Save Title'}
        </button>
      </div>
    </div>
  );
}

// ─── Expanded title detail ────────────────────────────────────────────────────
function TitleDetail({ title, onUpdate, isSaving, onUnlink, isUnlinking, onAddParty, isAddingParty, addingPartyOpen, setAddingPartyOpen, onRemoveParty }: any) {
  const [editing, setEditing] = useState(false);
  const [f, setF]             = useState({ title_type: title.title_type, title_date: title.title_date ?? '', registry_office: title.registry_office ?? '', area_sqm: title.area_sqm ?? '', remarks: title.remarks ?? '' });
  const set = (k: string, v: string) => setF(p => ({ ...p, [k]: v }));

  // Party form state
  const [pf, setPf] = useState({ full_name: '', party_type: 'INDIVIDUAL', role: 'REGISTERED_OWNER', share_numerator: '', share_denominator: '', effective_from: '' });
  const setPSet = (k: string, v: string) => setPf(p => ({ ...p, [k]: v }));

  return (
    <div>
      {/* Meta grid */}
      {!editing ? (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.75rem', marginBottom: '1rem' }}>
            {[
              ['Type', title.title_type],
              ['Date', title.title_date ?? '—'],
              ['Registry', title.registry_office ?? '—'],
              ['Lot No.', title.lot_number ?? '—'],
              ['Area', title.area_sqm ? `${Number(title.area_sqm).toLocaleString()} m²` : '—'],
            ].map(([label, value]) => (
              <div key={label as string}>
                <div style={{ fontSize: '0.6875rem', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)', marginBottom: 2 }}>{label}</div>
                <div style={{ fontWeight: 600, fontSize: '0.875rem' }}>{value}</div>
              </div>
            ))}
          </div>
          {title.remarks && (
            <div style={{ marginBottom: '1rem', fontSize: '0.8125rem', color: 'var(--text-secondary)', background: 'var(--bg-surface-alt)', padding: '0.5rem 0.75rem', borderRadius: 6 }}>
              {title.remarks}
            </div>
          )}
          <div style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem' }}>
            <button className="btn btn-secondary btn-sm" onClick={() => setEditing(true)}>Edit Metadata</button>
            <button className="btn btn-ghost btn-sm" style={{ color: '#b91c1c', borderColor: '#fca5a5' }}
              disabled={isUnlinking} onClick={onUnlink}>
              {isUnlinking ? 'Unlinking…' : 'Unlink Title'}
            </button>
          </div>
        </>
      ) : (
        <div style={{ marginBottom: '1rem' }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
            <div className="form-group"><label className="form-label">Type</label>
              <select className="form-select" value={f.title_type} onChange={e => set('title_type', e.target.value)}>
                {TITLE_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
            <div className="form-group"><label className="form-label">Date</label>
              <input type="date" className="form-input" value={f.title_date} onChange={e => set('title_date', e.target.value)} />
            </div>
            <div className="form-group"><label className="form-label">Registry Office</label>
              <input className="form-input" value={f.registry_office} onChange={e => set('registry_office', e.target.value)} />
            </div>
            <div className="form-group"><label className="form-label">Area (m²)</label>
              <input type="number" step="0.0001" className="form-input" value={f.area_sqm} onChange={e => set('area_sqm', e.target.value)} />
            </div>
          </div>
          <div className="form-group"><label className="form-label">Remarks</label>
            <textarea className="form-textarea" rows={2} value={f.remarks} onChange={e => set('remarks', e.target.value)} />
          </div>
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <button className="btn btn-primary btn-sm" disabled={isSaving}
              onClick={() => onUpdate({ ...f, area_sqm: f.area_sqm ? parseFloat(f.area_sqm as any) : null })}>
              {isSaving ? 'Saving…' : 'Save'}
            </button>
            <button className="btn btn-ghost btn-sm" onClick={() => setEditing(false)}>Cancel</button>
          </div>
        </div>
      )}

      {/* Parties section */}
      <div style={{ borderTop: '1px solid var(--border-color)', paddingTop: '1rem' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.75rem' }}>
          <span style={{ fontWeight: 600, fontSize: '0.875rem' }}>Registered Owners / Parties</span>
          <button className="btn btn-secondary btn-sm" onClick={() => setAddingPartyOpen(!addingPartyOpen)}>
            {addingPartyOpen ? 'Cancel' : '+ Add Party'}
          </button>
        </div>

        {addingPartyOpen && (
          <div style={{ border: '1px solid var(--border-color)', borderRadius: 'var(--radius-sm)', padding: '0.875rem', marginBottom: '0.875rem', background: 'var(--bg-surface-alt)' }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
              <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                <label className="form-label">Full Name <span className="form-required">*</span></label>
                <input className="form-input" value={pf.full_name} onChange={e => setPSet('full_name', e.target.value)} />
              </div>
              <div className="form-group"><label className="form-label">Party Type</label>
                <select className="form-select" value={pf.party_type} onChange={e => setPSet('party_type', e.target.value)}>
                  {['INDIVIDUAL','ORGANIZATION','GOVERNMENT'].map(v => <option key={v} value={v}>{v}</option>)}
                </select>
              </div>
              <div className="form-group"><label className="form-label">Role</label>
                <select className="form-select" value={pf.role} onChange={e => setPSet('role', e.target.value)}>
                  {PARTY_ROLES.map(v => <option key={v} value={v}>{v.replace(/_/g, ' ')}</option>)}
                </select>
              </div>
              <div className="form-group"><label className="form-label">Share (e.g. 1/2)</label>
                <div style={{ display: 'flex', gap: 4, alignItems: 'center' }}>
                  <input type="number" className="form-input" placeholder="num" style={{ width: 64 }} value={pf.share_numerator} onChange={e => setPSet('share_numerator', e.target.value)} />
                  <span>/</span>
                  <input type="number" className="form-input" placeholder="den" style={{ width: 64 }} value={pf.share_denominator} onChange={e => setPSet('share_denominator', e.target.value)} />
                </div>
              </div>
              <div className="form-group"><label className="form-label">Effective From</label>
                <input type="date" className="form-input" value={pf.effective_from} onChange={e => setPSet('effective_from', e.target.value)} />
              </div>
            </div>
            <button className="btn btn-primary btn-sm" disabled={isAddingParty || !pf.full_name.trim()}
              onClick={() => onAddParty({
                full_name: pf.full_name, party_type: pf.party_type, role: pf.role,
                share_numerator: pf.share_numerator ? parseInt(pf.share_numerator) : null,
                share_denominator: pf.share_denominator ? parseInt(pf.share_denominator) : null,
                effective_from: pf.effective_from || null,
              })}>
              {isAddingParty ? 'Adding…' : 'Add Party'}
            </button>
          </div>
        )}

        {title.parties.length === 0 ? (
          <p style={{ fontSize: '0.8125rem', color: 'var(--text-muted)' }}>No parties recorded.</p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            {title.parties.map((p: Party) => (
              <div key={p.id} style={{ display: 'flex', alignItems: 'center', gap: '0.75rem',
                padding: '0.5rem 0.75rem', background: 'var(--bg-surface)', borderRadius: 6,
                border: '1px solid var(--border-color)' }}>
                <div style={{ width: 32, height: 32, borderRadius: '50%', background: 'linear-gradient(135deg,var(--cerulean-200),var(--cerulean-400))',
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontWeight: 700, fontSize: '0.75rem', color: 'var(--cerulean-700)', flexShrink: 0 }}>
                  {(p.full_name || '?')[0].toUpperCase()}
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 600, fontSize: '0.875rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{p.full_name}</div>
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    {p.role.replace(/_/g, ' ')}
                    {p.share_numerator && ` · ${p.share_numerator}/${p.share_denominator}`}
                    {p.effective_from && ` · from ${p.effective_from}`}
                  </div>
                </div>
                <button type="button" title="Remove party"
                  style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444', fontSize: 16, padding: '0 4px' }}
                  onClick={() => { if (confirm(`Remove "${p.full_name}" from this title?`)) onRemoveParty(p.id); }}>
                  ×
                </button>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
