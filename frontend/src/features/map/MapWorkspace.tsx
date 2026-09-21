import React, { useState } from 'react';
import { useMapContext } from './MapContext';
import { LayerTree } from '../layers/LayerTree';
import { MeasureTool, IdentifyTool, ZoomToTool } from './SpatialTools';
import { DrawTools } from './DrawTools';
import { CoordinateReadout } from './CoordinateReadout';
import { layerApi } from '../layers/api/layerApi';

const SAMPLE_LAYER_CODE = 'SAMPLE_PARCEL_POLYGON';
export const SAMPLE_LAYER_ID_STORAGE_KEY = 'webgis.draw.layer_id';

/** Compute [w,s,e,n] extent (lon/lat EPSG:4326) from a GeoJSON collection. */
function extentFromGeojson(geojson: GeoJSON.FeatureCollection): [number, number, number, number] | undefined {
    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;
    const walk = (coords: number[] | number[][] | number[][][]) => {
        if (typeof coords[0] === 'number') {
            const [x, y] = coords as number[];
            if (Number.isFinite(x) && Number.isFinite(y)) {
                minX = Math.min(minX, x);
                maxX = Math.max(maxX, x);
                minY = Math.min(minY, y);
                maxY = Math.max(maxY, y);
            }
            return;
        }
        (coords as unknown[]).forEach((c) => walk(c as number[][]));
    };
    for (const feature of geojson.features) {
        const geom = feature.geometry;
        if (geom && 'coordinates' in geom) walk(geom.coordinates as never);
    }
    if (!Number.isFinite(minX)) return undefined;
    return [minX, minY, maxX, maxY];
}

export function MapWorkspace() {
    const { map, layerManager, registerViewportLayer } = useMapContext();
    const [loaded, setLoaded] = useState(false);

    if (!map || !layerManager) return null;

    const panelStyle: React.CSSProperties = { pointerEvents: 'auto' as const };

    /**
     * TASK-053: every feature request carries the viewport bbox — the sample
     * layer loader included. The layer id is resolved from the API (it has
     * changed between environments; hardcoding went stale).
     */
    const loadSample = async () => {
        try {
            const layers = await layerApi.getAll();
            const sample =
                layers.find((l) => (l as { code?: string }).code === SAMPLE_LAYER_CODE) ?? layers[0];
            if (!sample) return;

            const b = map.getBounds();
            const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].join(',');
            const geojson = await layerApi.getGeoJSON(sample.id, { bbox });

            const sourceLayerId = String(sample.id);
            layerManager.addGeoJsonLayer({
                id: sourceLayerId,
                name: sample.name ?? SAMPLE_LAYER_CODE,
                geojson,
                extent: extentFromGeojson(geojson),
            });
            // Register for moveend-driven bbox reload (TASK-053).
            registerViewportLayer(sample.id, sourceLayerId);
            localStorage.setItem(SAMPLE_LAYER_ID_STORAGE_KEY, sourceLayerId);
            setLoaded(true);
        } catch (e) {
            console.error('Failed to load sample layer', e);
        }
    };

    return (
        <>
            <div style={{ position: 'absolute', top: 10, left: 10, width: 260, ...panelStyle }}>
                {!loaded && (
                    <button
                        onClick={loadSample}
                        data-testid="load-sample-layer"
                        style={{ marginBottom: 4, padding: '4px 8px', cursor: 'pointer' }}
                    >
                        Load Sample Layer
                    </button>
                )}
                <LayerTree />
            </div>
            <div style={{ position: 'absolute', top: 10, right: 10, width: 320, ...panelStyle }}>
                <DrawTools />
                <ZoomToTool />
                <MeasureTool />
                <IdentifyTool />
            </div>
            <CoordinateReadout />
        </>
    );
}
