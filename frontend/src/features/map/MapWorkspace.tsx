import React, { useState } from 'react';
import { useMapContext } from './MapContext';
import { LayerTree } from '../layers/LayerTree';
import { MeasureTool, IdentifyTool, ZoomToTool } from './SpatialTools';
import { layerApi } from '../layers/api/layerApi';

export function MapWorkspace() {
    const map = useMapContext().map;
    const layerManager = useMapContext().layerManager;
    const [loaded, setLoaded] = useState(false);

    if (!map) return null;

    const panelStyle: React.CSSProperties = { pointerEvents: 'auto' as const };

    const loadSample = async () => {
        if (!layerManager) return;
        try {
            const geojson = await layerApi.getGeoJSON(322);
            layerManager.addGeoJsonLayer({ id: '322', name: 'SAMPLE_PARCEL_POLYGON', geojson });
            setLoaded(true);
        } catch (e) {
            console.error('Failed to load sample layer', e);
        }
    };

    return (
        <>
            <div style={{ position: 'absolute', top: 10, left: 10, width: 260, ...panelStyle }}>
                {!loaded && (
                    <button onClick={loadSample} style={{ marginBottom: 4, padding: '4px 8px', cursor: 'pointer' }}>
                        Load Sample Layer 322
                    </button>
                )}
                <LayerTree />
            </div>
            <div style={{ position: 'absolute', top: 10, right: 10, width: 320, ...panelStyle }}>
                <ZoomToTool />
                <MeasureTool />
                <IdentifyTool />
            </div>
        </>
    );
}
