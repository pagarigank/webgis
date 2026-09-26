import React from 'react';
import { useNavigate } from 'react-router-dom';
import { hasPermission, permissions } from '../../../auth/permissions';
import { useAuth } from '../../../auth/useAuth';
import { PROVENANCE_LABELS } from './badges';
import { useParcelSelection } from '../ParcelSelectionContext';
import type { ParcelHit } from '../types';

/**
 * TASK-104b - the action menu shown when a parcel is picked on the map.
 *
 * The map used to offer only a read-only `IdentifyPopup` driven by the generic
 * GIS identify tool, which answers against app.gis_features and therefore can
 * never resolve a parcel. This menu is fed by GET /parcels/locate instead, and
 * is the entry point for the parcel operations that were otherwise reachable
 * only from the editor route: open, subdivide, consolidate, lineage, history.
 *
 * Every tab destination is a real route: operations live as tabs under
 * /parcels/:id/:tab?, so there is no standalone /parcels/consolidate page.
 */

export interface ParcelActionMenuProps {
    /** Every parcel found at the click, containing ones first. */
    hits: ParcelHit[];
    /** The hit the menu is focused on. */
    activeId: string | null;
    onSelectHit: (id: string) => void;
    onClose: () => void;
}

const fmtArea = (m2: number | null): string => {
    if (m2 == null) return '—';
    return m2 >= 10000 ? `${(m2 / 10000).toFixed(4)} ha` : `${m2.toFixed(2)} m²`;
};

export const ParcelActionMenu: React.FC<ParcelActionMenuProps> = ({
    hits,
    activeId,
    onSelectHit,
    onClose,
}) => {
    const navigate = useNavigate();
    const { me } = useAuth();
    const { isSelected, toggle, selection, clear, ids } = useParcelSelection();

    if (hits.length === 0) return null;

    const active = hits.find((h) => h.id === activeId) ?? hits[0];
    const canEdit = me != null && hasPermission(me, permissions.parcelUpdate);
    const canSplit = me != null && hasPermission(me, permissions.parcelSplit);
    const canConsolidate = me != null && hasPermission(me, permissions.parcelConsolidate);
    const alreadySelected = isSelected(active.id);
    const selectedIds = ids();

    return (
        <div
            data-testid="parcel-action-menu"
            role="dialog"
            aria-label="Parcel actions"
            className="card"
            // `pointerEvents: 'auto'` is required, not cosmetic: MapContext
            // renders every map child inside a `pointerEvents: 'none'` overlay
            // (MapContext.tsx) and each panel opts back in explicitly. Without
            // it the whole menu was click-through — every button press landed on
            // the map canvas underneath and no parcel action could ever fire.
            //
            // Anchored bottom-right, clear of the map's own chrome: the
            // "Map Controls" card is absolutely positioned at top-right
            // (top/right 1rem, z-index 10) and the coordinate readout sits
            // bottom-left, so top-right would sit on top of the controls.
            style={{
                position: 'absolute',
                bottom: 40,
                right: 40,
                width: 288,
                zIndex: 1000,
                pointerEvents: 'auto',
            }}
        >
            <div className="flex justify-between items-center mb-2">
                <strong className="text-sm" data-testid="parcel-action-menu-title">
                    {hits.length > 1 ? `${hits.length} parcels here` : 'Parcel'}
                </strong>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close parcel actions"
                    data-testid="parcel-action-menu-close"
                    className="btn btn-ghost"
                >
                    Close
                </button>
            </div>

            {hits.length > 1 && (
                <ul className="mb-2" data-testid="parcel-action-menu-hits">
                    {hits.map((h) => (
                        <li key={h.id}>
                            <button
                                type="button"
                                onClick={() => onSelectHit(h.id)}
                                aria-current={h.id === active.id}
                                className={`list-item ${h.id === active.id ? 'selected' : ''}`}
                                style={{ width: '100%', textAlign: 'left', font: 'inherit' }}
                            >
                                <span className="font-mono">{h.parcel_code}</span>{' '}
                                <span className="text-muted">
                                    {h.contains_point ? 'inside' : `${h.distance_m.toFixed(0)} m`}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <dl className="text-sm mb-2">
                <div className="flex justify-between">
                    <dt className="text-muted">Code</dt>
                    <dd className="font-mono" data-testid="parcel-action-code">{active.parcel_code}</dd>
                </div>
                <div className="flex justify-between">
                    <dt className="text-muted">Status</dt>
                    <dd>{active.status}</dd>
                </div>
                <div className="flex justify-between">
                    <dt className="text-muted">Geometry</dt>
                    <dd>{PROVENANCE_LABELS[active.geometry_source] ?? active.geometry_source}</dd>
                </div>
                <div className="flex justify-between">
                    <dt className="text-muted">Area</dt>
                    <dd data-testid="parcel-action-area">{fmtArea(active.area_m2)}</dd>
                </div>
                {active.psgc_barangay && (
                    <div className="flex justify-between">
                        <dt className="text-muted">Barangay</dt>
                        <dd className="font-mono">{active.psgc_barangay}</dd>
                    </div>
                )}
                <div className="flex justify-between">
                    <dt className="text-muted">Version</dt>
                    <dd>v{active.version}</dd>
                </div>
            </dl>

            <div className="flex flex-col gap-2">
                <button
                    type="button"
                    className="btn btn-primary w-full"
                    data-testid="parcel-action-open"
                    onClick={() => navigate(`/parcels/${active.id}`)}
                >
                    Open parcel editor
                </button>

                <button
                    type="button"
                    className="btn btn-ghost w-full"
                    data-testid="parcel-action-split"
                    disabled={!canSplit}
                    title={canSplit ? undefined : 'Requires the parcel.split permission'}
                    onClick={() => navigate(`/parcels/${active.id}/split`)}
                >
                    Subdivide…
                </button>

                <button
                    type="button"
                    className="btn btn-ghost w-full"
                    data-testid="parcel-action-select"
                    aria-pressed={alreadySelected}
                    onClick={() => toggle(active)}
                >
                    {alreadySelected ? 'Remove from selection' : 'Add to selection'}
                </button>

                <button
                    type="button"
                    className="btn btn-ghost w-full"
                    data-testid="parcel-action-consolidate"
                    disabled={!canConsolidate}
                    title={canConsolidate ? undefined : 'Requires the parcel.consolidate permission'}
                    onClick={() => {
                        if (!alreadySelected) toggle(active);
                        // Consolidation is a tab on one of the parents, not a
                        // standalone route. The first pick is the primary
                        // parent, which is what seeds the new parcel code.
                        const primaryId = alreadySelected ? active.id : (selection[0]?.id ?? active.id);
                        navigate(`/parcels/${primaryId}/consolidate`);
                    }}
                >
                    Consolidate selected{selectedIds.length > 0 ? ` (${selectedIds.length})` : ''}…
                </button>

                <button
                    type="button"
                    className="btn btn-ghost w-full"
                    data-testid="parcel-action-lineage"
                    onClick={() => navigate(`/parcels/${active.id}/lineage`)}
                >
                    Lineage…
                </button>

                <button
                    type="button"
                    className="btn btn-ghost w-full"
                    data-testid="parcel-action-history"
                    onClick={() => navigate(`/parcels/${active.id}/history`)}
                >
                    History…
                </button>
            </div>

            {selection.length > 0 && (
                <div
                    className="flex justify-between items-center gap-2 mt-4 text-sm"
                    data-testid="parcel-selection-summary"
                >
                    <span className="text-muted">
                        {selection.length} selected: {selection.map((p) => p.parcel_code).join(', ')}
                    </span>
                    <button type="button" onClick={clear} className="btn btn-ghost" data-testid="parcel-selection-clear">
                        Clear
                    </button>
                </div>
            )}

            {!canEdit && (
                <p className="text-muted text-sm mt-2 mb-0" data-testid="parcel-action-readonly">
                    Read-only: you do not hold parcel.update.
                </p>
            )}
        </div>
    );
};
