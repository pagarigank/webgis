import apiClient from '../../../lib/apiClient';

export interface SurveyPlan {
  id: number;
  plan_number: string;
  plan_type: string;
  survey_date: string | null;
  approved_date: string | null;
  approving_agency: string | null;
  surveyor_name: string | null;
  surveyor_license: string | null;
  control_reference: string | null;
  crs_id: number | null;
  crs_code: string | null;
  crs_name: string | null;
  area_sqm: number | null;
  lot_count: number | null;
  psgc_barangay: string | null;
  source_document_id: string | null;
  remarks: string | null;
  version: number;
  created_by: number | null;
  created_at: string;
  updated_by: number | null;
  updated_at: string;
}

export interface SurveyPlanListParams {
  limit?: number;
  offset?: number;
  sort?: string;
  dir?: 'ASC' | 'DESC';
  q?: string;
  plan_type?: string;
  psgc_barangay?: string;
}

export interface SurveyPlanListPayload {
  data: SurveyPlan[];
  total: number;
  limit: number;
  offset: number;
  sort: string;
  dir: string;
}

export interface LinkedParcelSummary {
  id: string;
  parcel_code: string;
  lot_number: string | null;
  block_number: string | null;
  status: string;
  source_area_sqm: number | null;
  computed_area_sqm: number | null;
  verification_status: string;
  psgc_barangay: string | null;
  created_at: string;
}

export interface LinkedParcelsPayload {
  survey_plan_id: number;
  parcels: LinkedParcelSummary[];
  total: number;
}

export const surveyPlanApi = {
  list: async (params?: SurveyPlanListParams): Promise<SurveyPlanListPayload> => {
    return apiClient.get('/api/v1/survey-plans', { params });
  },

  getById: async (id: number | string): Promise<SurveyPlan> => {
    return apiClient.get(`/api/v1/survey-plans/${id}`);
  },

  getParcels: async (id: number | string): Promise<LinkedParcelsPayload> => {
    return apiClient.get(`/api/v1/survey-plans/${id}/parcels`);
  },

  create: async (data: Partial<SurveyPlan>): Promise<SurveyPlan> => {
    return apiClient.post('/api/v1/survey-plans', data);
  },

  update: async (id: number | string, data: Partial<SurveyPlan>, version: number): Promise<SurveyPlan> => {
    return apiClient.put(`/api/v1/survey-plans/${id}`, data, {
      headers: { 'If-Match': `"${version}"` },
    });
  },

  delete: async (id: number | string, reason: string): Promise<{ deleted: boolean; id: number }> => {
    return apiClient.delete(`/api/v1/survey-plans/${id}`, {
      data: { reason },
    });
  },
};
