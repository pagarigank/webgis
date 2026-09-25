/**
 * @vitest-environment jsdom
 */
import { describe, it, expect } from 'vitest';
import {
    buildSplitBody,
    buildSplitLine,
    canCommitSplit,
    initialSplitForm,
    previewStateKey,
    ringOf,
} from './SplitTab';
import type { SplitFormState } from './SplitTab';
import type { SplitPayload } from '../api/operationsApi';
import type { Parcel } from '../types';

/**
 * TASK-116 — the split tab's commit gate is pure logic: commit is enabled
 * only from a successful preview of the CURRENT inputs, and editing anything
 * invalidates the preview. These tests pin that contract without DOM.
 */

const parcel: Parcel = {
    id: 'p1',
    parcel_code: 'TEST_01',
    lot_number: null,
    block_number: null,
    title_number_ref: null,
    tax_declaration_no: null,
    source_area_sqm: null,
    source_area_unit: null,
    computed_area_sqm: null,
    psgc_barangay: null,
    psgc_barangay_name: null,
    psgc_municipality: null,
    psgc_province: null,
    survey_plan_id: null,
    survey_plan_number: null,
    location_description: null,
    status: 'DRAFT',
    provenance: 'MANUAL_DRAWING',
    geometry_source: 'MANUAL_DRAWING',
    verification_status: null,
    org_id: null,
    remarks: null,
    version: 1,
    created_by: null,
    created_at: '',
    updated_by: null,
    updated_at: '',
    geometry: {
        type: 'Polygon',
        coordinates: [
            [
                [121.0, 14.6],
                [121.001, 14.6],
                [121.001, 14.601],
                [121.0, 14.601],
                [121.0, 14.6],
            ],
        ],
    },
};

function passingPreview(): SplitPayload {
    return {
        operation_id: null,
        dry_run: true,
        method: 'MAP_SPLIT_LINE',
        parent: 'p1',
        children: [],
        area_reconciliation: { parent_sqm: 1, children_sum_sqm: 1, difference_sqm: 0, difference_pct: 0 },
        validation: { passed: true, checks: [], warnings: [] },
        parent_after: { status: 'SUPERSEDED' },
    };
}

describe('ringOf', () => {
    it('extracts the exterior ring of a polygon', () => {
        expect(ringOf(parcel.geometry)).toHaveLength(5);
    });

    it('returns empty for missing geometry', () => {
        expect(ringOf(null)).toEqual([]);
        expect(ringOf({ type: 'Point', coordinates: [0, 0] })).toEqual([]);
    });
});

describe('buildSplitLine', () => {
    it('runs vertically through the bbox midline, extended past the bounds', () => {
        const line = buildSplitLine(parcel, 0);
        const [start, end] = line;
        expect(start[0]).toBe(end[0]); // vertical
        expect(start[1]).toBeLessThan(14.6); // extends below the bbox
        expect(end[1]).toBeGreaterThan(14.601); // extends above the bbox
        expect(start[0]).toBeCloseTo(121.0005, 6); // midline of [121.0, 121.001]
    });

    it('applies the offset', () => {
        const [a] = buildSplitLine(parcel, 0.0002);
        const [b] = buildSplitLine(parcel, 0);
        expect(a[0]).toBeCloseTo(b[0] + 0.0002, 6);
    });

    it('degrades to a degenerate line when there is no geometry', () => {
        const line = buildSplitLine({ ...parcel, geometry: null }, 0);
        expect(line).toHaveLength(2);
    });
});

describe('buildSplitBody', () => {
    it('sends the split line and lot numbers for MAP_SPLIT_LINE', () => {
        const body = buildSplitBody(
            parcel,
            { ...initialSplitForm, lotA: '100-A', lotB: '100-B', reason: 'plan Psd-1' },
            [
                [121.0005, 14.5],
                [121.0005, 14.7],
            ],
        );
        expect(body.method).toBe('MAP_SPLIT_LINE');
        expect(body.split_line?.coordinates).toEqual([
            [121.0005, 14.5],
            [121.0005, 14.7],
        ]);
        expect(body.children[0].lot_number).toBe('100-A');
        expect(body.children[1].lot_number).toBe('100-B');
        expect(body.reason).toBe('plan Psd-1');
    });

    it('sends technical-description ids for TECHNICAL_DESCRIPTION', () => {
        const body = buildSplitBody(parcel, { ...initialSplitForm, method: 'TECHNICAL_DESCRIPTION', tdA: '11', tdB: '12' }, null);
        expect(body.children[0].technical_description_id).toBe(11);
        expect(body.children[1].technical_description_id).toBe(12);
    });
});

describe('preview invalidation (commit gate)', () => {
    it('enables commit only for a passing preview of the current inputs', () => {
        const form: SplitFormState = { ...initialSplitForm, lotA: 'A' };
        const key = previewStateKey(form, null);
        expect(canCommitSplit(passingPreview(), key, key)).toBe(true);
    });

    it('stays locked until a preview exists', () => {
        const key = previewStateKey(initialSplitForm, null);
        expect(canCommitSplit(null, '', key)).toBe(false);
    });

    it('invalidates when the inputs change after the preview', () => {
        const form = { ...initialSplitForm, lotA: 'A' };
        const key = previewStateKey(form, null);
        const edited: SplitFormState = { ...form, lotA: 'A2' };
        const editedKey = previewStateKey(edited, null);
        expect(canCommitSplit(passingPreview(), key, editedKey)).toBe(false);
    });

    it('invalidates when the drawn line moves', () => {
        const keyA = previewStateKey(initialSplitForm, [
            [0, 0],
            [1, 1],
        ]);
        const keyB = previewStateKey(initialSplitForm, [
            [0, 0],
            [2, 2],
        ]);
        expect(canCommitSplit(passingPreview(), keyA, keyB)).toBe(false);
    });

    it('stays locked for a failing preview', () => {
        const form = initialSplitForm;
        const key = previewStateKey(form, null);
        const failing = passingPreview();
        failing.validation.passed = false;
        expect(canCommitSplit(failing, key, key)).toBe(false);
    });
});
