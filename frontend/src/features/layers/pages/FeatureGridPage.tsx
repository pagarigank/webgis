import { useQuery } from '@tanstack/react-query';
import { layerApi, type FeatureCollection } from '../api/layerApi';
import type { Feature } from '../types';
import { AttributeTable } from '../components/AttributeTable';

interface FeatureGridPageProps {
    layerId: number;
}

/** Columns that are always shown: core identity + status. */
const CORE_COLUMNS = ['id', 'status', 'psgc_barangay', 'provenance', 'created_at', 'updated_at'] as const;

export function FeatureGridPage({ layerId }: FeatureGridPageProps) {
    // Pagination/sort/filter state from URL
    const search = new URLSearchParams(window.location.search);
    const page = Math.max(1, parseInt(search.get('page') ?? '1', 10));
    const perPage = Math.min(100, Math.max(10, parseInt(search.get('per_page') ?? '50', 10)));
    const sort = search.get('sort') ?? 'created_at';
    const dir = (search.get('dir') ?? 'desc').toUpperCase() as 'ASC' | 'DESC';
    const status = search.get('status') ?? undefined;
    const offset = (page - 1) * perPage;

    const { data, isLoading, isError } = useQuery({
        queryKey: ['features', layerId, { page, perPage, sort, dir, status }],
        queryFn: () =>
            layerApi.getFeatures(layerId, {
                limit: perPage,
                offset,
                sort,
                dir,
                status,
            }),
        placeholderData: (prev) => prev,
    });

    if (isError) {
        return (
            <div className="container py-4">
                <div className="alert alert-danger">Failed to load features. Please try again.</div>
            </div>
        );
    }

    const collection: FeatureCollection | undefined = data;

    if (isLoading || !collection) {
        return (
            <div className="container py-4">
                <div>Loading features...</div>
            </div>
        );
    }

    const totalPages = Math.max(1, Math.ceil(collection.total / perPage));

    return (
        <div className="container py-4">
            <div className="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <div>
                    <h4 className="mb-1">
                        Feature Attributes — Layer #{layerId}
                        <span className="text-muted ms-2 fw-normal">
                            {collection.total.toLocaleString()} total
                        </span>
                    </h4>
                    <div className="text-muted small">
                        Showing {collection.data.length} of {collection.total} features
                        {status ? ` (filtered by status=${status})` : ''}
                    </div>
                </div>
                <div className="d-flex gap-2 flex-wrap">
                    <select
                        className="form-select form-select-sm"
                        style={{ width: 'auto' }}
                        value={status ?? ''}
                        onChange={(e) => {
                            const params = new URLSearchParams(window.location.search);
                            if (e.target.value) {
                                params.set('status', e.target.value);
                            } else {
                                params.delete('status');
                            }
                            params.delete('page');
                            window.location.search = params.toString();
                        }}
                    >
                        <option value="">All statuses</option>
                        <option value="ACTIVE">Active</option>
                        <option value="PENDING">Pending</option>
                        <option value="REJECTED">Rejected</option>
                        <option value="ARCHIVED">Archived</option>
                    </select>
                    <button
                        className="btn btn-outline-secondary btn-sm"
                        onClick={() => {
                            window.location.search = `layer_id=${layerId}`;
                        }}
                    >
                        Reset filters
                    </button>
                </div>
            </div>

            <AttributeTable
                data={collection.data}
                columns={CORE_COLUMNS}
                sourceLayerId={`features-${layerId}`}
                multiSelect={true}
                total={collection.total}
                page={page}
                perPage={perPage}
                sort={sort}
                dir={dir}
                totalPages={totalPages}
                onPageChange={(p) => {
                    const params = new URLSearchParams(window.location.search);
                    params.set('page', String(Math.max(1, p)));
                    params.delete('per_page');
                    window.location.search = params.toString();
                }}
                onPerPageChange={(n) => {
                    const params = new URLSearchParams(window.location.search);
                    params.set('per_page', String(Math.min(100, Math.max(10, n))));
                    params.set('page', '1');
                    window.location.search = params.toString();
                }}
                onSortChange={(col, d) => {
                    const params = new URLSearchParams(window.location.search);
                    params.set('sort', col);
                    params.set('dir', d);
                    params.delete('page');
                    window.location.search = params.toString();
                }}
            />

            {/* Feature metadata footer */}
            <div className="mt-3 text-muted small">
                {collection.data.length === 0 && (
                    <span>No features match the current filters.</span>
                )}
                {collection.total > 0 && (
                    <span>
                        Data as of {new Date().toISOString().slice(0, 10)} ·
                        page {page} of {totalPages}
                    </span>
                )}
            </div>
        </div>
    );
}
