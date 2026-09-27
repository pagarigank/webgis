import apiClient from '../../../lib/apiClient';

/**
 * TASK-128 — typed client for the import lifecycle (api.md §10).
 *
 * The backend owns every state transition; the wizard never invents one. In
 * particular the status is the single source of truth for how far the user may
 * advance, so the UI derives its step ceiling from `ImportJob.status` rather
 * than from which button was last clicked.
 */

export type ImportStatus =
    | 'UPLOADED'
    | 'MAPPED'
    | 'VALIDATED'
    | 'COMMITTED'
    | 'CANCELLED'
    | 'FAILED';

export type TargetEntity = 'FEATURE' | 'PARCEL' | 'CONTROL_POINT';

export interface ImportJob {
    id: number;
    source_format: string;
    source_filename: string;
    target_entity: TargetEntity;
    target_layer_id: number | null;
    /** Null until the user declares it. CRS is never inferred (FR-252). */
    declared_crs: string | null;
    /** Offered for confirmation only; never used as the declared CRS. */
    suggested_crs: string | null;
    transformation_id: number | null;
    field_mapping: Record<string, string> | null;
    options: Record<string, unknown> | null;
    status: ImportStatus;
    total_rows: number;
    valid_rows: number;
    invalid_rows: number;
    validation_result: Record<string, unknown> | null;
    created_at: string;
    committed_at: string | null;
}

export interface ImportRow {
    row_number: number;
    values: Record<string, unknown> | null;
    geometry: GeoJSON.Geometry | null;
    is_valid: boolean;
    errors: Array<string | { field?: string; message: string }>;
}

export interface ImportPreview {
    page: number;
    page_size: number;
    total: number;
    rows: ImportRow[];
}

export interface ValidateResult {
    status: ImportStatus;
    total_rows: number;
    valid_rows: number;
    invalid_rows: number;
}

export interface CommitResult {
    status: ImportStatus;
    committed_rows: number;
}

export interface CrsOption {
    srid: number;
    code: string;
    name: string;
    datum: string | null;
    zone: string | null;
    is_projected: boolean;
    is_historical: boolean;
}

export interface CreateImportInput {
    file: File;
    target_entity: TargetEntity;
    target_layer_id?: number | null;
}

export const importApi = {
    /**
     * Multipart upload. Declared as multipart/form-data without a manual
     * Content-Type so the browser can set the multipart boundary; setting it by
     * hand produces a body the server cannot parse.
     */
    create: async (input: CreateImportInput): Promise<ImportJob> => {
        const form = new FormData();
        form.append('file', input.file);
        form.append('target_entity', input.target_entity);
        if (input.target_layer_id != null) {
            form.append('target_layer_id', String(input.target_layer_id));
        }
        return await apiClient.post('/imports', form);
    },

    get: async (id: number): Promise<ImportJob> => {
        return await apiClient.get(`/imports/${id}`);
    },

    setMapping: async (
        id: number,
        body: { declared_crs: string; field_mapping?: Record<string, string>; options?: Record<string, unknown> },
    ): Promise<ImportJob> => {
        return await apiClient.put(`/imports/${id}/mapping`, body);
    },

    validate: async (id: number): Promise<ValidateResult> => {
        return await apiClient.post(`/imports/${id}/validate`);
    },

    preview: async (id: number, page: number, pageSize: number): Promise<ImportPreview> => {
        return await apiClient.get(`/imports/${id}/preview`, { params: { page, page_size: pageSize } });
    },

    /**
     * The error report is CSV, not an envelope, so it is fetched as a blob and
     * handed to the browser as a download. The endpoint needs the bearer token
     * like any other call, which is why this goes through apiClient rather than
     * a plain link to the URL.
     */
    downloadErrors: async (id: number, filename = 'import-errors.csv'): Promise<void> => {
        const response = await apiClient.get<Blob>(`/imports/${id}/errors`, { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    },

    /**
     * Idempotency-Key is required by the backend: a replayed commit must not
     * insert the rows a second time, so the key is minted once per job and
     * reused if the operator retries after a network failure.
     */
    commit: async (id: number, partial: boolean, idempotencyKey: string): Promise<CommitResult> => {
        return await apiClient.post(
            `/imports/${id}/commit`,
            { partial },
            { headers: { 'Idempotency-Key': idempotencyKey } },
        );
    },

    cancel: async (id: number): Promise<{ cancelled: boolean }> => {
        return await apiClient.delete(`/imports/${id}`);
    },

    crsList: async (): Promise<CrsOption[]> => {
        return await apiClient.get('/crs');
    },
};

/** Row errors arrive either as bare strings or as { field, message }. */
export function rowErrorText(error: string | { field?: string; message: string }): string {
    if (typeof error === 'string') {
        return error;
    }
    return error.field ? `${error.field}: ${error.message}` : error.message;
}
