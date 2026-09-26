// @ts-nocheck
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useMapContext } from './MapContext';
import { parcelApi } from '../parcels/api/parcelApi';
import { ParcelActionMenu } from '../parcels/components/ParcelActionMenu';
import { useParcelSelection } from '../parcels/ParcelSelectionContext';
import type { ParcelHit } from '../parcels/types';

/**
 * TASK-104b — the parcel overlay and click resolver.
 *
 * The map had no parcel layer at all: `/spatial/identify` reads app.gis_features,
 * which is not joined to app.parcels, so clicking a cadastral parcel returned
 * nothing useful. This component draws the parcels that intersect the viewport
 * from GET /parcels/overlay and resolves a click through GET /parcels/locate,
 * then hands the hits to ParcelActionMenu.
 *
 * The overlay is driven by its own fetch rather than layerManager because
 * parcels are an application dataset governed by data-scope rules, not a
 * published map layer. It intentionally does not follow layer visibility — a
 * user who has hidden every published layer can still work with parcels.
 */

const SOURCE_ID = 'parcel-overlay';
const FILL_LAYER = 'parcel-overlay-fill';
const LINE_LAYER = 'parcel-overlay-line';
const SELECTED_LAYER = 'parcel-overlay-selected';

const EMPTY: GeoJSON.FeatureCollection = { type: 'FeatureCollection', features: [] };

/** Mirrors the backend's MAX_BBOX_DEG guard on GET /parcels/overlay. */
const MAX_BBOX_DEG = 5;

/**
 * Statuses are matched by name so an unknown status still renders (blue
 * default). The keys are the values allowed by ck_parcel_status:
 * DRAFT, SUBMITTED, UNDER_REVIEW, RETURNED, VERIFIED, APPROVED, PUBLISHED,
 * ARCHIVED, SUPERSEDED. A retired parcel must not read as live, so PUBLISHED
 * and APPROVED are the only greens.
 */
const fillColor: maplibregl.ExpressionSpecification = [
    'match',
    ['get', 'status'],
    'PUBLISHED', '#2f9e44',
    'APPROVED', '#66a80f',
    'VERIFIED', '#94d82d',
    'SUBMITTED', '#f59f00',
    'UNDER_REVIEW', '#f76707',
    'RETURNED', '#e8590c',
    'DRAFT', '#ff0000', // Temporarily red for testing visibility
    'ARCHIVED', '#adb5bd',
    'SUPERSEDED', '#868e96',
    '#4c6ef5',
];

export const ParcelOverlay: React.FC = () => {
    const { map, isLoaded } = useMapContext();
    const { selection } = useParcelSelection();
    const [hits, setHits] = useState<ParcelHit[]>([]);
    const [activeId, setActiveId] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    // A newer viewport supersedes the in-flight request.
    const inflightRef = useRef<AbortController | null>(null);
    const moveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const addLayers = useCallback((m: maplibregl.Map) => {
        if (m.getLayer(FILL_LAYER)) return;
        m.addSource(SOURCE_ID, { type: 'geojson', data: EMPTY });
        m.addLayer({
            id: FILL_LAYER,
            type: 'fill',
            source: SOURCE_ID,
            paint: { 'fill-color': fillColor, 'fill-opacity': 0.25 },
        });
        m.addLayer({
            id: LINE_LAYER,
            type: 'line',
            source: SOURCE_ID,
            paint: { 'line-color': '#1c3faa', 'line-width': 1.5 },
        });
        // Picked parents get a heavier stroke so a map-driven multi-select is
        // legible on the very layer the user is picking from.
        m.addLayer({
            id: SELECTED_LAYER,
            type: 'line',
            source: SOURCE_ID,
            filter: ['in', ['get', 'id'], ['literal', []]],
            paint: { 'line-color': '#f76707', 'line-width': 3 },
        });
    }, []);

    // ── overlay data, refetched on moveend (debounced) ──────────────────
    useEffect(() => {
        if (!map || !isLoaded) return;
        addLayers(map);

        const load = async () => {
            const b = map.getBounds();
            const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()];

            // The backend rejects envelopes wider/taller than 5 degrees as an
            // unbounded-query guard. The default map view is zoom 5 (the whole
            // archipelago, tens of degrees across), so without this guard every
            // /map load fired a guaranteed 400 and showed the error banner for
            // a viewport where individual parcels are sub-pixel anyway. Skip the
            // request and clear instead of asking for something that must fail.
            if (bbox[2] - bbox[0] >= MAX_BBOX_DEG || bbox[3] - bbox[1] >= MAX_BBOX_DEG) {
                inflightRef.current?.abort();
                (map.getSource(SOURCE_ID) as maplibregl.GeoJSONSource).setData(EMPTY as any);
                setError(null);
                return;
            }

            inflightRef.current?.abort();
            const controller = new AbortController();
            inflightRef.current = controller;
            try {
                const fc = await parcelApi.overlay(bbox);
                if (controller.signal.aborted) return;
                (map.getSource(SOURCE_ID) as maplibregl.GeoJSONSource).setData(fc as any);
                setError(null);
            } catch (err: any) {
                if (controller.signal.aborted || err?.name === 'AbortError' || err?.name === 'CanceledError') return;
                // A failed overlay must not break the rest of the map; the
                // previous geometry simply stays on screen.
                setError('Parcel overlay could not be loaded.');
            }
        };

        const onMoveEnd = () => {
            if (moveTimerRef.current) clearTimeout(moveTimerRef.current);
            moveTimerRef.current = setTimeout(() => void load(), 300);
        };

        void load();
        map.on('moveend', onMoveEnd);

        return () => {
            map.off('moveend', onMoveEnd);
            if (moveTimerRef.current) clearTimeout(moveTimerRef.current);
            inflightRef.current?.abort();
        };
    }, [map, isLoaded, addLayers]);

    // ── click → GET /parcels/locate ─────────────────────────────────────
    useEffect(() => {
        if (!map || !isLoaded) return;

        const onClick = async (e: maplibregl.MapMouseEvent) => {
            try {
                const res = await parcelApi.locate(e.lngLat.lng, e.lngLat.lat);
                setHits(res.parcels);
                setActiveId(res.parcels[0]?.id ?? null);
            } catch {
                setHits([]);
                setActiveId(null);
            }
        };

        map.on('click', onClick);
        return () => { map.off('click', onClick); };
    }, [map, isLoaded]);

    // ── reflect the shared selection in the selected-parent stroke ──────
    useEffect(() => {
        if (!map || !isLoaded || !map.getLayer(SELECTED_LAYER)) return;
        map.setFilter(SELECTED_LAYER, ['in', ['get', 'id'], ['literal', selection.map((p) => p.id)]]);
    }, [map, isLoaded, selection]);

    return (
        <>
            {error && (
                <div
                    data-testid="parcel-overlay-error"
                    role="status"
                    className="text-danger text-sm"
                    style={{ position: 'absolute', bottom: 40, left: 40, zIndex: 1000 }}
                >
                    {error}
                </div>
            )}
            {hits.length > 0 && (
                <ParcelActionMenu
                    hits={hits}
                    activeId={activeId}
                    onSelectHit={setActiveId}
                    onClose={() => { setHits([]); setActiveId(null); }}
                />
            )}
        </>
    );
};
