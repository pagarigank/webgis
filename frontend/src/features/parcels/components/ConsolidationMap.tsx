import { useEffect, useRef } from 'react';
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useBasemapToggle } from '../../map/basemap';
import { ANGELES_CITY_CENTER, DEFAULT_MAP_ZOOM } from '../../../lib/crs';

/**
 * TASK-117 — consolidation preview map. Renders every selected parent; the
 * parents named by blocking failures (VR-41 overlap / VR-42 gap / VR-43
 * multipart) are highlighted red so the offending parcels light up on the map
 * instead of the UI showing a generic error (the TASK-117 AC).
 */
export function ConsolidationMap({
    parcels,
    offendingIndexes,
}: {
    parcels: { id: string; parcel_code: string; status: string; geometry: GeoJSON.GeometryObject | null }[];
    offendingIndexes: number[];
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    useBasemapToggle(mapRef.current, 'satellite');

    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;
        const map = new maplibregl.Map({
            container: containerRef.current,
            style: 'https://demotiles.maplibre.org/style.json',
            // Fallback view until the parent parcels are rendered and fitted.
            center: ANGELES_CITY_CENTER,
            zoom: DEFAULT_MAP_ZOOM,
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        map.on('load', () => {
            map.addSource('parents', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({
                id: 'parents-fill',
                type: 'fill',
                source: 'parents',
                paint: {
                    'fill-color': ['case', ['boolean', ['get', 'offending'], false], '#dc2626', '#2563eb'],
                    'fill-opacity': 0.3,
                },
            });
            map.addLayer({
                id: 'parents-outline',
                type: 'line',
                source: 'parents',
                paint: {
                    'line-color': ['case', ['boolean', ['get', 'offending'], false], '#991b1b', '#1e40af'],
                    'line-width': 2,
                },
            });
        });
        mapRef.current = map;
        return () => {
            mapRef.current?.remove();
            mapRef.current = null;
        };
    }, []);

    useEffect(() => {
        const map = mapRef.current;
        if (!map || !map.getSource('parents')) return;
        const features = parcels
            .filter((p) => p.geometry != null)
            .map((p, i) => ({
                type: 'Feature' as const,
                properties: { parcel_code: p.parcel_code, offending: offendingIndexes.includes(i) },
                geometry: p.geometry!,
            }));
        (map.getSource('parents') as maplibregl.GeoJSONSource).setData({
            type: 'FeatureCollection',
            features,
        } as unknown as GeoJSON.FeatureCollection);

        const bounds = new maplibregl.LngLatBounds();
        const fitTo: typeof features = offendingIndexes.length > 0 ? features.filter((f) => f.properties.offending) : features;
        // Zoom-to-problem: when blocking failures name parents, the map frames
        // THOSE parcels instead of the whole selection (TASK-117 AC).
        for (const f of fitTo) {
            const g = f.geometry;
            const rings = g.type === 'Polygon' ? [g.coordinates[0]] : g.type === 'MultiPolygon' ? g.coordinates.map((poly) => poly[0]) : [];
            for (const ring of rings) for (const c of ring) bounds.extend(c as [number, number]);
        }
        if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 48, maxZoom: 18, duration: 0 });
    }, [parcels, offendingIndexes]);

    return <div ref={containerRef} style={{ height: '100%', minHeight: 320 }} data-testid="consolidation-map" />;
}
