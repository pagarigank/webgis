/**
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { HistoryTab, renderValue } from './HistoryTab';
import type { ParcelVersionSummary, ParcelComparePayload } from '../types';

afterEach(cleanup);
vi.clearAllMocks();

/**
 * TASK-104a - history tab behaviour.
 *
 * The restore gate matters because restore writes a new version: the UI must
 * not let a restore run without a recorded reason, and must hide restore
 * entirely for a user without parcel.version.restore rather than showing a
 * button that 403s.
 */

const summary = (over: Partial<ParcelVersionSummary> = {}): ParcelVersionSummary => ({
    id: 1,
    version: 1,
    status: 'PUBLISHED',
    provenance: 'SURVEYED',
    change_summary: 'Initial import',
    change_reason: null,
    changed_by: 1,
    changed_at: '2026-01-02T03:04:05Z',
    has_geometry: true,
    request_id: null,
    ...over,
});

const listVersions = vi.fn();
const compareVersions = vi.fn();
const restoreVersion = vi.fn();
const timeline = vi.fn();

vi.mock('../api/parcelApi', () => ({
    parcelApi: {
        listVersions: (...a: unknown[]) => listVersions(...a),
        compareVersions: (...a: unknown[]) => compareVersions(...a),
        restoreVersion: (...a: unknown[]) => restoreVersion(...a),
        timeline: (...a: unknown[]) => timeline(...a),
        timelineExportUrl: (id: string) => `/api/v1/parcels/${id}/timeline/export`,
    },
}));

const PERMS: string[] = ['parcel.view'];
vi.mock('../../../auth/useAuth', () => ({ useAuth: () => ({ me: { permissions: PERMS } }) }));
vi.mock('../../../auth/permissions', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../auth/permissions')>();
    return {
        ...actual,
        hasPermission: (_me: unknown, code: string) => PERMS.includes(code),
    };
});

const renderTab = () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const Wrapper = ({ children }: { children: React.ReactNode }) => (
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    );
    return render(<HistoryTab parcelId="p-1" currentVersion={2} />, { wrapper: Wrapper });
};

const comparePayload: ParcelComparePayload = {
    parcel_id: 'p-1',
    from_version: 1,
    to_version: 2,
    field_changes: [
        { field: 'lot_number', old: '11', new: '12', changed: true },
        { field: 'block_number', old: null, new: '4', changed: true },
    ],
    geometry_diff: { added: [], removed: [], moved: [{ index: 2, from: [1, 2], to: [1, 3] }], old_vertex_count: 4, new_vertex_count: 4 },
};

describe('renderValue', () => {
    it('renders empty values as a dash rather than blank cells', () => {
        expect(renderValue(null)).toBe('—');
        expect(renderValue(undefined)).toBe('—');
        expect(renderValue('')).toBe('—');
    });

    it('does not throw on object values', () => {
        expect(renderValue({ a: 1 })).toBe('{"a":1}');
    });

    it('keeps zero and false visible', () => {
        expect(renderValue(0)).toBe('0');
        expect(renderValue(false)).toBe('false');
    });
});

describe('HistoryTab versions', () => {
    it('lists versions and marks the current one', async () => {
        listVersions.mockResolvedValue({
            data: [summary({ version: 2, change_summary: 'Boundary corrected' }), summary({ version: 1 })],
            pagination: { page: 1, per_page: 20, total: 2 },
        });
        renderTab();
        await waitFor(() => expect(screen.getByTestId('history-version-2')).toBeDefined());
        expect(screen.getByTestId('history-version-2').textContent).toContain('Boundary corrected');
        expect(screen.getByTestId('history-version-2').textContent).toContain('current');
        expect(screen.getByTestId('history-version-1').textContent).not.toContain('current');
    });

    it('reports an empty lineage plainly', async () => {
        listVersions.mockResolvedValue({ data: [], pagination: { page: 1, per_page: 20, total: 0 } });
        renderTab();
        await waitFor(() => expect(screen.getByText('No revisions recorded yet.')).toBeDefined());
    });

    it('hides restore from a user without parcel.version.restore', async () => {
        listVersions.mockResolvedValue({ data: [summary()], pagination: { page: 1, per_page: 20, total: 1 } });
        renderTab();
        await waitFor(() => expect(screen.getByTestId('history-version-1')).toBeDefined());
        expect(screen.queryByTestId('history-restore-1')).toBeNull();
    });
});

describe('HistoryTab compare', () => {
    it('diffs the selected version and renders attribute and geometry changes', async () => {
        listVersions.mockResolvedValue({
            data: [summary({ version: 2 }), summary({ version: 1 })],
            pagination: { page: 1, per_page: 20, total: 2 },
        });
        compareVersions.mockResolvedValue(comparePayload);
        renderTab();
        await waitFor(() => expect(screen.getByTestId('history-compare-from-1')).toBeDefined());
        fireEvent.click(screen.getByTestId('history-compare-from-1'));

        await waitFor(() => expect(screen.getByTestId('history-compare-fields')).toBeDefined());
        expect(compareVersions).toHaveBeenCalledWith('p-1', 1, undefined);
        const table = screen.getByTestId('history-compare-fields').textContent ?? '';
        expect(table).toContain('lot_number');
        expect(table).toContain('11');
        expect(table).toContain('12');
        expect(table).toContain('block_number');

        const geom = screen.getByTestId('history-compare-geometry').textContent ?? '';
        expect(geom).toContain('moved: 1');
    });

    it('compares an explicit pair when a second version is chosen', async () => {
        listVersions.mockResolvedValue({
            data: [summary({ version: 2 }), summary({ version: 1 })],
            pagination: { page: 1, per_page: 20, total: 2 },
        });
        compareVersions.mockResolvedValue(comparePayload);
        renderTab();
        await waitFor(() => expect(screen.getByTestId('history-compare-from-1')).toBeDefined());
        fireEvent.click(screen.getByTestId('history-compare-from-1'));
        await waitFor(() => expect(screen.getByTestId('history-compare-to-2')).toBeDefined());
        fireEvent.click(screen.getByTestId('history-compare-to-2'));
        await waitFor(() => expect(compareVersions).toHaveBeenLastCalledWith('p-1', 1, 2));
    });

    it('states plainly when two versions differ in nothing', async () => {
        listVersions.mockResolvedValue({ data: [summary()], pagination: { page: 1, per_page: 20, total: 1 } });
        compareVersions.mockResolvedValue({ ...comparePayload, field_changes: [], geometry_diff: null });
        renderTab();
        await waitFor(() => expect(screen.getByTestId('history-compare-from-1')).toBeDefined());
        fireEvent.click(screen.getByTestId('history-compare-from-1'));
        await waitFor(() => expect(screen.getByTestId('history-compare-no-changes')).toBeDefined());
        expect(screen.getByText('No geometry change.')).toBeDefined();
    });
});

describe('HistoryTab timeline', () => {
    it('renders merged events oldest first and links the export', async () => {
        listVersions.mockResolvedValue({ data: [], pagination: { page: 1, per_page: 20, total: 0 } });
        timeline.mockResolvedValue({
            parcel_id: 'p-1',
            count: 2,
            events: [
                { kind: 'VERSION', at: '2026-01-01T00:00:00Z', actor: 'admin', action: 'Version 1 recorded (ACTIVE)', detail: {}, misc: {} },
                { kind: 'WORKFLOW', at: '2026-01-03T00:00:00Z', actor: 'reviewer', action: 'ACTIVE -> PUBLISHED', detail: {}, misc: {} },
            ],
        });
        renderTab();
        fireEvent.click(screen.getByTestId('history-view-timeline'));
        await waitFor(() => expect(screen.getAllByTestId('timeline-event').length).toBe(2));
        const events = screen.getAllByTestId('timeline-event').map((e) => e.textContent ?? '');
        expect(events[0]).toContain('Version 1 recorded');
        expect(events[1]).toContain('ACTIVE -> PUBLISHED');
        expect((screen.getByTestId('history-timeline-export') as HTMLAnchorElement).getAttribute('href'))
            .toBe('/api/v1/parcels/p-1/timeline/export');
    });
});

describe('HistoryTab restore', () => {
    it('requires a recorded reason before restore can run', async () => {
        PERMS.push('parcel.version.restore');
        listVersions.mockResolvedValue({ data: [summary()], pagination: { page: 1, per_page: 20, total: 1 } });
        restoreVersion.mockResolvedValue({ id: 'p-1', version: 3 });
        renderTab();
        await waitFor(() => expect(screen.getByTestId('history-restore-1')).toBeDefined());
        fireEvent.click(screen.getByTestId('history-restore-1'));

        const confirm = screen.getByTestId('history-restore-confirm') as HTMLButtonElement;
        expect(confirm.disabled).toBe(true);

        fireEvent.change(screen.getByTestId('history-restore-reason'), { target: { value: '  ' } });
        expect((screen.getByTestId('history-restore-confirm') as HTMLButtonElement).disabled).toBe(true);

        fireEvent.change(screen.getByTestId('history-restore-reason'), { target: { value: 'Reverted to survey plan SP-4' } });
        expect((screen.getByTestId('history-restore-confirm') as HTMLButtonElement).disabled).toBe(false);
        fireEvent.click(screen.getByTestId('history-restore-confirm'));
        await waitFor(() => expect(restoreVersion).toHaveBeenCalledWith('p-1', 1, 'Reverted to survey plan SP-4'));
    });

    it('tells the user that restore records a new version rather than rewinding', async () => {
        PERMS.push('parcel.version.restore');
        listVersions.mockResolvedValue({ data: [summary()], pagination: { page: 1, per_page: 20, total: 1 } });
        renderTab();
        await waitFor(() => expect(screen.getByTestId('history-restore-1')).toBeDefined());
        fireEvent.click(screen.getByTestId('history-restore-1'));
        expect(screen.getByTestId('history-restore-panel').textContent).toContain('does not rewind');
    });

    it('surfaces a restore failure instead of silently doing nothing', async () => {
        PERMS.push('parcel.version.restore');
        listVersions.mockResolvedValue({ data: [summary()], pagination: { page: 1, per_page: 20, total: 1 } });
        restoreVersion.mockRejectedValue({ response: { data: { error: { code: 'CONFLICT', message: 'Parcel changed since v1' } } } });
        renderTab();
        await waitFor(() => expect(screen.getByTestId('history-restore-1')).toBeDefined());
        fireEvent.click(screen.getByTestId('history-restore-1'));
        fireEvent.change(screen.getByTestId('history-restore-reason'), { target: { value: 'because' } });
        fireEvent.click(screen.getByTestId('history-restore-confirm'));
        await waitFor(() => expect(screen.getByTestId('history-error').textContent).toContain('Parcel changed since v1'));
    });
});

describe('HistoryTab compare failures', () => {
    it('surfaces a failed comparison instead of spinning forever', async () => {
        listVersions.mockResolvedValue({ data: [summary()], pagination: { page: 1, per_page: 20, total: 1 } });
        compareVersions.mockRejectedValue({ response: { data: { error: { code: 'NOT_FOUND', message: 'Parcel version not found' } } } });
        renderTab();
        await waitFor(() => expect(screen.getByTestId('history-compare-from-1')).toBeDefined());
        fireEvent.click(screen.getByTestId('history-compare-from-1'));
        await waitFor(() => expect(screen.getByTestId('history-compare-error').textContent).toContain('Parcel version not found'));
        expect(screen.getByTestId('history-compare-panel').textContent).toContain('Comparison failed');
    });
});
