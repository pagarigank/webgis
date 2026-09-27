import React, { useEffect, useMemo, useState } from 'react';
import { importApi, rowErrorText, type ImportJob, type ImportPreview } from '../api/importApi';
import { errorText } from '../errorText';

/**
 * TASK-128 step 4 — paginated review with per-row errors.
 *
 * Validation is run once, from the previous step, so this step only reads. The
 * source columns are taken from the first page's values rather than from a
 * separate schema call: the server exposes no column list, and reading it back
 * from the rows it just validated keeps the grid and the payload in step.
 */
export const PreviewStep: React.FC<{
    job: ImportJob;
}> = ({ job }) => {
    const [page, setPage] = useState(1);
    const [pageSize] = useState(50);
    const [data, setData] = useState<ImportPreview | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [downloading, setDownloading] = useState(false);
    const [onlyErrors, setOnlyErrors] = useState(false);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        importApi
            .preview(job.id, page, pageSize)
            .then((res) => {
                if (!cancelled) {
                    setData(res);
                    setError(null);
                }
            })
            .catch((err: any) => {
                if (!cancelled) setError(errorText(err, 'Failed to load the preview'));
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [job.id, page, pageSize]);

    const columns = useMemo(() => {
        const names = new Set<string>();
        for (const row of data?.rows ?? []) {
            for (const key of Object.keys(row.values ?? {})) names.add(key);
        }
        return Array.from(names);
    }, [data]);

    const visibleRows = (data?.rows ?? []).filter((r) => !onlyErrors || r.errors.length > 0);
    const totalPages = data ? Math.max(1, Math.ceil(data.total / data.page_size)) : 1;

    const downloadErrors = async () => {
        setDownloading(true);
        try {
            await importApi.downloadErrors(job.id);
        } catch (err: any) {
            setError(errorText(err, 'Failed to download the error report'));
        } finally {
            setDownloading(false);
        }
    };

    return (
        <section aria-labelledby="preview-step-heading" data-testid="step-preview">
            <h2 id="preview-step-heading">Review staged rows</h2>

            <p data-testid="validation-summary">
                {job.total_rows} row{job.total_rows === 1 ? '' : 's'} read · <strong>{job.valid_rows}</strong>{' '}
                valid · <strong data-testid="invalid-count">{job.invalid_rows}</strong> invalid
            </p>

            {job.invalid_rows > 0 ? (
                <p>
                    Invalid rows are staged but will not be committed. The report lists them with the reason.
                </p>
            ) : null}

            <div style={{ display: 'flex', gap: '12px', alignItems: 'center', margin: '12px 0' }}>
                <label style={{ display: 'flex', gap: '6px', alignItems: 'center' }}>
                    <input
                        type="checkbox"
                        data-testid="only-errors"
                        checked={onlyErrors}
                        onChange={(e) => setOnlyErrors(e.target.checked)}
                    />
                    Show failing rows only
                </label>

                <button
                    type="button"
                    data-testid="download-errors"
                    onClick={downloadErrors}
                    disabled={downloading || job.invalid_rows === 0}
                >
                    {downloading ? 'Preparing…' : 'Download error CSV'}
                </button>
            </div>

            {error ? <p role="alert">{error}</p> : null}

            <table data-testid="preview-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
                <caption className="sr-only">Staged rows with their validation result</caption>
                <thead>
                    <tr>
                        <th scope="col">Row</th>
                        {columns.map((c) => (
                            <th key={c} scope="col">
                                {c}
                            </th>
                        ))}
                        <th scope="col">Result</th>
                    </tr>
                </thead>
                <tbody>
                    {visibleRows.length === 0 ? (
                        <tr>
                            <td colSpan={columns.length + 2}>
                                {loading ? 'Loading…' : onlyErrors ? 'No failing rows on this page.' : 'No rows.'}
                            </td>
                        </tr>
                    ) : (
                        visibleRows.map((row) => (
                            <tr
                                key={row.row_number}
                                data-testid={`preview-row-${row.row_number}`}
                                data-valid={row.is_valid ? 'true' : 'false'}
                            >
                                <td>{row.row_number}</td>
                                {columns.map((c) => (
                                    <td key={c}>{formatCell(row.values?.[c])}</td>
                                ))}
                                <td>
                                    {row.errors.length === 0 ? (
                                        'valid'
                                    ) : (
                                        <ul style={{ margin: 0, paddingLeft: '18px' }}>
                                            {row.errors.map((e, i) => (
                                                <li key={i} data-testid={`row-error-${row.row_number}`}>
                                                    {rowErrorText(e)}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </td>
                            </tr>
                        ))
                    )}
                </tbody>
            </table>

            <div style={{ display: 'flex', gap: '8px', alignItems: 'center', marginTop: '12px' }}>
                <button type="button" data-testid="prev-page" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1 || loading}>
                    Previous
                </button>
                <span data-testid="page-indicator">
                    Page {page} of {totalPages}
                </span>
                <button
                    type="button"
                    data-testid="next-page"
                    onClick={() => setPage((p) => p + 1)}
                    disabled={page >= totalPages || loading}
                >
                    Next
                </button>
            </div>
        </section>
    );
};

/** Objects are shown as compact JSON so a geometry column stays readable. */
function formatCell(value: unknown): string {
    if (value === null || value === undefined) return '';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}
