import React, { useState, useCallback } from 'react';
import { useMapContext } from '../../features/map/MapContext';
import { spatialQueryApi, type SpatialOperation, type SpatialQueryResult } from './spatialQueryApi';
import { DrawManager } from '../../features/map/DrawManager';
import * as maplibregl from 'maplibre-gl';

const OPERATIONS: { value: SpatialOperation; label: string; description: string }[] = [
    { value: 'bbox', label: 'Bounding box', description: 'Features inside a rectangle' },
    { value: 'intersects', label: 'Intersects', description: 'Features that touch the geometry' },
    { value: 'within', label: 'Within', description: 'Features fully inside the geometry' },
    { value: 'contains', label: 'Contains', description: 'Features that contain the geometry' },
    { value: 'nearest', label: 'Nearest', description: 'Features sorted by distance to a point' },
    { value: 'within_distance', label: 'Within distance', description: 'Features within N metres of a point' },
    { value: 'buffer', label: 'Buffer', description: 'Features inside a buffered zone (not persisted)' },
];

export const SearchPanel: React.FC = () => {
    const map = useMapContext().map;
    const [operation, setOperation] = useState<SpatialOperation>('bbox');
    const [layerId, setLayerId] = useState<string>('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<SpatialQueryResult | null>(null);
    const [error, setError] = useState<string | null>(null);

    // Geometry input state (depends on operation)
    const [bboxW, setBboxW] = useState('');
    const [bboxS, setBboxS] = useState('');
    const [bboxE, setBboxE] = useState('');
    const [bboxN, setBboxN] = useState('');
    const [pointLng, setPointLng] = useState('');
    const [pointLat, setPointLat] = useState('');
    const [distanceM, setDistanceM] = useState('500');
    const [bufferM, setBufferM] = useState('100');

    const [drawMode, setDrawMode] = useState<'polygon' | 'line' | 'point' | null>(null);
    const drawManagerRef = React.useRef<DrawManager | null>(null);
    const [open, setOpen] = useState(false);

    // Lazy-init draw manager on mount
    React.useEffect(() => {
        if (!map) return;
        // DrawManager is already created in MapContext; we access it via context
        // For standalone draw-a-polygon, we'll use a temporary draw instance
    }, [map]);

    const startDrawPolygon = useCallback(() => {
        setDrawMode('polygon');
        setResult(null);
        setError(null);
    }, []);

    const cancelDraw = useCallback(() => {
        setDrawMode(null);
        drawManagerRef.current?.clearDraw();
    }, []);

    const runQuery = useCallback(async () => {
        if (!layerId) {
            setError('Please select a layer');
            return;
        }
        const lid = parseInt(layerId, 10);
        if (isNaN(lid)) {
            setError('Invalid layer ID');
            return;
        }

        setLoading(true);
        setError(null);

        try {
            let geometry: GeoJSON.GeometryObject | number[];
            const opts: any = { srid: 32651, limit: 100, offset: 0 };

            switch (operation) {
                case 'bbox': {
                    const w = parseFloat(bboxW);
                    const s = parseFloat(bboxS);
                    const e = parseFloat(bboxE);
                    const n = parseFloat(bboxN);
                    if (isNaN(w) || isNaN(s) || isNaN(e) || isNaN(n)) {
                        throw new Error('Invalid bbox coordinates');
                    }
                    geometry = [w, s, e, n];
                    break;
                }
                case 'nearest':
                case 'within_distance': {
                    const lng = parseFloat(pointLng);
                    const lat = parseFloat(pointLat);
                    if (isNaN(lng) || isNaN(lat)) {
                        throw new Error('Invalid point coordinates');
                    }
                    geometry = { type: 'Point', coordinates: [lng, lat] };
                    if (operation === 'within_distance') {
                        const d = parseFloat(distanceM);
                        if (!isNaN(d)) opts.distance_m = d;
                    }
                    break;
                }
                case 'buffer': {
                    const lng = parseFloat(pointLng);
                    const lat = parseFloat(pointLat);
                    if (isNaN(lng) || isNaN(lat)) {
                        throw new Error('Invalid point coordinates');
                    }
                    geometry = { type: 'Point', coordinates: [lng, lat] };
                    const b = parseFloat(bufferM);
                    if (!isNaN(b)) opts.buffer_m = b;
                    break;
                }
                case 'intersects':
                case 'within':
                case 'contains': {
                    // Use a simple point or the last drawn polygon
                    const lng = parseFloat(pointLng);
                    const lat = parseFloat(pointLat);
                    if (!isNaN(lng) && !isNaN(lat)) {
                        geometry = { type: 'Point', coordinates: [lng, lat] };
                    } else {
                        throw new Error('Enter coordinates or draw a geometry first');
                    }
                    break;
                }
                default:
                    throw new Error('Unsupported operation');
            }

            // For bbox, use GET convenience endpoint
            if (operation === 'bbox') {
                const [w, s, e, n] = geometry as number[];
                const r = await spatialQueryApi.bbox(lid, w, s, e, n, opts);
                setResult(r);
            } else {
                const r = await spatialQueryApi.query(operation, geometry, lid, opts);
                setResult(r);
            }
        } catch (err: any) {
            setError(err.message ?? 'Query failed');
            setResult(null);
        } finally {
            setLoading(false);
        }
    }, [operation, layerId, bboxW, bboxS, bboxE, bboxN, pointLng, pointLat, distanceM, bufferM]);

    const handleMapClick = useCallback(
        (e: maplibregl.MapMouseEvent) => {
            setPointLng(e.lngLat.lng.toFixed(6));
            setPointLat(e.lngLat.lat.toFixed(6));
            setResult(null);
        },
        [],
    );

    React.useEffect(() => {
        if (!map) return;
        map.on('click', handleMapClick);
        return () => {
            map.off('click', handleMapClick);
        };
    }, [map, handleMapClick]);

    // Draw-a-polygon: when drawMode is set, use a temporary MapboxDraw
    const [drawResult, setDrawResult] = useState<GeoJSON.FeatureCollection | null>(null);

    React.useEffect(() => {
        if (!map || !drawMode) return;

        // Import MapboxDraw dynamically to avoid static dependency on the same instance
        import('@mapbox/mapbox-gl-draw').then(({ default: MapboxDraw }) => {
            const draw = new MapboxDraw({
                displayControlsDefault: false,
                controls: {
                    polygon: drawMode === 'polygon',
                    point: drawMode === 'point',
                    line_string: drawMode === 'line',
                    trash: true,
                    combine_features: false,
                    uncombine_features: false,
                },
                defaultMode: drawMode === 'point' ? 'draw_point' : 'draw_polygon',
            });
            map.addControl(draw as any);

            (map as any).on('draw.create', () => {
                const features = draw.getAll();
                if (features && features.features && features.features.length > 0) {
                    setDrawResult(features);
                }
            });

            drawManagerRef.current = draw as any;

            return () => {
                map.removeControl(draw as any);
                drawManagerRef.current = null;
            };
        });
    }, [map, drawMode]);

    return (
        <div style={{
            position: 'absolute',
            top: 12,
            left: '50%',
            transform: 'translateX(-50%)',
            zIndex: 30,
            pointerEvents: 'auto',
            fontFamily: 'system-ui, sans-serif',
        }}>
            <button
                onClick={() => setOpen((v) => !v)}
                style={{
                    background: '#fff',
                    border: `1px solid ${open ? '#93c5fd' : '#e5e7eb'}`,
                    borderRadius: 8,
                    padding: '8px 16px',
                    fontSize: 13,
                    fontWeight: 600,
                    color: '#1d4ed8',
                    cursor: 'pointer',
                }}
            >
                🔍 Spatial Search {open ? '▲' : '▼'}
            </button>

            {open && (
            <div style={{
            background: '#fff',
            border: '1px solid #e5e7eb',
            borderRadius: 8,
            padding: 16,
            width: 470,
            maxWidth: 470,
            marginTop: 8,
        }}>
            <h3 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 600, color: '#1f2937' }}>
                🔍 Spatial Search (TASK-063)
            </h3>

            {/* Operation selector */}
            <div style={{ marginBottom: 12 }}>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 500, color: '#6b7280', marginBottom: 4 }}>
                    Operation
                </label>
                <select
                    value={operation}
                    onChange={(e) => setOperation(e.target.value as SpatialOperation)}
                    style={{
                        width: '100%',
                        padding: '6px 10px',
                        border: '1px solid #d1d5db',
                        borderRadius: 6,
                        fontSize: 13,
                        background: '#fff',
                    }}
                >
                    {OPERATIONS.map((op) => (
                        <option key={op.value} value={op.value}>
                            {op.label}
                        </option>
                    ))}
                </select>
                <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 2 }}>
                    {OPERATIONS.find((o) => o.value === operation)?.description}
                </div>
            </div>

            {/* Layer selector */}
            <div style={{ marginBottom: 12 }}>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 500, color: '#6b7280', marginBottom: 4 }}>
                    Layer ID
                </label>
                <input
                    type="number"
                    value={layerId}
                    onChange={(e) => setLayerId(e.target.value)}
                    placeholder="e.g. 1"
                    style={{
                        width: '100%',
                        padding: '6px 10px',
                        border: '1px solid #d1d5db',
                        borderRadius: 6,
                        fontSize: 13,
                    }}
                />
            </div>

            {/* Geometry input — varies by operation */}
            <div style={{ marginBottom: 12 }}>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 500, color: '#6b7280', marginBottom: 4 }}>
                    Geometry / Coordinates
                </label>

                {operation === 'bbox' && (
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 6 }}>
                        <input placeholder="West" value={bboxW} onChange={(e) => setBboxW(e.target.value)} style={inputStyle} />
                        <input placeholder="South" value={bboxS} onChange={(e) => setBboxS(e.target.value)} style={inputStyle} />
                        <input placeholder="East" value={bboxE} onChange={(e) => setBboxE(e.target.value)} style={inputStyle} />
                        <input placeholder="North" value={bboxN} onChange={(e) => setBboxN(e.target.value)} style={inputStyle} />
                    </div>
                )}

                {(['nearest', 'within_distance', 'buffer', 'intersects', 'within', 'contains'] as SpatialOperation[]).includes(operation) && (
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 6 }}>
                        <input
                            placeholder="Longitude"
                            value={pointLng}
                            onChange={(e) => setPointLng(e.target.value)}
                            style={inputStyle}
                        />
                        <input
                            placeholder="Latitude"
                            value={pointLat}
                            onChange={(e) => setPointLat(e.target.value)}
                            style={inputStyle}
                        />
                    </div>
                )}

                {(operation === 'within_distance' || operation === 'buffer') && (
                    <div style={{ marginTop: 6 }}>
                        <label style={{ fontSize: 12, color: '#6b7280' }}>
                            {operation === 'within_distance' ? 'Distance (m)' : 'Buffer radius (m)'}:
                            <input
                                type="number"
                                value={operation === 'within_distance' ? distanceM : bufferM}
                                onChange={(e) => operation === 'within_distance' ? setDistanceM(e.target.value) : setBufferM(e.target.value)}
                                style={{ marginLeft: 6, width: 80, padding: '2px 6px', border: '1px solid #d1d5db', borderRadius: 4, fontSize: 12 }}
                            />
                        </label>
                    </div>
                )}

                <div style={{ marginTop: 8, fontSize: 12, color: '#6b7280' }}>
                    Or{' '}
                    <button onClick={startDrawPolygon} style={{ background: 'none', border: 'none', color: '#2563eb', cursor: 'pointer', textDecoration: 'underline', fontSize: 12 }}>
                        draw a polygon on the map
                    </button>{' '}
                    <button onClick={cancelDraw} style={{ background: 'none', border: 'none', color: '#ef4444', cursor: 'pointer', textDecoration: 'underline', fontSize: 12 }}>
                        clear
                    </button>
                </div>
            </div>

            {/* Action */}
            <button
                onClick={runQuery}
                disabled={loading || !layerId}
                style={{
                    width: '100%',
                    padding: '8px 16px',
                    background: layerId ? '#2563eb' : '#f3f4f6',
                    color: layerId ? '#fff' : '#9ca3af',
                    border: 'none',
                    borderRadius: 6,
                    fontSize: 14,
                    fontWeight: 500,
                    cursor: layerId ? 'pointer' : 'not-allowed',
                }}
            >
                {loading ? 'Searching…' : `Search (${operation})`}
            </button>

            {/* Error */}
            {error && (
                <div style={{ marginTop: 8, padding: '8px 12px', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 6, color: '#dc2626', fontSize: 13 }}>
                    {error}
                </div>
            )}

            {/* Results */}
            {result && (
                <div style={{ marginTop: 12, padding: '10px 12px', background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <div style={{ fontSize: 12, fontWeight: 600, color: '#1f2937', marginBottom: 4 }}>
                        {result.count} of {result.total} features found ({result.operation})
                    </div>
                    {result.features.slice(0, 5).map((f, i) => (
                        <div key={i} style={{ fontSize: 12, padding: '4px 0', borderBottom: i < Math.min(5, result.features.length) - 1 ? '1px solid #e5e7eb' : 'none' }}>
                            <span style={{ fontWeight: 500, color: '#6b7280' }}>#{i + 1}</span>{' '}
                            <span style={{ fontFamily: 'monospace', color: '#1d4ed8' }}>{f.id}</span>
                            {' '}
                            <span style={{ color: '#6b7280' }}>
                                {f.properties?.status}
                                {f.properties?.psgc_barangay ? ` · ${f.properties.psgc_barangay}` : ''}
                            </span>
                            {f.properties && ('distance_m' in f.properties) && (
                                <span style={{ color: '#10b981', marginLeft: 8 }}>
                                    {(f.properties as any).distance_m.toFixed(2)} m
                                </span>
                            )}
                        </div>
                    ))}
                    {result.features.length > 5 && (
                        <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 4 }}>
                            +{result.features.length - 5} more
                        </div>
                    )}
                    {/* Draw result overlay on map */}
                    {drawResult && drawResult.features.length > 0 && (
                        <div style={{ marginTop: 8, fontSize: 11, color: '#6b7280' }}>
                            Polygon drawn — {drawResult.features.length} feature(s) captured
                        </div>
                    )}
                </div>
            )}

            {/* Near-me button */}
            <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid #e5e7eb' }}>
                <button
                    onClick={() => {
                        if (!map || !layerId) return;
                        const center = map.getCenter();
                        setPointLng(center.lng.toFixed(6));
                        setPointLat(center.lat.toFixed(6));
                        setResult(null);
                    }}
                    style={{
                        ...btnStyle,
                        background: layerId ? '#eff6ff' : '#f3f4f6',
                        color: layerId ? '#1d4ed8' : '#9ca3af',
                        border: `1px solid ${layerId ? '#93c5fd' : '#e5e7eb'}`,
                    }}
                >
                    📍 Use map center as point
                </button>
            </div>
            </div>
            )}
        </div>
    );
};

const inputStyle: React.CSSProperties = {
    padding: '6px 10px',
    border: '1px solid #d1d5db',
    borderRadius: 6,
    fontSize: 13,
    width: '100%',
    boxSizing: 'border-box',
};

const btnStyle: React.CSSProperties = {
    padding: '6px 12px',
    borderRadius: 6,
    fontSize: 12,
    cursor: 'pointer',
};
