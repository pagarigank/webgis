import axios from 'axios';
import type { InternalAxiosRequestConfig } from 'axios';
import { tokenStore } from '../auth/tokenStore';
import { createRefreshQueue } from './refreshQueue';
// `dead` distinguishes "the server rejected the session" from "the refresh could
// not be completed right now". Only the former ends the session; a throttled,
// offline, or briefly failing refresh must leave the user signed in so a later
// request can try again.
import { classifyRefreshError, shouldEndSession, type RefreshOutcome } from './refreshOutcome';

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
 * A refresh is retried per attempt rather than gated on a sticky failure flag.
 * The session ends only when the refresh endpoint itself rejects the session;
 * a throttled or briefly failing refresh leaves the user signed in so the next
 * request can recover.
 */

const baseURL = import.meta.env.VITE_API_BASE_URL || '/api/v1';

interface RetryConfig extends InternalAxiosRequestConfig {
  _authRetried?: boolean;
  _transientRetried?: boolean;
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

async function performRefresh(): Promise<RefreshOutcome> {
  try {
    const resp = await rawAuth.post('/auth/refresh', null, {
      headers: { 'X-CSRF-Token': tokenStore.resolveCsrfToken() ?? '' },
    });
    const envelope = resp.data as ApiEnvelope<{ access_token: string } | undefined>;
    if (envelope.success !== true || !envelope.data?.access_token) {
      tokenStore.setRefreshFailed(true);
      return { ok: false, dead: true };
    }
    tokenStore.setAccessToken(envelope.data.access_token);
    const csrf = resp.headers['x-csrf-token'];
    if (typeof csrf === 'string') {
      tokenStore.setCsrfToken(csrf);
    }
    tokenStore.setRefreshFailed(false);
    return { ok: true };
  } catch (err) {
    // A rejected session is terminal. A throttled (429) or briefly failing
    // refresh is not: it must not end the session, and it must not stop other
    // requests from retrying later. Only latch the terminal case.
    const outcome = classifyRefreshError(err);
    if (outcome.dead) {
      tokenStore.setRefreshFailed(true);
    }
    return outcome;
  }
}

const refreshQueue = createRefreshQueue<RefreshOutcome>(performRefresh);

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

    if (status === 401 && !isAuthEndpoint && config && config._authRetried !== true) {
      config._authRetried = true;

      // A dead session is latched so it cannot start a refresh storm: without
      // it, every subsequent 401 retries the refresh, each attempt presents the
      // already-revoked cookie, and the page tears itself down. A transient
      // failure does not latch, so throttling or a network blip still recovers
      // on the next request. The latch is per page load, which is what makes it
      // safe: it cannot outlive the document.
      if (tokenStore.getRefreshFailed()) {
        window.dispatchEvent(new Event('auth:unauthorized'));
        return Promise.reject(error);
      }

      let outcome = await refreshQueue();
      if (outcome.ok) {
        return apiClient(config);
      }

      // A transient failure is not this request's fault, so it gets one more
      // bounded attempt after a short pause. Without it, one throttled or
      // briefly failing refresh failed the request outright even though a
      // moment later the refresh would have succeeded — and because components
      // that query during the cold-boot window (basemaps and notifications on
      // /map) issue their requests together and share a single flight, a single
      // unlucky failure took out all of them at once.
      if (!outcome.dead && config._transientRetried !== true) {
        config._transientRetried = true;
        await new Promise((resolve) => setTimeout(resolve, 250));
        outcome = await refreshQueue();
        if (outcome.ok) {
          return apiClient(config);
        }
      }

      // Only a refresh the server actually rejected ends the session, and the
      // verdict is the *last* attempt: a first attempt that failed transiently
      // and a second that was rejected must still evict. A throttled or briefly
      // failing refresh leaves the user signed in, and the next request retries.
      if (shouldEndSession(outcome)) {
        window.dispatchEvent(new Event('auth:unauthorized'));
      }
    }

    return Promise.reject(error);
  }
);

export default apiClient;

// Response-shape normalization.
//
// The response interceptor above unwraps `{ success, data }` once, so a queryFn
// normally receives the inner payload directly. But the backend is not uniform:
// most controllers emit the envelope, while a few (notably the survey technical
// description controller) write their payload verbatim as a bare `{ data }`
// object with no `success` key, which the interceptor therefore passes through
// untouched. The result is that the same `apiClient.get()` call can hand back
// any of:
//
//   [ ... ]                                  bare array
//   { data: [ ... ] }                         bare wrapper, no `success`
//   { data: [ ... ], meta: { ... } }          paginated wrapper
//   <entity>                                  already unwrapped by the interceptor
//   { data: <entity> }                        wrapped entity
//
// These helpers walk up to two wrapper levels and never return a non-array or
// null, so consumers can safely call `.map`/`.length` on the result. Prefer
// them over hand-written `res.data.data` chains: the hand-written form assumed
// a double-wrapped envelope that no endpoint actually returns, so it silently
// produced `undefined` and crashed rendering components downstream.
const MAX_WRAPPER_DEPTH = 2;

function unwrapLayers(res: unknown): unknown {
  let cur = res;
  for (let depth = 0; depth < MAX_WRAPPER_DEPTH; depth++) {
    if (cur === null || cur === undefined) return cur;
    if (typeof cur !== 'object' || Array.isArray(cur)) return cur;
    const rec = cur as Record<string, unknown>;
    if (!('data' in rec)) return cur;
    cur = rec.data;
  }
  return cur;
}

/** Normalizes any list response shape to a real array; never returns null. */
export function unwrapList<T = unknown>(res: unknown): T[] {
  const unwrapped = unwrapLayers(res);
  if (Array.isArray(unwrapped)) return unwrapped as T[];
  if (unwrapped && typeof unwrapped === 'object') {
    // A paginated wrapper one level deeper than MAX_WRAPPER_DEPTH allows.
    const nested = (unwrapped as { data?: unknown }).data;
    if (Array.isArray(nested)) return nested as T[];
  }
  return [];
}

/**
 * Normalizes any single-entity response shape to the entity, or `null` when the
 * payload is missing. Use this instead of `res.data.data`, and handle the
 * `null` case at the call site.
 */
export function unwrapEntity<T = unknown>(res: unknown): T | null {
  const unwrapped = unwrapLayers(res);
  if (unwrapped === null || unwrapped === undefined) return null;
  if (typeof unwrapped !== 'object' || Array.isArray(unwrapped)) {
    return unwrapped as T;
  }
  const nested = (unwrapped as { data?: unknown }).data;
  return (nested ?? unwrapped) as T;
}