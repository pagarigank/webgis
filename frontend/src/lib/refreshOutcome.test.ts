import { describe, expect, it } from 'vitest';
import {
  classifyRefreshError,
  isTerminalRefreshStatus,
  shouldEndSession,
} from './refreshOutcome';

describe('isTerminalRefreshStatus', () => {
  it('treats 401 and 403 as a rejected session', () => {
    expect(isTerminalRefreshStatus(401)).toBe(true);
    expect(isTerminalRefreshStatus(403)).toBe(true);
  });

  it('treats throttling and server faults as recoverable', () => {
    expect(isTerminalRefreshStatus(429)).toBe(false);
    expect(isTerminalRefreshStatus(500)).toBe(false);
    expect(isTerminalRefreshStatus(502)).toBe(false);
    expect(isTerminalRefreshStatus(503)).toBe(false);
  });

  it('treats a missing status as recoverable', () => {
    expect(isTerminalRefreshStatus(undefined)).toBe(false);
  });
});

describe('classifyRefreshError', () => {
  const withStatus = (status: number) => ({ response: { status } });

  it('marks 401/403 as dead', () => {
    expect(classifyRefreshError(withStatus(401))).toEqual({ ok: false, dead: true });
    expect(classifyRefreshError(withStatus(403))).toEqual({ ok: false, dead: true });
  });

  it('does not mark a throttled refresh as dead', () => {
    expect(classifyRefreshError(withStatus(429))).toEqual({ ok: false, dead: false });
  });

  it('does not mark a server fault as dead', () => {
    expect(classifyRefreshError(withStatus(500))).toEqual({ ok: false, dead: false });
  });

  it('does not mark a network failure as dead', () => {
    // No response at all: nothing was heard back that could reject the session.
    expect(classifyRefreshError(new Error('Network Error'))).toEqual({ ok: false, dead: false });
    expect(classifyRefreshError(null)).toEqual({ ok: false, dead: false });
    expect(classifyRefreshError(undefined)).toEqual({ ok: false, dead: false });
  });
});

describe('shouldEndSession', () => {
  it('ends the session on a rejected refresh', () => {
    expect(shouldEndSession({ ok: false, dead: true })).toBe(true);
  });

  it('keeps the user signed in on a transient failure', () => {
    expect(shouldEndSession({ ok: false, dead: false })).toBe(false);
  });

  it('keeps the user signed in when the refresh succeeded', () => {
    expect(shouldEndSession({ ok: true })).toBe(false);
  });

  it('defers to the latest attempt when a retry was made', () => {
    // Regression: a transient first attempt followed by a terminal second one
    // must still evict. Verifying only the first attempt left the user in a
    // half-signed-in state with a dead session.
    const first = { ok: false, dead: false } as const;
    const second = { ok: false, dead: true } as const;

    expect(shouldEndSession(first)).toBe(false);
    expect(shouldEndSession(second)).toBe(true);
  });

  it('does not end the session when there is no outcome yet', () => {
    expect(shouldEndSession(undefined)).toBe(false);
  });
});
