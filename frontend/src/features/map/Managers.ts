import * as maplibregl from 'maplibre-gl';

export interface ActiveLayer {
    id: string;
    name: string;
    visible: boolean;
    opacity: number;
    extent?: [number, number, number, number];
    style?: any;
}

export class LayerManager {
    private map: maplibregl.Map;
    private layers: ActiveLayer[] = [];
    private listeners: ((layers: ActiveLayer[]) => void)[] = [];

    constructor(map: maplibregl.Map) {
        this.map = map;
    }

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

    addGeoJsonLayer(id: string, name: string, geojson: any, style: any, extent?: [number, number, number, number]) {
        if (this.map.getSource(id)) {
            (this.map.getSource(id) as maplibregl.GeoJSONSource).setData(geojson);
        } else {
            this.map.addSource(id, { type: 'geojson', data: geojson });
            this.map.addLayer({
                id: `${id}-layer`,
                type: style.type || 'fill',
                source: id,
                paint: style.paint || {}
            });
            this.layers.unshift({ id, name, visible: true, opacity: 1, extent, style });
            this.notify();
        }
    }

    removeLayer(id: string) {
        if (this.map.getLayer(`${id}-layer`)) {
            this.map.removeLayer(`${id}-layer`);
        }
        if (this.map.getSource(id)) {
            this.map.removeSource(id);
        }
        this.layers = this.layers.filter(l => l.id !== id);
        this.notify();
    }

    setVisibility(id: string, visible: boolean) {
        if (this.map.getLayer(`${id}-layer`)) {
            this.map.setLayoutProperty(`${id}-layer`, 'visibility', visible ? 'visible' : 'none');
            const layer = this.layers.find(l => l.id === id);
            if (layer) {
                layer.visible = visible;
                this.notify();
            }
        }
    }

    setOpacity(id: string, opacity: number) {
        const layer = this.layers.find(l => l.id === id);
        if (layer && this.map.getLayer(`${id}-layer`)) {
            // Depending on type, it's fill-opacity, line-opacity, circle-opacity, raster-opacity
            const type = layer.style?.type || 'fill';
            this.map.setPaintProperty(`${id}-layer`, `${type}-opacity` as any, opacity);
            layer.opacity = opacity;
            this.notify();
        }
    }

    reorderLayer(id: string, newIndex: number) {
        const oldIndex = this.layers.findIndex(l => l.id === id);
        if (oldIndex === -1) return;

        const layer = this.layers.splice(oldIndex, 1)[0];
        this.layers.splice(newIndex, 0, layer);
        
        // Simpler way: just iterate backwards and move to top
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
}

export class InteractionManager {
    private map: maplibregl.Map;

    constructor(map: maplibregl.Map) {
        this.map = map;
    }

    enableSelection(layerIds: string[], onSelect: (feature: any) => void) {
        this.map.on('click', (e: any) => {
            const features = this.map.queryRenderedFeatures(e.point, { layers: layerIds });
            if (features.length > 0) {
                onSelect(features[0]);
            } else {
                onSelect(null);
            }
        });
    }
}
