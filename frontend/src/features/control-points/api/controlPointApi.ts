import apiClient from '../../../lib/apiClient';

export interface ControlPoint {
  id: number;
  point_name: string;
  point_type: 'BLLM' | 'MBM' | 'PBM' | 'GCP' | 'CONTROL_POINT' | 'TIE_POINT' | 'REFERENCE_POINT' | 'OTHER';
  monument_type: string | null;
  easting: number | null;
  northing: number | null;
  elevation: number | null;
  latitude: number | null;
  longitude: number | null;
  coordinate_origin: 'PROJECTED' | 'GEOGRAPHIC';
  derived: {
    easting: boolean;
    northing: boolean;
    latitude: boolean;
    longitude: boolean;
  };
  native_crs_id: number | null;
  native_crs: string | null;
  native_crs_name: string | null;
  native_crs_srid: number | null;
  datum: string | null;
  zone: string | null;
  source: string | null;
  survey_reference: string | null;
  accuracy_class: string | null;
  accuracy_value_m: number | null;
  description: string | null;
  status: 'UNVERIFIED' | 'VERIFIED' | 'DISPUTED' | 'RETIRED';
  psgc_barangay: string | null;
  verified_by: number | null;
  verified_at: string | null;
  version: number;
  created_by: number | null;
  created_at: string;
  updated_by: number | null;
  updated_at: string;
  geom: GeoJSON.Point | null;
  distance_m?: number | null;
}

export interface ControlPointListParams {
  limit?: number;
  offset?: number;
  sort?: string;
  dir?: 'ASC' | 'DESC';
  status?: string;
  type?: string;
  q?: string;
  bbox?: string;
}

export interface ControlPointListPayload {
  data: ControlPoint[];
  total: number;
  limit: number;
  offset: number;
  sort: string;
  dir: string;
}

export interface NearestControlPointParams {
  lat: number;
  lon: number;
  limit?: number;
  type?: string;
}

export interface ControlPointPayload {
  point_name: string;
  point_type: string;
  monument_type?: string | null;
  native_crs: string;
  coordinate_origin: 'PROJECTED' | 'GEOGRAPHIC';
  easting?: number | null;
  northing?: number | null;
  latitude?: number | null;
  longitude?: number | null;
  elevation?: number | null;
  datum?: string | null;
  zone?: string | null;
  source?: string | null;
  survey_reference?: string | null;
  accuracy_class?: string | null;
  accuracy_value_m?: number | null;
  description?: string | null;
  status?: string;
  psgc_barangay?: string | null;
  change_reason?: string;
}

export interface DependentParcel {
  parcel_id: string;
  parcel_code: string;
  lot_number: string | null;
  block_number: string | null;
  status: string;
  psgc_barangay: string | null;
  tie_point_name: string;
  tie_point_status: string;
}

export const controlPointApi = {
  list: async (params?: ControlPointListParams): Promise<ControlPointListPayload> => {
    return apiClient.get('/control-points', { params });
  },

  getById: async (id: number | string): Promise<ControlPoint> => {
    return apiClient.get(`/control-points/${id}`);
  },

  getNearest: async (params: NearestControlPointParams): Promise<{ data: ControlPoint[]; total: number; limit: number }> => {
    return apiClient.get('/control-points/nearest', { params });
  },

  create: async (data: ControlPointPayload): Promise<ControlPoint> => {
    return apiClient.post('/control-points', data);
  },

  update: async (id: number | string, data: Partial<ControlPointPayload>, ifMatchVersion: number): Promise<ControlPoint> => {
    return apiClient.put(`/control-points/${id}`, data, {
      headers: { 'If-Match': `"${ifMatchVersion}"` },
    });
  },

  verify: async (id: number | string, changeReason?: string): Promise<ControlPoint> => {
    return apiClient.post(`/control-points/${id}/verify`, { change_reason: changeReason });
  },

  getDependents: async (id: number | string): Promise<{ parcels: DependentParcel[]; total: number }> => {
    return apiClient.get(`/control-points/${id}/dependents`);
  },

  delete: async (id: number | string, reason: string): Promise<{ deleted: boolean; id: number }> => {
    return apiClient.delete(`/control-points/${id}`, {
      data: { reason },
    });
  },
};
