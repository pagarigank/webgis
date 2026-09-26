// @ts-nocheck
import React, { useState, useCallback, useRef, useEffect, useSyncExternalStore } from 'react';
import { useMapContext } from './MapContext';
import { spatialApi } from './spatialApi';
import { ANGELES_CITY_CENTER, DEFAULT_MAP_ZOOM, crsDisplayName, getMeasurementSrid, getWorkingSrid, subscribeWorkingSrid } from '../../lib/crs';
import type { ActiveLayer } from './Managers';
import { IdentifyPopup } from './IdentifyPopup';
import * as maplibregl from 'maplibre-gl';
import type { MeasureDistanceResult, MeasureAreaResult } from './spatialApi';

export const MeasureTool: React.FC = () => {
    const map = useMapContext().map;
    const workingSrid = useSyncExternalStore(subscribeWorkingSrid, getWorkingSrid, getWorkingSrid);
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

        // DrawTool-free measure mode: show a crosshair so the grab pan cursor
        // doesn't make the tool look inert.
        const canvas = map.getCanvas();
        const prev = canvas.style.cursor;
        canvas.style.cursor = 'crosshair';

        map.on('click', handleMapClick);
        return () => {
            map.off('click', handleMapClick);
            canvas.style.cursor = prev;
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
                                CRS: {crsDisplayName(result.crs)} ({result.unit})
                                {result.crs !== workingSrid && (
                                    <span style={{ color: '#86efac' }}>
                                        {' '}
                                        — measured in PRS92, not the displayed{' '}
                                        {crsDisplayName(workingSrid)}
                                    </span>
                                )}
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
    borderWidth: 1,
    borderStyle: 'solid',
    borderColor: '#d1d5db',
    borderRadius: 6,
    padding: '6px 12px',
    fontSize: 13,
    cursor: 'pointer',
    color: '#1f2937',
};

export const IdentifyTool: React.FC = () => {
    const { map, layerManager } = useMapContext();
    const [popup, setPopup] = useState<{
        feature: any;
        layerName: string | null;
        distance_m: number | null;
        lng: number;
        lat: number;
    } | null>(null);
    const [layerId, setLayerId] = useState<string>('');
    const [loading, setLoading] = useState(false);
    const [displayLayers, setDisplayLayers] = useState<ActiveLayer[]>([]);
    const touchedRef = useRef(false);

    useEffect(() => {
        if (!layerManager) return;
        setDisplayLayers(layerManager.getLayers());
        return layerManager.subscribe((layers) => {
            setDisplayLayers([...layers]);
        });
    }, [layerManager]);

    // Wire identify to the currently displayed layer, like the draw target.
    useEffect(() => {
        if (touchedRef.current) return;
        const visible = displayLayers.filter((l) => l.visible);
        if (visible.length === 0) return;
        setLayerId(String(visible[0].id));
    }, [displayLayers]);

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
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                <span style={{ fontSize: 12, color: '#6b7280' }}>Layer:</span>
                <select
                    style={{
                        maxWidth: 200,
                        padding: '4px 6px',
                        border: '1px solid #d1d5db',
                        borderRadius: 4,
                        fontSize: 13,
                        background: '#fff',
                    }}
                    value={displayLayers.some((l) => String(l.id) === layerId) ? layerId : ''}
                    onChange={(e) => {
                        touchedRef.current = true;
                        setLayerId(e.target.value);
                    }}
                >
                    <option value="">
                        {displayLayers.filter((l) => l.visible).length > 0
                            ? '— manual/other —'
                            : '— none on map —'}
                    </option>
                    {displayLayers.filter((l) => l.visible).map((l) => (
                        <option key={l.id} value={String(l.id)}>
                            {l.name} (on map)
                        </option>
                    ))}
                </select>
                <span style={{ fontSize: 12, color: '#6b7280' }}>ID:</span>
                <input
                    type="number"
                    value={layerId}
                    onChange={(e) => {
                        touchedRef.current = true;
                        setLayerId(e.target.value);
                    }}
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
            {!layerId && displayLayers.filter((l) => l.visible).length === 0 && (
                <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 4 }}>
                    Load a layer from the ☰ switcher to identify its features — or type a layer ID
                    and click the map (or use "Identify at center").
                </div>
            )}
        </div>
    );
};

export const ZoomToTool: React.FC = () => {
    const map = useMapContext().map;
    const layerManager = useMapContext().layerManager;
    const [layers, setLayers] = useState<Array<{ id: string; name: string; visible: boolean; extent?: [number, number, number, number] }>>([]);

    useEffect(() => {
        if (!layerManager) return;
        setLayers(layerManager.getLayers());
        return layerManager.subscribe((next) => setLayers([...next]));
    }, [layerManager]);

    if (!map) return null;

    return (
        <div>
            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4 }}>Zoom to:</div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <button
                    onClick={() => map.flyTo({ center: ANGELES_CITY_CENTER, zoom: DEFAULT_MAP_ZOOM, duration: 1500 })}
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
                        {layers.length === 0 ? (
                            <div style={{ fontSize: 12, color: '#9ca3af' }}>
                                No layers loaded. Use the ☰ switcher to load a layer here.
                            </div>
                        ) : (
                            layers.map((layer) => (
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
                                            map.flyTo({ center: ANGELES_CITY_CENTER, zoom: DEFAULT_MAP_ZOOM, duration: 1000 });
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

export const NearestControlPointTool: React.FC = () => {
    const map = useMapContext().map;
    const [open, setOpen] = useState(false);
    const [selectedPoint, setSelectedPoint] = useState<any>(null);

    const handleSelect = (pt: any) => {
        setSelectedPoint(pt);
        if (map && pt.latitude !== null && pt.longitude !== null) {
            map.flyTo({
                center: [pt.longitude, pt.latitude],
                zoom: 16,
                duration: 1200,
            });
        }
    };

    if (!map) return null;

    const center = map.getCenter();

    return (
        <div style={{ marginTop: 8 }}>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                style={{
                    ...btnStyle,
                    width: '100%',
                    background: open ? '#2563eb' : '#fff',
                    color: open ? '#fff' : '#374151',
                    borderColor: open ? '#2563eb' : '#d1d5db',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: 6,
                    padding: '8px 12px',
                    fontWeight: 600,
                }}
            >
                <span>📍</span> {open ? 'Hide Control Point Picker' : 'Find Control Points Near Center'}
            </button>
            {open && (
                <div style={{ marginTop: 8 }}>
                    <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 4 }}>
                        Searching near map center: {center.lat.toFixed(5)}°, {center.lng.toFixed(5)}°
                    </div>
                    {/* Lazy-import or render ControlPointPicker */}
                    <React.Suspense fallback={<div>Loading picker…</div>}>
                        <ControlPointPickerWrapper
                            onSelect={handleSelect}
                            initialLat={center.lat}
                            initialLon={center.lng}
                            selectedPointId={selectedPoint?.id}
                        />
                    </React.Suspense>
                    {selectedPoint && (
                        <div style={{ marginTop: 8, padding: 8, background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 4, fontSize: 12 }}>
                            <strong>Selected:</strong> {selectedPoint.point_name} ({selectedPoint.status})
                            <br />
                            Lat: {selectedPoint.latitude?.toFixed(6)}°, Lon: {selectedPoint.longitude?.toFixed(6)}°
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

const ControlPointPickerWrapper = React.lazy(() =>
    import('../control-points/components/ControlPointPicker').then((m) => ({
        default: m.ControlPointPicker,
    }))
);

