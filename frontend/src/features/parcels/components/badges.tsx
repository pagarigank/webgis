import type { Parcel } from '../types';

/**
 * Shared badge rendering for the parcel editor shell and list (frontend.md §16):
 * status and provenance always carry a label and an icon — colour is never the
 * only signal.
 */

const STATUS_TONE: Record<string, string> = {
    DRAFT: 'secondary',
    SUBMITTED: 'info',
    UNDER_REVIEW: 'info',
    RETURNED: 'warning',
    VERIFIED: 'primary',
    APPROVED: 'success',
    PUBLISHED: 'success',
    ARCHIVED: 'secondary',
    SUPERSEDED: 'dark',
};

const STATUS_ICON: Record<string, string> = {
    DRAFT: '✎',
    SUBMITTED: '⇪',
    UNDER_REVIEW: '⇄',
    RETURNED: '↩',
    VERIFIED: '✓',
    APPROVED: '✔',
    PUBLISHED: '●',
    ARCHIVED: '▣',
    SUPERSEDED: '≋',
};

export function StatusBadge({ status }: { status: string }) {
    const tone = STATUS_TONE[status] ?? 'secondary';
    return (
        <span className={`badge bg-${tone}`} data-testid="parcel-status-badge">
            {STATUS_ICON[status] ?? ''} {status}
        </span>
    );
}

/** Human description per provenance value, shown inline on the Information tab. */
export const PROVENANCE_HELP: Record<string, string> = {
    SURVEY_COORDINATES: 'Coordinates from a field survey.',
    COMPUTED_FROM_TECHNICAL_DESCRIPTION: 'Computed from the parcel technical description courses.',
    TRANSFORMED_FROM_HISTORICAL_SURVEY: 'Transformed from an older survey of record.',
    IMPORTED_GIS: 'Imported from an external GIS dataset.',
    CAD_IMPORT: 'Imported from a CAD drawing.',
    DIGITIZED_FROM_IMAGERY: 'Digitised on screen over imagery.',
    MANUAL_DRAWING: 'Drawn by hand in the map editor.',
    APPROXIMATE: 'Approximate location; not survey-derived.',
};

export const PROVENANCE_VALUES = Object.keys(PROVENANCE_HELP);

/**
 * TASK-072 — survey-derived provenances (FR-199). These cannot be chosen for a
 * manually drawn parcel unless survey data is attached (survey_plan_id) and a
 * justification is recorded; the editor disables them otherwise.
 */
export const SURVEY_DERIVED_PROVENANCE: ReadonlySet<string> = new Set([
    'SURVEY_COORDINATES',
    'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
    'TRANSFORMED_FROM_HISTORICAL_SURVEY',
]);

const PROVENANCE_TONE: Record<string, string> = {
    SURVEY_COORDINATES: 'success',
    COMPUTED_FROM_TECHNICAL_DESCRIPTION: 'success',
    TRANSFORMED_FROM_HISTORICAL_SURVEY: 'success',
    IMPORTED_GIS: 'info',
    CAD_IMPORT: 'info',
    DIGITIZED_FROM_IMAGERY: 'info',
    MANUAL_DRAWING: 'warning',
    APPROXIMATE: 'danger',
};

export function ProvenanceBadge({ provenance }: { provenance: string }) {
    const tone = PROVENANCE_TONE[provenance] ?? 'secondary';
    return (
        <span className={`badge bg-${tone}`} data-testid="parcel-provenance-badge">
            {provenance}
        </span>
    );
}

/** Chips under the map preview / status bar describing the parcel geometry. */
export function ParcelMeta({ parcel }: { parcel: Parcel }) {
    return (
        <div className="d-flex flex-wrap gap-2 align-items-center">
            <StatusBadge status={parcel.status} />
            <ProvenanceBadge provenance={parcel.provenance} />
            {parcel.verification_status ? (
                <span className="badge bg-light text-dark border">
                    {parcel.verification_status}
                </span>
            ) : null}
            <span className="badge bg-light text-dark border" data-testid="parcel-version-badge">
                v{parcel.version}
            </span>
        </div>
    );
}