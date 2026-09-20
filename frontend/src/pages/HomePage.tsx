import React from 'react';
import { useMapContext } from '../features/map/MapContext';

export const HomePage: React.FC = () => {
  const { layerManager } = useMapContext();

  return (
    <div style={{ position: 'absolute', top: 20, left: 20, zIndex: 1000, width: 300, pointerEvents: 'auto' }}>
      <p className="text-muted text-sm mb-2">GIS Layers panel — LayerTree coming soon.</p>
      {layerManager && (
        <button
          className="btn btn-ghost"
          onClick={() => {
            const layers = layerManager.getLayers();
            console.log('[HomePage] active layers:', layers);
          }}
        >
          Log active layers to console
        </button>
      )}
    </div>
  );
};
