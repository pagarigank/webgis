export interface BasemapProvider {
  id: number;
  code: string;
  name: string;
  provider_type: 'XYZ' | 'TMS' | 'WMS' | 'WMTS' | 'VECTOR_TILE' | 'LOCAL_ORTHOPHOTO';
  service_url?: string;
  url_template?: string;
  layer_name?: string;
  matrix_set?: string;
  format?: string;
  srid: number;
  attribution_html: string;
  attribution_url?: string;
  license_type: 'OPEN_ODBL' | 'COMMERCIAL_WEB' | 'GOVERNMENT_GRANT' | 'ORGANIZATION_OWNED' | 'UNLICENSED';
  license_reference?: string;
  license_expires_on?: string;
  license_notes?: string;
  requires_api_key: boolean;
  api_key_env_name?: string;
  proxy_required: boolean;
  cache_ttl_seconds: number;
  min_zoom?: number;
  max_zoom?: number;
  is_enabled: boolean;
  is_default: boolean;
  display_order: number;
  status?: 'ACTIVE' | 'LICENSE_RESTRICTED';
}
