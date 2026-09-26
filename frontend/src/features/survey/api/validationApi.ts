import apiClient, { unwrapEntity } from '../../../lib/apiClient';

export interface ValidationCheckItem {
    id: string;
    name: string;
    rule: string;
    status: 'PASS' | 'WARN' | 'FAIL';
    severity: 'blocking' | 'warning' | 'info';
    message: string;
    details?: unknown;
}

export interface OverlappingParcelItem {
    parcel_id: string;
    parcel_code: string;
    lot_number: string | null;
    status: string;
    overlap_area_sqm: number;
    overlap_pct_of_subject: number;
    is_sliver: boolean;
}

export interface AreaComparisonData {
    computed_sqm: number | null;
    postgis_sqm: number | null;
    source_sqm: number | null;
    note: string;
}

export interface OverlapSummaryData {
    has_overlap: boolean;
    has_significant_overlap: boolean;
    total_overlap_area_sqm: number;
    sliver_count: number;
    overlapping_parcels: OverlappingParcelItem[];
}

export interface ValidationResult {
    parcel_id: string;
    computation_id: number | null;
    passed: boolean;
    can_submit: boolean;
    blocking_count: number;
    warning_count: number;
    blocking_failures: ValidationCheckItem[];
    warnings: ValidationCheckItem[];
    checks: ValidationCheckItem[];
    area_comparison: AreaComparisonData;
    overlap_summary: OverlapSummaryData;
    validated_at: string;
}

export const validationApi = {
    async validateParcel(parcelId: string, options: Record<string, unknown> = {}): Promise<ValidationResult> {
        const res = await apiClient.post(`/parcels/${parcelId}/validate`, options);
        return unwrapEntity<ValidationResult>(res)!;
    },

    async getValidation(parcelId: string, revalidate = false): Promise<ValidationResult> {
        const query = revalidate ? '?revalidate=true' : '';
        const res = await apiClient.get(`/parcels/${parcelId}/validation${query}`);
        return unwrapEntity<ValidationResult>(res)!;
    },

    async submitParcel(
        parcelId: string,
        reason?: string
    ): Promise<{ parcel: unknown; validation: ValidationResult }> {
        const res = await apiClient.post(`/parcels/${parcelId}/submit`, { change_reason: reason });
        return unwrapEntity<{ parcel: unknown; validation: ValidationResult }>(res)!;
    },
};
