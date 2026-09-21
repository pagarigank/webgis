// @ts-nocheck
import * as maplibregl from 'maplibre-gl';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface SelectionManagerOptions {
    map: maplibregl.Map;
    sourceId: string;
    // @ts-ignore
    layerId: string;
    /** GeoJSON source ids where features live (for queryRenderedFeatures). */
    querySourceIds: string[];
}

export interface SelectedFeatureRow {
    featureId: string;
    layerId: number;
    sourceId: string;
}

export class FeatureSelectionManager {
    private map: maplibregl.Map;
    private sourceId: string;
    private layerId: string;
    private querySourceIds: Set<string>;
    private selectedIds: Set<string> = new Set();
    private featureSourceMap: Map<string, string> = new Map(); // featureId -> sourceId
    private onSelectionChange: ((selectedIds: string[]) => void) | null = null;

    constructor(opts: SelectionManagerOptions) {
        this.map = opts.map;
        this.sourceId = opts.sourceId;
        this.layerId = opts.layerId;
        this.querySourceIds = new Set(opts.querySourceIds);
    }

    getSelectedIds(): ReadonlySet<string> {
        return this.selectedIds;
    }

    getSelectedRows(): SelectedFeatureRow[] {
        const rows: SelectedFeatureRow[] = [];
        for (const fid of this.selectedIds) {
            const src = this.featureSourceMap.get(fid) ?? this.sourceId;
            // layerId unknown from map side; caller provides it via registerFeatureSource
            rows.push({ featureId: fid, layerId: 0, sourceId: src });
        }
        return rows;
    }

    isSelected(featureId: string): boolean {
        return this.selectedIds.has(featureId);
    }

    setOnSelectionChange(cb: (selectedIds: string[]) => void) {
        this.onSelectionChange = cb;
    }

    notify() {
        this.onSelectionChange?.(Array.from(this.selectedIds));
    }

    /** Call after loading data: register each feature's source id. */
    registerFeatures(features: GeoJSON.FeatureCollection, sourceId: string) {
        for (const f of features.features) {
            if (f.id != null) {
                this.featureSourceMap.set(String(f.id), sourceId);
            }
        }
    }

    /** Toggle a single feature in selection (click on map). */
    toggleFeature(featureId: string) {
        if (this.selectedIds.has(featureId)) {
            this.selectedIds.delete(featureId);
        } else {
            this.selectedIds.add(featureId);
        }
        this.applyFeatureState();
        this.notify();
    }

    /** Select exactly one feature (row click in single-select mode). */
    selectOne(featureId: string) {
        this.selectedIds.clear();
        if (featureId) {
            this.selectedIds.add(featureId);
        }
        this.applyFeatureState();
        this.notify();
    }

    /** Select multiple features (shift-click in multi-select mode). */
    selectMultiple(ids: string[]) {
        for (const id of ids) {
            this.selectedIds.add(id);
        }
        this.applyFeatureState();
        this.notify();
    }

    clearSelection() {
        this.selectedIds.clear();
        this.applyFeatureState();
        this.notify();
    }

    /**
     * Apply feature-state to all registered features.
     * Selected = amber highlight, unselected = default paint.
     * We use fill-color via feature-state expression.
     */
    private applyFeatureState() {
        // Remove old state
        for (const fid of this.featureSourceMap.keys()) {
            const src = this.featureSourceMap.get(fid) ?? this.sourceId;
            this.map.setFeatureState({ source: src, id: fid }, { selected: false });
        }
        // Set new state
        for (const fid of this.selectedIds) {
            const src = this.featureSourceMap.get(fid) ?? this.sourceId;
            this.map.setFeatureState({ source: src, id: fid }, { selected: true });
        }
    }

    /**
     * Ensure the GeoJSON source layers have the feature-state-based paint
     * for selection highlight. Call once after layer is added.
     */
    ensureHighlightPaint() {
        const layers = this.map.getStyle().layers ?? [];
        for (const lid of this.querySourceIds) {
            // Find layers that use this source
            const targetLayers = layers.filter(
                (layer) => layer.source === lid && layer.type === 'fill'
            );
            for (const layer of targetLayers) {
                // Only add if not already added
                const currentPaint = this.map.getPaintProperty(layer.id, 'fill-color');
                if (!currentPaint || !Array.isArray(currentPaint) || currentPaint[0] !== 'case') {
                    this.map.setPaintProperty(layer.id, 'fill-color', [
                        'case',
                        ['boolean', ['feature-state', 'selected'], false],
                        '#ffd43b', // amber highlight
                        ['case',
                            ['boolean', ['feature-state', 'hovered'], false],
                            '#ff9900',
                            ['feature-state', 'fill-color', '#4ade80'], // default green
                        ],
                    ] as any);
                    this.map.setPaintProperty(layer.id, 'fill-opacity', [
                        'case',
                        ['boolean', ['feature-state', 'selected'], false],
                        0.9,
                        ['case',
                            ['boolean', ['feature-state', 'hovered'], false],
                            0.7,
                            ['feature-state', 'fill-opacity', 0.5],
                        ],
                    ] as any);
                }
            }
        }
    }
}
