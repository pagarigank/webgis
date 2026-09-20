import React, { createContext, useContext, useState, useRef, useEffect, useMemo } from 'react';
import type { ReactNode } from 'react';
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { LayerManager, InteractionManager } from './Managers';

export interface MapContextState {
    map: maplibregl.Map | null;
    isLoaded: boolean;
    layerManager: LayerManager | null;
    interactionMgr: InteractionManager | null;
}

const MapContext = createContext<MapContextState | undefined>(undefined);

export const MapProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
    const mapContainerRef = useRef<HTMLDivElement>(null);
    const [map, setMap] = useState<maplibregl.Map | null>(null);
    const [isLoaded, setIsLoaded] = useState(false);

    useEffect(() => {
        if (mapContainerRef.current && !map) {
            const instance = new maplibregl.Map({
                container: mapContainerRef.current,
                style: {
                    version: 8,
                    sources: {
                        'osm': {
                            type: 'raster',
                            tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                            tileSize: 256,
                            attribution: '&copy; OpenStreetMap Contributors'
                        }
                    },
                    layers: [
                        {
                            id: 'osm-layer',
                            type: 'raster',
                            source: 'osm',
                            minzoom: 0,
                            maxzoom: 19
                        }
                    ]
                },
                center: [121, 14.5], // default to Manila, PH approx
                zoom: 5
            });

            instance.addControl(new maplibregl.NavigationControl(), 'top-right');

            instance.on('load', () => {
                setIsLoaded(true);
            });

            setMap(instance);
        }
    }, [map]);

    const managers = useMemo(() => {
        if (!map || !isLoaded) return { layerManager: null, interactionMgr: null };
        return {
            layerManager: new LayerManager(map),
            interactionMgr: new InteractionManager(map)
        };
    }, [map, isLoaded]);

    return (
        <MapContext.Provider value={{ map, isLoaded, ...managers }}>
            <div style={{ position: 'relative', width: '100%', height: '100vh' }}>
                <div ref={mapContainerRef} style={{ width: '100%', height: '100%', position: 'absolute', top: 0, left: 0 }} />
                <div style={{ position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', pointerEvents: 'none' }}>
                    {children}
                </div>
            </div>
        </MapContext.Provider>
    );
};

export function useMapContext() {
    const context = useContext(MapContext);
    if (context === undefined) {
        throw new Error('useMapContext must be used within a MapProvider');
    }
    return context;
}
