import { describe, it, expect } from 'vitest';
import { buildConsolidateBody, canCommitConsolidation, offendingParents, initialConsolidationForm } from './ConsolidationTab';
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
