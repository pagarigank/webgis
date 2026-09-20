import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import apiClient from '../../lib/apiClient';

export function AuditLogView() {
  const [filters, setFilters] = useState({ entity_type: '', action: '' });

  const { data, isLoading, error } = useQuery({
    queryKey: ['audit_logs', filters],
    queryFn: () => apiClient.get('/audit-logs', { params: filters }).then(res => res.data.data)
  });

  return (
    <div className="card">
      <h1 className="mb-4">Audit Logs</h1>
      <div className="flex gap-4 mb-4" style={{ marginBottom: '1.5rem' }}>
        <input 
          type="text" 
          placeholder="Entity Type" 
          value={filters.entity_type} 
          onChange={e => setFilters({...filters, entity_type: e.target.value})}
          className="form-input"
          style={{ width: '250px' }}
        />
        <input 
          type="text" 
          placeholder="Action (e.g. CREATE)" 
          value={filters.action} 
          onChange={e => setFilters({...filters, action: e.target.value})}
          className="form-input"
          style={{ width: '250px' }}
        />
      </div>
      
      {isLoading ? <div>Loading audit logs...</div> : error ? <div className="text-danger">Error loading audit logs.</div> : (
        <div className="table-wrapper">
          <table className="data-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>User ID</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Fields Changed</th>
                <th>IP</th>
              </tr>
            </thead>
            <tbody>
              {data?.map((log: any) => (
                <AuditLogRow key={log.id} log={log} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function AuditLogRow({ log }: { log: any }) {
  const [expanded, setExpanded] = useState(false);

  return (
    <>
      <tr style={{ cursor: 'pointer' }} onClick={() => setExpanded(!expanded)}>
        <td>{new Date(log.occurred_at).toLocaleString()}</td>
        <td>{log.username_snapshot} ({log.user_id})</td>
        <td className="font-mono">{log.action}</td>
        <td>{log.entity_type} #{log.entity_id}</td>
        <td>{log.changed_fields?.join(', ')}</td>
        <td>{log.ip}</td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={6} style={{ background: 'var(--bg-page)', padding: '1.5rem' }}>
            <h4 style={{ marginBottom: '1rem' }}>Detailed Payload Changes</h4>
            <div className="grid-2-cols font-mono text-sm">
              <div style={{ background: 'rgba(239, 68, 68, 0.05)', padding: '1rem', border: '1px solid rgba(239, 68, 68, 0.2)', borderRadius: '8px' }}>
                <strong style={{ color: 'var(--brand-danger)' }}>Old Values:</strong>
                <pre style={{ margin: '0.5rem 0 0 0', overflowX: 'auto' }}>{JSON.stringify(log.old_values || {}, null, 2)}</pre>
              </div>
              <div style={{ background: 'rgba(34, 197, 94, 0.05)', padding: '1rem', border: '1px solid rgba(34, 197, 94, 0.2)', borderRadius: '8px' }}>
                <strong style={{ color: 'var(--brand-success)' }}>New Values:</strong>
                <pre style={{ margin: '0.5rem 0 0 0', overflowX: 'auto' }}>{JSON.stringify(log.new_values || {}, null, 2)}</pre>
              </div>
            </div>
          </td>
        </tr>
      )}
    </>
  );
}
