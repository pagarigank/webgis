// @ts-nocheck
import React, { createContext, useContext, useState, useCallback, useRef } from 'react';
import type { ReactNode } from 'react';

/**
 * Shared selection state for the map↔table sync (TASK-065).
 *
 * Lives in the layers feature because both FeatureGridPage (table side) and
 * the map feature need to read/write the same selection. The map feature
 * imports this context via the layers types package (no circular dep).
 */

export interface SelectedFeatureInfo {
    featureId: string;
    layerId: number;
    sourceId: string;
}

interface FeatureSelectionContextValue {
    /** Currently selected feature ids (ordered). */
    selectedIds: ReadonlySet<string>;
    /** True when anything is selected. */
    hasSelection: boolean;
    /** Number of selected features. */
    size: number;
    /** Select exactly these ids (replaces). */
    select: (ids: string[]) => void;
    /** Toggle one id in/out of the selection (multi-select toggle). */
    toggle: (id: string) => void;
    /** Clear all selection. */
    clear: () => void;
    /** Register that a feature id lives on a given source (map side calls this after load). */
    register: (featureId: string, sourceId: string, layerId: number) => void;
    /** Callback the table calls when the user clicks a row. */
    onRowSelect: (featureId: string) => void;
    /** Callback the map calls when the user clicks a map feature. */
    onMapSelect: (featureId: string, sourceId: string, layerId: number) => void;
}

const FeatureSelectionContext = createContext<FeatureSelectionContextValue | null>(null);

export function FeatureSelectionProvider({ children }: { children: ReactNode }) {
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const sourceMapRef = useRef<Map<string, { sourceId: string; layerId: number }>>(new Map());

    const value: FeatureSelectionContextValue = {
        get selectedIds() {
            return selected;
        },
        get hasSelection() {
            return selected.size > 0;
        },
        get size() {
            return selected.size;
        },
        select: useCallback((ids: string[]) => {
            setSelected(new Set(ids.filter(Boolean)));
        }, []),
        toggle: useCallback((id: string) => {
            setSelected((prev) => {
                const next = new Set(prev);
                if (next.has(id)) next.delete(id);
                else next.add(id);
                return next;
            });
        }, []),
        clear: useCallback(() => setSelected(new Set()), []),
        register: useCallback((featureId: string, sourceId: string, layerId: number) => {
            sourceMapRef.current.set(featureId, { sourceId, layerId });
        }, []),
        onRowSelect: useCallback((featureId: string) => {
            setSelected((prev) => {
                const next = new Set(prev);
                if (next.has(featureId)) next.delete(featureId);
                else next.add(featureId);
                return next;
            });
        }, []),
        onMapSelect: useCallback((featureId: string, sourceId: string, layerId: number) => {
            setSelected((prev) => {
                const next = new Set(prev);
                next.add(featureId);
                return next;
            });
        }, []),
    };

    return (
        <FeatureSelectionContext.Provider value={value}>
            {children}
        </FeatureSelectionContext.Provider>
    );
}

export function useFeatureSelection(): FeatureSelectionContextValue {
    const ctx = useContext(FeatureSelectionContext);
    if (!ctx) {
        throw new Error('useFeatureSelection must be used within FeatureSelectionProvider');
    }
    return ctx;
}
