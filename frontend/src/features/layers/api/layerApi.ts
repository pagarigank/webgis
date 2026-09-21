import apiClient from '../../../lib/apiClient';
import type { Layer } from '../types';

export interface Feature {
    id: string;
    status: string;
    psgc_barangay?: string;
    provenance?: string;
    version: number;
    created_by: number;
    created_at: string;
    updated_by: number;
    updated_at: string;
    attributes: Record<string, unknown>;
    geometry: GeoJSON.GeometryObject;
}

export interface FeatureCollection {
    data: Feature[];
    total: number;
    limit: number;
    offset: number;
    sort: string;
    dir: string;
    layer_id: number;
}

export const layerApi = {
    getAll: async (): Promise<Layer[]> => {
        return await apiClient.get('/layers');
    },

    getById: async (id: number): Promise<Layer> => {
        return await apiClient.get(`/layers/${id}`);
    },

    create: async (data: Partial<Layer>): Promise<{id: number}> => {
        return await apiClient.post('/layers', data);
    },

    update: async (id: number, data: Partial<Layer> & { version?: number }): Promise<{id: number; version: number}> => {
        const headers: Record<string, string> = {};
        if (data.version != null) {
            headers['If-Match'] = String(data.version);
        }
        return await apiClient.put(`/layers/${id}`, data, { headers });
    },

    delete: async (id: number): Promise<void> => {
        await apiClient.delete(`/layers/${id}`);
    },

    // ── Features (TASK-053/054) ──────────────────────────────────────────

    getFeatures: async (layerId: number, params?: {
        bbox?: string;
        limit?: number;
        offset?: number;
        sort?: string;
        dir?: 'ASC' | 'DESC';
        status?: string;
        attribute?: Record<string, string>;
    }): Promise<FeatureCollection> => {
        const qs = new URLSearchParams();
        if (params?.bbox) qs.set('bbox', params.bbox);
        if (params?.limit) qs.set('limit', String(params.limit));
        if (params?.offset) qs.set('offset', String(params.offset));
        if (params?.sort) qs.set('sort', params.sort);
        if (params?.dir) qs.set('dir', params.dir);
        if (params?.status) qs.set('status', params.status);
        if (params?.attribute) {
            for (const [k, v] of Object.entries(params.attribute)) {
                qs.set(`attribute.${k}`, v);
            }
        }
        const q = qs.toString();
        return await apiClient.get(`/layers/${layerId}/features${q ? '?' + q : ''}`);
    },

    getFeature: async (layerId: number, featureId: string): Promise<Feature> => {
        return await apiClient.get(`/layers/${layerId}/features/${featureId}`);
    },

    createFeature: async (layerId: number, data: {
        geometry: GeoJSON.GeometryObject;
        attributes?: Record<string, unknown>;
        psgc_barangay?: string;
        provenance?: string;
        status?: string;
    }): Promise<Feature> => {
        return await apiClient.post(`/layers/${layerId}/features`, data);
    },

    updateFeature: async (layerId: number, featureId: string, data: Partial<{
        geometry: GeoJSON.GeometryObject;
        attributes: Record<string, unknown>;
        status: string;
        psgc_barangay: string;
        provenance: string;
    }>, version?: number): Promise<Feature> => {
        const headers: Record<string, string> = {};
        if (version != null) {
            headers['If-Match'] = String(version);
        }
        return await apiClient.patch(`/layers/${layerId}/features/${featureId}`, data, { headers });
    },

    updateFeatureWithVersion: async (layerId: number, featureId: string, version: number, data: Partial<{
        geometry: GeoJSON.GeometryObject;
        attributes: Record<string, unknown>;
        status: string;
        psgc_barangay: string;
        provenance: string;
    }>): Promise<Feature> => {
        return layerApi.updateFeature(layerId, featureId, data, version);
    },

    deleteFeature: async (layerId: number, featureId: string): Promise<{id: string; deleted: boolean}> => {
        return await apiClient.delete(`/layers/${layerId}/features/${featureId}`);
    },

    getGeoJSON: async (layerId: number, params?: { bbox?: string; status?: string }): Promise<GeoJSON.FeatureCollection> => {
        const qs = new URLSearchParams();
        if (params?.bbox) qs.set('bbox', params.bbox);
        if (params?.status) qs.set('status', params.status);
        const q = qs.toString();
        return await apiClient.get(`/layers/${layerId}/features.geojson${q ? '?' + q : ''}`, {
            responseType: 'json',
        });
    },
};
