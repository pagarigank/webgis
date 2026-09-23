// Basemap toggle: roads (Esri World Street Map) <-> satellite (Esri World Imagery
// + place-name overlay). Both are additive raster underlays inserted at the very
// bottom of the style; the placeholder vector base and raster layers are toggled
// via visibility so app-owned layers (gl-draw glue, GIS feature layers) always
// stay on top and nobody has to re-add sources/layers after a style swap.
import { useCallback, useEffect, useRef, useState } from 'react';
import * as maplibregl from 'maplibre-gl';

export type BasemapKind = 'roads' | 'satellite';

const STREET_TILES =
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}';
const SATELLITE_TILES =
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
const LABELS_TILES =
    'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}';

const STREET_SOURCE_ID = 'webgis-basemap-streets';
const STREET_LAYER_ID = 'webgis-basemap-streets-layer';
const SAT_SOURCE_ID = 'webgis-basemap-satellite';
const SAT_LAYER_ID = 'webgis-basemap-satellite-layer';
const LABELS_SOURCE_ID = 'webgis-basemap-labels';
const LABELS_LAYER_ID = 'webgis-basemap-labels-layer';

// Layers the app owns and must never hide: mapbox-gl-draw glue layers, GIS
// feature layers (`<id>-layer`), and our own basemap rasters.
function isAppLayer(id: string): boolean {
    return id.startsWith('gl-draw') || id.endsWith('-layer');
}

export function useBasemapToggle(map: maplibregl.Map | null, initial: BasemapKind = 'roads') {
    const [basemap, setBasemap] = useState<BasemapKind>(initial);
    // Original (non-app) style layer ids, captured on load so we can hide them
    // under the raster basemaps and never touch app-owned layers.
    const baseLayerIdsRef = useRef<string[]>([]);
    const appliedRef = useRef(false);

    const ensureLayers = useCallback(() => {
        if (!map || !map.getStyle()) return;
        // Insert at the very bottom so the vector base gets covered while
        // app-owned layers that maplibre stacks later stay above.
        const beforeId = map.getStyle().layers[0]?.id;
        const rasters: [string, string, string][] = [
            [STREET_SOURCE_ID, STREET_TILES, STREET_LAYER_ID],
            [SAT_SOURCE_ID, SATELLITE_TILES, SAT_LAYER_ID],
            [LABELS_SOURCE_ID, LABELS_TILES, LABELS_LAYER_ID],
        ];
        for (const [sourceId, tiles, layerId] of rasters) {
            if (!map.getSource(sourceId)) {
                map.addSource(sourceId, {
                    type: 'raster',
                    tiles: [tiles],
                    tileSize: 256,
                });
            }
            if (!map.getLayer(layerId)) {
                map.addLayer({ id: layerId, type: 'raster', source: sourceId }, beforeId);
            }
        }
    }, [map]);

    const applyBasemap = useCallback(
        (next: BasemapKind) => {
            setBasemap(next);
            if (!map || !map.getStyle()) return;
            ensureLayers();
            const satelliteOn = next === 'satellite';
            const setVis = (id: string, visible: boolean) => {
                if (map.getLayer(id)) {
                    map.setLayoutProperty(id, 'visibility', visible ? 'visible' : 'none');
                }
            };
            setVis(STREET_LAYER_ID, !satelliteOn);
            setVis(SAT_LAYER_ID, satelliteOn);
            setVis(LABELS_LAYER_ID, satelliteOn);
            // Placeholder vector base sits above the rasters; hide it in both
            // modes so the raster basemap is what you see.
            for (const id of baseLayerIdsRef.current) {
                setVis(id, false);
            }
        },
        [map, ensureLayers],
    );

    // Capture base ids once the map+style are ready, then apply the initial
    // mode so the app doesn't boot into the bare placeholder style.
    useEffect(() => {
        if (!map || !map.getStyle() || appliedRef.current) return;
        appliedRef.current = true;
        const style = map.getStyle();
        baseLayerIdsRef.current = style.layers.map((l) => l.id).filter((id) => !isAppLayer(id));
        ensureLayers();
        const mode: BasemapKind = initial;
        const satelliteOn = mode === 'satellite';
        const setVis = (id: string, visible: boolean) => {
            if (map.getLayer(id)) {
                map.setLayoutProperty(id, 'visibility', visible ? 'visible' : 'none');
            }
        };
        setVis(STREET_LAYER_ID, !satelliteOn);
        setVis(SAT_LAYER_ID, satelliteOn);
        setVis(LABELS_LAYER_ID, satelliteOn);
        for (const id of baseLayerIdsRef.current) {
            setVis(id, false);
        }
    }, [map, ensureLayers, initial]);

    return { basemap, setBasemap: applyBasemap };
}