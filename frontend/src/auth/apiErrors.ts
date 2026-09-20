import type { AxiosError } from 'axios';

/**
 * Typed application error mirroring the server envelope
 * `{ error: { code, message, details } }` (api.md §1, ADR-14).
 */
export class ApiError extends Error {
  readonly code: string;
  readonly details: Record<string, unknown>;
  readonly status: number;

  constructor(code: string, message: string, details: Record<string, unknown> = {}, status = 0) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.details = details;
    this.status = status;
  }
}

/** Shape the axios error interceptor attaches when the body is an error envelope. */
export interface ServerErrorPayload {
  code: string;
  message: string;
  details?: Record<string, unknown>;
}

/** Map any thrown rejection (axios or otherwise) to an ApiError. */
export function toApiError(error: unknown, fallback = 'AUTH_INVALID'): ApiError {
  if (error instanceof ApiError) {
    return error;
  }
  const axiosError = error as AxiosError<{ error?: ServerErrorPayload }> | undefined;
  const server = axiosError?.response?.data?.error;
  if (server && server.code) {
    return new ApiError(server.code, server.message, server.details ?? {}, axiosError.response?.status ?? 0);
  }
  return new ApiError(fallback, 'An unexpected error occurred.', {}, axiosError?.response?.status ?? 0);
}