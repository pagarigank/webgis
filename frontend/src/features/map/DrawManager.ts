import * as maplibregl from 'maplibre-gl';
import MapboxDraw from '@mapbox/mapbox-gl-draw';
import type { Feature, FeatureCollection } from '../layers/types';
import { layerApi } from '../layers/api/layerApi';
import { validateGeometry } from '../../lib/geometry';

export interface DrawManagerOptions {
    map: maplibregl.Map;
    onSave?: (feature: Feature) => void;
    onError?: (error: DrawError) => void;
    onCancel?: () => void;
}

export interface DrawError {
    type: 'geometry_invalid' | 'geometry_not_simple' | 'version_conflict' | 'network' | 'validation' | 'no_undo' | 'no_redo';
    message: string;
    detail?: unknown;
}

export class DrawManager {
    private map: maplibregl.Map;
    private draw: MapboxDraw | null = null;
    private options: DrawManagerOptions;
    private currentLayerId: number | null = null;
    private currentFeatureId: string | null = null;
    private pendingFeatures: FeatureCollection | null = null;
    private undoStack: GeoJSON.Feature[] = [];
    private redoStack: GeoJSON.Feature[] = [];

    constructor(options: DrawManagerOptions) {
        this.map = options.map;
        this.options = options;
        this.initDraw();
    }

    private initDraw() {
        this.draw = new MapboxDraw({
            displayControlsDefault: false,
            controls: {
                polygon: true,
                point: true,
                line_string: true,
                trash: true,
                combine_features: false,
                uncombine_features: false,
            },
            defaultMode: 'simple_select',
            styles: [
                {
                    id: 'gl-draw-polygon-fill',
                    type: 'fill',
                    filter: ['all', ['==', '$type', 'Polygon'], ['!=', 'mode', 'static']],
                    paint: { 'fill-color': '#ff5500', 'fill-opacity': 0.3 },
                },
                {
                    id: 'gl-draw-polygon-stroke-active',
                    type: 'line',
                    filter: ['all', ['==', '$type', 'Polygon'], ['!=', 'mode', 'static']],
                    paint: { 'line-color': '#ff5500', 'line-width': 2 },
                },
                {
                    id: 'gl-draw-point',
                    type: 'circle',
                    filter: ['all', ['==', '$type', 'Point'], ['!=', 'mode', 'static']],
                    paint: { 'circle-radius': 4, 'circle-color': '#ff5500' },
                },
                {
                    id: 'gl-draw-line',
                    type: 'line',
                    filter: ['all', ['==', '$type', 'LineString'], ['!=', 'mode', 'static']],
                    paint: { 'line-color': '#ff5500', 'line-width': 2 },
                },
            ],
        });
        this.map.addControl(this.draw);

        this.draw.on('draw.create', () => this.onDrawChange());
        this.draw.on('draw.update', () => this.onDrawChange());
        this.draw.on('draw.delete', () => this.onDrawChange());
    }

    private onDrawChange(isUndoRedo = false) {
        if (!isUndoRedo) {
            const before = this.getDrawnFeatures();
            if (before.features.length > 0) {
                this.undoStack.push(before.features[0]);
                this.redoStack = [];
            }
        }
        const features = this.getDrawnFeatures();
        if (features.features.length > 0) {
            this.pendingFeatures = features;
        } else {
            this.pendingFeatures = null;
        }
        this.options.onSave?.(this.getFirstFeature());
    }

    getDrawnFeatures(): FeatureCollection {
        if (!this.draw) return { type: 'FeatureCollection', features: [] };
        return this.draw.getAll() || { type: 'FeatureCollection', features: [] };
    }

    getFirstFeature(): Feature | null {
        const fc = this.getDrawnFeatures();
        return fc.features[0] ?? null;
    }

    setMode(mode: string) {
        if (this.draw) this.draw.changeMode(mode);
    }

    async saveNew(layerId: number, attributes?: Record<string, unknown>): Promise<Feature | null> {
        const feature = this.getFirstFeature();
        if (!feature) {
            this.options.onError?.({ type: 'validation', message: 'No drawn feature to save' });
            return null;
        }

        const validation = validateGeometry(feature.geometry);
        if (!validation.valid) {
            this.options.onError?.({
                type: 'validation',
                message: validation.errors.join('; ') || 'Geometry validation failed',
                detail: { clientErrors: validation.errors },
            });
            return null;
        }

        try {
            const saved = await layerApi.createFeature(layerId, {
                geometry: feature.geometry,
                attributes: attributes ?? {},
                provenance: 'MANUAL_DRAWING',
                status: 'ACTIVE',
            });
            this.options.onSave?.(saved);
            this.clearDraw();
            return saved;
        } catch (err: any) {
            const error = this.mapErrorToDrawError(err);
            this.options.onError?.(error);
            return null;
        }
    }

    async saveUpdate(
        layerId: number,
        featureId: string,
        currentVersion: number,
        updates: Partial<{ geometry: GeoJSON.GeometryObject; attributes: Record<string, unknown>; status: string; psgc_barangay: string; provenance: string }>,
    ): Promise<Feature | null> {
        if (updates.geometry != null) {
            const validation = validateGeometry(updates.geometry);
            if (!validation.valid) {
                this.options.onError?.({
                    type: 'validation',
                    message: validation.errors.join('; ') || 'Geometry validation failed',
                    detail: { clientErrors: validation.errors },
                });
                return null;
            }
        }

        try {
            const saved = await layerApi.updateFeatureWithVersion(layerId, featureId, currentVersion, updates);
            this.options.onSave?.(saved);
            return saved;
        } catch (err: any) {
            const error = this.mapErrorToDrawError(err);
            if (error.type === 'version_conflict') {
                const latest = await layerApi.getFeature(layerId, featureId);
                try {
                    return await layerApi.updateFeatureWithVersion(layerId, featureId, latest.version, updates);
                } catch (retryErr: any) {
                    this.options.onError?.(this.mapErrorToDrawError(retryErr));
                    return null;
                }
            }
            this.options.onError?.(error);
            return null;
        }
    }

    clearDraw() {
        if (this.draw) this.draw.deleteAll();
        this.pendingFeatures = null;
    }

    destroy() {
        if (this.draw) {
            this.map.removeControl(this.draw);
        }
    }

    canUndo(): boolean {
        return this.undoStack.length > 0;
    }

    canRedo(): boolean {
        return this.redoStack.length > 0;
    }

    async undo(): Promise<Feature | null> {
        if (!this.canUndo()) {
            this.options.onError?.({ type: 'no_undo', message: 'Nothing to undo' });
            return null;
        }
        const previous = this.undoStack.pop()!;
        this.redoStack.push(this.getFirstFeature() ?? { ...previous, id: '' });
        await this.applyFeatureToDraw(previous);
        this.options.onSave?.(previous);
        return previous;
    }

    async redo(): Promise<Feature | null> {
        if (!this.canRedo()) {
            this.options.onError?.({ type: 'no_redo', message: 'Nothing to redo' });
            return null;
        }
        const next = this.redoStack.pop()!;
        this.undoStack.push(this.getFirstFeature() ?? { ...next, id: '' });
        await this.applyFeatureToDraw(next);
        this.options.onSave?.(next);
        return next;
    }

    private async applyFeatureToDraw(feature: GeoJSON.Feature): Promise<void> {
        if (!this.draw) return;
        this.draw.deleteAll();
        const fc: GeoJSON.FeatureCollection = { type: 'FeatureCollection', features: [feature] };
        this.draw.add(fc);
    }

    private mapErrorToDrawError(err: any): DrawError {
        if (!err) return { type: 'network', message: 'Unknown error' };
        const status = err.response?.status;
        const apiError = err.response?.data?.error;

        if (status === 400 && apiError?.code === 'GEOMETRY_INVALID') {
            return { type: 'geometry_invalid', message: apiError.message ?? 'Invalid geometry', detail: apiError.details };
        }
        if (status === 400 && apiError?.code === 'GEOMETRY_NOT_SIMPLE') {
            return { type: 'geometry_not_simple', message: apiError.message ?? 'Geometry is not simple', detail: apiError.details };
        }
        if (status === 409 && apiError?.code === 'VERSION_CONFLICT') {
            return { type: 'version_conflict', message: apiError.message ?? 'Version conflict', detail: { current_version: apiError.details?.current_version } };
        }
        if (status === 428 && apiError?.code === 'PRECONDITION_REQUIRED') {
            return { type: 'validation', message: 'If-Match header required' };
        }
        return {
            type: 'network',
            message: err.message ?? 'Network error',
            detail: apiError ?? err,
        };
    }
}
