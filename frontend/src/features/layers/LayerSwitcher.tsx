import { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useMapContext } from '../map/MapContext';
import { layerApi } from './api/layerApi';
import { extentFromGeojson } from '../../lib/geometry';
import { styleFromGeometryType } from '../map/Managers';
import type { Layer } from './types';

/**
 * Hamburger-style layer switcher: a ☰ button that expands a panel listing
 * every layer from the API. Each row has a checkbox — checking an unloaded
 * layer loads it into the map (bbox-scoped, moveend-registered); unchecking
 * hides it. Loaded state is derived from the LayerManager so the tree stays
 * in sync with everything else.
 */
export function LayerSwitcher() {
    const { map, layerManager, registerViewportLayer } = useMapContext();
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState<Set<number>>(new Set());
    // id -> visible, from the LayerManager (single source of truth).
    const [active, setActive] = useState<{ id: string; visible: boolean }[]>([]);

    const { data: dbLayers = [] } = useQuery({
        queryKey: ['layers'],
        queryFn: layerApi.getAll,
    });
    // Soft-hide: layers marked hidden in admin never appear in the switcher.
    const switchableLayers = dbLayers.filter((layer: any) => !layer.is_hidden);

    useEffect(() => {
        if (!layerManager) return;
        setActive(layerManager.getLayers().map((l) => ({ id: l.id, visible: l.visible })));
        return layerManager.subscribe((layers) => {
            setActive(layers.map((l) => ({ id: l.id, visible: l.visible })));
        });
    }, [layerManager]);

    const isVisible = (id: number) => {
        const a = active.find((l) => l.id === String(id));
        return !!a && a.visible;
    };

    const toggleLayer = async (layer: any, checked: boolean) => {
        if (!map || !layerManager) return;
        const sourceLayerId = String(layer.id);

        if (!checked) {
            layerManager.setVisibility(sourceLayerId, false);
            return;
        }

        // Already loaded → just show it.
        if (active.some((l) => l.id === sourceLayerId)) {
            layerManager.setVisibility(sourceLayerId, true);
            return;
        }

        // Not loaded → fetch bbox-scoped data and add source + layer.
        setLoading((prev) => new Set(prev).add(layer.id));
        try {
            const b = map.getBounds();
            const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].join(',');
            const geojson = await layerApi.getGeoJSON(layer.id, { bbox });
            const name = (layer as Layer).name ?? (layer as { code?: string }).code ?? String(layer.id);
            layerManager.addGeoJsonLayer({
                id: sourceLayerId,
                name,
                geojson,
                // Deterministic style from the layer's geometry type so a saved
                // polygon/line/point renders with the draw colors (never the
                // default black fill) even when an empty bbox returns no features.
                style: styleFromGeometryType((layer as { geometry_type?: string }).geometry_type),
                extent: extentFromGeojson(geojson),
            });
            registerViewportLayer(layer.id, sourceLayerId);
        } catch (err) {
            console.error(`[LayerSwitcher] failed to load layer ${layer.id}:`, err);
        } finally {
            setLoading((prev) => {
                const next = new Set(prev);
                next.delete(layer.id);
                return next;
            });
        }
    };

    return (
        <div
            data-testid="layer-switcher"
            className="bg-white shadow-sm rounded border"
            style={{ marginBottom: 10 }}
        >
            <button
                data-testid="layer-switcher-toggle"
                onClick={() => setOpen((o) => !o)}
                style={{
                    width: '100%',
                    background: 'none',
                    border: 'none',
                    padding: '8px 12px',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    cursor: 'pointer',
                    fontSize: 13,
                    fontWeight: 600,
                    color: '#1f2937',
                }}
            >
                <span>☰ Switch layer</span>
                <span style={{ fontSize: 11, color: '#6b7280' }}>{open ? '▲' : '▼'}</span>
            </button>

            {open && (
                <div data-testid="layer-switcher-panel" style={{ borderTop: '1px solid #e5e7eb', maxHeight: 260, overflowY: 'auto' }}>
                    {switchableLayers.length === 0 && (
                        <div className="p-2 text-muted small">No layers found.</div>
                    )}
                    {switchableLayers.map((layer: any) => {
                        const checked = isVisible(layer.id);
                        const busy = loading.has(layer.id);
                        return (
                            <label
                                key={layer.id}
                                className="d-flex align-items-center px-2 py-1"
                                style={{ fontSize: 13, cursor: 'pointer', gap: 8 }}
                            >
                                <input
                                    type="checkbox"
                                    data-testid={`layer-switch-${layer.id}`}
                                    checked={checked}
                                    disabled={busy}
                                    onChange={(e) => toggleLayer(layer, e.target.checked)}
                                />
                                {busy ? <span className="text-muted small">loading…</span> : (
                                    <span className="text-truncate" title={`${layer.name} (${layer.code})`}>
                                        {layer.name}
                                        <span className="text-muted"> · {layer.code}</span>
                                    </span>
                                )}
                            </label>
                        );
                    })}
                </div>
            )}
        </div>
    );
}