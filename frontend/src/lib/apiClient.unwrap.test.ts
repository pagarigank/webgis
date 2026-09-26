import { describe, it, expect } from 'vitest';
import { unwrapList, unwrapEntity } from './apiClient';

/**
 * These tests pin the real response shapes on the wire, because the bug they
 * guard against was silent: a hand-written `res.data.data` chain returned
 * `undefined` instead of throwing, and the crash surfaced far away in a
 * component's `.length` call.
 *
 * Two backend conventions are in play and both are real:
 *  - controllers using `Envelope::success` emit `{ success: true, data }`, which
 *    the apiClient response interceptor already unwraps to the inner payload;
 *  - controllers writing their payload verbatim (the survey technical
 *    description controller) emit a bare `{ data }` with no `success` key, so
 *    the interceptor passes it through untouched.
 *
 * Anything that reaches a list must come back as a real array, whatever shape
 * it arrived in.
 */
describe('unwrapList', () => {
  const row = { id: 7, parcel_id: 'p1' };

  it('returns a bare array unchanged', () => {
    expect(unwrapList([row])).toEqual([row]);
  });

  it('unwraps a bare { data } wrapper with no success key', () => {
    expect(unwrapList({ data: [row] })).toEqual([row]);
  });

  it('unwraps a { success, data } envelope', () => {
    expect(unwrapList({ success: true, data: [row] })).toEqual([row]);
  });

  it('unwraps a paginated { data, meta } wrapper', () => {
    expect(unwrapList({ data: [row], meta: { total: 1 } })).toEqual([row]);
  });

  it('unwraps a doubly wrapped paginated envelope', () => {
    expect(unwrapList({ success: true, data: { data: [row], meta: { total: 1 } } })).toEqual([row]);
  });

  it('never returns undefined for an empty list', () => {
    expect(unwrapList({ data: [] })).toEqual([]);
  });

  // The exact shape that reached TechnicalDescriptionTab and crashed it.
  it('never returns undefined for an empty paginated list', () => {
    expect(unwrapList({ data: [], meta: { total: 0 } })).toEqual([]);
  });

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['a bare object with no data key', { total: 3 }],
    ['a string', 'nope'],
    ['a number', 5],
  ])('degrades to an empty array for %s', (_label, input) => {
    expect(unwrapList(input)).toEqual([]);
  });

  it('never yields a non-array, so callers can always use .map/.length', () => {
    const shapes: unknown[] = [null, { data: null }, { data: { data: null } }, 7, 'x'];
    for (const shape of shapes) {
      expect(Array.isArray(unwrapList(shape))).toBe(true);
    }
  });
});

describe('unwrapEntity', () => {
  const td = { id: 3, version: 5, parcel_id: 'p1' };

  it('returns an already-unwrapped entity unchanged', () => {
    expect(unwrapEntity(td)).toEqual(td);
  });

  it('unwraps a bare { data } wrapper', () => {
    expect(unwrapEntity({ data: td })).toEqual(td);
  });

  it('unwraps a { success, data } envelope', () => {
    expect(unwrapEntity({ success: true, data: td })).toEqual(td);
  });

  it('preserves nested collections such as courses and tie_points', () => {
    const withChildren = { ...td, courses: [{ id: 1 }], tie_points: [] };
    expect(unwrapEntity({ data: withChildren })).toEqual(withChildren);
  });

  it('returns null when there is no entity, so callers must handle absence', () => {
    expect(unwrapEntity(null)).toBeNull();
    expect(unwrapEntity(undefined)).toBeNull();
    expect(unwrapEntity({ data: null })).toBeNull();
  });
});
