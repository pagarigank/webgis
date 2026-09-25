// @vitest-environment jsdom
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { AttributeTable } from './AttributeTable';
import type { Feature } from '../types';

afterEach(cleanup);

// Mock dependencies
vi.mock('../../../features/map/MapContext', () => ({
    useMapContext: () => ({
        selectionManager: null,
        registerFeatureSources: vi.fn(),
        setSelection: vi.fn(),
        clearSelection: vi.fn(),
        getSelectedIds: () => new Set<string>(),
    }),
}));

vi.mock('../../../auth/useAuth', () => ({
    useAuth: () => ({
        hasPermission: () => true,
    }),
}));

vi.mock('../FeatureSelectionContext', () => ({
    useFeatureSelection: () => ({
        selectedIds: new Set<string>(),
        toggle: vi.fn(),
    }),
}));

const mockFeatures: Feature[] = [
    {
        id: 'feat-1',
        status: 'ACTIVE',
        psgc_barangay: '137404001',
        provenance: 'MANUAL_DRAWING',
        version: 1,
        created_by: 1,
        created_at: '2026-09-20T10:00:00Z',
        updated_by: 1,
        updated_at: '2026-09-20T10:00:00Z',
        attributes: { name: 'Parcel A' },
        geometry: { type: 'Polygon', coordinates: [] },
    },
    {
        id: 'feat-2',
        status: 'PENDING',
        psgc_barangay: '137404002',
        provenance: 'CAD_IMPORT',
        version: 2,
        created_by: 1,
        created_at: '2026-09-21T12:00:00Z',
        updated_by: 1,
        updated_at: '2026-09-21T12:00:00Z',
        attributes: { name: 'Parcel B' },
        geometry: { type: 'Polygon', coordinates: [] },
    },
];

describe('AttributeTable', () => {
    const defaultProps = {
        data: mockFeatures,
        columns: ['id', 'status', 'psgc_barangay', 'provenance', 'created_at'] as Array<'id' | 'status' | 'psgc_barangay' | 'provenance' | 'created_at' | 'updated_at'>,
        total: 2,
        page: 1,
        perPage: 10,
        sort: 'created_at',
        dir: 'DESC' as const,
        totalPages: 1,
        onPageChange: vi.fn(),
        onPerPageChange: vi.fn(),
        onSortChange: vi.fn(),
    };

    it('renders headers and feature rows', () => {
        render(<AttributeTable {...defaultProps} />);

        expect(screen.getByText('ID')).toBeTruthy();
        expect(screen.getByText('Status')).toBeTruthy();
        expect(screen.getByText('Barangay')).toBeTruthy();
        expect(screen.getByText('Provenance')).toBeTruthy();

        expect(screen.getByText('feat-1')).toBeTruthy();
        expect(screen.getByText('feat-2')).toBeTruthy();
        expect(screen.getByText('active')).toBeTruthy();
        expect(screen.getByText('pending')).toBeTruthy();
    });

    it('renders empty state when data is empty', () => {
        render(<AttributeTable {...defaultProps} data={[]} total={0} />);
        expect(screen.getByText('No features to display.')).toBeTruthy();
    });

    it('calls onSortChange when header is clicked', () => {
        const onSortChange = vi.fn();
        render(<AttributeTable {...defaultProps} onSortChange={onSortChange} />);

        const statusHeader = screen.getByText('Status');
        fireEvent.click(statusHeader);

        expect(onSortChange).toHaveBeenCalledWith('status', 'ASC');
    });
});
