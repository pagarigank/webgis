/**
 * Session secrets held strictly in memory (ADR-23, frontend.md §12).
 *
 * The access token never touches localStorage/sessionStorage/indexedDB. The
 * refresh token lives in an HttpOnly cookie the JS cannot read. The CSRF token
 * is the readable half of the double-submit pair: the server issues it as a
 * non-HttpOnly `csrf_token` cookie and echoes it in the `X-CSRF-Token`
 * response header; we keep it in memory to set the same header on refresh and
 * logout. On a fresh page load memory is empty, so the value is hydrated from
 * the readable cookie (JS is allowed to read it — that is the point of the
 * double-submit design).
 */
export const REFRESH_COOKIE = 'refresh_token';
export const CSRF_COOKIE = 'csrf_token';

let accessToken: string | null = null;
let csrfToken: string | null = null;
let refreshFailed = false;

/** Extract a cookie's value from a raw Cookie/Jar header source (testable). */
export function cookieValue(source: string, name: string): string | null {
  for (const pair of source.split(';')) {
    const trimmed = pair.trim();
    if (trimmed.startsWith(`${name}=`)) {
      return trimmed.slice(name.length + 1);
    }
  }
  return null;
}

/** Read a cookie from document.cookie, when a browser is present. */
function readCookie(name: string): string | null {
  if (typeof document === 'undefined') {
    return null;
  }
  return cookieValue(document.cookie, name);
}

export const tokenStore = {
  getAccessToken: (): string | null => accessToken,
  setAccessToken: (token: string | null): void => {
    accessToken = token;
  },
  getCsrfToken: (): string | null => csrfToken,
  setCsrfToken: (token: string | null): void => {
    csrfToken = token;
  },
  /** Prefer in-memory value, fall back to the readable cookie (bootstrap). */
  resolveCsrfToken: (): string | null => csrfToken ?? readCookie(CSRF_COOKIE),
  getRefreshFailed: (): boolean => refreshFailed,
  setRefreshFailed: (failed: boolean): void => {
    refreshFailed = failed;
  },
  clear: (): void => {
    accessToken = null;
    csrfToken = null;
  },
};

/** True when a refresh cookie exists in the browser (cheap pre-flight check). */
export function hasRefreshCookie(): boolean {
  return readCookie(REFRESH_COOKIE) !== null;
}