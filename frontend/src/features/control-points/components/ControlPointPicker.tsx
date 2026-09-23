import React, { useState } from 'react';
import { controlPointApi } from '../api/controlPointApi';
import type { ControlPoint } from '../api/controlPointApi';
import { ControlPointStatusBadge } from './ControlPointStatusBadge';

export interface ControlPointPickerProps {
  onSelect: (point: ControlPoint) => void;
  initialLat?: number;
  initialLon?: number;
  selectedPointId?: number | null;
}

export const ControlPointPicker: React.FC<ControlPointPickerProps> = ({
  onSelect,
  initialLat,
  initialLon,
  selectedPointId,
}) => {
  const [mode, setMode] = useState<'nearest' | 'search'>('nearest');
  const [lat, setLat] = useState<string>(initialLat !== undefined ? initialLat.toFixed(6) : '14.599512');
  const [lon, setLon] = useState<string>(initialLon !== undefined ? initialLon.toFixed(6) : '120.984222');
  const [type, setType] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [points, setPoints] = useState<ControlPoint[]>([]);
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const handleQueryNearest = async () => {
    const latNum = parseFloat(lat);
    const lonNum = parseFloat(lon);
    if (isNaN(latNum) || isNaN(lonNum)) {
      setError('Please provide valid latitude and longitude numbers.');
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const res = await controlPointApi.getNearest({
        lat: latNum,
        lon: lonNum,
        limit: 10,
        type: type || undefined,
      });
      setPoints(res.data);
    } catch (err: any) {
      setError(err?.message || 'Failed to fetch nearest control points');
    } finally {
      setLoading(false);
    }
  };

  const handleSearch = async () => {
    if (!searchQuery.trim()) {
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const res = await controlPointApi.list({
        q: searchQuery.trim(),
        limit: 15,
        type: type || undefined,
      });
      setPoints(res.data);
    } catch (err: any) {
      setError(err?.message || 'Failed to search control points');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div
      data-testid="control-point-picker"
      style={{
        padding: '16px',
        backgroundColor: '#fff',
        borderRadius: '8px',
        border: '1px solid #dee2e6',
        maxWidth: '520px',
      }}
    >
      <div style={{ display: 'flex', gap: '8px', marginBottom: '12px', borderBottom: '1px solid #eee', paddingBottom: '8px' }}>
        <button
          type="button"
          onClick={() => setMode('nearest')}
          style={{
            padding: '6px 12px',
            backgroundColor: mode === 'nearest' ? '#007bff' : '#f8f9fa',
            color: mode === 'nearest' ? '#fff' : '#495057',
            border: '1px solid #ced4da',
            borderRadius: '4px',
            cursor: 'pointer',
            fontWeight: 500,
          }}
        >
          📍 Nearest to Location
        </button>
        <button
          type="button"
          onClick={() => setMode('search')}
          style={{
            padding: '6px 12px',
            backgroundColor: mode === 'search' ? '#007bff' : '#f8f9fa',
            color: mode === 'search' ? '#fff' : '#495057',
            border: '1px solid #ced4da',
            borderRadius: '4px',
            cursor: 'pointer',
            fontWeight: 500,
          }}
        >
          🔍 Search by Name
        </button>
      </div>

      {mode === 'nearest' ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '12px' }}>
          <div style={{ display: 'flex', gap: '8px' }}>
            <div style={{ flex: 1 }}>
              <label style={{ fontSize: '0.8rem', fontWeight: 600 }}>Latitude</label>
              <input
                type="text"
                value={lat}
                onChange={(e) => setLat(e.target.value)}
                style={{ width: '100%', padding: '6px', border: '1px solid #ccc', borderRadius: '4px' }}
                placeholder="14.599512"
              />
            </div>
            <div style={{ flex: 1 }}>
              <label style={{ fontSize: '0.8rem', fontWeight: 600 }}>Longitude</label>
              <input
                type="text"
                value={lon}
                onChange={(e) => setLon(e.target.value)}
                style={{ width: '100%', padding: '6px', border: '1px solid #ccc', borderRadius: '4px' }}
                placeholder="120.984222"
              />
            </div>
          </div>
          <div style={{ display: 'flex', gap: '8px' }}>
            <div style={{ flex: 1 }}>
              <label style={{ fontSize: '0.8rem', fontWeight: 600 }}>Filter Point Type</label>
              <select
                value={type}
                onChange={(e) => setType(e.target.value)}
                style={{ width: '100%', padding: '6px', border: '1px solid #ccc', borderRadius: '4px' }}
              >
                <option value="">All Types</option>
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
            <div style={{ alignSelf: 'flex-end' }}>
              <button
                type="button"
                onClick={handleQueryNearest}
                disabled={loading}
                style={{
                  padding: '6px 16px',
                  backgroundColor: '#28a745',
                  color: '#fff',
                  border: 'none',
                  borderRadius: '4px',
                  cursor: 'pointer',
                  fontWeight: 600,
                }}
              >
                {loading ? 'Finding…' : 'Find Nearest'}
              </button>
            </div>
          </div>
        </div>
      ) : (
        <div style={{ display: 'flex', gap: '8px', marginBottom: '12px' }}>
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
            placeholder="Search point name or description (e.g. BLLM-1)"
            style={{ flex: 1, padding: '6px', border: '1px solid #ccc', borderRadius: '4px' }}
          />
          <button
            type="button"
            onClick={handleSearch}
            disabled={loading}
            style={{
              padding: '6px 16px',
              backgroundColor: '#007bff',
              color: '#fff',
              border: 'none',
              borderRadius: '4px',
              cursor: 'pointer',
              fontWeight: 600,
            }}
          >
            {loading ? 'Searching…' : 'Search'}
          </button>
        </div>
      )}

      {error && (
        <div style={{ color: '#dc3545', fontSize: '0.85rem', marginBottom: '8px' }}>
          {error}
        </div>
      )}

      <div style={{ maxHeight: '280px', overflowY: 'auto', border: '1px solid #eee', borderRadius: '4px' }}>
        {points.length === 0 ? (
          <div style={{ padding: '16px', textAlign: 'center', color: '#6c757d', fontSize: '0.9rem' }}>
            {loading ? 'Loading control points…' : 'No control points found.'}
          </div>
        ) : (
          points.map((pt) => {
            const isSelected = selectedPointId === pt.id;
            return (
              <div
                key={pt.id}
                onClick={() => onSelect(pt)}
                style={{
                  padding: '10px 12px',
                  borderBottom: '1px solid #eee',
                  cursor: 'pointer',
                  backgroundColor: isSelected ? '#e8f4fd' : '#fff',
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                }}
              >
                <div>
                  <div style={{ fontWeight: 600, fontSize: '0.95rem', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <span>{pt.point_name}</span>
                    <span style={{ fontSize: '0.75rem', color: '#6c757d', backgroundColor: '#f0f0f0', padding: '1px 6px', borderRadius: '3px' }}>
                      {pt.point_type}
                    </span>
                    <ControlPointStatusBadge status={pt.status} size="sm" />
                  </div>
                  <div style={{ fontSize: '0.8rem', color: '#555', marginTop: '2px' }}>
                    {pt.native_crs ? `${pt.native_crs}: ` : ''}
                    {pt.easting !== null && pt.northing !== null
                      ? `E: ${pt.easting.toFixed(2)}, N: ${pt.northing.toFixed(2)}`
                      : pt.latitude !== null && pt.longitude !== null
                      ? `Lat: ${pt.latitude.toFixed(6)}, Lon: ${pt.longitude.toFixed(6)}`
                      : 'No coordinates'}
                  </div>
                </div>
                {pt.distance_m !== undefined && pt.distance_m !== null && (
                  <div style={{ textAlign: 'right', minWidth: '80px' }}>
                    <span style={{ fontSize: '0.85rem', fontWeight: 600, color: '#0056b3' }}>
                      {pt.distance_m < 1000
                        ? `${pt.distance_m.toFixed(1)} m`
                        : `${(pt.distance_m / 1000).toFixed(2)} km`}
                    </span>
                  </div>
                )}
              </div>
            );
          })
        )}
      </div>
    </div>
  );
};
