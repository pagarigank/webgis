import apiClient from '../../lib/apiClient';
import { getMeasurementSrid } from '../../lib/crs';

export type SpatialOperation = 'bbox' | 'intersects' | 'within' | 'contains' | 'nearest' | 'within_distance' | 'buffer';

export interface SpatialQueryResult {
    features: GeoJSON.Feature[];
    total: number;
    operation: SpatialOperation;
    count: number;
}

export const spatialQueryApi = {
    /**
     * Execute a spatial query.
     */
    query: async (
        operation: SpatialOperation,
        geometry: GeoJSON.GeometryObject | number[],
        layerId?: number,
        options?: {
            srid?: number;
            limit?: number;
            offset?: number;
            distance_m?: number;
            buffer_m?: number;
        },
    ): Promise<SpatialQueryResult> => {
        return await apiClient.post('/spatial/query', {
            operation,
            layer_id: layerId,
            geometry,
            srid: options?.srid ?? getMeasurementSrid(),
            limit: options?.limit ?? 100,
            offset: options?.offset ?? 0,
            distance_m: options?.distance_m,
            buffer_m: options?.buffer_m,
        });
    },

    /**
     * Bbox query via GET (convenience).
     */
    bbox: async (
        layerId: number,
        west: number,
        south: number,
        east: number,
        north: number,
        options?: { srid?: number; limit?: number; offset?: number },
    ): Promise<SpatialQueryResult> => {
        const params = new URLSearchParams({
            layer_id: layerId.toString(),
            west: west.toString(),
            south: south.toString(),
            east: east.toString(),
            north: north.toString(),
            srid: (options?.srid ?? getMeasurementSrid()).toString(),
            limit: (options?.limit ?? 100).toString(),
            offset: (options?.offset ?? 0).toString(),
        });
        return await apiClient.get(`/spatial/query/bbox?${params}`);
    },
};

