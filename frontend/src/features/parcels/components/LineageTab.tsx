import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { operationsApi } from '../api/operationsApi';
import type { LineageEdge, LineageNode, LineagePayload } from '../api/operationsApi';
import { StatusBadge } from './badges';

/**
 * TASK-118 — lineage view (architecture.md §18.5, api.md §8.4).
 *
 * Genealogy graph: nodes by generation depth with status badges, areas and
 * dates; edges labelled with the relationship type; expand controls (depth +
 * direction); depth truncation is STATED, never silent (the TASK-118 AC) via
 * the explicit truncated flags the backend reports; JSON export of the
 * returned graph.
 *
 * Pure helpers (layoutLineage, lineageExport) are extracted for unit tests.
 */

export interface LaidOutNode {
    node: LineageNode;
    /** Column index (0 = root, ancestors negative, descendants positive). */
    col: number;
    /** Row index within the column. */
    row: number;
}

/**
 * Layered layout: ancestors at negative columns, root at 0, descendants at
 * positive columns (siblings share a column). The backend reports generation
 * depth but not the side, so sides are relaxed outward from the root over the
 * edge list: a child of an ancestor-side node joins the descendant side one
 * column further, and vice versa.
 */
export function layoutLineage(payload: LineagePayload): LaidOutNode[] {
    const root = payload.nodes.find((n) => n.depth === 0);
    if (root == null) return [];

    const side = new Map<string, number>([[root.id, 0]]);
    let changed = true;
    while (changed) {
        changed = false;
        for (const e of payload.edges) {
            const pSide = side.get(e.parent);
            const cSide = side.get(e.child);
            // Down/right: a known parent on the root or descendant side places
            // its unknown child one column further right.
            if (pSide != null && pSide >= 0 && cSide == null) {
                side.set(e.child, pSide + 1);
                changed = true;
            }
            // Up/left: a known child on the root or ancestor side places its
            // unknown parent one column further left.
            else if (cSide != null && cSide <= 0 && pSide == null) {
                side.set(e.parent, cSide - 1);
                changed = true;
            }
        }
    }

    const placed: LaidOutNode[] = payload.nodes.map((node) => ({ node, col: side.get(node.id) ?? 0, row: 0 }));
    const perCol = new Map<number, number>();
    for (const p of placed) {
        const r = perCol.get(p.col) ?? 0;
        p.row = r;
        perCol.set(p.col, r + 1);
    }
    placed.sort((a, b) => a.col - b.col || a.row - b.row);
    return placed;
}

/** Build the downloadable JSON export of the currently displayed graph. */
export function lineageExport(payload: LineagePayload, parcelCode: string): string {
    return JSON.stringify(
        {
            parcel: parcelCode,
            direction: payload.direction,
            depth: payload.depth,
            truncated: payload.truncated,
            nodes: payload.nodes,
            edges: payload.edges,
        },
        null,
        2,
    );
}

const DIRECTIONS = [
    { value: 'both', label: 'Both directions' },
    { value: 'ancestors', label: 'Ancestors' },
    { value: 'descendants', label: 'Descendants' },
] as const;

export function LineageTab({ parcelId, parcelCode }: { parcelId: string; parcelCode: string }) {
    const [direction, setDirection] = useState('both');
    const [depth, setDepth] = useState(3);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['parcel', parcelId, 'lineage', direction, depth],
        queryFn: () => operationsApi.lineage(parcelId, direction, depth),
        enabled: parcelId.length > 0,
    });

    const layout = useMemo(() => (data ? layoutLineage(data) : []), [data]);
    const nodesById = useMemo(() => new Map((data?.nodes ?? []).map((n) => [n.id, n])), [data]);

    function downloadExport() {
        if (!data) return;
        const blob = new Blob([lineageExport(data, parcelCode)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `lineage-${parcelCode}.json`;
        a.click();
        URL.revokeObjectURL(url);
    }

    if (isLoading) return <p className="text-muted">Loading lineage…</p>;
    if (isError || !data) return <p className="text-danger">The lineage graph could not be loaded.</p>;

    return (
        <div data-testid="lineage-tab">
            <div className="d-flex flex-wrap gap-3 align-items-end mb-3">
                <div>
                    <label className="form-label small mb-1" htmlFor="lineage-direction">
                        Direction
                    </label>
                    <select id="lineage-direction" data-testid="lineage-direction" className="form-select form-select-sm" value={direction} onChange={(e) => setDirection(e.target.value)}>
                        {DIRECTIONS.map((d) => (
                            <option key={d.value} value={d.value}>
                                {d.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="form-label small mb-1" htmlFor="lineage-depth">
                        Depth (1–10)
                    </label>
                    <input
                        id="lineage-depth"
                        data-testid="lineage-depth"
                        type="number"
                        min={1}
                        max={10}
                        className="form-control form-control-sm"
                        style={{ width: 90 }}
                        value={depth}
                        onChange={(e) => setDepth(Math.min(10, Math.max(1, Number(e.target.value) || 1)))}
                    />
                </div>
                <button type="button" className="btn btn-outline-secondary btn-sm" data-testid="lineage-expand" onClick={() => setDepth((d) => Math.min(10, d + 1))}>
                    Expand +1
                </button>
                <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => setDepth((d) => Math.max(1, d - 1))}>
                    Collapse −1
                </button>
                <button type="button" className="btn btn-outline-secondary btn-sm ms-auto" data-testid="lineage-export" onClick={downloadExport}>
                    Export JSON
                </button>
            </div>

            {/* Truncation is stated, never silent (TASK-118 AC). */}
            {data.truncated && (
                <div className="alert alert-warning py-2 small" data-testid="lineage-truncated">
                    Graph truncated at depth {data.depth}
                    {data.truncated_ancestors && ' — more ancestors exist'}
                    {data.truncated_descendants && ' — more descendants exist'}
                    . Increase the depth to reveal them.
                </div>
            )}

            <div className="lineage-graph" data-testid="lineage-graph" style={{ overflowX: 'auto' }}>
                {layout.length === 0 && <p className="text-muted">No related parcels.</p>}
                <div className="d-inline-flex flex-row align-items-stretch gap-4 py-2">
                    {[...new Set(layout.map((p) => p.col))]
                        .sort((a, b) => a - b)
                        .map((col) => (
                            <div key={col} className="d-flex flex-column gap-2 justify-content-center" data-testid={`lineage-col-${col}`}>
                                {layout
                                    .filter((p) => p.col === col)
                                    .map((p) => (
                                        <LineageNodeCard key={p.node.id} node={p.node} isRoot={p.node.depth === 0} />
                                    ))}
                                {layout.filter((p) => p.col === col).length === 0 && <span className="text-muted small">—</span>}
                            </div>
                        ))}
                </div>
            </div>

            <div className="mt-4" data-testid="lineage-edges">
                <h6>Relationships</h6>
                <table className="table table-sm">
                    <thead>
                        <tr>
                            <th>Parent</th>
                            <th>Child</th>
                            <th>Type</th>
                            <th>Effective</th>
                            <th>Operation</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.edges.map((e, i) => (
                            <LineageEdgeRow key={i} edge={e} nodesById={nodesById} />
                        ))}
                        {data.edges.length === 0 && (
                            <tr>
                                <td colSpan={5} className="text-muted small">
                                    No relationships.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function LineageNodeCard({ node, isRoot }: { node: LineageNode; isRoot: boolean }) {
    return (
        <div
            className={`card ${isRoot ? 'border-primary' : ''}`}
            style={{ minWidth: 220 }}
            data-testid={`lineage-node-${node.parcel_code}`}
            data-status={node.status}
        >
            <div className="card-body py-2 px-3">
                <div className="d-flex justify-content-between align-items-center gap-2">
                    {/* Superseded nodes stay distinct (badge) but navigable —
                        TASK-118 AC. */}
                    <Link to={`/parcels/${node.id}/lineage`} className="small fw-semibold text-decoration-none">
                        {node.parcel_code}
                    </Link>
                    <StatusBadge status={node.status} />
                </div>
                <div className="small text-muted">
                    {node.lot_number != null && <>lot {node.lot_number} · </>}
                    {node.area_sqm != null ? `${node.area_sqm.toLocaleString()} m²` : 'area —'}
                    {isRoot && <span className="badge bg-primary ms-1">this parcel</span>}
                </div>
            </div>
        </div>
    );
}

function LineageEdgeRow({ edge, nodesById }: { edge: LineageEdge; nodesById: Map<string, LineageNode> }) {
    return (
        <tr>
            <td className="small">{nodesById.get(edge.parent)?.parcel_code ?? edge.parent.slice(0, 8)}</td>
            <td className="small">{nodesById.get(edge.child)?.parcel_code ?? edge.child.slice(0, 8)}</td>
            <td>
                <span className="badge bg-light text-dark border small">{edge.type}</span>
            </td>
            <td className="small text-muted">{edge.effective_date ?? '—'}</td>
            <td className="small text-muted">{edge.operation_id != null ? `#${edge.operation_id}` : '—'}</td>
        </tr>
    );
}
