import { describe, expect, it } from 'vitest';
import { cookieValue, hasRefreshCookie, tokenStore } from './tokenStore';

describe('tokenStore', () => {
  it('stores the access token in memory only', () => {
    tokenStore.setAccessToken('jwt.value');
    expect(tokenStore.getAccessToken()).toBe('jwt.value');
  });

  it('stores the csrf token and hydrates from the readable cookie', () => {
    tokenStore.setCsrfToken('csrf-value');
    expect(tokenStore.getCsrfToken()).toBe('csrf-value');
    expect(tokenStore.resolveCsrfToken()).toBe('csrf-value');

    tokenStore.setCsrfToken(null);
    expect(tokenStore.resolveCsrfToken()).toBeNull();
  });

  it('tracks the refresh-failed latch', () => {
    expect(tokenStore.getRefreshFailed()).toBe(false);
    tokenStore.setRefreshFailed(true);
    expect(tokenStore.getRefreshFailed()).toBe(true);
    tokenStore.setRefreshFailed(false);
  });

  it('clear() drops every secret but leaves the latch intact after a login gate', () => {
    tokenStore.setAccessToken('jwt.value');
    tokenStore.setCsrfToken('csrf-value');
    tokenStore.clear();
    expect(tokenStore.getAccessToken()).toBeNull();
    expect(tokenStore.getCsrfToken()).toBeNull();
  });
});

describe('cookieValue', () => {
  it('parses a single cookie', () => {
    expect(cookieValue('refresh_token=abc', 'refresh_token')).toBe('abc');
  });

  it('ignores name-prefix collisions', () => {
    // refresh_token_x must not match refresh_token
    expect(cookieValue('refresh_token_x=1; refresh_token=abc', 'refresh_token')).toBe('abc');
  });

  it('returns null when absent', () => {
    expect(cookieValue('other=1', 'refresh_token')).toBeNull();
    expect(cookieValue('', 'refresh_token')).toBeNull();
  });
});

describe('hasRefreshCookie', () => {
  it('reports no refresh cookie outside a browser (no document)', () => {
    expect(hasRefreshCookie()).toBe(false);
  });
});