export interface LayerField {
    id?: number;
    layer_id?: number;
    field_name: string;
    name?: string;
    field_label: string;
    label?: string;
    field_type: string;
    type?: string;
    config?: Record<string, any>;
    required?: boolean;
    order_index?: number;
    editable?: boolean;
    is_pii?: boolean;
    searchable?: boolean;
    sortable?: boolean;
    displayable?: boolean;
    sort_order?: number;
    default_value?: any;
    validation_rules?: any;
    options?: any;
}

export interface LayerStyleRule {
    id?: number;
}

export interface LayerStyle {
    type?: string;
    paint?: Record<string, any>;
    layout?: Record<string, any>;
    rules?: LayerStyleRule[];
}

export interface LayerPermission {
    id?: number;
    layer_id?: number;
    role_id?: number;
    can_view?: boolean;
    can_create?: boolean;
    can_edit?: boolean;
    can_delete?: boolean;
}

export interface Layer {
    id: number;
    field_name: string;
    name?: string;
    type?: string;
    description?: string;
    is_hidden?: boolean;
    style?: LayerStyle;
    fields?: LayerField[];
    permissions?: LayerPermission[];
    version?: number;
    created_at?: string;
    updated_at?: string;
}


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
