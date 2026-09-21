export type { Layer, LayerField, LayerStyle, LayerStyleRule, LayerPermission } from '../types';

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
