import axios from 'axios';
import type { InternalAxiosRequestConfig } from 'axios';
import { tokenStore } from '../auth/tokenStore';
import { createRefreshQueue } from './refreshQueue';

/**
 * The single Axios instance for the whole SPA (ADR-13, frontend.md §12).
 *
 * Responsibilities:
 * - base URL from Vite env (`/api/v1`), cookies sent cross-origin;
 * - Bearer authorization injected from the in-memory token store;
 * - `X-CSRF-Token` for the cookie-authenticated refresh/logout calls, hydrated
 *   from the readable cookie on a fresh page load when memory is empty;
 * - `{ success, data }` envelope unwrapping and typed error mapping;
 * - 401 → silent refresh (single flight, once per request) → retry the original.
 *
 * After a failed refresh the session is treated as dead until a real login:
 * `refreshFailed` stops the retry loop from re-entering the queue (e.g. a
 * TanStack query retry that would otherwise hit the refresh endpoint again).
 */

const baseURL = import.meta.env.VITE_API_BASE_URL || '/api/v1';

interface RetryConfig extends InternalAxiosRequestConfig {
  _authRetried?: boolean;
}

interface ApiEnvelope<T = unknown> {
  success: boolean;
  data: T;
  error?: { code: string; message: string; details?: Record<string, unknown> };
}

// Bare instance for the refresh call: no interceptors means no retry recursion.
const rawAuth = axios.create({
  baseURL,
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
});

async function performRefresh(): Promise<boolean> {
  try {
    const resp = await rawAuth.post('/auth/refresh', null, {
      headers: { 'X-CSRF-Token': tokenStore.resolveCsrfToken() ?? '' },
    });
    const envelope = resp.data as ApiEnvelope<{ access_token: string } | undefined>;
    if (envelope.success !== true || !envelope.data?.access_token) {
      tokenStore.setRefreshFailed(true);
      return false;
    }
    tokenStore.setAccessToken(envelope.data.access_token);
    const csrf = resp.headers['x-csrf-token'];
    if (typeof csrf === 'string') {
      tokenStore.setCsrfToken(csrf);
    }
    tokenStore.setRefreshFailed(false);
    return true;
  } catch {
    tokenStore.setRefreshFailed(true);
    return false;
  }
}

const refreshQueue = createRefreshQueue<boolean>(performRefresh);

const apiClient = axios.create({
  baseURL,
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
});

apiClient.interceptors.request.use((config) => {
  const token = tokenStore.getAccessToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  const csrf = tokenStore.resolveCsrfToken();
  if (csrf) {
    config.headers['X-CSRF-Token'] = csrf;
  }
  if (!config.headers['X-Request-Id']) {
    config.headers['X-Request-Id'] = crypto.randomUUID();
  }
  return config;
});

apiClient.interceptors.response.use(
  (response) => {
    // Persist the CSRF half of every auth handshake (login / mfa / refresh).
    const csrf = response.headers['x-csrf-token'];
    if (typeof csrf === 'string') {
      tokenStore.setCsrfToken(csrf);
    }

    const body = response.data as ApiEnvelope;
    if (body && typeof body === 'object' && body.success) {
      const payload = body.data as { access_token?: string } | undefined;
      if (payload && typeof payload.access_token === 'string') {
        tokenStore.setAccessToken(payload.access_token);
        tokenStore.setRefreshFailed(false);
      }
      return body.data as never;
    }
    return body as never;
  },
  async (error) => {
    const status: number | undefined = error?.response?.status;
    const config = error?.config as RetryConfig | undefined;
    const url: string = config?.url ?? '';
    const isAuthEndpoint = /^\/auth\//.test(url);

    if (
      status === 401 &&
      !isAuthEndpoint &&
      !tokenStore.getRefreshFailed() &&
      config &&
      config._authRetried !== true
    ) {
      config._authRetried = true;
      const refreshed = await refreshQueue();
      if (refreshed) {
        return apiClient(config);
      }
    }

    // If we get a 401 and we are not an auth endpoint, and the refresh failed or wasn't attempted,
    // dispatch an event so the UI can instantly evict the user.
    if (status === 401 && !isAuthEndpoint) {
      window.dispatchEvent(new Event('auth:unauthorized'));
    }

    return Promise.reject(error);
  }
);

export default apiClient;

// The response interceptor already unwraps the outer { success, data } envelope,
// so a queryFn receives the inner payload directly. Endpoints return that payload
// either as a plain array (e.g. /audit-logs) or as { data: [...], meta } (paginated
// lists like /users, /roles, /organizations). This normalizes both to an array and
// never yields a non-array, so consumers can safely call .map on the result.
export function unwrapList<T = unknown>(res: unknown): T[] {
  if (Array.isArray(res)) return res as T[];
  const nested = (res as { data?: unknown } | null | undefined)?.data;
  return Array.isArray(nested) ? (nested as T[]) : [];
}