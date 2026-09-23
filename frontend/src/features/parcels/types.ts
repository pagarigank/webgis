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
    survey_plan_id: number | null;
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

/** PATCH body (subset of ParcelController::update allowed fields). */
export type ParcelPatch = Partial<
    Pick<
        Parcel,
        | 'lot_number'
        | 'block_number'
        | 'title_number_ref'
        | 'tax_declaration_no'
        | 'source_area_sqm'
        | 'source_area_unit'
        | 'psgc_barangay'
        | 'psgc_municipality'
        | 'psgc_province'
        | 'location_description'
        | 'remarks'
        | 'provenance'
        | 'geometry_source'
        | 'survey_plan_id'
    >
> & { change_reason?: string; status?: string };

/** POST /parcels body (TASK-072 manual drawing + provenance guard). */
export interface ParcelCreateInput {
    parcel_code: string;
    provenance: string;
    lot_number?: string | null;
    block_number?: string | null;
    title_number_ref?: string | null;
    tax_declaration_no?: string | null;
    source_area_sqm?: number | null;
    source_area_unit?: string;
    psgc_barangay?: string | null;
    psgc_municipality?: string | null;
    psgc_province?: string | null;
    location_description?: string | null;
    status?: string;
    geometry?: GeoJSON.Polygon | GeoJSON.MultiPolygon | null;
    survey_plan_id?: number | null;
    change_reason?: string;
}

/** A row of the version lineage index (GET /parcels/{id}/versions). */
export interface ParcelVersionSummary {
    id: number;
    version: number;
    status: string;
    provenance: string;
    change_summary: string | null;
    change_reason: string | null;
    changed_by: number | null;
    changed_at: string;
    has_geometry: boolean;
    request_id: string | null;
}

export interface ParcelVersionsPayload {
    data: ParcelVersionSummary[];
    pagination: { page: number; per_page: number; total: number };
}