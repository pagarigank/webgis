import React, { useMemo, useEffect, useCallback } from 'react';
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
import { useMapContext } from '../../features/map/MapContext';

interface AttributeTableProps {
    data: Feature[];
    columns: Array<'id' | 'status' | 'psgc_barangay' | 'provenance' | 'created_at' | 'updated_at'>;
    /** Source id used by the map for feature-state queries (TASK-065). */
    sourceLayerId?: string;
    /** If true, row click toggles multiple selection (shift-click multi). */
    multiSelect?: boolean;
    total: number;
    page: number;
    perPage: number;
    sort: string;
    dir: 'ASC' | 'DESC';
    totalPages: number;
    onPageChange: (page: number) => void;
    onPerPageChange: (n: number) => void;
    onSortChange: (col: string, dir: 'ASC' | 'DESC') => void;
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
    total,
    page,
    perPage,
    sort,
    dir,
    totalPages,
    onPageChange,
    onPerPageChange,
    onSortChange,
}: AttributeTableProps) {
    const { selectionManager, registerFeatureSources, setSelection, clearSelection, getSelectedIds } = useMapContext();
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

    const selectedIds = React.useMemo(() => getSelectedIds(), [getSelectedIds]);

    const handleRowClick = useCallback((featureId: string, event: React.MouseEvent) => {
        if (event.shiftKey && multiSelect) {
            const sm = selectionManager;
            if (sm) {
                sm.toggleFeature(featureId);
            } else {
                const current = Array.from(getSelectedIds());
                if (current.includes(featureId)) {
                    setSelection(current.filter(id => id !== featureId));
                } else {
                    setSelection([...current, featureId]);
                }
            }
        } else {
            const sm = selectionManager;
            if (sm) {
                sm.selectOne(featureId);
            } else {
                setSelection([featureId]);
            }
        }
    }, [selectionManager, getSelectedIds, setSelection, multiSelect]);

    const handleClearSelection = useCallback(() => {
        const sm = selectionManager;
        if (sm) {
            sm.clearSelection();
        } else {
            clearSelection();
        }
    }, [selectionManager, clearSelection]);

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

    return (
        <div className="card shadow-sm">
            {/* Selection info bar */}
            {hasSelection && (
                <div className="mb-2 px-3 py-2 bg-warning-subtle border-bottom d-flex justify-content-between align-items-center">
                    <span className="text-danger fw-semibold">
                        {selectedCount} feature{selectedCount !== 1 ? 's' : ''} selected
                    </span>
                    <button
                        className="btn btn-sm btn-outline-danger"
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
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {table.getRowModel().rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={columns.length}
                                    className="text-center py-5 text-muted"
                                >
                                    No features to display.
                                </td>
                            </tr>
                        ) : (
                            table.getRowModel().rows.map((row) => {
                                const isSelected = selectedIds.has(row.id);
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
                            <>
                                <span className="badge bg-warning text-dark me-1">{selectedCount} selected</span>
                            </>
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
