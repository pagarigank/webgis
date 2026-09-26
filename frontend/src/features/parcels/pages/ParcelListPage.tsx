import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useBasemapToggle } from '../../map/basemap';
import { ANGELES_CITY_CENTER, DEFAULT_MAP_ZOOM } from '../../../lib/crs';
import { parcelApi } from '../api/parcelApi';
import type { Parcel, ParcelListParams } from '../types';
import { QuickCreateParcelModal } from '../components/QuickCreateParcelModal';

export interface ParcelMapRef {
    fitToParcel: (parcel: Parcel) => void;
}

const STATUS_OPTIONS = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'RETURNED', 'VERIFIED', 'APPROVED', 'PUBLISHED', 'ARCHIVED', 'SUPERSEDED'];
const PAGE_SIZE = 10;

/**
 * TASK-070 — parcel list page with keyword/status/historical filters, a
 * paginated results table, and a map preview. Panning/zooming the preview
 * with "Only in map view" enabled narrows results to the visible bbox.
 */
export function ParcelListPage() {
    const [showCreateModal, setShowCreateModal] = useState(false);
    const queryClient = useQueryClient();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [includeHistorical, setIncludeHistorical] = useState(false);
    const [restrictToMap, setRestrictToMap] = useState(false);
    const [bbox, setBbox] = useState<string | null>(null);
    const [offset, setOffset] = useState(0);
    const mapRef = useRef<ParcelMapRef>(null);

    const params: ParcelListParams = useMemo(() => ({
        limit: PAGE_SIZE,
        offset,
        sort: 'created_at',
        dir: 'DESC',
        ...(search.trim() !== '' ? { q: search.trim() } : {}),
        ...(status !== '' ? { status } : {}),
        ...(includeHistorical ? { include_historical: true } : {}),
        ...(restrictToMap && bbox ? { bbox } : {}),
    }), [search, status, includeHistorical, restrictToMap, bbox, offset]);

    const { data, isLoading, isFetching, error } = useQuery({
        queryKey: ['parcels', params],
        queryFn: () => parcelApi.list(params),
        placeholderData: (prev) => prev,
    });

    const total = data?.total ?? 0;
    const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    const page = Math.floor(offset / PAGE_SIZE) + 1;

    const applyFilters = () => {
        setOffset(0);
    };

    const resetFilters = () => {
        setSearch('');
        setStatus('');
        setIncludeHistorical(false);
        setRestrictToMap(false);
        setBbox(null);
        setOffset(0);
    };

    return (
        <div>
            {/* ── Page header ── */}
            <div className="page-header">
                <div>
                    <h2 className="page-title" style={{ margin: 0 }}>Parcels</h2>
                    <p className="page-subtitle" style={{ marginTop: '0.25rem' }}>
                        {total} result{total === 1 ? '' : 's'}
                        {restrictToMap && bbox ? ' · limited to map view' : ''}
                    </p>
                </div>
                <button
                    className="btn btn-primary btn-sm"
                    data-testid="parcel-create-btn"
                    onClick={() => setShowCreateModal(true)}
                >
                    + New parcel
                </button>
            </div>

            {/* ── Filters ── */}
            <div className="card" style={{ marginBottom: '1.25rem' }}>
                <div className="card-body">
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr auto auto auto', gap: '0.75rem', alignItems: 'end' }}>
                        <div className="form-group" style={{ margin: 0 }}>
                            <label className="form-label" htmlFor="parcel-q">Search</label>
                            <input
                                id="parcel-q"
                                type="text"
                                className="form-input"
                                placeholder="Lot, block, plan, TCT, TD, code, location, barangay…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                data-testid="parcel-search"
                            />
                        </div>
                        <div className="form-group" style={{ margin: 0, minWidth: 160 }}>
                            <label className="form-label" htmlFor="parcel-status">Status</label>
                            <select
                                id="parcel-status"
                                className="form-select"
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                data-testid="parcel-status-filter"
                            >
                                <option value="">All statuses</option>
                                {STATUS_OPTIONS.map((s) => (
                                    <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
                                ))}
                            </select>
                        </div>
                        <div style={{ paddingBottom: '0.125rem' }}>
                            <label className="form-check" style={{ marginBottom: '0.375rem' }}>
                                <input
                                    className="form-check-input"
                                    type="checkbox"
                                    id="parcel-include-historical"
                                    checked={includeHistorical}
                                    onChange={(e) => setIncludeHistorical(e.target.checked)}
                                    data-testid="parcel-include-historical"
                                />
                                <span className="form-check-label">Include historical</span>
                            </label>
                            <label className="form-check">
                                <input
                                    className="form-check-input"
                                    type="checkbox"
                                    id="parcel-restrict-map"
                                    checked={restrictToMap}
                                    onChange={(e) => setRestrictToMap(e.target.checked)}
                                    data-testid="parcel-restrict-map"
                                />
                                <span className="form-check-label">Only in map view</span>
                            </label>
                        </div>
                        <div className="flex gap-2" style={{ paddingBottom: '0.125rem' }}>
                            <button className="btn btn-primary btn-sm" onClick={applyFilters} data-testid="parcel-search-btn">Apply</button>
                            <button className="btn btn-ghost btn-sm" onClick={resetFilters}>Reset</button>
                        </div>
                    </div>
                </div>
            </div>

            {/* ── Results + map preview ── */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 360px', gap: '1.25rem', alignItems: 'start' }}>
                <div className="card">
                    <ParcelTable
                        data={data?.data}
                        isLoading={isLoading || isFetching}
                        error={error}
                        currentPage={page}
                        pages={pages}
                        onPrev={offset > 0 ? () => setOffset(Math.max(0, offset - PAGE_SIZE)) : undefined}
                        onNext={offset + PAGE_SIZE < total ? () => setOffset(offset + PAGE_SIZE) : undefined}
                        onZoomTo={(p) => mapRef.current?.fitToParcel(p)}
                    />
                </div>
                <div className="card" style={{ position: 'sticky', top: 'calc(var(--header-height) + 1rem)' }}>
                    <div className="card-header">
                        <span>Map Preview</span>
                        <span className="badge badge-gray">EPSG:4326</span>
                    </div>
                    <ParcelMap
                        ref={mapRef}
                        parcels={data?.data ?? []}
                        onViewportChange={setBbox}
                    />
                </div>
            </div>

            {showCreateModal && (
                <QuickCreateParcelModal
                    onClose={() => {
                        setShowCreateModal(false);
                        // Refresh the parcel list after a successful create
                        queryClient.invalidateQueries({ queryKey: ['parcels'] });
                    }}
                />
            )}
        </div>
    );
}

function ParcelTable(props: {
    data: Parcel[] | undefined;
    isLoading: boolean;
    error: unknown;
    currentPage: number;
    pages: number;
    onPrev?: () => void;
    onNext?: () => void;
    onZoomTo?: (p: Parcel) => void;
}) {
    const { data, isLoading, error, currentPage, pages, onPrev, onNext, onZoomTo } = props;

    return (
        <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Lot / Block</th>
                        <th>Title Ref</th>
                        <th>TD No.</th>
                        <th>Barangay</th>
                        <th>Survey Plan</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {error ? (
                        <tr>
                            <td colSpan={7} style={{ textAlign: 'center', padding: '2rem', color: 'var(--brand-danger)' }}>
                                Error loading parcels.
                            </td>
                        </tr>
                    ) : isLoading && !data ? (
                        <tr>
                            <td colSpan={7} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>
                                Loading parcels…
                            </td>
                        </tr>
                    ) : !data || data.length === 0 ? (
                        <tr>
                            <td colSpan={7} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>
                                No parcels found.
                            </td>
                        </tr>
                    ) : (
                        data.map((p) => (
                            <tr key={p.id} data-testid="parcel-row">
                                <td>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                                        <Link
                                            to={`/parcels/${p.id}/information`}
                                            style={{ textDecoration: 'none', color: 'var(--blue-600)', fontFamily: 'var(--font-mono)', fontSize: '0.8125rem', fontWeight: 500 }}
                                            data-testid={`parcel-open-${p.parcel_code}`}
                                        >
                                            {p.parcel_code}
                                            <span className="sr-only"> Open editor</span>
                                        </Link>
                                        {p.geometry && (
                                            <button
                                                className="btn btn-ghost btn-sm"
                                                style={{ padding: '0.125rem 0.25rem', height: 'auto', fontSize: '0.75rem' }}
                                                onClick={() => onZoomTo?.(p)}
                                                title="Zoom to parcel on map"
                                            >
                                                🔍
                                            </button>
                                        )}
                                    </div>
                                </td>
                                <td className="font-mono text-sm">
                                    {p.lot_number ?? '—'}
                                    {p.block_number ? <span className="text-muted"> / {p.block_number}</span> : null}
                                </td>
                                <td className="text-sm">{p.title_number_ref ?? '—'}</td>
                                <td className="text-sm">{p.tax_declaration_no ?? '—'}</td>
                                <td className="text-sm">{p.psgc_barangay_name ?? p.psgc_barangay ?? '—'}</td>
                                <td className="text-sm font-mono">{p.survey_plan_number ?? '—'}</td>
                                <td>
                                    <span className={`status-badge status-${p.status}`}>
                                        {p.status.replace(/_/g, ' ')}
                                    </span>
                                </td>
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
            {!error && data && data.length > 0 && (
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '0.625rem 1rem', borderTop: '1px solid var(--border-color)' }}>
                    <span className="text-sm text-muted">Page {currentPage} of {pages}</span>
                    <div className="flex gap-2">
                        <button className="btn btn-ghost btn-sm" disabled={!onPrev} onClick={onPrev}>← Prev</button>
                        <button className="btn btn-ghost btn-sm" disabled={!onNext} onClick={onNext}>Next →</button>
                    </div>
                </div>
            )}
        </div>
    );
}


import { forwardRef, useImperativeHandle } from 'react';

const ParcelMap = forwardRef<ParcelMapRef, {
    parcels: Parcel[];
    onViewportChange: (bbox: string | null) => void;
}>((props, ref) => {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const [mapReady, setMapReady] = useState(false);
    const { parcels, onViewportChange } = props;
    useBasemapToggle(mapRef.current, 'satellite');

    useImperativeHandle(ref, () => ({
        fitToParcel: (parcel: Parcel) => {
            const map = mapRef.current;
            if (!map || !parcel.geometry) return;
            const bounds = new maplibregl.LngLatBounds();
            const coords = parcel.geometry.type === 'Polygon'
                ? parcel.geometry.coordinates[0]
                : parcel.geometry.type === 'MultiPolygon'
                    ? parcel.geometry.coordinates[0][0]
                    : parcel.geometry.type === 'LineString'
                        ? parcel.geometry.coordinates
                        : null;
            if (coords) {
                for (const coord of coords) {
                    bounds.extend(coord as [number, number]);
                }
                if (!bounds.isEmpty()) {
                    map.fitBounds(bounds, { padding: 32, maxZoom: 18 });
                }
            }
        }
    }));

    // Initialize the map once (mirrors MapView / MapContext init pattern).
    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;
        const map = new maplibregl.Map({
            container: containerRef.current,
            style: 'https://demotiles.maplibre.org/style.json',
            center: ANGELES_CITY_CENTER,
            zoom: DEFAULT_MAP_ZOOM,
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        map.on('load', () => {
            map.addSource('parcels', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({ id: 'parcel-fills-layer', type: 'fill', source: 'parcels', paint: { 'fill-color': '#2563eb', 'fill-opacity': 0.45 } });
            map.addLayer({ id: 'parcel-lines-layer', type: 'line', source: 'parcels', paint: { 'line-color': '#1e40af', 'line-width': 2 } });
            setMapReady(true);
        });
        mapRef.current = map;
        // Publish the first viewport once the map settles.
        const pushBbox = () => {
            if (!mapRef.current) return;
            const b = mapRef.current.getBounds();
            onViewportChange(`${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`);
        };
        map.on('load', () => setTimeout(pushBbox, 200));
        map.on('moveend', pushBbox);
        return () => {
            mapRef.current?.remove();
            mapRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Reflect result geometries as GeoJSON on the source.
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !mapReady) return;
        if (!map.getSource('parcels')) return;
        const features = parcels
            .filter((p) => p.geometry)
            .map((p) => ({ type: 'Feature', id: p.id, properties: { parcel_code: p.parcel_code }, geometry: p.geometry }));
        (map.getSource('parcels') as maplibregl.GeoJSONSource).setData({
            type: 'FeatureCollection',
            features,
        } as GeoJSON.FeatureCollection);
    }, [parcels, mapReady]);

    return <div ref={containerRef} style={{ height: '460px' }} data-testid="parcel-map" data-map-ready={mapReady ? 'true' : 'false'} />;
});