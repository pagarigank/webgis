import React from 'react';
import { useMapContext } from './MapContext';
import { LayerTree } from '../layers/LayerTree';
import { LayerSwitcher } from '../layers/LayerSwitcher';
import { MeasureTool, IdentifyTool, ZoomToTool, NearestControlPointTool } from './SpatialTools';
import { DrawTools } from './DrawTools';
import { BasemapToggle } from './BasemapToggle';
import { CoordinateReadout } from './CoordinateReadout';
import { ParcelOverlay } from './ParcelOverlay';

export function MapWorkspace() {
    const { layerManager } = useMapContext();

    if (!layerManager) return null;

    const panelStyle: React.CSSProperties = { pointerEvents: 'auto' as const };

    return (
        <>
            <div style={{ position: 'absolute', top: 10, left: 10, width: 260, ...panelStyle }}>
                <LayerSwitcher />
                <LayerTree />
            </div>
            <div style={{ position: 'absolute', top: 10, right: 10, width: 320, ...panelStyle }}>
                <BasemapToggle />
                <DrawTools />
                <ZoomToTool />
                <MeasureTool />
                <IdentifyTool />
                <NearestControlPointTool />
            </div>
            <ParcelOverlay />
            <CoordinateReadout />
        </>
    );
}
