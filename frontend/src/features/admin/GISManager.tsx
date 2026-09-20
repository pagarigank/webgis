import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient from '../../lib/apiClient';

interface Layer {
  id: number;
  code: string;
  name: string;
  geometry_type: string;
  description: string | null;
}

export function GISManager() {
  const queryClient = useQueryClient();
  const [selectedLayerId, setSelectedLayerId] = useState<number | null>(null);

  const { data: layers = [], isLoading } = useQuery<Layer[]>({
    queryKey: ['layers'],
    queryFn: async () => {
      const res = await apiClient.get('/layers');
      return res.data;
    }
  });

  const createMutation = useMutation({
    mutationFn: async (payload: Partial<Layer>) => {
      await apiClient.post('/layers', payload);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['layers'] })
  });

  const updateMutation = useMutation({
    mutationFn: async ({ id, ...payload }: Partial<Layer> & { id: number }) => {
      await apiClient.put(`/layers/${id}`, payload);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['layers'] })
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/layers/${id}`);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['layers'] })
  });

  const selectedLayer = layers.find(l => l.id === selectedLayerId);

  const handleCreate = () => {
    const code = prompt('Enter a unique code for the new layer (e.g., PARCELS):');
    if (!code) return;
    const name = prompt('Enter a display name:');
    if (!name) return;
    const geomType = prompt('Geometry type (POINT, LINESTRING, POLYGON):', 'POLYGON');
    if (!geomType) return;
    createMutation.mutate({ code, name, geometry_type: geomType });
  };

  if (isLoading) return <div>Loading layers...</div>;

  return (
    <div className="layout-split">
      <div className="layout-sidebar">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
          <h3 style={{ margin: 0 }}>GIS Layers</h3>
          <button className="btn btn-primary" onClick={handleCreate}>New Layer</button>
        </div>
        <div className="list-group">
          {layers.map(layer => (
            <button
              key={layer.id}
              className={`list-group-item ${selectedLayerId === layer.id ? 'active' : ''}`}
              onClick={() => setSelectedLayerId(layer.id)}
            >
              <div><strong>{layer.name}</strong></div>
              <div className="text-sm opacity-75">{layer.code} • {layer.geometry_type}</div>
            </button>
          ))}
          {layers.length === 0 && <div className="text-muted text-sm">No layers found.</div>}
        </div>
      </div>
      <div className="layout-content">
        {selectedLayer ? (
          <div className="card">
            <h3>Edit Layer: {selectedLayer.name}</h3>
            <form onSubmit={e => {
              e.preventDefault();
              const fd = new FormData(e.currentTarget);
              updateMutation.mutate({
                id: selectedLayer.id,
                name: fd.get('name') as string,
                description: fd.get('description') as string,
                geometry_type: fd.get('geometry_type') as string
              });
            }}>
              <div className="form-group">
                <label>Code (Immutable)</label>
                <input type="text" className="form-control" value={selectedLayer.code} disabled />
              </div>
              <div className="form-group">
                <label>Name</label>
                <input type="text" name="name" className="form-control" defaultValue={selectedLayer.name} required />
              </div>
              <div className="form-group">
                <label>Geometry Type</label>
                <select name="geometry_type" className="form-control" defaultValue={selectedLayer.geometry_type}>
                  <option value="POINT">POINT</option>
                  <option value="LINESTRING">LINESTRING</option>
                  <option value="POLYGON">POLYGON</option>
                </select>
              </div>
              <div className="form-group">
                <label>Description</label>
                <textarea name="description" className="form-control" defaultValue={selectedLayer.description || ''} rows={3} />
              </div>
              <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.5rem' }}>
                <button type="submit" className="btn btn-primary">Save Changes</button>
                <button 
                  type="button" 
                  className="btn btn-danger"
                  onClick={() => {
                    if (confirm('Are you sure you want to delete this layer?')) {
                      deleteMutation.mutate(selectedLayer.id);
                      setSelectedLayerId(null);
                    }
                  }}
                >
                  Delete
                </button>
              </div>
            </form>
          </div>
        ) : (
          <div className="card" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '300px' }}>
            <p className="text-muted">Select a layer to view details.</p>
          </div>
        )}
      </div>
    </div>
  );
}
