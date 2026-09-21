// @ts-nocheck
import * as maplibregl from 'maplibre-gl';
import MapboxDraw from '@mapbox/mapbox-gl-draw';
import '@mapbox/mapbox-gl-draw/dist/mapbox-gl-draw.css';
import apiClient from '../../lib/apiClient';

export interface ActiveLayer {
    id: string;
    name: string;
    visible: boolean;
    opacity: number;
    extent?: [number, number, number, number];
    style?: any;
}

export interface GeoJsonLayerOptions {
    id: string;
    name: string;
    geojson: GeoJSON.FeatureCollection | GeoJSON.Feature | null;
    style?: any;
    extent?: [number, number, number, number];
    onUpdate?: (geojson: GeoJSON.FeatureCollection) => void;
}

/**
 * LayerManager handles GeoJSON and MVT layers on the MapLibre map.
 * Supports dynamic source switching between GeoJSON and MVT tiles per layer.
 */
export class LayerManager {
    private map: maplibregl.Map;
    private layers: ActiveLayer[] = [];
    private drawInstance: MapboxDraw | null = null;
        private listeners: ((layers: ActiveLayer[]) => void)[] = [];

    constructor(map: maplibregl.Map) {
        this.map = map;
    }

    // ── layer CRUD ────────────────────────────────────────────────────────

    subscribe(listener: (layers: ActiveLayer[]) => void) {
        this.listeners.push(listener);
        return () => {
            this.listeners = this.listeners.filter(l => l !== listener);
        };
    }

    private notify() {
        this.listeners.forEach(l => l([...this.layers]));
    }

    getLayers() {
        return this.layers;
    }

    addGeoJsonLayer(options: GeoJsonLayerOptions) {
        const { id, name, geojson, style, extent } = options;
        const sourceId = `source-${id}`;
        const layerId = `${id}-layer`;

        if (this.map.getSource(sourceId)) {
            // Update existing source data
            (this.map.getSource(sourceId) as maplibregl.GeoJSONSource).setData(geojson || { type: 'FeatureCollection', features: [] });
        } else {
            // Add source + layer
            this.map.addSource(sourceId, {
                type: 'geojson',
                data: geojson || { type: 'FeatureCollection', features: [] },
            });

            this.map.addLayer({
                id: layerId,
                type: style?.type || 'fill',
                source: sourceId,
                paint: style?.paint || {},
                layout: style?.layout || {},
            });

            // Attach extent to layer metadata for zoom-to
            this.layers.unshift({ id, name, visible: true, opacity: 1, extent, style });
            this.notify();
        }

        // Store onUpdate callback in map metadata
        if (options.onUpdate) {
            (this.map.getSource(sourceId) as any).onUpdate = options.onUpdate;
        }
    }

    removeLayer(id: string) {
        const sourceId = `source-${id}`;
        const layerId = `${id}-layer`;
        if (this.map.getLayer(layerId)) {
            this.map.removeLayer(layerId);
        }
        if (this.map.getSource(sourceId)) {
            this.map.removeSource(sourceId);
        }
        this.layers = this.layers.filter(l => l.id !== id);
        this.notify();
    }

    setVisibility(id: string, visible: boolean) {
        const layerId = `${id}-layer`;
        if (this.map.getLayer(layerId)) {
            this.map.setLayoutProperty(layerId, 'visibility', visible ? 'visible' : 'none');
            const layer = this.layers.find(l => l.id === id);
            if (layer) {
                layer.visible = visible;
                this.notify();
            }
        }
    }

    setOpacity(id: string, opacity: number) {
        const layerId = `${id}-layer`;
        const layer = this.layers.find(l => l.id === id);
        if (layer && this.map.getLayer(layerId)) {
            const type = layer.style?.type || 'fill';
            const paintKey = `${type}-opacity` as string;
            this.map.setPaintProperty(layerId, paintKey as string, opacity);
            layer.opacity = opacity;
            this.notify();
        }
    }

    reorderLayer(id: string, newIndex: number) {
        const oldIndex = this.layers.findIndex(l => l.id === id);
        if (oldIndex === -1) return;

        const layer = this.layers.splice(oldIndex, 1)[0];
        this.layers.splice(newIndex, 0, layer);

        for (let i = this.layers.length - 1; i >= 0; i--) {
            const layerId = `${this.layers[i].id}-layer`;
            if (this.map.getLayer(layerId)) {
                this.map.moveLayer(layerId);
            }
        }
        this.notify();
    }

    zoomTo(id: string) {
        const layer = this.layers.find(l => l.id === id);
        if (layer && layer.extent) {
            this.map.fitBounds(layer.extent, { padding: 50 });
        }
    }

    // ── MVT tile layer ────────────────────────────────────────────────────

    addMvtlayer(id: string, name: string, urlTemplate: string, extent?: [number, number, number, number]) {
        const sourceId = `source-${id}`;
        const layerId = `${id}-layer`;

        if (this.map.getSource(sourceId)) {
            (this.map.getSource(sourceId) as maplibregl.VectorTileSource).setUrl(urlTemplate);
        } else {
            this.map.addSource(sourceId, {
                type: 'vector',
                tiles: [urlTemplate],
            });

            // Add each layer from the MVT source individually
            // (styles would come from the layer config in a real app)
            this.map.addLayer({
                id: layerId,
                type: 'fill',
                source: sourceId,
                'source-layer': 'features', // default source layer name
                paint: {
                    'fill-color': '#ff5500',
                    'fill-opacity': 0.5,
                },
            });

            this.layers.unshift({ id, name, visible: true, opacity: 1, extent, style: { type: 'fill' } });
            this.notify();
        }
    }

    updateMvtlayerUrl(id: string, urlTemplate: string) {
        const sourceId = `source-${id}`;
        if (this.map.getSource(sourceId)) {
            (this.map.getSource(sourceId) as maplibregl.VectorTileSource).setUrl(urlTemplate);
        }
    }

    // ── GeoJSON data sync (TASK-053) ─────────────────────────────────────

    /**
     * Attach a live data source to a layer ID. When features change on the server,
     * call syncLayerData() to refresh the GeoJSON source from the API.
     * bbox is [minx, miny, maxx, maxy] in EPSG:4326.
     */
    attachLayerDataSource(id: string, fetchGeoJSON: () => Promise<GeoJSON.FeatureCollection | null>) {
        const sourceId = `source-${id}`;
        const update = async () => {
            try {
                const data = await fetchGeoJSON();
                if (this.map.getSource(sourceId)) {
                    (this.map.getSource(sourceId) as maplibregl.GeoJSONSource).setData(data || { type: 'FeatureCollection', features: [] });
                }
            } catch (err) {
                console.warn(`[LayerManager] Failed to sync layer ${id}:`, err);
            }
        };
        // Store the updater on the map for external triggers
        (this.map as any)._layerUpdaters = (this.map as any)._layerUpdaters || {};
        (this.map as any)._layerUpdaters[id] = update;
    }

    /**
     * Trigger a data refresh for a specific layer (or all if id is null).
     */
    async syncLayerData(id?: string) {
        const updaters = (this.map as any)._layerUpdaters;
        if (!updaters) return;
        if (id) {
            (updaters[id] as (() => Promise<void>) | undefined)?.();
        } else {
            Object.values(updaters).forEach((fn: any) => fn?.());
        }
    }

    // ── draw tools (TASK-053/055) ─────────────────────────────────────────

    /**
     * Initialize MapboxDraw on the map for polygon/point/line creation.
     * The draw instance is stored so tools can enable/disable it.
     */
    enableDraw(options?: { mode?: string }) {
        if (this.drawInstance) return this.drawInstance;

        this.drawInstance = new MapboxDraw({
            displayControlsDefault: false,
            controls: {
                polygon: true,
                point: true,
                line_string: true,
                trash: true,
                combine_features: false,
                uncombine_features: false,
            },
            defaultMode: (options?.mode || 'simple_select') as any,
            styles: [
                // Polygon fill
                {
                    id: 'gl-draw-polygon-fill',
                    type: 'fill',
                    filter: ['all', ['==', '$type', 'Polygon'], ['!=', 'mode', 'static']],
                    paint: {
                        'fill-color': '#ff5500',
                        'fill-opacity': 0.3,
                    },
                },
                // Polygon stroke
                {
                    id: 'gl-draw-polygon-stroke-active',
                    type: 'line',
                    filter: ['all', ['==', '$type', 'Polygon'], ['!=', 'mode', 'static']],
                    paint: {
                        'line-color': '#ff5500',
                        'line-width': 2,
                    },
                },
                // Vertex points
                {
                    id: 'gl-draw-point',
                    type: 'circle',
                    filter: ['all', ['==', '$type', 'Point'], ['!=', 'mode', 'static']],
                    paint: {
                        'circle-radius': 4,
                        'circle-color': '#ff5500',
                    },
                },
                // Line stroke
                {
                    id: 'gl-draw-line',
                    type: 'line',
                    filter: ['all', ['==', '$type', 'LineString'], ['!=', 'mode', 'static']],
                    paint: {
                        'line-color': '#ff5500',
                        'line-width': 2,
                    },
                },
            ],
        });

        this.map.addControl(this.drawInstance as any);
        return this.drawInstance;
    }

    getDraw() {
        return this.drawInstance;
    }

    /**
     * Set draw mode: 'simple_select' | 'direct_select' | 'draw_polygon' | 'draw_point' | 'draw_line' | 'static'
     */
    setDrawMode(mode: 'simple_select' | 'direct_select' | 'draw_polygon' | 'draw_point' | 'draw_line' | 'static' = 'simple_select') {
        if (this.drawInstance) {
            this.drawInstance.changeMode(mode);
        }
    }

    /**
     * Return drawn features as GeoJSON FeatureCollection.
     */
    getDrawnFeatures(): GeoJSON.FeatureCollection {
        if (!this.drawInstance) return { type: 'FeatureCollection', features: [] };
        const data = this.drawInstance.getAll();
        return data || { type: 'FeatureCollection', features: [] };
    }

    /**
     * Clear all drawn features.
     */
    clearDrawnFeatures() {
        if (this.drawInstance) {
            this.drawInstance.deleteAll();
        }
    }

    /**
     * Subscribe to draw create/update/delete events.
     * Returns unsubscribe function.
     */
    onDrawChange(listener: (event: { action: string; features: GeoJSON.FeatureCollection }) => void) {
        if (!this.drawInstance) return () => {};

        const handler = () => {
            listener({
                action: this.drawInstance?.getMode() || '',
                features: this.getDrawnFeatures(),
            });
        };

        (this.map as any).on('draw.create', handler);
        (this.map as any).on('draw.update', handler);
        (this.map as any).on('draw.delete', handler);

        return () => {
            (this.map as any).off('draw.create', handler);
            (this.map as any).off('draw.update', handler);
            (this.map as any).off('draw.delete', handler);
        };
    }

    /**
     * Load GeoJSON from the backend for a layer into its source.
     * This is the primary data-loading path for TASK-053.
     */
    async loadLayerFeatures(
        layerId: number,
        sourceLayerId: string,
        bbox?: [number, number, number, number],
        status?: string,
        signal?: AbortSignal,
    ): Promise<GeoJSON.FeatureCollection> {
        const sourceId = `source-${sourceLayerId}`;
        try {
            const params = new URLSearchParams();
            if (bbox) {
                params.set('bbox', bbox.join(','));
            }
            if (status) {
                params.set('status', status);
            }
            const response = await apiClient.get(`/layers/${layerId}/features.geojson`, {
                params,
                responseType: 'json',
                // TASK-053: rapid panning cancels the stale request.
                signal,
            });

            const geojson = response as GeoJSON.FeatureCollection;

            if (this.map.getSource(sourceId)) {
                (this.map.getSource(sourceId) as maplibregl.GeoJSONSource).setData(geojson);
            }

            return geojson;
        } catch (err) {
            console.error(`[LayerManager] Failed to load features for layer ${layerId}:`, err);
            throw err;
        }
    }
}

// ── coordinate readout (TASK-056) ─────────────────────────────────────────

export function formatCoordinate(lng: number, lat: number, decimals = 6): string {
    const ns = lat >= 0 ? 'N' : 'S';
    const ew = lng >= 0 ? 'E' : 'W';
    return `${Math.abs(lat).toFixed(decimals)}°${ns}, ${Math.abs(lng).toFixed(decimals)}°${ew}`;
}

/**
 * InteractionManager handles mouse interactions: hover, click, coordinate readout.
 */
export class InteractionManager {
    private map: maplibregl.Map;
    private coordinateDisplay?: HTMLElement;

    constructor(map: maplibregl.Map) {
        this.map = map;

        // Coordinate readout on mousemove
        this.map.on('mousemove', (e: maplibregl.MapMouseEvent) => {
            if (this.coordinateDisplay) {
                this.coordinateDisplay.textContent = formatCoordinate(e.lngLat.lng, e.lngLat.lat);
            }
        });
    }

    /**
     * Set the HTML element to display coordinates in (TASK-056).
     */
    setCoordinateDisplay(element: HTMLElement) {
        this.coordinateDisplay = element;
    }

    enableSelection(layerIds: string[], onSelect: (feature: any) => void) {
        this.map.on('click', (e: maplibregl.MapMouseEvent) => {
            // Don't fire selection when drawing
            // @ts-ignore
            const drawMode = this.map.getMode?.() || 'simple_select';
            if (drawMode?.startsWith('draw_')) return;

            const features = this.map.queryRenderedFeatures(e.point, { layers: layerIds });
            if (features.length > 0) {
                onSelect(features[0]);
            } else {
                onSelect(null);
            }
        });
    }
}
