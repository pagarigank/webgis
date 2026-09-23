import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useBasemapToggle } from '../../map/basemap';
import { parcelApi } from '../api/parcelApi';
import type { Parcel, ParcelListParams } from '../types';

const STATUS_OPTIONS = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'RETURNED', 'VERIFIED', 'APPROVED', 'PUBLISHED', 'ARCHIVED', 'SUPERSEDED'];
const PAGE_SIZE = 10;

/**
 * TASK-070 — parcel list page with keyword/status/historical filters, a
 * paginated results table, and a map preview. Panning/zooming the preview
 * with "Only in map view" enabled narrows results to the visible bbox.
 */
export function ParcelListPage() {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [includeHistorical, setIncludeHistorical] = useState(false);
    const [restrictToMap, setRestrictToMap] = useState(false);
    const [bbox, setBbox] = useState<string | null>(null);
    const [offset, setOffset] = useState(0);

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
        <div className="container-fluid py-4">
            <div className="d-flex justify-content-between align-items-center mb-4">
                <h2 className="mb-0">Parcels</h2>
                <div className="d-flex gap-3 align-items-center">
                    <div className="text-muted small">
                        {total} result{total === 1 ? '' : 's'}
                        {restrictToMap && bbox ? ' · limited to map view' : ''}
                    </div>
                    <Link to="/parcels/new" className="btn btn-primary btn-sm" data-testid="parcel-create-btn">
                        + New parcel
                    </Link>
                </div>
            </div>

            <div className="card shadow-sm mb-3">
                <div className="card-body">
                    <div className="row g-2 align-items-end">
                        <div className="col-md-4">
                            <label className="form-label small text-muted mb-1" htmlFor="parcel-q">Search</label>
                            <input
                                id="parcel-q"
                                type="text"
                                className="form-control"
                                placeholder="Lot, block, plan, TCT, TD, code, location, barangay…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                data-testid="parcel-search"
                            />
                        </div>
                        <div className="col-md-2">
                            <label className="form-label small text-muted mb-1" htmlFor="parcel-status">Status</label>
                            <select
                                id="parcel-status"
                                className="form-select"
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                data-testid="parcel-status-filter"
                            >
                                <option value="">All statuses</option>
                                {STATUS_OPTIONS.map((s) => (
                                    <option key={s} value={s}>{s}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-3 d-flex flex-column">
                            <span className="small text-muted mb-1">&nbsp;</span>
                            <div className="d-flex gap-3">
                                <div className="form-check">
                                    <input
                                        className="form-check-input"
                                        type="checkbox"
                                        id="parcel-include-historical"
                                        checked={includeHistorical}
                                        onChange={(e) => setIncludeHistorical(e.target.checked)}
                                        data-testid="parcel-include-historical"
                                    />
                                    <label className="form-check-label small" htmlFor="parcel-include-historical">
                                        Include historical
                                    </label>
                                </div>
                                <div className="form-check">
                                    <input
                                        className="form-check-input"
                                        type="checkbox"
                                        id="parcel-restrict-map"
                                        checked={restrictToMap}
                                        onChange={(e) => setRestrictToMap(e.target.checked)}
                                        data-testid="parcel-restrict-map"
                                    />
                                    <label className="form-check-label small" htmlFor="parcel-restrict-map">
                                        Only in map view
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div className="col-md-3 text-md-end">
                            <button className="btn btn-primary" onClick={applyFilters} data-testid="parcel-search-btn">Apply</button>
                            <button className="btn btn-outline-secondary ms-2" onClick={resetFilters}>Reset</button>
                        </div>
                    </div>
                </div>
            </div>

            <div className="row g-3">
                <div className="col-lg-8">
                    <div className="card shadow-sm">
                        <ParcelTable
                            data={data?.data}
                            isLoading={isLoading || isFetching}
                            error={error}
                            currentPage={page}
                            pages={pages}
                            onPrev={offset > 0 ? () => setOffset(Math.max(0, offset - PAGE_SIZE)) : undefined}
                            onNext={offset + PAGE_SIZE < total ? () => setOffset(offset + PAGE_SIZE) : undefined}
                        />
                    </div>
                </div>
                <div className="col-lg-4">
                    <div className="card shadow-sm">
                        <div className="card-header d-flex justify-content-between align-items-center">
                            <span className="fw-semibold">Map Preview</span>
                            <span className="badge bg-secondary">EPSG:4326</span>
                        </div>
                        <ParcelMap
                            parcels={data?.data ?? []}
                            onViewportChange={setBbox}
                        />
                    </div>
                </div>
            </div>
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
}) {
    const { data, isLoading, error, currentPage, pages, onPrev, onNext } = props;

    return (
        <div className="table-responsive">
            <table className="table table-hover mb-0 align-middle">
                <thead className="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Lot / Block</th>
                        <th>Title</th>
                        <th>TD</th>
                        <th>Barangay</th>
                        <th>Survey Plan</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {error ? (
                        <tr>
                            <td colSpan={7} className="text-center py-4 text-danger">Error loading parcels.</td>
                        </tr>
                    ) : isLoading && !data ? (
                        <tr>
                            <td colSpan={7} className="text-center py-4 text-muted">Loading parcels…</td>
                        </tr>
                    ) : !data || data.length === 0 ? (
                        <tr>
                            <td colSpan={7} className="text-center py-4 text-muted">No parcels found.</td>
                        </tr>
                    ) : (
                        data.map((p) => (
                            <tr key={p.id} data-testid="parcel-row">
                                <td>
                                    <Link
                                        to={`/parcels/${p.id}/information`}
                                        className="text-decoration-none"
                                        data-testid={`parcel-open-${p.parcel_code}`}
                                    >
                                        <code>{p.parcel_code}</code>
                                        <span className="visually-hidden"> Open editor</span>
                                    </Link>
                                </td>
                                <td>
                                    {p.lot_number ?? '—'}
                                    {p.block_number ? <span className="text-muted small"> / {p.block_number}</span> : null}
                                </td>
                                <td>{p.title_number_ref ?? '—'}</td>
                                <td>{p.tax_declaration_no ?? '—'}</td>
                                <td>{p.psgc_barangay_name ?? p.psgc_barangay ?? '—'}</td>
                                <td>{p.survey_plan_number ?? '—'}</td>
                                <td><span className="badge bg-secondary">{p.status}</span></td>
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
            {!error && data && data.length > 0 && (
                <div className="d-flex justify-content-between align-items-center p-2 border-top">
                    <span className="small text-muted">Page {currentPage} of {pages}</span>
                    <div>
                        <button className="btn btn-sm btn-outline-secondary" disabled={!onPrev} onClick={onPrev}>Prev</button>
                        <button className="btn btn-sm btn-outline-secondary ms-2" disabled={!onNext} onClick={onNext}>Next</button>
                    </div>
                </div>
            )}
        </div>
    );
}

function ParcelMap(props: {
    parcels: Parcel[];
    onViewportChange: (bbox: string | null) => void;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const [mapReady, setMapReady] = useState(false);
    const { parcels, onViewportChange } = props;
    useBasemapToggle(mapRef.current, 'satellite');

    // Initialize the map once (mirrors MapView / MapContext init pattern).
    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;
        const map = new maplibregl.Map({
            container: containerRef.current,
            style: 'https://demotiles.maplibre.org/style.json',
            center: [121, 14.5],
            zoom: 5,
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
        if (!map) return;
        if (!map.getSource('parcels')) return;
        const features = parcels
            .filter((p) => p.geometry)
            .map((p) => ({ type: 'Feature', id: p.id, properties: { parcel_code: p.parcel_code }, geometry: p.geometry }));
        (map.getSource('parcels') as maplibregl.GeoJSONSource).setData({
            type: 'FeatureCollection',
            features,
        } as GeoJSON.FeatureCollection);
    }, [parcels]);

    return <div ref={containerRef} style={{ height: '460px' }} data-testid="parcel-map" />;
}