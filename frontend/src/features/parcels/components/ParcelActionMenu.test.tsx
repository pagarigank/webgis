/**
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react';
import { useEffect } from 'react';
import { MemoryRouter } from 'react-router-dom';
import { ParcelActionMenu } from './ParcelActionMenu';
import { ParcelSelectionProvider, useParcelSelection } from '../ParcelSelectionContext';
import type { ParcelHit } from '../types';

afterEach(cleanup);

/**
 * TASK-104b - map-driven parcel actions.
 *
 * The regression this file exists to prevent: the action menu originally
 * navigated to `/parcels/consolidate`, which is not a route. It collides with
 * `/parcels/:id/:tab?`, so the app rendered the parcel editor for a parcel whose
 * id was the literal string "consolidate". Consolidation is a tab, so the
 * destination must be `/parcels/<primaryParentId>/consolidate`.
 */

const hit = (over: Partial<ParcelHit> = {}): ParcelHit => ({
    id: '11111111-1111-1111-1111-111111111111',
    parcel_code: 'P-001',
    status: 'PUBLISHED',
    geometry_source: 'SURVEYED',
    psgc_province: null,
    psgc_municipality: null,
    psgc_barangay: null,
    source_area_sqm: null,
    source_area_unit: null,
    computed_area_sqm: 1000,
    version: 3,
    area_m2: 1000,
    area_ha: null,
    distance_m: 0,
    contains_point: true,
    ...over,
});

vi.mock('../../../auth/useAuth', () => ({
    useAuth: () => ({ me: { permissions: ['parcel.view', 'parcel.update', 'parcel.split', 'parcel.consolidate'] } }),
}));

vi.mock('../../../auth/permissions', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../auth/permissions')>();
    return {
        ...actual,
        hasPermission: (me: unknown, code: string) =>
            (me as { permissions: string[] }).permissions.includes(code),
    };
});

/** Captures every navigate() call so destinations can be asserted exactly. */
const navigateSpy = vi.fn();
vi.mock('react-router-dom', async (importOriginal) => {
    const actual = await importOriginal<typeof import('react-router-dom')>();
    return { ...actual, useNavigate: () => navigateSpy };
});

const renderMenu = (hits: ParcelHit[], activeId: string | null = hits[0]?.id ?? null) =>
    render(
        <MemoryRouter>
            <ParcelSelectionProvider>
                <ParcelActionMenu
                    hits={hits}
                    activeId={activeId}
                    onSelectHit={vi.fn()}
                    onClose={vi.fn()}
                />
            </ParcelSelectionProvider>
        </MemoryRouter>,
    );

describe('ParcelActionMenu destinations', () => {
    it('sends split, lineage and history to the parcel editor tabs', () => {
        const a = hit({ id: 'aaaa', parcel_code: 'P-AAA' });
        renderMenu([a]);

        fireEvent.click(screen.getByTestId('parcel-action-split'));
        expect(navigateSpy).toHaveBeenLastCalledWith('/parcels/aaaa/split');

        fireEvent.click(screen.getByTestId('parcel-action-lineage'));
        expect(navigateSpy).toHaveBeenLastCalledWith('/parcels/aaaa/lineage');

        fireEvent.click(screen.getByTestId('parcel-action-history'));
        expect(navigateSpy).toHaveBeenLastCalledWith('/parcels/aaaa/history');
    });

    it('opens the editor for the active parcel', () => {
        renderMenu([hit({ id: 'bbbb' })]);
        fireEvent.click(screen.getByTestId('parcel-action-open'));
        expect(navigateSpy).toHaveBeenLastCalledWith('/parcels/bbbb');
    });

    it('never navigates to a non-existent /parcels/consolidate route', () => {
        // Regression: this used to be the consolidate destination and it
        // collided with /parcels/:id/:tab?.
        const a = hit({ id: 'cccc' });
        renderMenu([a]);
        fireEvent.click(screen.getByTestId('parcel-action-consolidate'));
        expect(navigateSpy).toHaveBeenLastCalledWith('/parcels/cccc/consolidate');
        expect(navigateSpy).not.toHaveBeenCalledWith('/parcels/consolidate');
    });

    it('carries an existing multi-selection through to the primary parent', async () => {
        const a = hit({ id: 'dddd', parcel_code: 'P-DDDD' });
        const b = hit({ id: 'eeee', parcel_code: 'P-EEEE' });
        const { getByTestId } = render(
            <MemoryRouter>
                <ParcelSelectionProvider>
                    <Seed ids={['dddd', 'eeee']} />
                    <ParcelActionMenu hits={[a]} activeId={a.id} onSelectHit={vi.fn()} onClose={vi.fn()} />
                </ParcelSelectionProvider>
            </MemoryRouter>,
        );
        // Picked already, so consolidate keeps the first pick as primary rather
        // than re-adding the active parcel.
        await waitFor(() => expect(screen.getByTestId('seeded').textContent).toBe('2'));
        fireEvent.click(getByTestId('parcel-action-consolidate'));
        expect(navigateSpy).toHaveBeenLastCalledWith('/parcels/dddd/consolidate');
    });
});

describe('ParcelActionMenu multi-select', () => {
    it('adds the active parcel to the shared selection', () => {
        const a = hit({ id: 'ffff', parcel_code: 'P-FFFF' });
        render(
            <MemoryRouter>
                <ParcelSelectionProvider>
                    <ParcelActionMenu hits={[a]} activeId={a.id} onSelectHit={vi.fn()} onClose={vi.fn()} />
                    <SelectionSummary />
                </ParcelSelectionProvider>
            </MemoryRouter>,
        );
        expect(screen.queryByTestId('parcel-selection-summary')).toBeNull();
        fireEvent.click(screen.getByTestId('parcel-action-select'));
        expect(screen.getByTestId('parcel-selection-summary').textContent).toContain('1 selected');
        expect(screen.getByTestId('parcel-action-select').textContent).toContain('Remove from selection');
    });

    it('lists every hit at an overlapping click and can switch between them', () => {
        const a = hit({ id: 'g1', parcel_code: 'P-G1', contains_point: true, distance_m: 0 });
        const b = hit({ id: 'g2', parcel_code: 'P-G2', contains_point: false, distance_m: 12 });
        const onSelectHit = vi.fn();
        render(
            <MemoryRouter>
                <ParcelSelectionProvider>
                    <ParcelActionMenu hits={[a, b]} activeId={a.id} onSelectHit={onSelectHit} onClose={vi.fn()} />
                </ParcelSelectionProvider>
            </MemoryRouter>,
        );
        expect(screen.getByTestId('parcel-action-menu-title').textContent).toContain('2 parcels here');
        expect(screen.getByTestId('parcel-action-code').textContent).toContain('P-G1');
        fireEvent.click(screen.getByText('P-G2'));
        expect(onSelectHit).toHaveBeenCalledWith('g2');
    });
});

/** Pre-populates the selection context before the menu renders. */
function Seed({ ids }: { ids: string[] }) {
    const { selection, toggle } = useParcelSelection();
    useEffect(() => {
        for (const id of ids) toggle(hit({ id, parcel_code: id.toUpperCase() }));
        // Seeding is a one-shot setup step for this fixture.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    return <div data-testid="seeded">{selection.length}</div>;
}

function SelectionSummary() {
    const { selection } = useParcelSelection();
    return <div data-testid="outer-summary">{selection.map((p) => p.id).join(',')}</div>;
}
