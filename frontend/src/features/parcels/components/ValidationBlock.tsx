import type { ValidationCheck, ValidationWarning } from '../api/operationsApi';

/**
 * TASK-116/117 — validation block shared by the split and consolidation tabs.
 * Renders every check the backend reported (pass shown small, fail highlighted)
 * plus the warnings list — SPLIT_INVALID/CONSOLIDATION_INVALID enumerate every
 * failed rule, and the UI mirrors that completeness rather than showing a
 * single generic error.
 */
export function ValidationBlock({ checks, warnings }: { checks: ValidationCheck[]; warnings: ValidationWarning[] }) {
    const failed = checks.filter((c) => c.status === 'fail');
    const passed = checks.filter((c) => c.status !== 'fail');

    return (
        <div data-testid="validation-block">
            <div className={failed.length > 0 ? 'alert alert-danger py-2' : 'alert alert-success py-2'} data-testid="validation-summary">
                {failed.length > 0 ? (
                    <strong>
                        {failed.length} blocking failure{failed.length > 1 ? 's' : ''}
                    </strong>
                ) : (
                    <strong>All checks passed</strong>
                )}
            </div>
            <ul className="list-unstyled mb-2">
                {failed.map((c, i) => (
                    <li key={`f${i}`} className="small mb-1" data-testid={`check-fail-${c.rule}`}>
                        <span className="badge bg-danger me-1">{c.rule}</span>
                        {c.message}
                    </li>
                ))}
                {passed.map((c, i) => (
                    <li key={`p${i}`} className="small text-muted mb-1" data-testid={`check-pass-${c.rule}`}>
                        <span className="badge bg-light text-dark border me-1">{c.rule}</span>
                        {c.message}
                    </li>
                ))}
            </ul>
            {warnings.length > 0 && (
                <div className="alert alert-warning py-2" data-testid="validation-warnings">
                    <ul className="mb-0 small">
                        {warnings.map((w, i) => (
                            <li key={i}>
                                <span className="badge bg-warning text-dark me-1">{w.rule}</span>
                                {w.message}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
