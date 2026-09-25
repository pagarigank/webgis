import apiClient from '../../../lib/apiClient';

/**
 * TASK-116/117/118 — split, consolidation and lineage API clients
 * (api.md §8.2 / §8.3, architecture.md §18.3/§18.4).
 *
 * Every payload shape mirrors the backend services exactly: the preview
 * (dry_run=true) response carries the full validation block, so the UI can
 * gate the commit on a successful preview of the CURRENT inputs (TASK-116 AC)
 * instead of trusting stale state.
 */

export interface ValidationCheck {
    rule: string;
    status: string;
    message: string;
}

export interface ValidationWarning {
    rule: string;
    message: string;
}

export interface PairwiseOverlap {
    i: number;
    j: number;
    overlap_area_sqm: number;
}

export interface SplitReconciliation {
    parent_sqm: number | null;
    children_sum_sqm: number;
    difference_sqm: number | null;
    difference_pct: number | null;
}

export interface ConsolidationReconciliation {
    parents_sum_sqm: number;
    union_sqm: number | null;
    difference_sqm: number;
}

export interface SplitChildPreview {
    temp_id: string;
    parcel_id: string | null;
    lot_number: string | null;
    area_sqm: number;
    share_pct: number;
    geometry: GeoJSON.GeometryObject | null;
}

export interface SplitPayload {
    operation_id: number | null;
    dry_run: boolean;
    method: string;
    parent: string;
    children: SplitChildPreview[];
    area_reconciliation: SplitReconciliation;
    validation: { passed: boolean; checks: ValidationCheck[]; warnings: ValidationWarning[] };
    parent_after: { status: string };
}

export interface ConsolidationPayload {
    operation_id: number | null;
    dry_run: boolean;
    result: {
        parcel_id: string | null;
        parcel_code: string | null;
        area_sqm: number | null;
        geometry: GeoJSON.GeometryObject | null;
    };
    area_reconciliation: ConsolidationReconciliation;
    validation: { passed: boolean; checks: ValidationCheck[]; warnings: ValidationWarning[] };
    parents_after: { id: string; parcel_code: string; status: string }[];
}

export interface LineageNode {
    id: string;
    parcel_code: string;
    lot_number: string | null;
    status: string;
    area_sqm: number | null;
    depth: number;
}

export interface LineageEdge {
    parent: string;
    child: string;
    type: string;
    operation_id: number | null;
    effective_date: string | null;
}

export interface LineagePayload {
    nodes: LineageNode[];
    edges: LineageEdge[];
    truncated: boolean;
    truncated_ancestors: boolean;
    truncated_descendants: boolean;
    depth: number;
    direction: string;
}

export interface SplitBody {
    method: string;
    split_line?: { type: 'LineString'; coordinates: [number, number][] };
    children: {
        lot_number?: string;
        geometry?: GeoJSON.GeometryObject;
        technical_description_id?: number;
    }[];
    reason?: string;
}

export interface ConsolidateBody {
    parent_parcel_ids: string[];
    parent_versions?: Record<string, number>;
    new_parcel?: { lot_number?: string | null; remarks?: string | null };
    allow_multipart?: boolean;
    reason?: string;
}

/**
 * Normalise an axios/backend error into { code, message, failures } so the
 * tabs can render the per-rule failure list (SPLIT_INVALID/CONSOLIDATION_INVALID
 * carry every failed check in details.failures) instead of a generic error.
 */
export function extractApiError(err: unknown): {
    code: string;
    message: string;
    failures: ValidationCheck[];
    warnings: ValidationWarning[];
} {
    const axiosErr = err as {
        response?: {
            data?: {
                error?: {
                    code?: string;
                    message?: string;
                    details?: { failures?: ValidationCheck[]; warnings?: ValidationWarning[] };
                };
            };
        };
        message?: string;
    };
    const error = axiosErr?.response?.data?.error;
    return {
        code: error?.code ?? 'REQUEST_FAILED',
        message: error?.message ?? axiosErr?.message ?? 'The request failed.',
        failures: error?.details?.failures ?? [],
        warnings: error?.details?.warnings ?? [],
    };
}

export const operationsApi = {
    /** Dry run — writes nothing; returns the full preview payload (200). */
    splitPreview: async (parcelId: string, body: SplitBody): Promise<SplitPayload> => {
        return (await apiClient.post(`/parcels/${parcelId}/split`, body, {
            params: { dry_run: true },
        })) as SplitPayload;
    },

    /** Commit — requires If-Match (parent version); returns 201 with children ids. */
    splitCommit: async (parcelId: string, body: SplitBody, version: number, idempotencyKey: string): Promise<SplitPayload> => {
        return (await apiClient.post(`/parcels/${parcelId}/split`, body, {
            headers: { 'If-Match': String(version), 'Idempotency-Key': idempotencyKey },
        })) as SplitPayload;
    },

    /** Consolidation dry run — parent_versions is only required for commit. */
    consolidatePreview: async (body: ConsolidateBody): Promise<ConsolidationPayload> => {
        return (await apiClient.post('/parcels/consolidate', body, {
            params: { dry_run: true },
        })) as ConsolidationPayload;
    },

    /** Consolidation commit — parent_versions (id → version) verified per parent. */
    consolidateCommit: async (body: ConsolidateBody, idempotencyKey: string): Promise<ConsolidationPayload> => {
        return (await apiClient.post('/parcels/consolidate', body, {
            headers: { 'Idempotency-Key': idempotencyKey },
        })) as ConsolidationPayload;
    },

    /** Genealogy graph with explicit truncation flags (never silent, TASK-118). */
    lineage: async (parcelId: string, direction: string, depth: number): Promise<LineagePayload> => {
        return (await apiClient.get(`/parcels/${parcelId}/lineage`, {
            params: { direction, depth },
        })) as LineagePayload;
    },
};

/** Split methods (api.md §8.2) — labels for the method selector. */
export const SPLIT_METHODS = [
    { value: 'MAP_SPLIT_LINE', label: 'Split line on map' },
    { value: 'TECHNICAL_DESCRIPTION', label: 'From technical descriptions' },
    { value: 'SURVEY_GEOMETRY', label: 'From survey geometry' },
    { value: 'IMPORTED_GEOMETRY', label: 'From imported geometry' },
] as const;

/** Children of these methods supply explicit geometry (GeoJSON per child). */
export const GEOMETRY_CHILD_METHODS: ReadonlySet<string> = new Set(['SURVEY_GEOMETRY', 'IMPORTED_GEOMETRY']);
