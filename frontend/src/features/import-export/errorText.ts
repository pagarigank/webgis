import { toApiError } from '../../auth/apiErrors';

/**
 * Human-readable text for anything thrown by `apiClient`.
 *
 * A raw axios rejection carries only `message: "Request failed with status code
 * 422"`, which tells an operator nothing. The actionable text lives in the
 * server error envelope (`error.message`, e.g. "File exceeds the 50 MB cap"), so
 * the wizard surfaces that instead of the status line.
 */
export function errorText(error: unknown, fallback: string): string {
  const message = toApiError(error, 'UNKNOWN').message;
  return message && message !== 'An unexpected error occurred.' ? message : fallback;
}
