import apiClient, { unwrapEntity, unwrapList } from '../../../lib/apiClient';

export interface Vertex {
  seq: number;
  label: string;
  easting: number;
  northing: number;
  latitude: number | null;
  longitude: number | null;
  delta_e: number;
  delta_n: number;
}

export interface ClosureResult {
  delta_e: number;
  delta_n: number;
  linear_error_m: number;
  error_azimuth_dd: number | null;
  perimeter_m: number;
  relative_precision: string;
  relative_precision_denominator: number | null;
  status: 'WITHIN_TOLERANCE' | 'EXCEEDS_TOLERANCE' | 'NOT_CLOSED' | 'INDETERMINATE';
  max_error_m: number;
  min_precision_denominator: number;
}

export interface AreaResult {
  computed_sqm: number;
  postgis_sqm: number;
  source_sqm: number | null;
  difference_sqm: number | null;
  difference_pct: number | null;
  note: string;
}

export interface ComputationWarning {
  rule: string;
  severity: 'warning' | 'info' | 'error';
  message: string;
}

export interface ComputationDetail {
  computation_id: number;
  engine_version: string;
  compute_crs: string;
  tie_points: Array<{
    name: string;
    as_used_easting: number;
    as_used_northing: number;
    as_used_status: string;
  }>;
  vertices: Vertex[];
  closure: ClosureResult;
  area: AreaResult;
  warnings: ComputationWarning[];
  geometry_preview: any | null;
  persisted_to_parcel: boolean;
  adjustment_method?: string | null;
  base_computation_id?: number | null;
}

export interface ComputationSummary {
  id: number;
  parcel_id: string;
  technical_description_id: number;
  compute_crs: string;
  method: string;
  adjustment_method: string | null;
  base_computation_id: number | null;
  linear_error_m: number;
  relative_precision: string;
  computed_area_sqm: number;
  postgis_area_sqm: number | null;
  source_area_sqm: number | null;
  closure_status: string;
  is_current: boolean;
  engine_version: string;
  computed_at: string;
  computed_by_name: string;
}

export interface ReplayResult {
  computation_id: number;
  replayed: boolean;
  matches_original: boolean;
  original_closure: {
    linear_error_m: number;
    perimeter_m: number;
  };
  replayed_closure: {
    delta_e: number;
    delta_n: number;
    linear_error_m: number;
    error_azimuth_dd: number;
    perimeter_m: number;
    relative_precision_denominator: number | null;
    status: string;
  };
}

export interface ZoneRecommendation {
  recommended_zone: string;
  recommended_crs_code: string;
  recommended_srid: number;
  cm_longitude: number;
  distance_from_cm_deg: number;
  all_zones: Array<{
    zone: string;
    code: string;
    srid: number;
    cm: number;
  }>;
}

export const computationApi = {
  calculate: async (
    parcelId: string,
    params: {
      technical_description_id: number;
      compute_crs: string;
      tolerances?: Record<string, number>;
    }
  ): Promise<ComputationDetail> => {
    const res = await apiClient.post(`/parcels/${parcelId}/calculate`, params);
    return unwrapEntity<ComputationDetail>(res)!;
  },

  listForParcel: async (parcelId: string): Promise<ComputationSummary[]> => {
    const res = await apiClient.get(`/parcels/${parcelId}/computations`);
    return unwrapList<ComputationSummary>(res);
  },

  get: async (id: number): Promise<ComputationDetail> => {
    const res = await apiClient.get(`/computations/${id}`);
    return unwrapEntity<ComputationDetail>(res)!;
  },

  getSnapshot: async (id: number): Promise<Record<string, unknown>> => {
    const res = await apiClient.get(`/computations/${id}/snapshot`);
    return unwrapEntity<Record<string, unknown>>(res) ?? {};
  },

  replay: async (id: number): Promise<ReplayResult> => {
    const res = await apiClient.post(`/computations/${id}/replay`);
    return unwrapEntity<ReplayResult>(res)!;
  },

  adjust: async (
    id: number,
    method: 'COMPASS' | 'TRANSIT',
    params?: Record<string, unknown>
  ): Promise<ComputationDetail> => {
    const res = await apiClient.post(`/computations/${id}/adjust`, {
      method,
      params: params ?? {},
    });
    return unwrapEntity<ComputationDetail>(res)!;
  },

  suggestZone: async (lon: number): Promise<ZoneRecommendation> => {
    const res = await apiClient.get(`/crs/suggest-ptm-zone?lon=${lon}`);
    return unwrapEntity<ZoneRecommendation>(res)!;
  },

  acceptComputation: async (
    parcelId: string,
    computationId: number,
    reason: string
  ): Promise<unknown> => {
    const res = await apiClient.post(`/parcels/${parcelId}/accept-computation`, {
      computation_id: computationId,
      reason,
    });
    return unwrapEntity(res);
  },
};
