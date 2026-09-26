/**
 * Client CRS registration (TASK-056).
 *
 * Registers the PRS92 / Luzon 1911 / Web Mercator zones from `/api/v1/crs`
 * with proj4 so coordinates can be displayed in a chosen CRS, plus the local
 * PPCS (cadastre schedules) zones used by BLLM tie points. The display CRS
 * is purely a client concern: geometry transmitted to the API is always
 * EPSG:4326 (CRS policy — never transform silently, never store transformed).
 *
 * The *working* CRS (the "core PCS") is the projected system used for
 * measurement, analysis, and the coordinate readout. It is chosen by the user
 * in the map CRS selector and defaults to PRS92.
 */
import proj4 from 'proj4';
import apiClient from './apiClient';

/**
 * Default working CRS: PRS92 / Philippines zone III (EPSG:3123).
 *
 * PRS92 is the Philippines' national modern datum, and the Philippine
 * Transverse Mercator grid is split into 2-degree zones with central meridians
 * at 117E, 119E, 121E, 123E and 125E (zones I-V). A zone is only accurate near
 * its own central meridian, so "PRS92" alone is not a usable answer - the zone
 * matters, and picking the wrong one silently inflates every distance and area.
 *
 * Zone III is the correct choice for the app's home area: it is the zone used
 * for Metro Manila, Bulacan, Pampanga, Tarlac and Nueva Ecija, and it puts
 * Angeles City only ~43 km off its central meridian. Measured distortion there
 * is a few parts per million, versus hundreds of km off-meridian for the other
 * zones. Users working outside Central Luzon should pick their own zone from
 * the selector.
 */
export const DEFAULT_WORKING_SRID = 3123;

/** Home view: Angeles City, Pampanga (the app's default map centre). */
export const ANGELES_CITY_CENTER: [number, number] = [120.5954, 15.1453];

/** City-scale zoom: shows Angeles City plus the surrounding Pampanga plain. */
export const DEFAULT_MAP_ZOOM = 12.5;

export interface CrsEntry {
    srid: number;
    code: string;
    name: string;
    datum: string | null;
    zone: string | null;
    is_projected: boolean;
    is_historical: boolean;
}

/**
 * Proj4 definitions for codes proj4's builtin list does not cover.
 *
 * These MUST stay byte-identical to the `proj4text` of the matching row in
 * `spatial_ref_sys`. The client transforms coordinates for the readout and for
 * client-side measurement while the backend transforms through PostGIS
 * `ST_Transform`; if the two definitions drift, the same point is displayed at
 * two different places. A previous revision of this file carried PRS92
 * rotation parameters of -1.53/-2.509/-1.192/4.464 against the registry's
 * EPSG:6683 values of -3.068/4.903/1.578/-1.06, which put EPSG:3123 about 103 m
 * away from the backend; the Luzon 1911 entries were missing their towgs84
 * entirely.
 */
const FALLBACK_DEFS: Record<number, string> = {
    // PRS92 / Philippines reference system 1992, Transverse Mercator zones I-V
    3121: '+proj=tmerc +lat_0=0 +lon_0=117 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-3.068,4.903,1.578,-1.06 +units=m +no_defs',
    3122: '+proj=tmerc +lat_0=0 +lon_0=119 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-3.068,4.903,1.578,-1.06 +units=m +no_defs',
    3123: '+proj=tmerc +lat_0=0 +lon_0=121 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-3.068,4.903,1.578,-1.06 +units=m +no_defs',
    3124: '+proj=tmerc +lat_0=0 +lon_0=123 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-3.068,4.903,1.578,-1.06 +units=m +no_defs',
    3125: '+proj=tmerc +lat_0=0 +lon_0=125 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-127.62,-67.24,-47.04,-3.068,4.903,1.578,-1.06 +units=m +no_defs',
    // Luzon 1911 (historical) zones I-V
    25391: '+proj=tmerc +lat_0=0 +lon_0=117 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-133,-77,-51,0,0,0,0 +units=m +no_defs',
    25392: '+proj=tmerc +lat_0=0 +lon_0=119 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-133,-77,-51,0,0,0,0 +units=m +no_defs',
    25393: '+proj=tmerc +lat_0=0 +lon_0=121 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-133,-77,-51,0,0,0,0 +units=m +no_defs',
    25394: '+proj=tmerc +lat_0=0 +lon_0=123 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-133,-77,-51,0,0,0,0 +units=m +no_defs',
    25395: '+proj=tmerc +lat_0=0 +lon_0=125 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +towgs84=-133,-77,-51,0,0,0,0 +units=m +no_defs',
    // PPCS (Philippine Plane Coordinate System) zones I-V, Clarke 1866, no datum shift.
    // The published geographic positions in PPCS schedules are in the local
    // Clarke 1866 datum, so these carry no towgs84 on purpose. Do not add
    // TOWGS84[0,0,0,0,0,0,0] to the registry WKT: PROJ discards that degenerate
    // set and applies a real transformation, shifting northings by ~117 m.
    990101: '+proj=tmerc +lat_0=0 +lon_0=117 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
    990102: '+proj=tmerc +lat_0=0 +lon_0=119 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
    990103: '+proj=tmerc +lat_0=0 +lon_0=121 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
    990104: '+proj=tmerc +lat_0=0 +lon_0=123 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
    990105: '+proj=tmerc +lat_0=0 +lon_0=125 +k=0.99995 +x_0=500000 +y_0=0 +ellps=clrk66 +units=m +no_defs',
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
 * Human label for the CRS selector and the readout, e.g.
 * "PRS92 / Philippines zone III (EPSG:3123)".
 *
 * Falls back to the bare code for anything not in the registry, and names the
 * built-ins that proj4 knows but `/crs` may not list, so an option is never
 * rendered as a bare number.
 */
export function crsDisplayName(srid: number): string {
    const entry = getCrs(srid);
    if (entry) return `${entry.name} (${entry.code})`;
    const builtin = BUILTIN_NAMES[srid];
    return builtin ? `${builtin} (EPSG:${srid})` : `EPSG:${srid}`;
}

const BUILTIN_NAMES: Record<number, string> = {
    4326: 'WGS 84 (geographic)',
    3857: 'WGS 84 / Pseudo-Mercator',
};

/**
 * Datum families, ordered for display. PRS92 leads because it is the national
 * datum and the app default; PPCS follows because published cadastre schedules
 * are expressed in it; historical datums are grouped last and flagged so a
 * surveyor does not pick one by accident.
 */
export interface CrsGroup {
    label: string;
    entries: CrsEntry[];
}

const PPCS_DATUM_PREFIX = 'PPCS';

export function getCrsGroups(srids?: readonly number[]): CrsGroup[] {
    const pool = srids?.length ? srids.map((s) => getCrs(s)).filter((c): c is CrsEntry => !!c) : [...registry];
    const groups: CrsGroup[] = [
        { label: 'PRS92 (default)', entries: [] },
        { label: 'PPCS (cadastre schedules)', entries: [] },
        { label: 'WGS 84', entries: [] },
        { label: 'Luzon 1911 (historical)', entries: [] },
    ];
    for (const entry of pool) {
        if (entry.datum === 'PRS92') groups[0].entries.push(entry);
        else if (entry.datum?.startsWith(PPCS_DATUM_PREFIX)) groups[1].entries.push(entry);
        else if (entry.is_historical) groups[3].entries.push(entry);
        else groups[2].entries.push(entry);
    }
    return groups.filter((g) => g.entries.length > 0);
}

// ---------------------------------------------------------------------------
// Working CRS store
// ---------------------------------------------------------------------------

const STORAGE_KEY = 'webgis.working_crs';
/**
 * The pre-PRS92 default. A session that stored exactly this value never made a
 * deliberate choice - it just inherited the old hardcoded 4326 - so it is
 * treated as "unset" and migrated to PRS92. Any other stored value is an
 * explicit user choice and is preserved.
 */
const LEGACY_DEFAULT_SRID = 4326;

function readStoredSrid(): number {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw === null) return DEFAULT_WORKING_SRID;
        const parsed = Number(raw);
        if (!Number.isFinite(parsed) || parsed <= 0) return DEFAULT_WORKING_SRID;
        if (parsed === LEGACY_DEFAULT_SRID) return DEFAULT_WORKING_SRID;
        return parsed;
    } catch {
        // Private mode / disabled storage: fall back to the default.
        return DEFAULT_WORKING_SRID;
    }
}

let workingSrid: number = readStoredSrid();
const listeners = new Set<() => void>();

/** The projected CRS used for measurement, analysis, and the readout. */
export function getWorkingSrid(): number {
    return workingSrid;
}

/**
 * Change the working CRS. Persisted, and notified to every subscriber so the
 * readout and the measurement calls stay in agreement.
 */
export function setWorkingSrid(srid: number): void {
    if (!Number.isFinite(srid) || srid <= 0 || srid === workingSrid) return;
    workingSrid = srid;
    try {
        localStorage.setItem(STORAGE_KEY, String(srid));
    } catch {
        // Non-fatal: the selection still applies for this session.
    }
    for (const notify of listeners) notify();
}

export function subscribeWorkingSrid(listener: () => void): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

/** Test seam: reset to the default and clear any persisted choice. */
export function resetWorkingSridForTests(): void {
    workingSrid = DEFAULT_WORKING_SRID;
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
        /* ignore */
    }
    for (const notify of listeners) notify();
}

/** Geographic CRSes recognised without a registry lookup. */
const KNOWN_GEOGRAPHIC_SRIDS = new Set([4326, 4269, 4277]);

/**
 * Whether a CRS is projected, i.e. usable for measurement.
 *
 * Prefers the registry's own `is_projected` flag and falls back to a small
 * known-geographic set so the answer is still correct before `/crs` loads.
 */
export function isProjectedSrid(srid: number): boolean {
    const entry = getCrs(srid);
    if (entry) return entry.is_projected;
    return !KNOWN_GEOGRAPHIC_SRIDS.has(srid);
}

/**
 * The CRS measurement is actually computed in.
 *
 * A geographic working CRS is fine for the readout but unusable for
 * measurement: the API computes `ST_Length`/`ST_Area` in the CRS it is given
 * and reports the result as `unit: 'm'`. Handed EPSG:4326 it would return
 * *degrees* under a metre label, so a geographic selection falls back to the
 * PRS92 default instead of silently producing numbers that are off by a
 * factor of ~111,000.
 */
export function getMeasurementSrid(): number {
    return isProjectedSrid(workingSrid) ? workingSrid : DEFAULT_WORKING_SRID;
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
