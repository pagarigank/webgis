import { describe, it, expect } from 'vitest';
import { layoutLineage, lineageExport } from './LineageTab';
import type { LineageEdge, LineageNode, LineagePayload } from '../api/operationsApi';

/**
 * TASK-118 — pure logic for the lineage view: the layered layout (ancestors
 * left, root centre, descendants right, siblings sharing a column) and the
 * JSON export shape.
 */

function node(id: string, code: string, depth: number, status = 'DRAFT'): LineageNode {
    return { id, parcel_code: code, lot_number: null, status, area_sqm: 1000, depth };
}

function edge(parent: string, child: string, type = 'SUBDIVISION'): LineageEdge {
    return { parent, child, type, operation_id: 1, effective_date: '2026-09-25' };
}

function payload(nodes: LineageNode[], edges: LineageEdge[], truncated = false): LineagePayload {
    return { nodes, edges, truncated, truncated_ancestors: truncated, truncated_descendants: false, depth: 3, direction: 'both' };
}

describe('layoutLineage', () => {
    it('places the root at column 0 and direct parent/child at ±1', () => {
        const p = payload(
            [node('g', 'GP', 2), node('pa', 'P', 1), node('root', 'ROOT', 0), node('c1', 'C1', 1), node('c2', 'C2', 1)],
            [edge('g', 'pa'), edge('pa', 'root'), edge('root', 'c1'), edge('root', 'c2')],
        );
        const layout = layoutLineage(p);
        const colOf = (id: string) => layout.find((l) => l.node.id === id)?.col;
        expect(colOf('root')).toBe(0);
        expect(colOf('pa')).toBe(-1);
        expect(colOf('g')).toBe(-2);
        expect(colOf('c1')).toBe(1);
        expect(colOf('c2')).toBe(1);
    });

    it('stacks siblings in separate rows of the same column', () => {
        const p = payload(
            [node('root', 'ROOT', 0), node('c1', 'C1', 1), node('c2', 'C2', 1)],
            [edge('root', 'c1'), edge('root', 'c2')],
        );
        const layout = layoutLineage(p);
        const rows = layout.filter((l) => l.col === 1).map((l) => l.row);
        expect(new Set(rows).size).toBe(2);
    });

    it('handles grandchildren on both sides', () => {
        const p = payload(
            [node('gp', 'GP', 2), node('pa', 'P', 1), node('root', 'ROOT', 0), node('c', 'C', 1), node('gc', 'GC', 2)],
            [edge('gp', 'pa'), edge('pa', 'root'), edge('root', 'c'), edge('c', 'gc')],
        );
        const layout = layoutLineage(p);
        const colOf = (id: string) => layout.find((l) => l.node.id === id)?.col;
        expect(colOf('gp')).toBe(-2);
        expect(colOf('pa')).toBe(-1);
        expect(colOf('gc')).toBe(2);
    });

    it('returns empty without a root node', () => {
        expect(layoutLineage(payload([], []))).toEqual([]);
    });
});

describe('lineageExport', () => {
    it('records truncation and the graph verbatim', () => {
        const p = payload([node('root', 'ROOT', 0)], [], true);
        const parsed = JSON.parse(lineageExport(p, 'ROOT')) as Record<string, unknown>;
        expect(parsed['parcel']).toBe('ROOT');
        expect(parsed['truncated']).toBe(true);
        expect(Array.isArray(parsed['nodes'])).toBe(true);
        expect(Array.isArray(parsed['edges'])).toBe(true);
    });
});
