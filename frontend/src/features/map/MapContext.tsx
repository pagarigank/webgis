import React, { createContext, useContext, useState, useRef, useEffect, useMemo, useCallback } from 'react';
import type { ReactNode } from 'react';
import * as maplibregl from 'maplibre-gl';
import { LayerManager, InteractionManager, formatCoordinate } from './Managers';
import { DrawManager } from './DrawManager';
import type { DrawError } from './DrawManager';
import { ConflictDialogHost } from './ConflictDialogHost';
import 'maplibre-gl/dist/maplibre-gl.css';

export type DrawMode = 'simple_select' | 'direct_select' | 'draw_polygon' | 'draw_point' | 'draw_line' | 'static';

export interface MapContextState {
    map: maplibregl.Map | null;
    isLoaded: boolean;
    layerManager: LayerManager | null;
    interactionMgr: InteractionManager | null;
    drawManager: DrawManager | null;
    drawMode: DrawMode;
    setDrawMode: (mode: DrawMode) => void;
    clearDraw: () => void;
    undo: () => Promise<void>;
    redo: () => Promise<void>;
    canUndo: () => boolean;
    canRedo: () => boolean;
    hasUnsavedChanges: () => boolean;
    onError: (error: DrawError) => void;
    coordinate: string;
    loadLayerFeatures: (layerId: number, sourceLayerId: string, bbox?: [number, number, number, number], status?: string) => Promise<GeoJSON.FeatureCollection>;
}

const MapContext = createContext<MapContextState | undefined>(undefined);

export const MapProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
    const mapContainerRef = useRef<HTMLDivElement>(null);
    const [map, setMap] = useState<maplibregl.Map | null>(null);
    const [isLoaded, setIsLoaded] = useState(false);
    const [drawMode, setDrawMode] = useState<DrawMode>('simple_select');
    const [drawManager, setDrawManager] = useState<DrawManager | null>(null);
    const [onError, setOnError] = useState<((error: DrawError) => void) | null>(null);
    const [drawnFeatures, setDrawnFeatures] = useState<GeoJSON.FeatureCollection>({ type: 'FeatureCollection', features: [] });
    const [savedFeatureCallback, setSavedFeatureCallback] = useState<((feature: GeoJSON.Feature) => void) | null>(null);
    const [coordinate, setCoordinate] = useState<string>('');
    const [hasPendingEdits, setHasPendingEdits] = useState(false);
    const layerManagerRef = useRef<LayerManager | null>(null);
    const drawChangeListenerRef = useRef<(() => void) | null>(null);
    const conflictHostRef = useRef<((error: DrawError, ctx: {
        layerId: number;
        featureId: string;
        yourVersion: any;
    }) => void) | null>(null);

    // Initialize map
    useEffect(() => {
        if (mapContainerRef.current && !map) {
            const instance = new maplibregl.Map({
                container: mapContainerRef.current,
                style: 'https://demotiles.maplibre.org/style.json',
                center: [121, 14.5],
                zoom: 5,
            });

            instance.addControl(new maplibregl.NavigationControl(), 'top-right');

            instance.on('load', () => {
                setIsLoaded(true);
            });

            setMap(instance);
        }
    }, [map]);

    // Initialize managers once map is loaded
    useEffect(() => {
        if (!map || !isLoaded) return;

        const lm = new LayerManager(map);
        const im = new InteractionManager(map);
        layerManagerRef.current = lm;

        // Draw manager (TASK-058/061)
        const dm = new DrawManager({
            map,
            onSave: () => {
                if (savedFeatureCallback) savedFeatureCallback(null);
            },
            onError: (error) => {
                if (onError) onError(error);
            },
            onVersionConflict: (error, context) => {
                conflictHostRef.current?.(error, context);
            },
        });
        drawManagerRef.current = dm;
        setDrawManager(dm);

        const setConflictHost = useCallback((fn: typeof conflictHostRef.current) => {
            conflictHostRef.current = fn;
        }, []);

        // Coordinate readout display (TASK-056) — use a floating div
        const coordDiv = document.createElement('div');
        coordDiv.id = 'coordinate-display';
        coordDiv.style.cssText = 'position:absolute;bottom:10px;left:10px;background:rgba(0,0,0,0.7);color:#fff;padding:4px 8px;border-radius:4px;font-size:12px;font-family:monospace;z-index:1000;pointer-events:none;';
        coordDiv.textContent = '—';
        map.getContainer().appendChild(coordDiv);
        im.setCoordinateDisplay(coordDiv);

        // Draw change listener (legacy — still fires on draw.create/update/delete)
        const unsubscribe = lm.onDrawChange(({ features }) => {
            setDrawnFeatures(features);
        });

        drawChangeListenerRef.current = unsubscribe;

        // Coordinate update on mousemove (handled by InteractionManager)
        const coordUpdate = () => {
            if (instance && map.getContainer()) {
                const el = document.getElementById('coordinate-display') as HTMLElement | null;
                if (el) setCoordinate(el.textContent || '');
            }
        };
        instance.on('mousemove', coordUpdate);

        // Keyboard shortcuts for undo/redo (TASK-059)
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return;

            if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
                e.preventDefault();
                if (e.shiftKey) {
                    drawManagerRef.current?.redo().then(() => setHasPendingEdits(false));
                } else {
                    drawManagerRef.current?.undo().then(() => setHasPendingEdits(false));
                }
            } else if ((e.ctrlKey || e.metaKey) && e.key === 'y') {
                e.preventDefault();
                drawManagerRef.current?.redo().then(() => setHasPendingEdits(false));
            }
        };
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            unsubscribe();
            if (drawChangeListenerRef.current) drawChangeListenerRef.current();
            coordDiv.remove();
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [map, isLoaded]);

    // Draw mode setter
    const handleSetDrawMode = useCallback((mode: DrawMode) => {
        setDrawMode(mode);
        if (layerManagerRef.current) {
            layerManagerRef.current.setDrawMode(mode);
        }
        if (drawManagerRef.current) {
            drawManagerRef.current.setMode(mode);
        }
    }, []);

    const handleClearDraw = useCallback(() => {
        if (layerManagerRef.current) {
            layerManagerRef.current.clearDrawnFeatures();
            setDrawnFeatures({ type: 'FeatureCollection', features: [] });
        }
        if (drawManagerRef.current) {
            drawManagerRef.current.clearDraw();
        }
        setHasPendingEdits(false);
    }, []);

    const handleLoadLayerFeatures = useCallback(async (
        layerId: number,
        sourceLayerId: string,
        bbox?: [number, number, number, number],
        status?: string,
    ) => {
        if (!layerManagerRef.current) throw new Error('LayerManager not initialized');
        return layerManagerRef.current.loadLayerFeatures(layerId, sourceLayerId, bbox, status);
    }, []);

    const managers = useMemo(() => {
        if (!map || !isLoaded) return { layerManager: null, interactionMgr: null };
        return {
            layerManager: layerManagerRef.current || null,
            interactionMgr: new InteractionManager(map),
        };
    }, [map, isLoaded]);

    return (
        <MapContext.Provider value={{
            map,
            isLoaded,
            layerManager: managers.layerManager,
            interactionMgr: managers.interactionMgr,
            drawManager,
            drawMode,
            setDrawMode: handleSetDrawMode,
            clearDraw: handleClearDraw,
            undo: async () => { drawManagerRef.current?.undo().then(() => setHasPendingEdits(false)); },
            redo: async () => { drawManagerRef.current?.redo().then(() => setHasPendingEdits(false)); },
            canUndo: () => drawManagerRef.current?.canUndo() ?? false,
            canRedo: () => drawManagerRef.current?.canRedo() ?? false,
            hasUnsavedChanges: () => hasPendingEdits,
            onError: (error) => { if (onError) onError(error); },
            coordinate,
            loadLayerFeatures: handleLoadLayerFeatures,
        }}>
            <div style={{ position: 'relative', width: '100%', height: '100vh' }}>
                <div ref={mapContainerRef} style={{ width: '100%', height: '100%', position: 'absolute', top: 0, left: 0 }} />
                <div style={{ position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', pointerEvents: 'none' }}>
                    {children}
                    <ConflictDialogHost />
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
