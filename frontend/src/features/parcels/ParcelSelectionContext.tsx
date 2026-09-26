import React, { createContext, useCallback, useContext, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import type { ParcelHit } from '../types';

/**
 * TASK-104b - the parcel selection set.
 *
 * Split needs exactly one parent parcel and consolidation needs two or more, so
 * the map action menu collects hits here and the consolidate route picks them
 * up as its starting set. The selection survives a click on the map but not a
 * reload, which matches the existing FeatureSelectionManager behaviour for
 * layer features.
 *
 * The backend re-validates every parent at consolidation time (union preview,
 * area reconciliation, and a commit-time data-scope assertion), so the ids held
 * here are treated as a hint, never as authority.
 */
interface ParcelSelectionState {
    /** Selected parcels, insertion-ordered so the first pick is the primary. */
    selection: ParcelHit[];
    isSelected: (id: string) => boolean;
    /** Add the hit, or remove it when already selected. Returns true if added. */
    toggle: (hit: ParcelHit) => boolean;
    remove: (id: string) => void;
    clear: () => void;
    /** Ids in selection order, for handing off to a route. */
    ids: () => string[];
}

const ParcelSelectionContext = createContext<ParcelSelectionState | undefined>(undefined);

export const ParcelSelectionProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
    const [selection, setSelection] = useState<ParcelHit[]>([]);

    const isSelected = useCallback(
        (id: string) => selection.some((p) => p.id === id),
        [selection],
    );

    const toggle = useCallback((hit: ParcelHit) => {
        let added = false;
        setSelection((prev) => {
            if (prev.some((p) => p.id === hit.id)) {
                return prev.filter((p) => p.id !== hit.id);
            }
            // Re-picking an already-known parcel refreshes its attributes
            // (status, area, version) rather than appending a duplicate.
            added = true;
            return [...prev.filter((p) => p.id !== hit.id), hit];
        });
        return added;
    }, []);

    const remove = useCallback((id: string) => {
        setSelection((prev) => prev.filter((p) => p.id !== id));
    }, []);

    const clear = useCallback(() => setSelection([]), []);

    const ids = useCallback(() => selection.map((p) => p.id), [selection]);

    const value = useMemo<ParcelSelectionState>(
        () => ({ selection, isSelected, toggle, remove, clear, ids }),
        [selection, isSelected, toggle, remove, clear, ids],
    );

    return <ParcelSelectionContext.Provider value={value}>{children}</ParcelSelectionContext.Provider>;
};

export function useParcelSelection(): ParcelSelectionState {
    const ctx = useContext(ParcelSelectionContext);
    if (ctx === undefined) {
        throw new Error('useParcelSelection must be used within a ParcelSelectionProvider');
    }
    return ctx;
}
