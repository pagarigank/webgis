import apiClient from '../../../lib/apiClient';

export interface CourseBearing {
  quadrant: string | null;
  deg: number | null;
  min: number | null;
  sec: number | null;
  bearing_type?: string;
  normalized?: string | null;
  azimuth_dd: number | null;
  original?: string | null;
}

export interface CourseDistance {
  value: number | null;
  unit: string;
  meters: number | null;
  original?: string | null;
}

export interface TechnicalDescriptionCourse {
  id: number;
  technical_description_id: number;
  seq: number;
  from_point_label: string;
  to_point_label: string;
  bearing: CourseBearing;
  distance: CourseDistance;
  extraction_method: string;
  confidence: number | null;
  source_span?: { start: number; end: number } | null;
  is_confirmed: boolean;
  remarks: string | null;
  resolved?: boolean;
  issues?: Array<{ field: string; rule: string; message: string }>;
}

export interface TiePoint {
  id: number;
  technical_description_id: number;
  control_point_id: number | null;
  adhoc_name: string | null;
  role: string;
  as_used_easting: number;
  as_used_northing: number;
  as_used_crs_id: number;
  as_used_status: string;
  sequence: number;
  notes: string | null;
}

export interface TieLine {
  id: number;
  tie_point_id: number;
  seq: number;
  to_point_label: string;
  bearing: CourseBearing;
  distance: CourseDistance;
}

export interface TechnicalDescription {
  id: number;
  parcel_id: string;
  revision: number;
  survey_plan_id: number | null;
  original_text: string | null;
  source_type: string;
  parser_status: 'NOT_PARSED' | 'PARSED' | 'PARTIAL' | 'FAILED' | 'CONFIRMED';
  status: string;
  is_current: boolean;
  confirmed_by: number | null;
  confirmed_at: string | null;
  bearing_reference: string;
  distance_unit: string;
  point_of_beginning_label: string | null;
  survey_reference: string | null;
  version: number;
  created_at: string;
  updated_at: string;
  courses?: TechnicalDescriptionCourse[];
  tie_points?: TiePoint[];
  tie_lines?: TieLine[];
}

export interface CourseValidationError {
  rule: string;
  field: string;
  course_seq: number;
  message: string;
}

export interface CourseValidationResult {
  valid: boolean;
  errors: CourseValidationError[];
  warnings: CourseValidationError[];
  validated_courses: Array<Record<string, unknown>>;
}

export interface StagedParseResult {
  parser_status: 'PARSED' | 'PARTIAL' | 'FAILED';
  tie_point_name: string | null;
  tie_lines: Array<{
    from_point: string;
    to_point: string;
    bearing: CourseBearing | null;
    distance: CourseDistance | null;
    confidence: number;
    source_span: { start: number; end: number };
  }>;
  point_of_beginning_label: string;
  courses: Array<{
    seq: number;
    from_point_label: string;
    to_point_label: string;
    bearing: CourseBearing | null;
    distance: CourseDistance | null;
    extraction_method: string;
    confidence: number;
    source_span: { start: number; end: number };
    resolved: boolean;
    issues: Array<{ field: string; rule: string; message: string }>;
  }>;
  area_sqm_claimed: number | null;
  overall_confidence: number;
}

export const surveyApi = {
  async listByParcel(parcelId: string): Promise<TechnicalDescription[]> {
    const res = await apiClient.get<{ success: boolean; data: TechnicalDescription[] }>(
      `/api/v1/parcels/${parcelId}/technical-descriptions`
    );
    return res.data.data;
  },

  async createForParcel(parcelId: string, payload: {
    original_text?: string;
    source_type?: string;
    bearing_reference?: string;
    distance_unit?: string;
    point_of_beginning_label?: string;
    survey_plan_id?: number | null;
    is_current?: boolean;
  }): Promise<TechnicalDescription> {
    const res = await apiClient.post<{ success: boolean; data: TechnicalDescription }>(
      `/api/v1/parcels/${parcelId}/technical-descriptions`,
      payload
    );
    return res.data.data;
  },

  async getById(id: number): Promise<{ data: TechnicalDescription; etag?: string }> {
    const res = await apiClient.get<{ success: boolean; data: TechnicalDescription }>(
      `/api/v1/technical-descriptions/${id}`
    );
    const etag = res.headers['etag'] as string | undefined;
    return { data: res.data.data, etag };
  },

  async update(id: number, payload: Partial<TechnicalDescription>, ifMatch?: string): Promise<TechnicalDescription> {
    const headers: Record<string, string> = {};
    if (ifMatch) {
      headers['If-Match'] = ifMatch;
    }
    const res = await apiClient.put<{ success: boolean; data: TechnicalDescription }>(
      `/api/v1/technical-descriptions/${id}`,
      payload,
      { headers }
    );
    return res.data.data;
  },

  async addCourse(tdId: number, course: {
    seq?: number;
    from_point_label?: string;
    to_point_label?: string;
    bearing?: string;
    quadrant?: string;
    deg?: number;
    min?: number;
    sec?: number;
    distance: number;
    unit?: string;
    remarks?: string;
  }): Promise<TechnicalDescription> {
    const res = await apiClient.post<{ success: boolean; data: TechnicalDescription }>(
      `/api/v1/technical-descriptions/${tdId}/courses`,
      course
    );
    return res.data.data;
  },

  async updateCourse(tdId: number, courseId: number, course: {
    from_point_label?: string;
    to_point_label?: string;
    bearing?: string;
    quadrant?: string;
    deg?: number;
    min?: number;
    sec?: number;
    distance?: number;
    unit?: string;
    remarks?: string;
  }): Promise<TechnicalDescription> {
    const res = await apiClient.put<{ success: boolean; data: TechnicalDescription }>(
      `/api/v1/technical-descriptions/${tdId}/courses/${courseId}`,
      course
    );
    return res.data.data;
  },

  async deleteCourse(tdId: number, courseId: number): Promise<TechnicalDescription> {
    const res = await apiClient.delete<{ success: boolean; data: TechnicalDescription }>(
      `/api/v1/technical-descriptions/${tdId}/courses/${courseId}`
    );
    return res.data.data;
  },

  async reorderCourses(tdId: number, courseIds: number[]): Promise<TechnicalDescription> {
    const res = await apiClient.put<{ success: boolean; data: TechnicalDescription }>(
      `/api/v1/technical-descriptions/${tdId}/courses/order`,
      { course_ids: courseIds }
    );
    return res.data.data;
  },

  async validateCourses(tdId: number): Promise<CourseValidationResult> {
    const res = await apiClient.post<{ success: boolean; data: CourseValidationResult }>(
      `/api/v1/technical-descriptions/${tdId}/validate`,
      {}
    );
    return res.data.data;
  },

  async parseText(payload: {
    text: string;
    source_type?: string;
    distance_unit_hint?: string;
  }): Promise<StagedParseResult> {
    const res = await apiClient.post<{ success: boolean; data: StagedParseResult }>(
      `/api/v1/survey/parse`,
      payload
    );
    return res.data.data;
  },

  async confirm(tdId: number): Promise<TechnicalDescription> {
    const res = await apiClient.post<{ success: boolean; data: TechnicalDescription }>(
      `/api/v1/technical-descriptions/${tdId}/confirm`,
      {}
    );
    return res.data.data;
  },

  async ocrAssist(tdId: number, text: string): Promise<TechnicalDescription> {
    const res = await apiClient.post<{ success: boolean; data: TechnicalDescription }>(
      `/api/v1/technical-descriptions/${tdId}/ocr`,
      { text }
    );
    return res.data.data;
  },
};
