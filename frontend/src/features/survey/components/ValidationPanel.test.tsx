// @vitest-environment jsdom
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { ValidationPanel } from './ValidationPanel';
import { validationApi } from '../api/validationApi';
import type { ValidationResult } from '../api/validationApi';

afterEach(cleanup);

const mockPassedValidationResult: ValidationResult = {
    parcel_id: 'test-parcel-uuid',
    computation_id: 101,
    passed: true,
    can_submit: true,
    blocking_count: 0,
    warning_count: 1,
    blocking_failures: [],
    warnings: [
        {
            id: 'tie_point_verified',
            name: 'Tie Point Verification',
            rule: 'VR-19',
            status: 'WARN',
            severity: 'warning',
            message: 'Tie point BLLM 1 is unverified; persisted as a review warning for approval.',
        },
    ],
    checks: [
        {
            id: 'technical_description',
            name: 'Technical Description',
            rule: 'VR-TD-CONFIRMED',
            status: 'PASS',
            severity: 'info',
            message: 'Technical description (Rev 1) is confirmed.',
        },
        {
            id: 'tie_point_found',
            name: 'Tie Point Monument',
            rule: 'VR-TIE-FOUND',
            status: 'PASS',
            severity: 'info',
            message: 'Tie point monument attached: BLLM 1.',
        },
        {
            id: 'tie_point_verified',
            name: 'Tie Point Verification',
            rule: 'VR-19',
            status: 'WARN',
            severity: 'warning',
            message: 'Tie point BLLM 1 is unverified; persisted as a review warning for approval.',
        },
        {
            id: 'course_count',
            name: 'Minimum Course Count',
            rule: 'VR-10',
            status: 'PASS',
            severity: 'info',
            message: 'Boundary contains 4 courses forming a closed ring.',
        },
        {
            id: 'bearing_reference',
            name: 'Bearing Reference',
            rule: 'VR-BEARING-REF',
            status: 'PASS',
            severity: 'info',
            message: 'Bearing reference is GRID.',
        },
        {
            id: 'course_syntax',
            name: 'Course Syntax & Geometry Rules',
            rule: 'VR-01',
            status: 'PASS',
            severity: 'info',
            message: 'All boundary course bearings and distances conform to survey standards.',
        },
        {
            id: 'crs_area_of_use',
            name: 'Compute Coordinate System',
            rule: 'VR-20',
            status: 'PASS',
            severity: 'info',
            message: 'Compute CRS EPSG:3123 (PRS92 / Zone III) is within declared area of use.',
        },
        {
            id: 'traverse_closure',
            name: 'Traverse Closure Tolerance',
            rule: 'VR-11',
            status: 'PASS',
            severity: 'info',
            message: 'Traverse closure is within tolerance (LE: 0.0020m, Precision: 1:150000).',
        },
        {
            id: 'geometry_validity',
            name: 'Geometry Topology & Simplicity',
            rule: 'VR-14',
            status: 'PASS',
            severity: 'info',
            message: 'Computed boundary polygon is topologically valid and non-self-intersecting.',
        },
        {
            id: 'area_plausibility',
            name: 'Area Plausibility',
            rule: 'VR-15',
            status: 'PASS',
            severity: 'info',
            message: 'Computed planar area is 5000.00 sqm.',
        },
        {
            id: 'area_reconciliation',
            name: 'Area Comparison vs Source',
            rule: 'VR-16',
            status: 'PASS',
            severity: 'info',
            message: 'Computed area matches source area within 0.5% (diff: 0.00 sqm).',
        },
        {
            id: 'parcel_overlap',
            name: 'Cadastral Boundary Overlap',
            rule: 'VR-18',
            status: 'PASS',
            severity: 'info',
            message: 'No boundary overlap detected with active cadastral parcels.',
        },
    ],
    area_comparison: {
        computed_sqm: 5000.0,
        postgis_sqm: 5000.04,
        source_sqm: 5000.0,
        note: 'Area comparison is a validation aid, not a determination of correctness.',
    },
    overlap_summary: {
        has_overlap: false,
        has_significant_overlap: false,
        total_overlap_area_sqm: 0.0,
        sliver_count: 0,
        overlapping_parcels: [],
    },
    validated_at: '2026-09-24T10:00:00Z',
};

const mockFailedValidationResult: ValidationResult = {
    ...mockPassedValidationResult,
    passed: false,
    can_submit: false,
    blocking_count: 1,
    blocking_failures: [
        {
            id: 'traverse_closure',
            name: 'Traverse Closure Tolerance',
            rule: 'VR-11',
            status: 'FAIL',
            severity: 'blocking',
            message: 'Traverse linear error 0.8500m exceeds maximum tolerance.',
        },
    ],
    checks: mockPassedValidationResult.checks.map((c) =>
        c.id === 'traverse_closure'
            ? {
                  id: 'traverse_closure',
                  name: 'Traverse Closure Tolerance',
                  rule: 'VR-11',
                  status: 'FAIL' as const,
                  severity: 'blocking' as const,
                  message: 'Traverse linear error 0.8500m exceeds maximum tolerance.',
              }
            : c
    ),
};

describe('ValidationPanel (TASK-099)', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('renders the 12-point checklist with status badges and rule IDs', async () => {
        vi.spyOn(validationApi, 'getValidation').mockResolvedValue(mockPassedValidationResult);

        render(
            <ValidationPanel
                parcelId="test-parcel-uuid"
                parcel={{ id: 'test-parcel-uuid', status: 'DRAFT' }}
            />
        );

        await waitFor(() => {
            expect(screen.getByTestId('validation-panel')).toBeDefined();
        });

        // Verify checklist table is rendered
        expect(screen.getByTestId('checklist-table')).toBeDefined();

        // Verify specific rule IDs are displayed
        expect(screen.getByTestId('rule-id-technical_description').textContent).toBe('VR-TD-CONFIRMED');
        expect(screen.getByTestId('rule-id-tie_point_found').textContent).toBe('VR-TIE-FOUND');
        expect(screen.getByTestId('rule-id-tie_point_verified').textContent).toBe('VR-19');
        expect(screen.getByTestId('rule-id-course_count').textContent).toBe('VR-10');
        expect(screen.getByTestId('rule-id-crs_area_of_use').textContent).toBe('VR-20');
        expect(screen.getByTestId('rule-id-traverse_closure').textContent).toBe('VR-11');
        expect(screen.getByTestId('rule-id-parcel_overlap').textContent).toBe('VR-18');
    });

    it('displays mandatory validation aid note per FR-127 and keeps warnings visible per FR-126', async () => {
        vi.spyOn(validationApi, 'getValidation').mockResolvedValue(mockPassedValidationResult);

        render(
            <ValidationPanel
                parcelId="test-parcel-uuid"
                parcel={{ id: 'test-parcel-uuid', status: 'DRAFT' }}
            />
        );

        await waitFor(() => {
            expect(screen.getByTestId('area-comparison-card')).toBeDefined();
        });

        // FR-127: Area comparison validation aid note
        const note = screen.getByTestId('area-validation-note');
        expect(note.textContent).toContain('Area comparison is a validation aid, not a determination of correctness.');

        // FR-126: Warnings are visible in full and there is NO "dismiss-all" button
        expect(screen.queryByText(/dismiss all/i)).toBeNull();
        expect(screen.queryByText(/suppress/i)).toBeNull();
        expect(screen.getByText(/Tie point BLLM 1 is unverified/i)).toBeDefined();
    });

    it('disables submit button on blocking failures and enables when can_submit is true', async () => {
        vi.spyOn(validationApi, 'getValidation').mockResolvedValue(mockFailedValidationResult);

        render(
            <ValidationPanel
                parcelId="test-parcel-uuid"
                parcel={{ id: 'test-parcel-uuid', status: 'DRAFT' }}
            />
        );

        await waitFor(() => {
            expect(screen.getByTestId('btn-submit-for-review')).toBeDefined();
        });

        const submitBtn = screen.getByTestId('btn-submit-for-review') as HTMLButtonElement;
        expect(submitBtn.disabled).toBe(true);

        // Re-run validation with passed result
        vi.spyOn(validationApi, 'getValidation').mockResolvedValue(mockPassedValidationResult);
        fireEvent.click(screen.getByTestId('btn-revalidate'));

        await waitFor(() => {
            const btn = screen.getByTestId('btn-submit-for-review') as HTMLButtonElement;
            expect(btn.disabled).toBe(false);
        });
    });

    it('triggers "show me" navigation action to relevant tab', async () => {
        vi.spyOn(validationApi, 'getValidation').mockResolvedValue(mockPassedValidationResult);
        const onNavigateTab = vi.fn();

        render(
            <ValidationPanel
                parcelId="test-parcel-uuid"
                parcel={{ id: 'test-parcel-uuid', status: 'DRAFT' }}
                onNavigateTab={onNavigateTab}
            />
        );

        await waitFor(() => {
            expect(screen.getByTestId('show-me-technical_description')).toBeDefined();
        });

        fireEvent.click(screen.getByTestId('show-me-technical_description'));
        expect(onNavigateTab).toHaveBeenCalledWith('techdesc');
    });

    it('opens submission modal and submits parcel with justification', async () => {
        vi.spyOn(validationApi, 'getValidation').mockResolvedValue(mockPassedValidationResult);
        const submitSpy = vi.spyOn(validationApi, 'submitParcel').mockResolvedValue({
            parcel: { id: 'test-parcel-uuid', status: 'SUBMITTED' },
            validation: mockPassedValidationResult,
        });
        const onSubmitted = vi.fn();

        render(
            <ValidationPanel
                parcelId="test-parcel-uuid"
                parcel={{ id: 'test-parcel-uuid', status: 'DRAFT' }}
                onSubmitted={onSubmitted}
            />
        );

        await waitFor(() => {
            expect(screen.getByTestId('btn-submit-for-review')).toBeDefined();
        });

        fireEvent.click(screen.getByTestId('btn-submit-for-review'));

        // Modal should appear
        await waitFor(() => {
            expect(screen.getByTestId('submit-modal')).toBeDefined();
        });
        expect(screen.getByTestId('btn-confirm-submit')).toBeDefined();

        fireEvent.click(screen.getByTestId('btn-confirm-submit'));

        await waitFor(() => {
            expect(submitSpy).toHaveBeenCalledWith('test-parcel-uuid', expect.any(String));
            expect(onSubmitted).toHaveBeenCalled();
        });
    });
});
