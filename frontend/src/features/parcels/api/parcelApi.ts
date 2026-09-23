import apiClient from '../../../lib/apiClient';
import type { Parcel, ParcelListParams, ParcelListPayload, ParcelPatch, ParcelVersionsPayload } from '../types';

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
};