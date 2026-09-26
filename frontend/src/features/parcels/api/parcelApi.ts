import apiClient from '../../../lib/apiClient';
import type {
    Parcel,
    ParcelListParams,
    ParcelListPayload,
    ParcelPatch,
    ParcelVersionsPayload,
    ParcelCreateInput,
    ParcelLocatePayload,
    ParcelOverlayCollection,
    ParcelVersionDetail,
    ParcelComparePayload,
    ParcelTimelinePayload,
} from '../types';

/**
 * TASK-070 — parcel list/search. The backend list endpoint returns an inner
 * `{ data, total, limit, offset, sort, dir, include_historical }` payload
 * (see ParcelController::list). Keyword `q` searches lot, block, survey plan
 * number, title, tax declaration, parcel code, location, and barangay name.
 */
export const parcelApi = {
    list: async (params?: ParcelListParams): Promise<ParcelListPayload> => {
        return (await apiClient.get('/parcels', { params })) as ParcelListPayload;
    },

    getById: async (id: string): Promise<Parcel> => {
        return (await apiClient.get(`/parcels/${id}`)) as Parcel;
    },

    /** TASK-072 — create a parcel (draw → attributes → Save draft). 201 on success. */
    create: async (input: ParcelCreateInput): Promise<Parcel> => {
        return (await apiClient.post('/parcels', input)) as Parcel;
    },

    /** PATCH semantics require If-Match with the current parcel version (428/409). */
    update: async (id: string, patch: ParcelPatch, version: number): Promise<Parcel> => {
        return (await apiClient.patch(`/parcels/${id}`, patch, {
            headers: { 'If-Match': String(version) },
        })) as Parcel;
    },

    /** Append-only lineage index (TASK-069), newest first. */
    listVersions: async (id: string, page = 1, perPage = 20): Promise<ParcelVersionsPayload> => {
        return (await apiClient.get(`/parcels/${id}/versions`, {
            params: { page, per_page: perPage },
        })) as ParcelVersionsPayload;
    },

    /**
     * TASK-104b - resolve a map click to real parcels. The generic GIS identify
     * tool answers against app.gis_features, which has no relationship to
     * app.parcels, so a parcel is not identifiable through it. Containing
     * parcels are returned first, then the nearest within `toleranceM`.
     *
     * `lng`/`lat` are the click position, which MapLibre always reports in
     * EPSG:4326, so that is also the default input `srid`. Defaulting to 32651
     * here (as spatialApi.identify does for projected input) made the backend
     * reinterpret a latitude of ~14.5 as a UTM northing in metres and resolve
     * the click to empty water off West Africa, so the menu never opened.
     */
    locate: async (lng: number, lat: number, options?: { srid?: number; toleranceM?: number; limit?: number }): Promise<ParcelLocatePayload> => {
        return (await apiClient.get('/parcels/locate', {
            params: {
                lng,
                lat,
                srid: options?.srid ?? 4326,
                tolerance_m: options?.toleranceM,
                limit: options?.limit ?? 10,
            },
        })) as ParcelLocatePayload;
    },

    /**
     * TASK-104b - GeoJSON for the parcels intersecting a viewport, used to draw
     * the parcel overlay. `bbox` is [west, south, east, north] in EPSG:4326 and
     * the backend rejects envelopes wider than 5 degrees.
     */
    overlay: async (bbox: [number, number, number, number], limit = 500): Promise<ParcelOverlayCollection> => {
        return (await apiClient.get('/parcels/overlay', {
            params: { bbox: bbox.join(','), limit },
        })) as ParcelOverlayCollection;
    },

    /**
     * TASK-104a - one recorded version with its attribute snapshot and the
     * geometry as it stood at that version.
     */
    getVersion: async (id: string, version: number): Promise<ParcelVersionDetail> => {
        return (await apiClient.get(`/parcels/${id}/versions/${version}`)) as ParcelVersionDetail;
    },

    /**
     * TASK-104a - diff a version against another one. `against` defaults to the
     * parcel's current version server-side, so comparing with the live state
     * needs no extra argument.
     */
    compareVersions: async (id: string, version: number, against?: number): Promise<ParcelComparePayload> => {
        return (await apiClient.get(`/parcels/${id}/versions/${version}/compare`, {
            params: against != null ? { against } : undefined,
        })) as ParcelComparePayload;
    },

    /**
     * TASK-104a - restore a recorded version. This does NOT roll the parcel
     * back to the old version number: the server re-applies the stored key
     * attributes onto the live row and records a NEW version, so lineage stays
     * append-only. Requires parcel.version.restore.
     */
    restoreVersion: async (id: string, version: number, reason: string): Promise<Parcel> => {
        return (await apiClient.post(`/parcels/${id}/versions/${version}/restore`, {
            reason: reason.trim(),
        })) as Parcel;
    },

    /** TASK-104a - merged version/workflow/audit timeline, oldest first. */
    timeline: async (id: string): Promise<ParcelTimelinePayload> => {
        return (await apiClient.get(`/parcels/${id}/timeline`)) as ParcelTimelinePayload;
    },

    /** Direct link for the browser; the endpoint streams a file attachment. */
    timelineExportUrl: (id: string) => `/api/v1/parcels/${id}/timeline/export`,
};