// @ts-nocheck
import React, { useState, useCallback, useRef } from 'react';
import { useMapContext } from './MapContext';
import { spatialApi } from './spatialApi';
import { IdentifyPopup } from './IdentifyPopup';
import * as maplibregl from 'maplibre-gl';
import type { MeasureDistanceResult, MeasureAreaResult } from './spatialApi';

const CRS_LABELS: Record<number, string> = {
    32651: 'EPSG:32651 (UTM 51N, Metro Manila)',
};

export const MeasureTool: React.FC = () => {
    const map = useMapContext().map;
    const [mode, setMode] = useState<'distance' | 'area' | null>(null);
    const [result, setResult] = useState<{
        text: string;
        crs: number;
        unit: string;
    } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const drawingPoints = useRef<Array<{ lng: number; lat: number }>>([]);

    const startDistance = useCallback(() => {
        setMode('distance');
        drawingPoints.current = [];
        setResult(null);
        setError(null);
    }, []);

    const startArea = useCallback(() => {
        setMode('area');
        drawingPoints.current = [];
        setResult(null);
        setError(null);
    }, []);

    const cancel = useCallback(() => {
        setMode(null);
        drawingPoints.current = [];
        setResult(null);
        setError(null);
    }, []);

    const handleMapClick = useCallback(
        (e: maplibregl.MapMouseEvent) => {
            if (!mode) return;
            drawingPoints.current.push({ lng: e.lngLat.lng, lat: e.lngLat.lat });

            if (mode === 'distance' && drawingPoints.current.length === 2) {
                const pts = drawingPoints.current;
                const line: GeoJSON.LineString = {
                    type: 'LineString',
                    coordinates: pts.map((p) => [p.lng, p.lat]),
                };
                spatialApi
                    .measure('distance', line)
                    .then((r) => {
                        setResult({
                            text: `Distance: ${((r as MeasureDistanceResult).length_m / 1000).toFixed(3)} km (${(r as MeasureDistanceResult).length_m.toFixed(2)} m)`,
                            crs: r.crs,
                            unit: r.unit,
                        });
                    })
                    .catch((err) => setError(err.message ?? 'Measure failed'));
            } else if (mode === 'area' && drawingPoints.current.length === 3) {
                const pts = drawingPoints.current;
                // Close the ring
                const ring = [...pts, pts[0]];
                const poly: GeoJSON.Polygon = {
                    type: 'Polygon',
                    coordinates: [ring.map((p) => [p.lng, p.lat])],
                };
                spatialApi
                    .measure('area', poly)
                    .then((r) => {
                        const ha = (r as MeasureAreaResult).area_ha;
                        const m2 = (r as MeasureAreaResult).area_m2;
                        setResult({
                            text: `Area: ${ha.toFixed(4)} ha (${m2.toFixed(2)} m²)`,
                            crs: r.crs,
                            unit: r.unit,
                        });
                    })
                    .catch((err) => setError(err.message ?? 'Measure failed'));
            }
        },
        [mode],
    );

    React.useEffect(() => {
        if (!map || !mode) return;

        map.on('click', handleMapClick);
        return () => {
            map.off('click', handleMapClick);
        };
    }, [map, mode, handleMapClick]);

    if (!map) return null;

    return (
        <div>
            {mode === null && (
                <div style={{ display: 'flex', gap: 8 }}>
                    <button onClick={startDistance} style={btnStyle}>
                        📏 Measure distance
                    </button>
                    <button onClick={startArea} style={btnStyle}>
                        🟦 Measure area
                    </button>
                </div>
            )}

            {mode && (
                <div style={{ marginTop: 8 }}>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4 }}>
                        {mode === 'distance'
                            ? 'Click two points to measure distance'
                            : 'Click three points to measure area (triangle)'}
                        {' '}
                        <button onClick={cancel} style={{ background: 'none', border: 'none', color: '#ef4444', cursor: 'pointer', fontSize: 12, textDecoration: 'underline' }}>
                            Cancel
                        </button>
                    </div>
                    {error && (
                        <div style={{ color: '#ef4444', fontSize: 12, marginBottom: 4 }}>{error}</div>
                    )}
                    {result && (
                        <div
                            style={{
                                background: '#f0fdf4',
                                border: '1px solid #86efac',
                                borderRadius: 6,
                                padding: '8px 12px',
                                fontSize: 13,
                                color: '#166534',
                            }}
                        >
                            <div>{result.text}</div>
                            <div style={{ fontSize: 11, color: '#4ade80', marginTop: 4 }}>
                                CRS: {CRS_LABELS[result.crs] ?? `EPSG:${result.crs}`} ({result.unit})
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

const btnStyle: React.CSSProperties = {
    background: '#fff',
    border: '1px solid #d1d5db',
    borderRadius: 6,
    padding: '6px 12px',
    fontSize: 13,
    cursor: 'pointer',
    color: '#1f2937',
};

export const IdentifyTool: React.FC = () => {
    const map = useMapContext().map;
    const [popup, setPopup] = useState<{
        feature: any;
        layerName: string | null;
        distance_m: number | null;
        lng: number;
        lat: number;
    } | null>(null);
    const [layerId, setLayerId] = useState<string>('');
    const [loading, setLoading] = useState(false);

    const handleIdentify = useCallback(
        (e: maplibregl.MapMouseEvent) => {
            if (!layerId || !map) return;
            const lid = parseInt(layerId, 10);
            if (isNaN(lid)) return;
            setLoading(true);
            spatialApi
                .identify(e.lngLat.lng, e.lngLat.lat, lid)
                .then((r) => {
                    if (!r.feature) {
                        setPopup(null);
                    } else {
                        setPopup({
                            feature: r.feature,
                            layerName: r.layer_name,
                            distance_m: r.distance_m,
                            lng: e.lngLat.lng,
                            lat: e.lngLat.lat,
                        });
                    }
                })
                .catch(() => setPopup(null))
                .finally(() => setLoading(false));
        },
        [map, layerId],
    );

    React.useEffect(() => {
        if (!map || !layerId) return;
        map.on('click', handleIdentify);
        return () => {
            map.off('click', handleIdentify);
        };
    }, [map, layerId, handleIdentify]);

    const closePopup = useCallback(() => setPopup(null), []);

    if (!map) return null;

    return (
        <div>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <span style={{ fontSize: 12, color: '#6b7280' }}>Layer ID:</span>
                <input
                    type="number"
                    value={layerId}
                    onChange={(e) => setLayerId(e.target.value)}
                    placeholder="e.g. 1"
                    style={{
                        width: 80,
                        padding: '4px 8px',
                        border: '1px solid #d1d5db',
                        borderRadius: 4,
                        fontSize: 13,
                    }}
                />
                <button
                    onClick={() => {
                        // Trigger identify at map center
                        const center = map.getCenter();
                        handleIdentify({
                            lngLat: { lng: center.lng, lat: center.lat },
                            point: { x: 0, y: 0 },
                        } as maplibregl.MapMouseEvent);
                    }}
                    disabled={!layerId || loading}
                    style={{
                        ...btnStyle,
                        background: layerId ? '#2563eb' : '#f3f4f6',
                        color: layerId ? '#fff' : '#9ca3af',
                        cursor: layerId ? 'pointer' : 'not-allowed',
                    }}
                >
                    {loading ? 'Identifying…' : 'Identify at center'}
                </button>
            </div>
            {popup && (
                <IdentifyPopup
                    feature={popup.feature}
                    layerName={popup.layerName}
                    distance_m={popup.distance_m}
                    onClose={closePopup}
                />
            )}
            {!layerId && (
                <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 4 }}>
                    Enter a layer ID and click the map (or use "Identify at center") to see feature details.
                </div>
            )}
        </div>
    );
};

export const ZoomToTool: React.FC = () => {
    const map = useMapContext().map;
    const layerManager = useMapContext().layerManager;

    if (!map) return null;

    return (
        <div>
            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4 }}>Zoom to:</div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <button
                    onClick={() => map.flyTo({ center: [121, 14.5], zoom: 10, duration: 1500 })}
                    style={btnStyle}
                >
                    🇵🇭 Metro Manila
                </button>
                <button
                    onClick={() => map.flyTo({ center: [0, 0], zoom: 2, duration: 1500 })}
                    style={btnStyle}
                >
                    🌍 World
                </button>
                {layerManager && (
                    <>
                        <div style={{ width: '100%', margin: '8px 0', fontSize: 12, color: '#6b7280' }}>
                            Or zoom to a loaded layer:
                        </div>
                        {layerManager.getLayers().length === 0 ? (
                            <div style={{ fontSize: 12, color: '#9ca3af' }}>
                                No layers loaded. Load a layer to see zoom-to buttons.
                            </div>
                        ) : (
                            layerManager.getLayers().map((layer) => (
                                <button
                                    key={layer.id}
                                    onClick={() => {
                                        const l = layerManager.getLayers().find((l) => l.id === layer.id);
                                        if (l?.extent) {
                                            map.fitBounds(
                                                l.extent,
                                                { padding: 50, duration: 1000 },
                                            );
                                        } else {
                                            map.flyTo({ center: [121, 14.5], zoom: 10, duration: 1000 });
                                        }
                                    }}
                                    style={{ ...btnStyle, background: '#eff6ff', borderColor: '#93c5fd', color: '#1d4ed8' }}
                                >
                                    {layer.name}
                                </button>
                            ))
                        )
                    }
                    </>
                )}
            </div>
        </div>
    );
};
