/**
 * Client CRS registration (TASK-056).
 *
 * Registers the PRS92 / Luzon 1911 / Web Mercator zones from `/api/v1/crs`
 * with proj4 so coordinates can be displayed in a chosen CRS. The display CRS
 * is purely a client concern: geometry transmitted to the API is always
 * EPSG:4326 (CRS policy — never transform silently, never store transformed).
 */
import proj4 from 'proj4';
import apiClient from './apiClient';

export interface CrsEntry {
    srid: number;
    code: string;
    name: string;
    datum: string | null;
    zone: string | null;
    is_projected: boolean;
    is_historical: boolean;
}

/** Proj4 definitions for EPSG codes not present in proj4's builtin list. */
const FALLBACK_DEFS: Record<number, string> = {
    // PRS92 / Philippines reference system 1992, Transverse Mercator zones I-V
    3121: '+proj=tmerc +lat_0=0 +lon_0=117 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-1.53,-2.509,-1.192,4.464 +units=m +no_defs',
    3122: '+proj=tmerc +lat_0=0 +lon_0=119 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-1.53,-2.509,-1.192,4.464 +units=m +no_defs',
    3123: '+proj=tmerc +lat_0=0 +lon_0=121 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-1.53,-2.509,-1.192,4.464 +units=m +no_defs',
    3124: '+proj=tmerc +lat_0=0 +lon_0=123 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-1.53,-2.509,-1.192,4.464 +units=m +no_defs',
    3125: '+proj=tmerc +lat_0=0 +lon_0=125 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-1.53,-2.509,-1.192,4.464 +units=m +no_defs',
    // Luzon 1911 (historical) zones I-V
    25391: '+proj=tmerc +lat_0=0 +lon_0=117 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
    25392: '+proj=tmerc +lat_0=0 +lon_0=119 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
    25393: '+proj=tmerc +lat_0=0 +lon_0=121 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
    25394: '+proj=tmerc +lat_0=0 +lon_0=123 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
    25395: '+proj=tmerc +lat_0=0 +lon_0=125 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
};

const registry: CrsEntry[] = [];
const registered = new Set<number>();
let registrationPromise: Promise<void> | null = null;

function proj4DefFor(entry: CrsEntry): string | null {
    if (FALLBACK_DEFS[entry.srid]) return FALLBACK_DEFS[entry.srid];
    // proj4 has EPSG:3857/4326 built in; anything else we cannot derive here.
    return null;
}

/**
 * Fetch `/api/v1/crs` and register every known CRS with proj4.
 * Idempotent; the promise is cached so concurrent callers share one fetch.
 */
export async function registerCrsRegistry(): Promise<void> {
    if (registrationPromise) return registrationPromise;

    registrationPromise = (async () => {
        const res = await apiClient.get('/crs');
        const list: CrsEntry[] = res?.data ?? res ?? [];
        registry.length = 0;
        for (const entry of list) {
            registry.push(entry);
            if (registered.has(entry.srid)) continue;
            const def = proj4DefFor(entry);
            if (def) {
                proj4.defs(`EPSG:${entry.srid}`, def);
                registered.add(entry.srid);
            }
        }
        // Always make sure the two builtin display CRSes exist.
        if (!registered.has(4326)) {
            proj4.defs('EPSG:4326', '+proj=longlat +datum=WGS84 +no_defs');
            registered.add(4326);
        }
        if (!registered.has(3857)) {
            proj4.defs('EPSG:3857', '+proj=merc +a=6378137 +b=6378137 +lat_ts=0 +lon_0=0 +x_0=0 +y_0=0 +k=1 +units=m +no_defs');
            registered.add(3857);
        }
    })();

    return registrationPromise;
}

export function getCrsList(): readonly CrsEntry[] {
    return registry;
}

export function getCrs(srid: number): CrsEntry | undefined {
    return registry.find((c) => c.srid === srid);
}

/** Human label used by the CRS selector and the coordinate readout. */
export function crsLabel(srid: number): string {
    const entry = getCrs(srid);
    return entry ? entry.code : `EPSG:${srid}`;
}

/**
 * Convert a WGS84 lng/lat pair to the target CRS (display only).
 * Returns null when the CRS is not registered.
 */
export function projectFrom4326(
    lng: number,
    lat: number,
    srid: number,
): [number, number] | null {
    if (srid === 4326) return [lng, lat];
    if (!registered.has(srid)) return null;
    try {
        return proj4('EPSG:4326', `EPSG:${srid}`, [lng, lat]) as [number, number];
    } catch {
        return null;
    }
}
