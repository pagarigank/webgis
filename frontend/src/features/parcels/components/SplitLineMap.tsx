import { useEffect, useRef } from 'react';
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useBasemapToggle } from '../../map/basemap';
import { ANGELES_CITY_CENTER, DEFAULT_MAP_ZOOM } from '../../../lib/crs';
import { ringOf } from './SplitTab';
import type { Parcel } from '../types';

/**
 * TASK-116 — split-line drawing map. Renders the parcel outline and the
 * current split line; the first two clicks after "Draw" set the line's start
 * and end. Clicks within ~14 px of a parcel vertex SNAP to it (the TASK-116
 * AC names snapping explicitly) — the snapped coordinate replaces the raw
 * click so the cut aligns exactly with the boundary.
 */
export function SplitLineMap({
    parcel,
    line,
    onChange,
}: {
    parcel: Parcel;
    line: [number, number][];
    onChange: (coords: [number, number][]) => void;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    useBasemapToggle(mapRef.current, 'satellite');

    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;
        const map = new maplibregl.Map({
            container: containerRef.current,
            style: 'https://demotiles.maplibre.org/style.json',
            // Fallback view until the parcel outline is rendered and fitted.
            center: ANGELES_CITY_CENTER,
            zoom: DEFAULT_MAP_ZOOM,
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');

        map.on('load', () => {
            map.addSource('parcel', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({ id: 'parcel-fill', type: 'fill', source: 'parcel', paint: { 'fill-color': '#2563eb', 'fill-opacity': 0.15 } });
            map.addLayer({ id: 'parcel-outline', type: 'line', source: 'parcel', paint: { 'line-color': '#1e40af', 'line-width': 2 } });

            map.addSource('split-line', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({ id: 'split-line-layer', type: 'line', source: 'split-line', paint: { 'line-color': '#dc2626', 'line-width': 3, 'line-dasharray': [2, 1] } });

            map.on('click', (e) => handleClick(e));
        });

        mapRef.current = map;
        return () => {
            mapRef.current?.remove();
            mapRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Parcel outline + fit.
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !map.getSource('parcel')) return;
        const coords = ringOf(parcel.geometry);
        const feature = coords.length > 0 ? [{ type: 'Feature' as const, properties: {}, geometry: parcel.geometry! }] : [];
        (map.getSource('parcel') as maplibregl.GeoJSONSource).setData({
            type: 'FeatureCollection',
            features: feature,
        } as unknown as GeoJSON.FeatureCollection);
        if (coords.length > 0) {
            const bounds = new maplibregl.LngLatBounds();
            for (const c of coords) bounds.extend(c);
            if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 48, maxZoom: 20, duration: 0 });
        }
    }, [parcel]);

    // Split line rendering.
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !map.getSource('split-line')) return;
        if (line.length < 2) return;
        (map.getSource('split-line') as maplibregl.GeoJSONSource).setData({
            type: 'FeatureCollection',
            features: [
                {
                    type: 'Feature',
                    properties: {},
                    geometry: { type: 'LineString', coordinates: line },
                },
            ],
        } as unknown as GeoJSON.FeatureCollection);
    }, [line]);

    /** Snap a click to the nearest parcel vertex within ~14 px. */
    function handleClick(e: maplibregl.MapMouseEvent) {
        const map = mapRef.current;
        if (!map) return;
        const coords = ringOf(parcel.geometry);
        let clicked: [number, number] = [e.lngLat.lng, e.lngLat.lat];
        if (coords.length > 0) {
            const clickPx = map.project(e.lngLat);
            let bestDist = Infinity;
            let snapped: [number, number] | null = null;
            for (const c of coords) {
                const p = map.project({ lng: c[0], lat: c[1] });
                const d = Math.hypot(p.x - clickPx.x, p.y - clickPx.y);
                if (d < bestDist) {
                    bestDist = d;
                    snapped = c;
                }
            }
            if (snapped && bestDist <= 14) clicked = snapped;
        }
        // First click replaces the line, second click extends it to the end.
        onChange(line.length >= 2 ? [clicked, line[1]] : [line[0], clicked]);
    }

    return (
        <div className="position-relative" style={{ minHeight: 300 }}>
            <div ref={containerRef} style={{ height: '100%', minHeight: 300 }} data-testid="split-line-map" />
            <div className="position-absolute top-0 start-0 m-2 bg-white bg-opacity-75 rounded px-2 py-1 small" data-testid="split-map-hint">
                Click twice to set the split line (near-vertex clicks snap).
            </div>
        </div>
    );
}
