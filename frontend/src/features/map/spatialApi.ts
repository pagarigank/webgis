import apiClient from '../../lib/apiClient';
import { getMeasurementSrid } from '../../lib/crs';

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
    // `srid` is the CRS the measurement is *computed in*, not the CRS the input
    // geometry is expressed in: the API always reads input geometry as WGS84
    // and reprojects to this CRS server-side. Defaulting to the user's working
    // CRS keeps the numbers on screen consistent with the CRS shown in the
    // readout, and defaults to PRS92 rather than a hardcoded UTM zone.
    measure: async (type: 'distance' | 'area', geometry: GeoJSON.GeometryObject, srid?: number): Promise<MeasureResult> => {
        return await apiClient.post('/spatial/measure', {
            type,
            geometry,
            srid: srid ?? getMeasurementSrid(),
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
            srid: (srid ?? getMeasurementSrid()).toString(),
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
            srid: (srid ?? getMeasurementSrid()).toString(),
        });
        return await apiClient.get(`/spatial/identify-nearby?${params}`);
    },
};

