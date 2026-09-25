/**
 * Classification of a failed token refresh.
 *
 * The distinction matters for the user's session: a refresh the server
 * *rejected* means the session is genuinely over, but a refresh that was merely
 * throttled or briefly unreachable must never evict a signed-in user.
 */

/** Result of one refresh attempt. */
export type RefreshOutcome = { ok: true } | { ok: false; dead: boolean };

/**
 * A rejected session is terminal. Everything else is recoverable.
 *
 * Only 401/403 mean "this refresh token is no longer valid". A 429 is the
 * rate limiter and, a 5xx or a dropped connection is the server's problem —
 * none of them say anything about whether the session is still good, and
 * treating them as terminal logs users out at random.
 */
export function isTerminalRefreshStatus(status: number | undefined): boolean {
  return status === 401 || status === 403;
}

/**
 * Decide whether a refresh failure ends the session, from a thrown error.
 *
 * An error carrying no response (network failure, CORS abort) is transient by
 * construction: nothing was heard back that could reject the session.
 */
export function classifyRefreshError(err: unknown): { ok: false; dead: boolean } {
  const status = (err as { response?: { status?: number } } | null)?.response?.status;
  return { ok: false, dead: isTerminalRefreshStatus(status) };
}

/**
 * Whether a failed refresh should end the session.
 *
 * Only the *latest* attempt decides. A first attempt that failed transiently
 * followed by a second that the server rejected must still evict; reading the
 * first attempt's verdict would leave the user in a half-signed-in state with a
 * dead session and no way out.
 */
export function shouldEndSession(outcome: RefreshOutcome | undefined): boolean {
  return outcome !== undefined && !outcome.ok && outcome.dead;
}
