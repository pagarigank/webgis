import { describe, expect, it, vi } from 'vitest';
import { createRefreshQueue } from './refreshQueue';

describe('createRefreshQueue', () => {
  it('runs `perform` once for concurrent callers', async () => {
    const perform = vi.fn(async () => 'ok' as const);
    const run = createRefreshQueue(perform);

    const results = await Promise.all([run(), run(), run()]);
    expect(results).toEqual(['ok', 'ok', 'ok']);
    expect(perform).toHaveBeenCalledTimes(1);
  });

  it('clears the in-flight slot after settling', async () => {
    let calls = 0;
    const run = createRefreshQueue(async () => {
      calls += 1;
      return 'ok' as const;
    });

    await run();
    await run();
    expect(calls).toBe(2);
  });

  it('releases the slot after a failure so a later call retries', async () => {
    let calls = 0;
    const run = createRefreshQueue(async () => {
      calls += 1;
      if (calls === 1) {
        throw new Error('boom');
      }
      return 'ok' as const;
    });

    await expect(run()).rejects.toThrow('boom');
    await expect(run()).resolves.toBe('ok');
    expect(calls).toBe(2);
  });
});