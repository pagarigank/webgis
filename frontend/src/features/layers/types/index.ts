export interface Layer {
    id: number;
    code: string;
    name: string;
    description?: string;
    group_path: string;
    geometry_type: 'POINT' | 'MULTIPOINT' | 'LINESTRING' | 'MULTILINESTRING' | 'POLYGON' | 'MULTIPOLYGON' | 'GEOMETRY';
    srid: number;
    source?: string;
    render_mode: 'geojson' | 'mvt';
    extent?: string;
    feature_count_cache?: number;
    created_at?: string;
    updated_at?: string;
}

export interface LayerField {
    id: number;
    layer_id: number;
    field_name: string;
    field_label: string;
    field_type: 'text' | 'long_text' | 'integer' | 'decimal' | 'boolean' | 'date' | 'datetime' | 'dropdown' | 'multi_select' | 'email' | 'phone' | 'url' | 'currency' | 'reference' | 'user' | 'document';
    required: boolean;
    default_value?: any;
    options?: any;
    validation_rules?: any;
    searchable: boolean;
    sortable: boolean;
    displayable: boolean;
    editable: boolean;
    is_pii: boolean;
    sort_order: number;
    created_at?: string;
    updated_at?: string;
}

export interface LayerStyleRule {
    value?: string | number;
    min?: number;
    max?: number;
    fill?: string;
    stroke?: string;
    width?: number;
    opacity?: number;
    icon?: string;
}

export interface LayerStyle {
    id: number;
    layer_id: number;
    style_type: 'SINGLE' | 'CATEGORIZED' | 'GRADUATED';
    attribute_field?: string;
    rules: LayerStyleRule[];
    default_rule: LayerStyleRule;
    label_config?: any;
    is_active: boolean;
    version: number;
    created_at?: string;
    updated_at?: string;
}

export interface LayerPermission {
    layer_id: number;
    role_id: number;
    can_view: boolean;
    can_create: boolean;
    can_update: boolean;
    can_delete: boolean;
    can_approve: boolean;
}
