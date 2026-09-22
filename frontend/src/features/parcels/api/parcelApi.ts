import apiClient from '../../../lib/apiClient';
import type { Parcel, ParcelListParams, ParcelListPayload } from '../types';

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
};