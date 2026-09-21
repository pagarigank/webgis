import React, { useQuery, useCallback, useState, useMemo, useRef } from 'react';
import { layerApi, type FeatureCollection } from '../api/layerApi';
import type { Feature } from '../types';
import { AttributeTable } from '../components/AttributeTable';
import { FeatureEditor } from '../components/FeatureEditor';
import type { LayerField } from '../types';
import { useAuth } from '../../auth/useAuth';
import { hasPermission } from '../../auth/permissions';

interface FeatureGridPageProps {
    layerId: number;
}

/** Columns that are always shown: core identity + status. */
const CORE_COLUMNS = ['id', 'status', 'psgc_barangay', 'provenance', 'created_at', 'updated_at'] as const;

export function FeatureGridPage({ layerId }: FeatureGridPageProps) {
    const { me } = useAuth();
    const search = new URLSearchParams(window.location.search);
    const page = Math.max(1, parseInt(search.get('page') ?? '1', 10));
    const perPage = Math.min(100, Math.max(10, parseInt(search.get('per_page') ?? '50', 10)));
    const sort = search.get('sort') ?? 'created_at';
    const dir = (search.get('dir') ?? 'desc').toUpperCase() as 'ASC' | 'DESC';
    const status = search.get('status') ?? undefined;
    const offset = (page - 1) * perPage;

    const { data, isLoading, isError, refetch } = useQuery({
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

    const [editingFeature, setEditingFeature] = useState<Feature | null>(null);
    const [editorMode, setEditorMode] = useState<'create' | 'edit'>('edit');
    const [editorError, setEditorError] = useState<string | null>(null);
    const [editorSaving, setEditorSaving] = useState(false);

    // Layer fields — in a real app these come from the layer metadata endpoint
    const [layerFields, setLayerFields] = useState<LayerField[]>([]);
    const [fieldsLoading, setFieldsLoading] = useState(true);

    // Load layer fields once
    useEffect(() => {
        const loadFields = async () => {
            try {
                const layer = await layerApi.getById(layerId);
                setLayerFields(layer.fields ?? []);
            } catch {
                setLayerFields([]);
            } finally {
                setFieldsLoading(false);
            }
        };
        loadFields();
    }, [layerId]);

    const handleFeaturesChanged = useCallback(() => {
        refetch();
    }, [refetch]);

    const handleCreate = useCallback(() => {
        setEditorMode('create');
        setEditingFeature(null);
        setEditorError(null);
    }, []);

    const handleEdit = useCallback((feature: Feature) => {
        setEditorMode('edit');
        setEditingFeature(feature);
        setEditorError(null);
    }, []);

    const handleEditorSave = useCallback(() => {
        setEditingFeature(null);
        handleFeaturesChanged();
    }, [handleFeaturesChanged]);

    const handleEditorClose = useCallback(() => {
        setEditingFeature(null);
        setEditorError(null);
    }, []);

    const canCreate = hasPermission(me, 'gis.feature.create');
    const canEdit = hasPermission(me, 'gis.feature.update');
    const canDelete = hasPermission(me, 'gis.feature.delete');
    const canViewPII = hasPermission(me, 'user.view.pii') || hasPermission(me, 'organization.view.pii');

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
            {/* Toolbar */}
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
                    {canCreate && (
                        <button
                            className="btn btn-primary btn-sm"
                            onClick={handleCreate}
                            disabled={editorSaving}
                        >
                            + New Feature
                        </button>
                    )}
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
                layerId={layerId}
                me={me}
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
                onFeaturesChanged={handleFeaturesChanged}
                editingFeature={editingFeature}
                setEditingFeature={setEditingFeature}
                error={editorError}
                saving={editorSaving}
                onSaveComplete={handleEditorSave}
            />

            {/* Feature editor modal */}
            {editingFeature || editorMode === 'create' ? (
                <FeatureEditor
                    open={true}
                    onClose={handleEditorClose}
                    onSave={handleEditorSave}
                    mode={editorMode}
                    feature={editingFeature}
                    layerId={layerId}
                    fields={layerFields}
                    canViewPII={canViewPII}
                    saving={editorSaving}
                    error={editorError}
                    setError={setEditorError}
                />
            ) : null}

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
