import { useEffect, useRef, useState } from 'react';
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useBasemapToggle } from '../../map/basemap';
import type { Parcel } from '../types';

/**
 * Persistent right-hand map preview for the parcel editor (frontend.md §7).
 * Shows the current parcel geometry; when it exists the map fits to its bounds.
 * Rendered over the satellite basemap so the parcel overlays imagery.
 */
export function ParcelPreviewMap({ parcel }: { parcel: Parcel }) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const [mapReady, setMapReady] = useState(false);
    useBasemapToggle(mapRef.current, 'satellite');

    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;
        const map = new maplibregl.Map({
            container: containerRef.current,
            style: 'https://demotiles.maplibre.org/style.json',
            center: [121, 14.5],
            zoom: 5,
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        map.on('load', () => {
            map.addSource('parcel', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({ id: 'parcel-fill-layer', type: 'fill', source: 'parcel', paint: { 'fill-color': '#2563eb', 'fill-opacity': 0.35 } });
            map.addLayer({ id: 'parcel-outline-layer', type: 'line', source: 'parcel', paint: { 'line-color': '#1e40af', 'line-width': 2 } });
            setMapReady(true);
        });
        mapRef.current = map;
        return () => {
            mapRef.current?.remove();
            mapRef.current = null;
        };
    }, []);

    useEffect(() => {
        const map = mapRef.current;
        if (!map) return;
        if (!map.getSource('parcel')) return;

        const features = parcel.geometry
            ? [{ type: 'Feature', id: parcel.id, properties: { parcel_code: parcel.parcel_code }, geometry: parcel.geometry }]
            : [];
        (map.getSource('parcel') as maplibregl.GeoJSONSource).setData({
            type: 'FeatureCollection',
            features,
        } as GeoJSON.FeatureCollection);

        if (parcel.geometry) {
            // Fit to the parcel when it exists.
            const bounds = new maplibregl.LngLatBounds();
            const coords = parcel.geometry.type === 'Polygon'
                ? parcel.geometry.coordinates[0]
                : parcel.geometry.type === 'MultiPolygon'
                    ? parcel.geometry.coordinates[0][0]
                    : parcel.geometry.type === 'LineString'
                        ? parcel.geometry.coordinates
                        : null;
            if (coords) {
                for (const coord of coords) {
                    bounds.extend(coord as [number, number]);
                }
                if (!bounds.isEmpty()) {
                    map.fitBounds(bounds, { padding: 32, maxZoom: 20 });
                }
            }
        }
    }, [parcel]);

    return <div ref={containerRef} style={{ height: '100%', minHeight: 420 }} data-testid="parcel-editor-map" data-map-ready={mapReady ? 'true' : 'false'} />;
}