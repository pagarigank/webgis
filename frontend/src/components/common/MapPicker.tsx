import { useEffect, useRef, useState } from 'react';
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import MapboxDraw from '@mapbox/mapbox-gl-draw';
import '@mapbox/mapbox-gl-draw/dist/mapbox-gl-draw.css';
import { ANGELES_CITY_CENTER, DEFAULT_MAP_ZOOM } from '../../lib/crs';

export interface MapPickerProps {
  initialGeoJson?: any;
  onChange?: (geoJson: any) => void;
  height?: string;
}

export function MapPicker({ initialGeoJson, onChange, height = '400px' }: MapPickerProps) {
  const mapContainer = useRef<HTMLDivElement>(null);
  const map = useRef<maplibregl.Map | null>(null);
  const draw = useRef<MapboxDraw | null>(null);
  const [isReady, setIsReady] = useState(false);

  const loadedGeoJsonRef = useRef<string | null>(null);

  useEffect(() => {
    if (!mapContainer.current) return;
    if (map.current) return; // initialize map only once

    map.current = new maplibregl.Map({
      container: mapContainer.current,
      style: 'https://demotiles.maplibre.org/style.json', // Placeholder style
      center: ANGELES_CITY_CENTER, // Home view: Angeles City
      zoom: DEFAULT_MAP_ZOOM
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

    map.current.on('load', () => {
      setIsReady(true);
      if (initialGeoJson && draw.current) {
        draw.current.add(initialGeoJson);
        loadedGeoJsonRef.current = JSON.stringify(initialGeoJson);
      }
    });

    const updateGeometry = () => {
      if (draw.current && onChangeRef.current) {
        const data = draw.current.getAll();
        const stringified = JSON.stringify(data);
        if (loadedGeoJsonRef.current !== stringified) {
          loadedGeoJsonRef.current = stringified;
          onChangeRef.current(data);
        }
      }
    };

    map.current.on('draw.create' as any, updateGeometry);
    map.current.on('draw.delete' as any, updateGeometry);
    map.current.on('draw.update' as any, updateGeometry);

    return () => {
      if (map.current) {
        map.current.remove();
        map.current = null;
      }
    };
  }, []); // onChange is excluded to prevent re-binding, so we must be careful. But better to use a ref for onChange.

  // Use a ref for onChange to avoid stale closures
  const onChangeRef = useRef(onChange);
  useEffect(() => {
    onChangeRef.current = onChange;
  }, [onChange]);

  // When initialGeoJson changes from outside, update draw
  useEffect(() => {
    if (isReady && draw.current && initialGeoJson) {
      const stringified = JSON.stringify(initialGeoJson);
      if (loadedGeoJsonRef.current !== stringified) {
        draw.current.deleteAll();
        draw.current.add(initialGeoJson);
        loadedGeoJsonRef.current = stringified;
      }
    }
  }, [initialGeoJson, isReady]);

  return <div ref={mapContainer} style={{ width: '100%', height }} className="border rounded" />;
}
