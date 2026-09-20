/**
 * Single-flight async primitive (ADR-23): concurrent callers receive the same
 * in-flight promise instead of each triggering their own refresh. Once the
 * promise settles it is discarded so a later burst starts a fresh flight.
 */
export function createRefreshQueue<T>(perform: () => Promise<T>): () => Promise<T> {
  let inflight: Promise<T> | null = null;

  return (): Promise<T> => {
    if (inflight === null) {
      inflight = perform().finally(() => {
        inflight = null;
      });
    }
    return inflight;
  };
}