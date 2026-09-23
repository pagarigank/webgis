import React, { useState, useEffect, useCallback } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { controlPointApi } from '../api/controlPointApi';
import type { ControlPoint } from '../api/controlPointApi';
import { ControlPointStatusBadge } from '../components/ControlPointStatusBadge';
import { useAuth } from '../../../auth/useAuth';
import { hasPermission } from '../../../auth/permissions';

export const ControlPointListPage: React.FC = () => {
  const { me } = useAuth();
  const navigate = useNavigate();

  const [points, setPoints] = useState<ControlPoint[]>([]);
  const [total, setTotal] = useState<number>(0);
  const [page, setPage] = useState<number>(1);
  const limit = 20;
  const [sort, setSort] = useState<string>('point_name');
  const [dir, setDir] = useState<'ASC' | 'DESC'>('ASC');
  const [q, setQ] = useState<string>('');
  const [status, setStatus] = useState<string>('');
  const [type, setType] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  const canCreate = hasPermission(me, 'control_point.create');

  const loadPoints = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await controlPointApi.list({
        limit,
        offset: (page - 1) * limit,
        sort,
        dir,
        q: q.trim() || undefined,
        status: status || undefined,
        type: type || undefined,
      });
      setPoints(res.data);
      setTotal(res.total);
    } catch (err: any) {
      setError(err?.message || 'Failed to load control points');
    } finally {
      setLoading(false);
    }
  }, [page, limit, sort, dir, q, status, type]);

  useEffect(() => {
    loadPoints();
  }, [loadPoints]);

  const handleSort = (column: string) => {
    if (sort === column) {
      setDir(dir === 'ASC' ? 'DESC' : 'ASC');
    } else {
      setSort(column);
      setDir('ASC');
    }
    setPage(1);
  };

  const totalPages = Math.ceil(total / limit) || 1;

  return (
    <div style={{ padding: '24px', maxWidth: '1400px', margin: '0 auto' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
        <div>
          <h1 style={{ fontSize: '1.75rem', fontWeight: 700, margin: 0, color: '#212529' }}>
            Survey Control Points
          </h1>
          <p style={{ margin: '4px 0 0', color: '#6c757d', fontSize: '0.9rem' }}>
            Geodetic monuments, reference points, and tie points registry
          </p>
        </div>
        {canCreate && (
          <Link
            to="/control-points/new"
            style={{
              padding: '8px 16px',
              backgroundColor: '#007bff',
              color: '#fff',
              textDecoration: 'none',
              borderRadius: '4px',
              fontWeight: 600,
              fontSize: '0.9rem',
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
            }}
          >
            <span>+</span> New Control Point
          </Link>
        )}
      </div>

      {/* Filter Toolbar */}
      <div
        style={{
          display: 'flex',
          flexWrap: 'wrap',
          gap: '12px',
          alignItems: 'center',
          padding: '16px',
          backgroundColor: '#f8f9fa',
          borderRadius: '6px',
          border: '1px solid #dee2e6',
          marginBottom: '20px',
        }}
      >
        <div style={{ flex: '1 1 240px' }}>
          <input
            type="text"
            placeholder="Search point name, reference, description..."
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setPage(1);
            }}
            style={{ width: '100%', padding: '8px 12px', border: '1px solid #ced4da', borderRadius: '4px' }}
          />
        </div>
        <div style={{ width: '180px' }}>
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            style={{ width: '100%', padding: '8px 12px', border: '1px solid #ced4da', borderRadius: '4px' }}
          >
            <option value="">All Statuses</option>
            <option value="UNVERIFIED">⚠️ UNVERIFIED</option>
            <option value="VERIFIED">✓ VERIFIED</option>
            <option value="DISPUTED">⛔ DISPUTED</option>
            <option value="RETIRED">○ RETIRED</option>
          </select>
        </div>
        <div style={{ width: '180px' }}>
          <select
            value={type}
            onChange={(e) => {
              setType(e.target.value);
              setPage(1);
            }}
            style={{ width: '100%', padding: '8px 12px', border: '1px solid #ced4da', borderRadius: '4px' }}
          >
            <option value="">All Monument Types</option>
            <option value="BLLM">BLLM</option>
            <option value="MBM">MBM</option>
            <option value="PBM">PBM</option>
            <option value="GCP">GCP</option>
            <option value="CONTROL_POINT">CONTROL_POINT</option>
            <option value="TIE_POINT">TIE_POINT</option>
            <option value="REFERENCE_POINT">REFERENCE_POINT</option>
            <option value="OTHER">OTHER</option>
          </select>
        </div>
        <div>
          <button
            type="button"
            onClick={() => {
              setQ('');
              setStatus('');
              setType('');
              setPage(1);
            }}
            style={{
              padding: '8px 14px',
              backgroundColor: '#fff',
              border: '1px solid #ced4da',
              borderRadius: '4px',
              cursor: 'pointer',
              color: '#495057',
            }}
          >
            Reset Filters
          </button>
        </div>
      </div>

      {error && (
        <div style={{ padding: '12px', backgroundColor: '#f8d7da', color: '#721c24', borderRadius: '4px', marginBottom: '16px' }}>
          {error}
        </div>
      )}

      {/* Table */}
      <div style={{ border: '1px solid #dee2e6', borderRadius: '6px', overflow: 'hidden', backgroundColor: '#fff' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '0.9rem' }}>
          <thead>
            <tr style={{ backgroundColor: '#f1f3f5', borderBottom: '2px solid #dee2e6' }}>
              <th style={{ padding: '12px', cursor: 'pointer' }} onClick={() => handleSort('point_name')}>
                Point Name {sort === 'point_name' ? (dir === 'ASC' ? '▲' : '▼') : ''}
              </th>
              <th style={{ padding: '12px', cursor: 'pointer' }} onClick={() => handleSort('point_type')}>
                Type {sort === 'point_type' ? (dir === 'ASC' ? '▲' : '▼') : ''}
              </th>
              <th style={{ padding: '12px' }}>Native CRS</th>
              <th style={{ padding: '12px' }}>Coordinates (Projected)</th>
              <th style={{ padding: '12px' }}>Geographic (WGS84)</th>
              <th style={{ padding: '12px', cursor: 'pointer' }} onClick={() => handleSort('status')}>
                Status {sort === 'status' ? (dir === 'ASC' ? '▲' : '▼') : ''}
              </th>
              <th style={{ padding: '12px', textAlign: 'right' }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={7} style={{ padding: '32px', textAlign: 'center', color: '#6c757d' }}>
                  Loading control points…
                </td>
              </tr>
            ) : points.length === 0 ? (
              <tr>
                <td colSpan={7} style={{ padding: '32px', textAlign: 'center', color: '#6c757d' }}>
                  No control points found matching criteria.
                </td>
              </tr>
            ) : (
              points.map((pt) => (
                <tr
                  key={pt.id}
                  style={{
                    borderBottom: '1px solid #dee2e6',
                    backgroundColor: pt.status === 'UNVERIFIED' ? '#fffdf7' : 'inherit',
                  }}
                >
                  <td style={{ padding: '12px', fontWeight: 600 }}>
                    <Link to={`/control-points/${pt.id}`} style={{ color: '#0056b3', textDecoration: 'none' }}>
                      {pt.point_name}
                    </Link>
                    {pt.monument_type && (
                      <div style={{ fontSize: '0.75rem', color: '#6c757d', fontWeight: 'normal' }}>
                        {pt.monument_type}
                      </div>
                    )}
                  </td>
                  <td style={{ padding: '12px' }}>
                    <span style={{ fontSize: '0.8rem', backgroundColor: '#e9ecef', padding: '2px 6px', borderRadius: '3px' }}>
                      {pt.point_type}
                    </span>
                  </td>
                  <td style={{ padding: '12px' }}>
                    <div>{pt.native_crs || '—'}</div>
                    {pt.zone && <div style={{ fontSize: '0.75rem', color: '#6c757d' }}>{pt.zone}</div>}
                  </td>
                  <td style={{ padding: '12px', fontFamily: 'monospace' }}>
                    {pt.easting !== null && pt.northing !== null ? (
                      <div>
                        E: {pt.easting.toFixed(2)}
                        <br />
                        N: {pt.northing.toFixed(2)}
                        {pt.derived?.easting && (
                          <span style={{ fontSize: '0.7rem', color: '#856404', marginLeft: '4px' }}>
                            (derived)
                          </span>
                        )}
                      </div>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td style={{ padding: '12px', fontFamily: 'monospace' }}>
                    {pt.latitude !== null && pt.longitude !== null ? (
                      <div>
                        Lat: {pt.latitude.toFixed(6)}°
                        <br />
                        Lon: {pt.longitude.toFixed(6)}°
                        {pt.derived?.latitude && (
                          <span style={{ fontSize: '0.7rem', color: '#856404', marginLeft: '4px' }}>
                            (derived)
                          </span>
                        )}
                      </div>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td style={{ padding: '12px' }}>
                    <ControlPointStatusBadge status={pt.status} />
                  </td>
                  <td style={{ padding: '12px', textAlign: 'right' }}>
                    <button
                      type="button"
                      onClick={() => navigate(`/control-points/${pt.id}`)}
                      style={{
                        padding: '4px 10px',
                        backgroundColor: '#f8f9fa',
                        border: '1px solid #ced4da',
                        borderRadius: '4px',
                        cursor: 'pointer',
                        fontSize: '0.85rem',
                      }}
                    >
                      View / Edit
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination Bar */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '16px' }}>
        <div style={{ color: '#6c757d', fontSize: '0.85rem' }}>
          Showing {points.length > 0 ? (page - 1) * limit + 1 : 0} to{' '}
          {Math.min(page * limit, total)} of {total} control points
        </div>
        <div style={{ display: 'flex', gap: '6px' }}>
          <button
            type="button"
            disabled={page <= 1}
            onClick={() => setPage((p) => Math.max(p - 1, 1))}
            style={{
              padding: '6px 12px',
              border: '1px solid #ced4da',
              borderRadius: '4px',
              backgroundColor: page <= 1 ? '#e9ecef' : '#fff',
              cursor: page <= 1 ? 'not-allowed' : 'pointer',
            }}
          >
            Previous
          </button>
          <span style={{ padding: '6px 12px', fontSize: '0.9rem', color: '#495057' }}>
            Page {page} of {totalPages}
          </span>
          <button
            type="button"
            disabled={page >= totalPages}
            onClick={() => setPage((p) => Math.min(p + 1, totalPages))}
            style={{
              padding: '6px 12px',
              border: '1px solid #ced4da',
              borderRadius: '4px',
              backgroundColor: page >= totalPages ? '#e9ecef' : '#fff',
              cursor: page >= totalPages ? 'not-allowed' : 'pointer',
            }}
          >
            Next
          </button>
        </div>
      </div>
    </div>
  );
};
