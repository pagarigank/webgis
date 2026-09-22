export interface Parcel {
    id: string;
    parcel_code: string;
    lot_number: string | null;
    block_number: string | null;
    title_number_ref: string | null;
    tax_declaration_no: string | null;
    source_area_sqm: number | null;
    source_area_unit: string | null;
    computed_area_sqm: number | null;
    psgc_barangay: string | null;
    psgc_barangay_name: string | null;
    psgc_municipality: string | null;
    psgc_province: string | null;
    survey_plan_number: string | null;
    location_description: string | null;
    status: string;
    provenance: string;
    geometry_source: string;
    verification_status: string | null;
    org_id: number | null;
    remarks: string | null;
    version: number;
    created_by: number | null;
    created_at: string;
    updated_by: number | null;
    updated_at: string;
    geometry: GeoJSON.GeometryObject | null;
}

export interface ParcelListPayload {
    data: Parcel[];
    total: number;
    limit: number;
    offset: number;
    sort: string;
    dir: string;
    include_historical: boolean;
}

export interface ParcelListParams {
    limit?: number;
    offset?: number;
    sort?: string;
    dir?: 'ASC' | 'DESC';
    status?: string;
    psgc_barangay?: string;
    q?: string;
    include_historical?: boolean;
    bbox?: string;
}