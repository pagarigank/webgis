import type { ReactNode } from 'react';

/**
 * Label + control wrapper shared by the parcel create form and the editor's
 * Information tab.
 *
 * Both forms describe the same parcel attributes, and they had drifted apart:
 * the editor ordered title/tax-declaration differently from the create form, and
 * the two disagreed on column widths. Rendering the label through one component
 * is what keeps them in step.
 *
 * It also fixes a defect the hand-rolled markup had: no label was ever bound to
 * its control (`htmlFor`/`id` were absent on every field), so the accessible
 * name of most inputs was just the placeholder, clicking a label did not focus
 * the field, and validation errors had nowhere consistent to render.
 *
 * Markup follows the pattern the layers and basemap forms already use —
 * `form-label` with a `*` on required fields, and an inline `invalid-feedback`
 * message — rather than the muted micro-labels the parcel forms used.
 */
export function ParcelField(props: {
    /** Must be unique in the document and repeated as the control's `id`. */
    id: string;
    label: ReactNode;
    required?: boolean;
    /** Persistent hint under the control. Hidden while an error is showing. */
    hint?: ReactNode;
    /** Inline validation message. Presence switches the control to invalid. */
    error?: string;
    /** Grid column classes, e.g. `col-md-6`. Omit for full width. */
    className?: string;
    children: ReactNode;
}) {
    const { id, label, required, hint, error, className, children } = props;
    // We will render 'form-group' and allow className to inject grid classes if needed.
    return (
        <div className={`form-group ${className || ''}`.trim()}>
            <label className="form-label" htmlFor={id}>
                {label}
                {required ? (
                    <span className="form-required" aria-hidden="true">
                        *
                    </span>
                ) : null}
            </label>
            {children}
            {error ? (
                <div className="form-error" id={`${id}-error`} role="alert">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    {error}
                </div>
            ) : hint ? (
                <div className="form-hint" id={`${id}-hint`}>
                    {hint}
                </div>
            ) : null}
        </div>
    );
}
