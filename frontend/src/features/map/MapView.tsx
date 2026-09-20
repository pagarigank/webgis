import React, { useRef, useEffect, useState } from 'react';
import * as maplibregl from 'maplibre-gl';
import * as MapboxDraw from '@mapbox/mapbox-gl-draw';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@mapbox/mapbox-gl-draw/dist/mapbox-gl-draw.css';

export function MapView() {
  const mapContainer = useRef<HTMLDivElement>(null);
  const map = useRef<maplibregl.Map | null>(null);
  const draw = useRef<MapboxDraw | null>(null);
  const [drawnFeatures, setDrawnFeatures] = useState<any>(null);

  useEffect(() => {
    if (!mapContainer.current) return;
    if (map.current) return; // initialize map only once

    map.current = new maplibregl.Map({
      container: mapContainer.current,
      style: 'https://demotiles.maplibre.org/style.json', // Placeholder style
      center: [121.0, 14.5], // Philippines roughly
      zoom: 5
    });

    const DrawConstructor = (MapboxDraw as any).default || MapboxDraw;
    draw.current = new DrawConstructor({
      displayControlsDefault: false,
      controls: {
        polygon: true,
        trash: true
      }
    });

    map.current.addControl(draw.current as any);

    const updateGeometry = () => {
      if (draw.current) {
        const data = draw.current.getAll();
        setDrawnFeatures(data);
      }
    };

    map.current.on('draw.create', updateGeometry);
    map.current.on('draw.delete', updateGeometry);
    map.current.on('draw.update', updateGeometry);

    return () => {
      if (map.current) {
        map.current.remove();
        map.current = null;
      }
    };
  }, []);

  return (
    <div style={{ position: 'relative', width: '100%', height: '100%' }}>
      <div ref={mapContainer} style={{ width: '100%', height: '100%', minHeight: '600px' }} />
      <div 
        className="card"
        style={{ 
          position: 'absolute', 
          top: '1rem', 
          right: '1rem', 
          width: '300px', 
          maxHeight: '80%', 
          overflowY: 'auto',
          zIndex: 10 
        }}
      >
        <h3 style={{ marginTop: 0 }}>Map Controls</h3>
        <p className="text-muted text-sm">Draw a polygon using the tools on the right.</p>
        {drawnFeatures && drawnFeatures.features.length > 0 && (
          <div style={{ marginTop: '1rem' }}>
            <strong>Drawn Polygon Data:</strong>
            <pre style={{ fontSize: '0.75rem', background: 'var(--surface-color)', padding: '0.5rem', borderRadius: '4px', overflowX: 'auto' }}>
              {JSON.stringify(drawnFeatures, null, 2)}
            </pre>
          </div>
        )}
      </div>
    </div>
  );
}
