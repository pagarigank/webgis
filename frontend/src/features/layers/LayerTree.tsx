import React, { useState, useEffect } from 'react';
import { useMapContext } from '../map/MapContext';
import type { ActiveLayer } from '../map/Managers';

export function LayerTree() {
    const { layerManager } = useMapContext();
    const [layers, setLayers] = useState<ActiveLayer[]>([]);

    useEffect(() => {
        if (!layerManager) return;
        setLayers(layerManager.getLayers());
        const unsubscribe = layerManager.subscribe((newLayers) => {
            setLayers(newLayers);
        });
        return unsubscribe;
    }, [layerManager]);

    const handleDragStart = (e: React.DragEvent, id: string) => {
        e.dataTransfer.setData('text/plain', id);
    };

    const handleDrop = (e: React.DragEvent, targetIndex: number) => {
        e.preventDefault();
        if (!layerManager) return;
        const draggedId = e.dataTransfer.getData('text/plain');
        if (draggedId) {
            layerManager.reorderLayer(draggedId, targetIndex);
        }
    };

    const renderLegend = (style: any) => {
        if (!style || !style.paint) return null;
        if (style.type === 'fill' && style.paint['fill-color']) {
            return (
                <div style={{
                    width: 16, height: 16, backgroundColor: style.paint['fill-color'], 
                    border: '1px solid #ccc', display: 'inline-block', marginRight: 8
                }} />
            );
        }
        if (style.type === 'line' && style.paint['line-color']) {
            return (
                <div style={{
                    width: 16, height: 2, backgroundColor: style.paint['line-color'], 
                    display: 'inline-block', marginRight: 8, verticalAlign: 'middle'
                }} />
            );
        }
        return null;
    };

    return (
        <div className="layer-tree bg-white shadow-sm rounded border">
            <div className="p-2 border-bottom fw-bold bg-light">Map Layers</div>
            {layers.length === 0 && <div className="p-3 text-muted small">No layers active</div>}
            
            <ul className="list-group list-group-flush">
                {layers.map((layer, index) => (
                    <li 
                        key={layer.id} 
                        className="list-group-item p-2"
                        draggable
                        onDragStart={(e) => handleDragStart(e, layer.id)}
                        onDragOver={(e) => e.preventDefault()}
                        onDrop={(e) => handleDrop(e, index)}
                        style={{ cursor: 'grab' }}
                    >
                        <div className="d-flex align-items-center justify-content-between mb-1">
                            <div className="d-flex align-items-center">
                                <span className="me-2 text-muted" style={{ cursor: 'grab' }}>&#x2630;</span>
                                <input 
                                    type="checkbox" 
                                    className="form-check-input me-2" 
                                    checked={layer.visible}
                                    onChange={(e) => layerManager?.setVisibility(layer.id, e.target.checked)}
                                />
                                {renderLegend(layer.style)}
                                <span className="text-truncate" style={{ maxWidth: '150px' }} title={layer.name}>
                                    {layer.name}
                                </span>
                            </div>
                            {layer.extent && (
                                <button 
                                    className="btn btn-sm btn-link p-0 text-decoration-none ms-2"
                                    onClick={() => layerManager?.zoomTo(layer.id)}
                                    title="Zoom to layer"
                                >
                                    &#x1F50D;
                                </button>
                            )}
                        </div>
                        <div className="d-flex align-items-center mt-2">
                            <span className="text-muted small me-2" style={{ width: '45px' }}>Opacity</span>
                            <input 
                                type="range" 
                                className="form-range flex-grow-1"
                                min="0" max="1" step="0.05"
                                value={layer.opacity}
                                onChange={(e) => layerManager?.setOpacity(layer.id, parseFloat(e.target.value))}
                            />
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
