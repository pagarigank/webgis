import React, { useRef, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient from '../../../lib/apiClient';

// ─── Types ────────────────────────────────────────────────────────────────────
interface DocumentLink {
  id: string;
  original_filename: string;
  doc_type: string | null;
  mime_type: string | null;
  byte_size: number | null;
  access_level: string;
  description: string | null;
  uploaded_at: string;
  link_role: string;
}

const DOC_TYPES = ['TITLE', 'SURVEY_PLAN', 'TAX_DECLARATION', 'DEED', 'COURT_ORDER', 'PHOTO', 'MAP', 'OTHER'];
const ACCESS_LEVELS = ['PUBLIC', 'INTERNAL', 'RESTRICTED', 'SENSITIVE_PERSONAL'];
const LINK_ROLES = ['SUPPORTING', 'PRIMARY', 'REFERENCE', 'ARCHIVE'];

// ─── Helpers ──────────────────────────────────────────────────────────────────
function formatBytes(bytes: number | null): string {
  if (bytes == null) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function mimeIcon(mime: string | null): string {
  if (!mime) return '📄';
  if (mime.startsWith('image/')) return '🖼️';
  if (mime === 'application/pdf') return '📑';
  if (mime.includes('word') || mime.includes('document')) return '📝';
  if (mime.includes('sheet') || mime.includes('excel')) return '📊';
  if (mime.includes('zip') || mime.includes('compressed')) return '🗜️';
  return '📄';
}

const ACCESS_COLORS: Record<string, { bg: string; color: string }> = {
  PUBLIC:            { bg: 'rgba(34,197,94,0.10)',  color: '#15803d' },
  INTERNAL:          { bg: 'rgba(37,99,235,0.10)',  color: '#1d4ed8' },
  RESTRICTED:        { bg: 'rgba(245,158,11,0.10)', color: '#b45309' },
  SENSITIVE_PERSONAL:{ bg: 'rgba(239,68,68,0.10)',  color: '#b91c1c' },
};

function AccessBadge({ level }: { level: string }) {
  const s = ACCESS_COLORS[level] ?? { bg: 'var(--bg-surface-alt)', color: 'var(--text-muted)' };
  return (
    <span style={{ fontSize: '0.6875rem', fontWeight: 600, padding: '2px 8px', borderRadius: 99,
      background: s.bg, color: s.color, letterSpacing: '0.03em' }}>
      {level.replace(/_/g, ' ')}
    </span>
  );
}

// ─── API calls ────────────────────────────────────────────────────────────────
async function listDocuments(parcelId: string): Promise<DocumentLink[]> {
  // Query document_links for this parcel by fetching the entity links
  const rows = await apiClient.get(`/parcels/${parcelId}/documents`) as DocumentLink[];
  return rows;
}

async function uploadDocument(parcelId: string, file: File, meta: { doc_type: string; access_level: string; description: string; link_role: string }): Promise<void> {
  const form = new FormData();
  form.append('file', file);
  form.append('doc_type', meta.doc_type);
  form.append('access_level', meta.access_level);
  form.append('description', meta.description);
  form.append('entity_type', 'parcel');
  form.append('entity_id', parcelId);
  form.append('link_role', meta.link_role);
  await apiClient.post('/documents', form, { headers: { 'Content-Type': 'multipart/form-data' } });
}

async function downloadDocument(docId: string): Promise<void> {
  // Mint token then open download URL
  const result = await apiClient.post(`/documents/${docId}/download-token`) as { download_url: string };
  window.open(`/api/v1${result.download_url.replace('/api/v1', '')}`, '_blank');
}

// ─── Main component ───────────────────────────────────────────────────────────
export function DocumentsTab({ parcelId }: { parcelId: string }) {
  const qc = useQueryClient();
  const fileRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading]     = useState(false);
  const [showUpload, setShowUpload]   = useState(false);
  const [error, setError]             = useState<string | null>(null);
  const [uploadMeta, setUploadMeta]   = useState({
    doc_type: 'SUPPORTING', access_level: 'INTERNAL', description: '', link_role: 'SUPPORTING',
  });

  const { data: docs = [], isLoading } = useQuery<DocumentLink[]>({
    queryKey: ['parcel-documents', parcelId],
    queryFn: () => listDocuments(parcelId),
    retry: false,
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['parcel-documents', parcelId] });

  const downloadMut = useMutation({
    mutationFn: (docId: string) => downloadDocument(docId),
    onError: (e: any) => setError(e?.response?.data?.message ?? e.message),
  });

  const handleUpload = async () => {
    const file = fileRef.current?.files?.[0];
    if (!file) return;
    setUploading(true); setError(null);
    try {
      await uploadDocument(parcelId, file, uploadMeta);
      invalidate();
      setShowUpload(false);
      if (fileRef.current) fileRef.current.value = '';
    } catch (e: any) {
      setError(e?.response?.data?.message ?? e.message ?? 'Upload failed.');
    } finally {
      setUploading(false);
    }
  };

  if (isLoading) return <div style={{ padding: '2rem', textAlign: 'center', color: 'var(--text-muted)' }}>Loading documents…</div>;

  return (
    <div data-testid="parcel-documents-tab">
      {error && (
        <div style={{ marginBottom: '1rem', padding: '0.625rem 0.875rem',
          background: 'rgba(239,68,68,0.10)', border: '1px solid rgba(239,68,68,0.25)',
          borderRadius: 'var(--radius-md)', fontSize: '0.875rem', color: '#b91c1c' }}>
          {error}
          <button onClick={() => setError(null)} style={{ float: 'right', background: 'none', border: 'none', cursor: 'pointer', color: 'inherit' }}>✕</button>
        </div>
      )}

      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
        <div>
          <h4 style={{ margin: 0, fontWeight: 700 }}>Documents</h4>
          <p style={{ margin: '2px 0 0', fontSize: '0.8125rem', color: 'var(--text-muted)' }}>
            Supporting documents, survey plans, deeds, and other attachments.
          </p>
        </div>
        {!showUpload && (
          <button className="btn btn-primary btn-sm" onClick={() => setShowUpload(true)}>
            ↑ Upload Document
          </button>
        )}
      </div>

      {/* Upload panel */}
      {showUpload && (
        <div style={{ border: '1px solid var(--border-color)', borderRadius: 'var(--radius-md)',
          padding: '1.25rem', background: 'var(--bg-surface-alt)', marginBottom: '1.25rem' }}>
          <h5 style={{ margin: '0 0 1rem', fontWeight: 700 }}>Upload New Document</h5>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
            <div className="form-group" style={{ gridColumn: '1 / -1' }}>
              <label className="form-label">File <span className="form-required">*</span></label>
              <input ref={fileRef} type="file" className="form-input" style={{ padding: '0.4rem 0.75rem' }}
                accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.doc,.docx,.xls,.xlsx,.zip" />
            </div>
            <div className="form-group">
              <label className="form-label">Document Type</label>
              <select className="form-select" value={uploadMeta.doc_type}
                onChange={e => setUploadMeta(p => ({ ...p, doc_type: e.target.value }))}>
                {DOC_TYPES.map(t => <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label className="form-label">Access Level</label>
              <select className="form-select" value={uploadMeta.access_level}
                onChange={e => setUploadMeta(p => ({ ...p, access_level: e.target.value }))}>
                {ACCESS_LEVELS.map(l => <option key={l} value={l}>{l.replace(/_/g, ' ')}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label className="form-label">Link Role</label>
              <select className="form-select" value={uploadMeta.link_role}
                onChange={e => setUploadMeta(p => ({ ...p, link_role: e.target.value }))}>
                {LINK_ROLES.map(r => <option key={r} value={r}>{r}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label className="form-label">Description</label>
              <input className="form-input" value={uploadMeta.description}
                onChange={e => setUploadMeta(p => ({ ...p, description: e.target.value }))}
                placeholder="Brief description…" />
            </div>
          </div>
          <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end', marginTop: '0.5rem' }}>
            <button className="btn btn-ghost btn-sm" onClick={() => setShowUpload(false)}>Cancel</button>
            <button className="btn btn-primary btn-sm" disabled={uploading} onClick={handleUpload}>
              {uploading ? 'Uploading…' : 'Upload'}
            </button>
          </div>
        </div>
      )}

      {/* Document list */}
      {docs.length === 0 ? (
        <div style={{ padding: '2.5rem', textAlign: 'center', border: '2px dashed var(--border-strong)',
          borderRadius: 'var(--radius-lg)', color: 'var(--text-muted)' }}>
          <div style={{ fontSize: '2rem', marginBottom: '0.5rem' }}>📂</div>
          <p style={{ margin: 0 }}>No documents attached yet. Click <strong>Upload Document</strong> to add one.</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          {docs.map(doc => (
            <div key={doc.id} style={{ display: 'flex', alignItems: 'center', gap: '0.875rem',
              padding: '0.75rem 1rem', border: '1px solid var(--border-color)',
              borderRadius: 'var(--radius-md)', background: 'var(--bg-surface)',
              transition: 'background 0.12s' }}
              onMouseEnter={e => (e.currentTarget.style.background = 'var(--bg-surface-alt)')}
              onMouseLeave={e => (e.currentTarget.style.background = 'var(--bg-surface)')}>

              {/* Icon */}
              <span style={{ fontSize: '1.5rem', flexShrink: 0 }}>{mimeIcon(doc.mime_type)}</span>

              {/* Info */}
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontWeight: 600, fontSize: '0.875rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {doc.original_filename}
                </div>
                <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', flexWrap: 'wrap', marginTop: 2 }}>
                  {doc.doc_type && (
                    <span style={{ fontSize: '0.6875rem', fontWeight: 600, padding: '1px 7px', borderRadius: 99,
                      background: 'var(--bg-surface-alt)', border: '1px solid var(--border-color)', color: 'var(--text-secondary)' }}>
                      {doc.doc_type?.replace(/_/g, ' ')}
                    </span>
                  )}
                  <AccessBadge level={doc.access_level} />
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>{formatBytes(doc.byte_size)}</span>
                  {doc.description && (
                    <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 200 }}>
                      — {doc.description}
                    </span>
                  )}
                </div>
              </div>

              {/* Date */}
              <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', flexShrink: 0, textAlign: 'right' }}>
                {new Date(doc.uploaded_at).toLocaleDateString()}
              </div>

              {/* Download */}
              <button className="btn btn-secondary btn-sm" style={{ flexShrink: 0 }}
                disabled={downloadMut.isPending}
                onClick={() => downloadMut.mutate(doc.id)}>
                ↓ Download
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
