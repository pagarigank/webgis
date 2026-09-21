// TASK-056: CRS selector + coordinate readout with the CRS name attached.
// The display CRS is client-side only — geometry sent to the API stays 4326.
import { useEffect, useMemo, useState } from 'react';
import { useMapContext } from './MapContext';
import {
    registerCrsRegistry,
    getCrsList,
    crsLabel,
    projectFrom4326,
} from '../../lib/crs';

const STORAGE_KEY = 'webgis.display_crs';

/** Default display CRS list offered before the registry loads. */
const QUICK_OPTS = [4326, 3123, 3857];

export function CoordinateReadout() {
    const map = useMapContext().map;
    const [lngLat, setLngLat] = useState<{ lng: number; lat: number } | null>(null);
    const [srid, setSrid] = useState<number>(() => {
        const saved = Number(localStorage.getItem(STORAGE_KEY));
        return Number.isFinite(saved) && saved > 0 ? saved : 4326;
    });
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

    useEffect(() => {
        localStorage.setItem(STORAGE_KEY, String(srid));
    }, [srid]);

    const options = useMemo(() => {
        const list = registryReady ? getCrsList() : [];
        const srids = new Set<number>([...QUICK_OPTS, ...list.map((c) => c.srid), srid]);
        return [...srids].sort((a, b) => a - b);
    }, [registryReady, srid]);

    const display = useMemo(() => {
        if (!lngLat) return '—';
        const proj = projectFrom4326(lngLat.lng, lngLat.lat, srid);
        if (!proj) return `${lngLat.lat.toFixed(6)}, ${lngLat.lng.toFixed(6)}`;
        if (srid === 4326) return `${lngLat.lat.toFixed(6)}, ${lngLat.lng.toFixed(6)}`;
        return `E ${proj[0].toFixed(2)}, N ${proj[1].toFixed(2)}`;
    }, [lngLat, srid]);

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
            <select
                aria-label="Display CRS"
                data-testid="crs-selector"
                value={srid}
                onChange={(e) => setSrid(Number(e.target.value))}
                style={{
                    background: 'transparent',
                    color: '#fff',
                    border: '1px solid rgba(255,255,255,0.3)',
                    borderRadius: 4,
                    fontSize: 11,
                    padding: '2px 4px',
                }}
            >
                {options.map((s) => (
                    <option key={s} value={s} style={{ color: '#111' }}>
                        {crsLabel(s)}
                    </option>
                ))}
            </select>
        </div>
    );
}
