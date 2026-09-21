import { describe, expect, it } from 'vitest';
import { validateGeometry } from './geometry';

const polygon = (ring: [number, number][]) =>
    ({ type: 'Polygon', coordinates: [ring] }) as GeoJSON.Polygon;

describe('validateGeometry', () => {
    it('accepts a simple closed rectangle', () => {
        const ring: [number, number][] = [
            [120.56, 14.09],
            [122.53, 14.09],
            [122.53, 12.59],
            [120.56, 12.59],
            [120.56, 14.09],
        ];
        expect(validateGeometry(polygon(ring)).valid).toBe(true);
    });

    it('accepts a ring with duplicate consecutive vertices', () => {
        const ring: [number, number][] = [
            [120.56, 14.09],
            [122.53, 14.09],
            [122.53, 12.59],
            [120.56, 12.59],
            [120.56, 14.09],
            [120.56, 14.09],
        ];
        expect(validateGeometry(polygon(ring)).valid).toBe(true);
    });

    it('rejects a self-intersecting (bow-tie) ring', () => {
        const ring: [number, number][] = [
            [120.56, 14.09],
            [122.53, 12.59],
            [122.53, 14.09],
            [120.56, 12.59],
            [120.56, 14.09],
        ];
        const result = validateGeometry(polygon(ring));
        expect(result.valid).toBe(false);
        expect(result.errors.join(' ')).toMatch(/self-intersect/i);
    });

    it('rejects an unclosed ring', () => {
        const ring: [number, number][] = [
            [120.56, 14.09],
            [122.53, 14.09],
            [122.53, 12.59],
        ];
        const result = validateGeometry(polygon(ring));
        expect(result.valid).toBe(false);
        expect(result.errors.join(' ')).toMatch(/not closed/i);
    });
});
