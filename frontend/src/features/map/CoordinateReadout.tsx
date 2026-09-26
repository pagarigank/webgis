// TASK-056: CRS selector + coordinate readout with the CRS name attached.
// The display CRS is client-side only — geometry sent to the API stays 4326.
//
// The selected CRS is the app's *working* CRS: it drives this readout and the
// SRID used for measurement/analysis (see spatialApi), so the numbers on screen
// and the numbers in the readout always agree. It defaults to PRS92 /
// Philippines zone III (EPSG:3123) rather than a bare WGS84 readout, because
// cadastral work in the Philippines is done in PRS92.
import { useEffect, useMemo, useState, useSyncExternalStore } from 'react';
import { useMapContext } from './MapContext';
import {
    registerCrsRegistry,
    getCrsList,
    getCrsGroups,
    crsDisplayName,
    projectFrom4326,
    getWorkingSrid,
    setWorkingSrid,
    subscribeWorkingSrid,
    DEFAULT_WORKING_SRID,
} from '../../lib/crs';

/** SRIDs offered before the registry finishes loading. */
const QUICK_OPTS = [3123, 3121, 3122, 3124, 3125, 4326, 3857];

export function CoordinateReadout() {
    const map = useMapContext().map;
    const [lngLat, setLngLat] = useState<{ lng: number; lat: number } | null>(null);
    const srid = useSyncExternalStore(subscribeWorkingSrid, getWorkingSrid, getWorkingSrid);
    const [registryReady, setRegistryReady] = useState(false);

    // Register the CRS registry (idempotent; one fetch shared by all callers).
    useEffect(() => {
        let cancelled = false;
        registerCrsRegistry()
            .then(() => {
                if (!cancelled) setRegistryReady(true);
            })
            .catch((err) => console.warn('[CoordinateReadout] CRS registry failed:', err));
        return () => {
            cancelled = true;
        };
    }, []);

    // Live readout on mousemove.
    useEffect(() => {
        if (!map) return;
        const onMove = (e: { lngLat: { lng: number; lat: number } }) =>
            setLngLat({ lng: e.lngLat.lng, lat: e.lngLat.lat });
        map.on('mousemove', onMove);
        return () => {
            map.off('mousemove', onMove);
        };
    }, [map]);

    // Rendered options: PRS92 zones first, then WGS 84, then the historical
    // Luzon 1911 zones, so the default family leads and a superseded datum is
    // never the first thing in the list.
    const groups = useMemo(() => {
        const list = registryReady ? getCrsList() : [];
        const srids = [...new Set([...QUICK_OPTS, ...list.map((c) => c.srid), srid])];
        return getCrsGroups(srids);
    }, [registryReady, srid]);

    const display = useMemo(() => {
        if (!lngLat) return '—';
        if (srid === 4326) return `${lngLat.lat.toFixed(6)}, ${lngLat.lng.toFixed(6)}`;
        if (!registryReady) return `${lngLat.lat.toFixed(6)}, ${lngLat.lng.toFixed(6)}`;
        const proj = projectFrom4326(lngLat.lng, lngLat.lat, srid);
        if (!proj) return `${lngLat.lat.toFixed(6)}, ${lngLat.lng.toFixed(6)}`;
        return `E ${proj[0].toFixed(2)}, N ${proj[1].toFixed(2)}`;
    }, [lngLat, srid, registryReady]);

    return (
        <div
            data-testid="coordinate-readout"
            style={{
                position: 'absolute',
                bottom: 10,
                left: 10,
                zIndex: 5,
                background: 'rgba(0,0,0,0.75)',
                color: '#fff',
                padding: '4px 10px',
                borderRadius: 6,
                fontSize: 12,
                fontFamily: 'monospace',
                display: 'flex',
                gap: 10,
                alignItems: 'center',
                pointerEvents: 'auto',
            }}
        >
            <span data-testid="readout-value">{display}</span>
            <span style={{ color: '#9ca3af' }}>|</span>
            <label
                htmlFor="crs-selector"
                style={{ color: '#9ca3af', fontSize: 11, fontFamily: 'system-ui, sans-serif' }}
            >
                CRS
            </label>
            <select
                id="crs-selector"
                aria-label="Coordinate reference system"
                data-testid="crs-selector"
                title={`Working coordinate system: ${crsDisplayName(srid)}`}
                value={srid}
                onChange={(e) => setWorkingSrid(Number(e.target.value))}
                style={{
                    background: 'transparent',
                    color: '#fff',
                    border: '1px solid rgba(255,255,255,0.3)',
                    borderRadius: 4,
                    fontSize: 11,
                    padding: '2px 4px',
                    maxWidth: 260,
                }}
            >
                {groups.map((group) => (
                    <optgroup key={group.label} label={group.label}>
                        {group.entries.map((entry) => (
                            <option
                                key={entry.srid}
                                value={entry.srid}
                                style={{ color: '#111' }}
                            >
                                {entry.name}
                                {entry.srid === DEFAULT_WORKING_SRID ? ' (default)' : ''}
                            </option>
                        ))}
                    </optgroup>
                ))}
            </select>
        </div>
    );
}
