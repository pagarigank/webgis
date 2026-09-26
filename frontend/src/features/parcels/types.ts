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
/**
 * TASK-104b - a parcel resolved from a map click (GET /parcels/locate).
 * `contains_point` is true when the click fell inside the polygon; otherwise
 * the parcel is merely the nearest one within the search tolerance.
 */
export interface ParcelHit {
    id: string;
    parcel_code: string;
    status: string;
    geometry_source: string;
    psgc_province: string | null;
    psgc_municipality: string | null;
    psgc_barangay: string | null;
    source_area_sqm: number | null;
    source_area_unit: string | null;
    computed_area_sqm: number | null;
    version: number;
    area_m2: number | null;
    area_ha: number | null;
    distance_m: number;
    contains_point: boolean;
}

export interface ParcelLocatePayload {
    parcels: ParcelHit[];
    tolerance_m: number;
}

/** Properties carried on each overlay feature (GET /parcels/overlay). */
export interface ParcelOverlayProperties {
    parcel_code: string;
    status: string;
    geometry_source: string;
    version: number;
    area_m2: number | null;
}

export type ParcelOverlayCollection = GeoJSON.FeatureCollection<
    GeoJSON.Polygon | GeoJSON.MultiPolygon,
    ParcelOverlayProperties
>;

/** One attribute difference between two parcel versions (TASK-104a). */
export interface ParcelFieldChange {
    field: string;
    old: unknown;
    new: unknown;
    changed: boolean;
}

/** A vertex that moved between two versions, matched by ring position. */
export interface MovedVertex {
    index: number;
    from: [number, number];
    to: [number, number];
}

export interface ParcelGeometryDiff {
    added: [number, number][];
    removed: [number, number][];
    moved: MovedVertex[];
    old_vertex_count: number;
    new_vertex_count: number;
}

/** GET /parcels/{id}/versions/{v}/compare */
export interface ParcelComparePayload {
    parcel_id: string;
    from_version: number;
    to_version: number;
    field_changes: ParcelFieldChange[];
    geometry_diff: ParcelGeometryDiff | null;
}

/** GET /parcels/{id}/versions/{v} */
export interface ParcelVersionDetail {
    id: number;
    version: number;
    status: string;
    geometry_source: string;
    change_summary: string | null;
    change_reason: string | null;
    changed_by: number | null;
    changed_at: string;
    geometry: GeoJSON.GeometryObject | null;
    snapshot: Record<string, unknown>;
}

/** A single event on the merged version/workflow/audit timeline. */
export interface ParcelTimelineEvent {
    kind: 'VERSION' | 'WORKFLOW' | 'AUDIT';
    at: string;
    actor: string | null;
    action: string;
    detail: Record<string, unknown>;
    misc: Record<string, unknown>;
}

export interface ParcelTimelinePayload {
    parcel_id: string;
    count: number;
    events: ParcelTimelineEvent[];
}
