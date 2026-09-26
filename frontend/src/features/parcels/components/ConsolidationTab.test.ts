import { describe, it, expect } from 'vitest';
import { buildConsolidateBody, canCommitConsolidation, offendingParents, initialConsolidationForm, seedParentSelection, mergeParentRows } from './ConsolidationTab';
import type { ConsolidationPayload, ValidationCheck } from '../api/operationsApi';

/**
 * TASK-117 — pure logic for the consolidation tab: the commit gate (commit
 * unlocks only from a PASSING preview) and the offending-parent extraction
 * that drives the red map highlighting (the AC: blocking failures highlight
 * the offending parcels on the map rather than a generic error).
 */

describe('buildConsolidateBody', () => {
    it('includes parent_versions only for commit', () => {
        const versions = { a: 1, b: 2 };
        const previewBody = buildConsolidateBody(['a', 'b'], versions, initialConsolidationForm, false);
        const commitBody = buildConsolidateBody(['a', 'b'], versions, initialConsolidationForm, true);
        expect(previewBody.parent_versions).toBeUndefined();
        expect(commitBody.parent_versions).toEqual(versions);
    });

    it('preserves the input order (first input seeds the new code)', () => {
        const body = buildConsolidateBody(['b-id', 'a-id'], {}, initialConsolidationForm, false);
        expect(body.parent_parcel_ids).toEqual(['b-id', 'a-id']);
    });

    it('trims optional fields and keeps multipart choice', () => {
        const body = buildConsolidateBody(['a', 'b'], {}, { ...initialConsolidationForm, lotNumber: ' 201 ', allowMultipart: true }, false);
        expect(body.new_parcel?.lot_number).toBe('201');
        expect(body.allow_multipart).toBe(true);
    });
});

describe('offendingParents', () => {
    it('extracts parent indexes from VR-41 overlap messages', () => {
        const failures: ValidationCheck[] = [
            { rule: 'VR-41', status: 'fail', message: 'Parents overlap: #0 × #2 by 12.5 m² (ε 0.01 m²).' },
        ];
        expect(offendingParents(failures)).toEqual([0, 2]);
    });

    it('collects indexes across multiple failures without duplicates', () => {
        const failures: ValidationCheck[] = [
            { rule: 'VR-41', status: 'fail', message: '#0 × #1 overlap.' },
            { rule: 'VR-42', status: 'fail', message: 'Gap 40.0 m² (#1, #3).' },
        ];
        expect(offendingParents(failures)).toEqual([0, 1, 3]);
    });

    it('returns empty when no parent is named', () => {
        expect(offendingParents([{ rule: 'VR-40', status: 'fail', message: 'Requires at least 2 parents.' }])).toEqual([]);
    });
});

describe('commit gate', () => {
    const payload = (passed: boolean): ConsolidationPayload =>
        ({
            operation_id: null,
            dry_run: true,
            result: { parcel_id: null, parcel_code: null, area_sqm: null, geometry: null },
            area_reconciliation: { parents_sum_sqm: 0, union_sqm: null, difference_sqm: 0 },
            validation: { passed, checks: [], warnings: [] },
            parents_after: [],
        }) as ConsolidationPayload;

    it('unlocks only from a passing preview', () => {
        expect(canCommitConsolidation(payload(true))).toBe(true);
        expect(canCommitConsolidation(payload(false))).toBe(false);
        expect(canCommitConsolidation(null)).toBe(false);
    });
});

describe('seedParentSelection (TASK-104b map multi-select)', () => {
    it('keeps map pick order so the first pick is the primary parent', () => {
        expect(seedParentSelection(['m1', 'm2', 'm3'])).toEqual(['m1', 'm2', 'm3']);
    });

    it('appends the tab parcel when it is not already picked', () => {
        expect(seedParentSelection(['m1', 'm2'], 'tab-id')).toEqual(['m1', 'm2', 'tab-id']);
    });

    it('does not duplicate a parcel that was both picked and opened', () => {
        // A parcel picked on the map and then opened directly must not end up
        // as its own co-parent in the union.
        expect(seedParentSelection(['m1', 'm2'], 'm1')).toEqual(['m1', 'm2']);
    });

    it('falls back to the tab parcel when nothing was picked on the map', () => {
        expect(seedParentSelection([], 'tab-id')).toEqual(['tab-id']);
        expect(seedParentSelection([])).toEqual([]);
    });

    it('de-duplicates repeated map picks', () => {
        expect(seedParentSelection(['m1', 'm1', 'm2'], 'm1')).toEqual(['m1', 'm2']);
    });
});

describe('mergeParentRows (TASK-104b off-page parents)', () => {
    const row = (id: string, version: number) => ({
        id,
        parcel_code: `P-${id}`,
        status: 'ACTIVE',
        geometry: null,
        version,
    });

    it('resolves a parent that is only in the directly fetched set', () => {
        // The regression: a map-picked parent is not on the 25-row candidate
        // page, so its version was missing and commit failed 428/409.
        const { rows, versions, unresolved } = mergeParentRows([row('a', 2)], [row('b', 7)], ['a', 'b']);
        expect(rows.map((r) => r.id)).toEqual(['a', 'b']);
        expect(versions).toEqual({ a: 2, b: 7 });
        expect(unresolved).toEqual([]);
    });

    it('preserves selection order regardless of source order', () => {
        const { rows, versions } = mergeParentRows([row('a', 1), row('b', 1)], [row('c', 1)], ['c', 'a']);
        expect(rows.map((r) => r.id)).toEqual(['c', 'a']);
        expect(Object.keys(versions)).toEqual(['c', 'a']);
    });

    it('reports an id present in neither source as unresolved', () => {
        const { rows, versions, unresolved } = mergeParentRows([row('a', 1)], [], ['a', 'ghost']);
        expect(rows.map((r) => r.id)).toEqual(['a']);
        expect(unresolved).toEqual(['ghost']);
        expect(versions['ghost']).toBeUndefined();
    });

    it('omits a zero version so the commit gate stays shut', () => {
        const { versions } = mergeParentRows([], [row('a', 0)], ['a']);
        expect(versions).toEqual({});
    });

    it('lets a fresh fetch win over a stale list row', () => {
        const { versions } = mergeParentRows([row('a', 1)], [row('a', 9)], ['a']);
        expect(versions).toEqual({ a: 9 });
    });
});
