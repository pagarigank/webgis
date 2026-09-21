import apiClient from '../../lib/apiClient';

export interface MeasureDistanceResult {
    length_m: number;
    crs: number;
    unit: string;
}

export interface MeasureAreaResult {
    area_m2: number;
    area_ha: number;
    crs: number;
    unit: string;
}

export type MeasureResult = MeasureDistanceResult | MeasureAreaResult;

export const spatialApi = {
    measure: async (type: 'distance' | 'area', geometry: GeoJSON.GeometryObject, srid?: number): Promise<MeasureResult> => {
        return await apiClient.post('/spatial/measure', {
            type,
            geometry,
            srid: srid ?? 32651,
        });
    },

    identify: async (lng: number, lat: number, layerId: number, featureId: string = '', srid?: number): Promise<{
        feature: {
            id: string;
            status: string;
            psgc_barangay?: string;
            attributes: Record<string, unknown>;
        } | null;
        distance_m: number | null;
        layer_name: string | null;
    }> => {
        const params = new URLSearchParams({
            lng: lng.toString(),
            lat: lat.toString(),
            layer_id: layerId.toString(),
            srid: (srid ?? 32651).toString(),
        });
        if (featureId) params.set('feature_id', featureId);
        return await apiClient.get(`/spatial/identify?${params}`);
    },

    identifyNearby: async (lng: number, lat: number, srid?: number): Promise<{
        features: Array<{
            id: string;
            layer_id: number;
            layer_name: string;
            status: string;
            psgc_barangay?: string;
            distance_m: number;
            attributes: Record<string, unknown>;
        }>;
    }> => {
        const params = new URLSearchParams({
            lng: lng.toString(),
            lat: lat.toString(),
            srid: (srid ?? 32651).toString(),
        });
        return await apiClient.get(`/spatial/identify-nearby?${params}`);
    },
};

