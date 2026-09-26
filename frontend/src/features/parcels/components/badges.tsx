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

/**
 * Short human label per provenance value, for the option text in the pickers.
 *
 * The stored values are SCREAMING_SNAKE database enums. Showing them raw in a
 * dropdown forced the operator to read `COMPUTED_FROM_TECHNICAL_DESCRIPTION` and
 * decide what it meant; the forms now show this label and still submit the
 * enum, matching how the layer form presents `geometry_type` as "MultiPolygon".
 */
export const PROVENANCE_LABELS: Record<string, string> = {
    SURVEY_COORDINATES: 'Survey coordinates',
    COMPUTED_FROM_TECHNICAL_DESCRIPTION: 'Computed from technical description',
    TRANSFORMED_FROM_HISTORICAL_SURVEY: 'Transformed from historical survey',
    IMPORTED_GIS: 'Imported from GIS dataset',
    CAD_IMPORT: 'Imported from CAD drawing',
    DIGITIZED_FROM_IMAGERY: 'Digitised from imagery',
    MANUAL_DRAWING: 'Manual drawing',
    APPROXIMATE: 'Approximate',
};

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
 * Accepted PSGC code length, as a hint for the operator.
 *
 * Every row in `ref.psgc_areas` is 9 digits — province, municipality/city and
 * barangay alike (e.g. `133900000` / `133901000` / `133901001`) — but PSGC has
 * issued longer forms, so `ParcelController` accepts 9 to 12 (`/^\d{9,12}$/`).
 * This string is the single source for that range; it previously read
 * "10–12 digits" in the form, which contradicted the very validation it was
 * annotating and would have rejected a valid 9-digit code.
 */
export const PSGC_DIGIT_HINT = '9–12 digits';

/** Options for the source-area unit selector, with display labels. */
export const AREA_UNITS: { value: string; label: string }[] = [
    { value: 'sqm', label: 'm²' },
    { value: 'ha', label: 'hectares (ha)' },
];

/**
 * The three PSGC attributes, in the order both parcel forms render them.
 *
 * The examples are real codes from `ref.psgc_areas` and are all 9 digits, so
 * they satisfy the form's own validation. Earlier placeholders (`1339`,
 * `133901`) were shorter prefixes of the real codes and would have been
 * rejected by the very field they annotated.
 *
 * `ref.psgc_areas` resolves the real hierarchy (province → municipality →
 * barangay), so the operator is expected to enter codes consistent with one
 * another; the create form's `parcel_code` and this block are the only place a
 * parcel's location is captured.
 */
export const PSGC_FIELDS = [
    { name: 'psgc_province', label: 'Province', placeholder: 'e.g. 133900000', testId: 'parcel-create-psgc-prov' },
    {
        name: 'psgc_municipality',
        label: 'Municipality / city',
        placeholder: 'e.g. 133901000',
        testId: 'parcel-create-psgc-muni',
    },
    { name: 'psgc_barangay', label: 'Barangay', placeholder: 'e.g. 133901001', testId: 'parcel-create-psgc-barangay' },
] as const;

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