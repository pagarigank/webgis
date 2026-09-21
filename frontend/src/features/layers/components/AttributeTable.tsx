// @ts-nocheck
import React, { useMemo, useEffect, useCallback, useState } from 'react';
import {
    useReactTable,
    getCoreRowModel,
    getSortedRowModel,
    getPaginationRowModel,
    flexRender,
    type ColumnDef,
    type SortingState,
    type PaginationState,
} from '@tanstack/react-table';
import type { Feature } from '../../features/layers/types';
import { useMapContext } from '../../../features/map/MapContext';
import { layerApi } from '../api/layerApi';
import { hasPermission } from '../../../auth/permissions';
import { useAuth } from '../../../auth/useAuth';
import { useFeatureSelection } from '../FeatureSelectionContext';

interface AttributeTableProps {
    data: Feature[];
    columns: Array<'id' | 'status' | 'psgc_barangay' | 'provenance' | 'created_at' | 'updated_at'>;
    /** Source id used by the map for feature-state queries (TASK-065). */
    sourceLayerId?: string;
    /** If true, row click toggles multiple selection (shift-click multi). */
    multiSelect?: boolean;
    /** Layer id for CRUD operations. */
    layerId?: number;
    /** Current user for permission checks. */
    me?: any;
    total: number;
    page: number;
    perPage: number;
    sort: string;
    dir: 'ASC' | 'DESC';
    totalPages: number;
    onPageChange: (page: number) => void;
    onPerPageChange: (n: number) => void;
    onSortChange: (col: string, dir: 'ASC' | 'DESC') => void;
    /** Callback when a feature is created/updated/deleted — triggers re-fetch. */
    onFeaturesChanged?: () => void;
    /** Currently editing feature (for edit modal). */
    editingFeature?: Feature | null;
    /** Set editing feature. */
    setEditingFeature?: (f: Feature | null) => void;
    /** Error message to display. */
    error?: string | null;
    /** Whether a save operation is in progress. */
    saving?: boolean;
    onSaveComplete?: () => void;
    /** Fields available for this layer (for FieldRenderer). */
    fields?: any[];
}

const COLUMN_LABELS: Record<string, string> = {
    id: 'ID',
    status: 'Status',
    psgc_barangay: 'Barangay',
    provenance: 'Provenance',
    created_at: 'Created',
    updated_at: 'Updated',
};

function formatValue(col: string, value: unknown): string {
    if (value == null || value === '') return '—';
    if (col === 'id') return String(value);
    if (col === 'status') return String(value).toLowerCase();
    if (col === 'created_at' || col === 'updated_at') {
        if (typeof value === 'string') {
            try {
                return new Date(value).toLocaleString('en-PH', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                });
            } catch {
                return value;
            }
        }
        return String(value);
    }
    return String(value);
}

export function AttributeTable({
    data,
    columns,
    sourceLayerId,
    multiSelect,
    layerId,
    me,
    total,
    page,
    perPage,
    sort,
    dir,
    totalPages,
    onPageChange,
    onPerPageChange,
    onSortChange,
    onFeaturesChanged,
    editingFeature,
    setEditingFeature,
    error,
    saving,
    onSaveComplete,
    fields,
}: AttributeTableProps) {
    const { selectionManager, registerFeatureSources, setSelection, clearSelection, getSelectedIds } = useMapContext();
    const { hasPermission: hasPerm } = useAuth();
    const { selectedIds: sharedSelectedIds, toggle: toggleShared } = useFeatureSelection();
    const [sorting, setSorting] = React.useState<SortingState>(() => {
        const dirLower = dir.toLowerCase() as 'asc' | 'desc';
        return [{ id: sort, desc: dirLower === 'desc' }];
    });
    const [pagination, setPagination] = React.useMemo<PaginationState>(
        () => ({
            pageIndex: page - 1,
            pageSize: perPage,
        }),
        [page, perPage],
    );

    // Merge local selection with shared selection context
    const localSelectedIds = React.useRef(new Set<string>());
    const selectedIds = React.useMemo(() => {
        const merged = new Set(localSelectedIds.current);
        for (const id of sharedSelectedIds) {
            merged.add(id);
        }
        return merged;
    }, [sharedSelectedIds]);

    // Register feature sources after data loads (TASK-065)
    useEffect(() => {
        if (data.length > 0 && sourceLayerId) {
            const fc: GeoJSON.FeatureCollection = {
                type: 'FeatureCollection',
                features: data.map(f => ({
                    type: 'Feature' as const,
                    id: f.id,
                    geometry: f.geometry,
                    properties: f.attributes,
                })),
            };
            registerFeatureSources(fc, sourceLayerId);
        }
    }, [data, sourceLayerId, registerFeatureSources]);

    const handleRowClick = useCallback((featureId: string, event: React.MouseEvent) => {
        if (event.shiftKey && multiSelect) {
            const sm = selectionManager;
            if (sm) {
                sm.toggleFeature(featureId);
                localSelectedIds.current = new Set(sm.getSelectedIds());
            } else {
                const current = Array.from(getSelectedIds());
                if (current.includes(featureId)) {
                    setSelection(current.filter(id => id !== featureId));
                    localSelectedIds.current.delete(featureId);
                } else {
                    setSelection([...current, featureId]);
                    localSelectedIds.current.add(featureId);
                }
            }
            toggleShared(featureId);
        } else {
            const sm = selectionManager;
            if (sm) {
                sm.selectOne(featureId);
                localSelectedIds.current = new Set(sm.getSelectedIds());
                if (event.shiftKey) {
                    toggleShared(featureId);
                }
            } else {
                setSelection([featureId]);
                localSelectedIds.current = new Set([featureId]);
                if (event.shiftKey) {
                    toggleShared(featureId);
                }
            }
        }
    }, [selectionManager, getSelectedIds, setSelection, multiSelect, toggleShared]);

    const handleClearSelection = useCallback(() => {
        const sm = selectionManager;
        if (sm) {
            sm.clearSelection();
            localSelectedIds.current.clear();
        } else {
            clearSelection();
            localSelectedIds.current.clear();
        }
    }, [selectionManager, clearSelection]);

    const handleEditFeature = useCallback((feature: Feature) => {
        setEditingFeature?.(feature);
    }, [setEditingFeature]);

    const handleDeleteFeature = useCallback(async (feature: Feature) => {
        if (!layerId) return;
        try {
            await layerApi.deleteFeature(layerId, feature.id);
            if (onFeaturesChanged) onFeaturesChanged();
        } catch (err) {
            console.error('Failed to delete feature:', err);
        }
    }, [layerId, onFeaturesChanged]);

    const handleBulkDelete = useCallback(async () => {
        if (!layerId || selectedIds.size === 0) return;
        if (!confirm(`Delete ${selectedIds.size} selected features?`)) return;
        try {
            for (const id of selectedIds) {
                await layerApi.deleteFeature(layerId, id);
            }
            if (onFeaturesChanged) onFeaturesChanged();
            handleClearSelection();
        } catch (err) {
            console.error('Bulk delete failed:', err);
        }
    }, [layerId, selectedIds, onFeaturesChanged, handleClearSelection]);

    const handleDuplicateFeature = useCallback(async (feature: Feature) => {
        if (!layerId) return;
        try {
            await layerApi.createFeature(layerId, {
                geometry: feature.geometry,
                attributes: { ...feature.attributes },
                status: 'PENDING',
                psgc_barangay: feature.psgc_barangay ?? undefined,
                provenance: feature.provenance ?? undefined,
            });
            if (onFeaturesChanged) onFeaturesChanged();
        } catch (err) {
            console.error('Failed to duplicate feature:', err);
        }
    }, [layerId, onFeaturesChanged]);

    const columnsDef: ColumnDef<Feature>[] = useMemo(
        () =>
            columns.map((col) => ({
                accessorKey: col,
                header: COLUMN_LABELS[col] ?? col,
                cell: ({ getValue }) => {
                    const v = getValue<unknown>();
                    return <span className="cell-value">{formatValue(col, v)}</span>;
                },
            })),
        [columns],
    );

    const table = useReactTable({
        data,
        columns: columnsDef,
        state: {
            sorting,
            pagination,
        },
        onSortingChange: setSorting,
        onPaginationChange: setPagination,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getPaginationRowModel: getPaginationRowModel(),
        manualPagination: true,
        pageCount: totalPages,
        rowCount: total,
        enabledSortingMode: 'multi',
    });

    const selectedCount = selectedIds.size;
    const hasSelection = selectedCount > 0;
    const canEdit = hasPerm(me, 'gis.feature.update');
    const canDelete = hasPerm(me, 'gis.feature.delete');
    const canCreate = hasPerm(me, 'gis.feature.create');

    return (
        <div className="card shadow-sm">
            {/* Selection info bar */}
            {hasSelection && (
                <div className="mb-2 px-3 py-2 bg-warning-subtle border-bottom d-flex justify-content-between align-items-center">
                    <span className="text-danger fw-semibold">
                        {selectedCount} feature{selectedCount !== 1 ? 's' : ''} selected
                        {canDelete && (
                            <button
                                className="btn btn-sm btn-outline-danger ms-2"
                                onClick={handleBulkDelete}
                                disabled={saving}
                            >
                                Bulk delete
                            </button>
                        )}
                    </span>
                    <button
                        className="btn btn-sm btn-outline-secondary"
                        onClick={handleClearSelection}
                    >
                        Clear selection
                    </button>
                </div>
            )}

            <div className="table-responsive">
                <table className="table table-hover table-striped mb-0 align-middle">
                    <thead className="table-light">
                        {table.getHeaderGroups().map((headerGroup) => (
                            <tr key={headerGroup.id}>
                                {headerGroup.headers.map((header) => (
                                    <th
                                        key={header.id}
                                        scope="col"
                                        className="cursor-pointer"
                                        style={{
                                            userSelect: 'none',
                                            whiteSpace: 'nowrap',
                                        }}
                                        onClick={() => {
                                            const col = header.column.id;
                                            const nextDir =
                                                header.column.getIsSorted() === 'asc'
                                                    ? 'desc'
                                                    : header.column.getIsSorted() === 'desc'
                                                        ? undefined
                                                        : 'asc';
                                            const d: 'ASC' | 'DESC' =
                                                nextDir === 'asc' ? 'ASC' : 'DESC';
                                            onSortChange(col, d);
                                        }}
                                    >
                                        <div
                                            className="d-flex align-items-center gap-1"
                                            style={{ cursor: 'pointer' }}
                                        >
                                            {flexRender(
                                                header.column.columnDef.header,
                                                header.getContext(),
                                            )}
                                            <SortIndicator
                                                sorted={header.column.getIsSorted()}
                                            />
                                        </div>
                                    </th>
                                ))}
                                {/* Actions column */}
                                <th scope="col" className="text-nowrap" style={{ width: '140px', minWidth: '120px' }}>
                                    Actions
                                </th>
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {table.getRowModel().rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={columns.length + 1}
                                    className="text-center py-5 text-muted"
                                >
                                    {hasSelection && (
                                        <span className="badge bg-warning text-dark me-2" style={{ cursor: 'pointer' }} onClick={handleClearSelection}>
                                            Clear selection ({selectedCount})
                                        </span>
                                    )}
                                    No features to display.
                                </td>
                            </tr>
                        ) : (
                            table.getRowModel().rows.map((row) => {
                                const isSelected = selectedIds.has(row.id);
                                const feat = row.original;
                                return (
                                    <tr
                                        key={row.id}
                                        className={`feature-row ${isSelected ? 'table-primary' : ''}`}
                                        style={{
                                            cursor: 'pointer',
                                            background: isSelected ? 'rgba(255, 193, 7, 0.15)' : undefined,
                                        }}
                                        onClick={(e) => handleRowClick(row.id, e)}
                                    >
                                        {row.getVisibleCells().map((cell) => (
                                            <td key={cell.id} style={{ verticalAlign: 'middle' }}>
                                                {flexRender(
                                                    cell.column.columnDef.cell,
                                                    cell.getContext(),
                                                )}
                                            </td>
                                        ))}
                                        <td style={{ verticalAlign: 'middle', whiteSpace: 'nowrap' }}>
                                            <div className="btn-group btn-group-sm" style={{ display: 'flex', gap: 2 }}>
                                                {canEdit && (
                                                    <button
                                                        className="btn btn-outline-primary"
                                                        title="Edit feature"
                                                        onClick={(e) => { e.stopPropagation(); handleEditFeature(feat); }}
                                                        disabled={saving}
                                                    >
                                                        Edit
                                                    </button>
                                                )}
                                                {canDelete && (
                                                    <button
                                                        className="btn btn-outline-danger"
                                                        title="Delete feature"
                                                        onClick={(e) => { e.stopPropagation(); handleDeleteFeature(feat); }}
                                                        disabled={saving}
                                                    >
                                                        Del
                                                    </button>
                                                )}
                                                <button
                                                    className="btn btn-outline-secondary"
                                                    title="Zoom to feature on map"
                                                    onClick={(e) => { e.stopPropagation(); /* map zoom handled externally */ }}
                                                >
                                                    Zoom
                                                </button>
                                                {canEdit && (
                                                    <button
                                                        className="btn btn-outline-secondary"
                                                        title="Duplicate feature"
                                                        onClick={(e) => { e.stopPropagation(); handleDuplicateFeature(feat); }}
                                                        disabled={saving}
                                                    >
                                                        Dup
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {/* Pagination + per-page */}
            <div className="d-flex justify-content-between align-items-center flex-wrap gap-3 px-3 py-2 border-top">
                <div className="d-flex align-items-center gap-2">
                    <span className="text-muted small">
                        {hasSelection && (
                            <span className="badge bg-warning text-dark me-1">{selectedCount} selected</span>
                        )}
                    </span>
                </div>

                <div className="d-flex align-items-center gap-2">
                    <label className="text-muted small me-1">Show</label>
                    <select
                        className="form-select form-select-sm"
                        style={{ width: 'auto', width: '70px' }}
                        value={perPage}
                        onChange={(e) => onPerPageChange(Number(e.target.value))}
                    >
                        {[10, 25, 50, 100].map((n) => (
                            <option key={n} value={n}>
                                {n}
                            </option>
                        ))}
                    </select>
                    <span className="text-muted small">per page</span>
                </div>

                <div className="d-flex align-items-center gap-2">
                    <span className="text-muted small">
                        Page {page} of {totalPages}
                    </span>
                    <div className="d-flex gap-1">
                        <button
                            className="btn btn-outline-secondary btn-sm"
                            onClick={() => onPageChange(1)}
                            disabled={page <= 1}
                        >
                            « First
                        </button>
                        <button
                            className="btn btn-outline-secondary btn-sm"
                            onClick={() => onPageChange(page - 1)}
                            disabled={page <= 1}
                        >
                            ‹ Prev
                        </button>
                        <button
                            className="btn btn-outline-secondary btn-sm"
                            onClick={() => onPageChange(page + 1)}
                            disabled={page >= totalPages}
                        >
                            Next ›
                        </button>
                        <button
                            className="btn btn-outline-secondary btn-sm"
                            onClick={() => onPageChange(totalPages)}
                            disabled={page >= totalPages}
                        >
                            Last »
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function SortIndicator({
    sorted,
}: {
    sorted: false | 'asc' | 'desc';
}) {
    if (!sorted) return null;
    return (
        <span className="small text-muted" style={{ marginLeft: 4 }}>
            {sorted === 'asc' ? '▲' : '▼'}
        </span>
    );
}
