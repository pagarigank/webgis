/**
 * Client-side geometry validation for draw-and-save flows (TASK-060).
 * These are quick feedback checks; the server verdict always wins.
 */

export interface GeometryValidationResult {
    valid: boolean;
    errors: string[];
}

/**
 * Validate a GeoJSON geometry object before sending to the server.
 * Returns a list of human-readable errors; empty list = passes client-side checks.
 */
export function validateGeometry(geom: GeoJSON.GeometryObject): GeometryValidationResult {
    const errors: string[] = [];
    const type = geom.type;

    // Minimum vertices per type
    const minVertices: Record<string, number> = {
        Point: 1,
        MultiPoint: 1,
        LineString: 2,
        MultiLineString: 1,
        Polygon: 1, // at least one ring
        MultiPolygon: 1,
        GeometryCollection: 1,
    };

    // ── Coordinate bounds (WGS84 sanity) ──────────────────────────────────
    if (!coordinatesInWgs84Range(geom.coordinates, type)) {
        errors.push('Coordinates are outside WGS84 range (longitude [-180,180], latitude [-90,90])');
    }

    // ── Minimum vertices ───────────────────────────────────────────────────
    const coordLeafCount = countCoordinateLeaves(geom.coordinates, type);
    const min = minVertices[type] ?? 1;
    if (coordLeafCount < min) {
        errors.push(`Too few coordinates for ${type} (need at least ${min})`);
    }

    // ── Ring closure (Polygon rings must close) ────────────────────────────
    if (type === 'Polygon' || type === 'MultiPolygon') {
        const rings = type === 'Polygon' ? geom.coordinates : geom.coordinates.flatMap(r => r);
        for (let i = 0; i < rings.length; i++) {
            const ring = rings[i];
            if (!ringClosed(ring)) {
                errors.push(`Polygon ring ${i + 1} is not closed (first !== last vertex)`);
            }
        }
    }

    // ── Self-intersection heuristic (Polygon rings) ────────────────────────
    if (type === 'Polygon' || type === 'MultiPolygon') {
        const rings = type === 'Polygon' ? geom.coordinates : geom.coordinates.flatMap(r => r);
        for (let i = 0; i < rings.length; i++) {
            if (ringHasSelfIntersection(rings[i])) {
                errors.push(`Polygon ring ${i + 1} appears to self-intersect`);
            }
        }
    }

    // ── LineString minimum length ──────────────────────────────────────────
    if (type === 'LineString') {
        const pts = geom.coordinates as [number, number][];
        if (pts.length >= 2) {
            let total = 0;
            for (let i = 1; i < pts.length; i++) {
                total += haversineDistance(pts[i - 1], pts[i]);
            }
            if (total < 0.00001) {
                // ~1 metre in degrees at equator — flag near-zero-length lines
                errors.push('LineString is shorter than ~1 metre');
            }
        }
    }

    return { valid: errors.length === 0, errors };
}

// ── helpers ───────────────────────────────────────────────────────────────

/**
 * Check if all coordinates are within WGS84 bounds.
 */
function coordinatesInWgs84Range(coords: any, type: string): boolean {
    const pairs = collectLngLatPairs(coords, type);
    for (const [x, y] of pairs) {
        if (x < -180 || x > 180 || y < -90 || y > 90) {
            return false;
        }
    }
    return true;
}

/**
 * Collect every [lng, lat] leaf pair from a GeoJSON coordinate tree.
 */
function collectLngLatPairs(coords: any, type: string): [number, number][] {
    const result: [number, number][] = [];

    function walk(c: any): void {
        if (Array.isArray(c) && c.length > 0 && typeof c[0] === 'number') {
            // Leaf: [lng, lat] or [lng, lat, elevation]
            if (typeof c[0] === 'number' && typeof c[1] === 'number') {
                result.push([c[0], c[1]]);
            }
        } else if (Array.isArray(c)) {
            for (const child of c) {
                walk(child);
            }
        }
    }

    walk(coords);
    return result;
}

/**
 * Count leaf coordinate pairs for minimum-vertex check.
 */
function countCoordinateLeaves(coords: any, type: string): number {
    const pairs = collectLngLatPairs(coords, type);
    return pairs.length;
}

/**
 * A ring is closed if the first and last vertices are within 1mm.
 */
function ringClosed(ring: [number, number][]): boolean {
    if (ring.length < 3) return false;
    const [fx, fy] = ring[0];
    const [lx, ly] = ring[ring.length - 1];
    return Math.abs(fx - lx) < 1e-5 && Math.abs(fy - ly) < 1e-5;
}

/**
 * Crude self-intersection check for a polyline ring:
 * walks every non-adjacent pair of segments and checks for intersection.
 * O(n^2) but n is small for drawn polygons on screen.
 */
function ringHasSelfIntersection(ring: [number, number][]): boolean {
    const n = ring.length;
    if (n < 4) return false; // triangle can't self-intersect

    for (let i = 0; i < n - 1; i++) {
        for (let j = i + 2; j < n - 1; j++) {
            // Skip adjacent segments (they share a vertex)
            if (j === i + 1) continue;
            if (segmentsIntersect(ring[i], ring[i + 1], ring[j], ring[j + 1])) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Check if two line segments AB and CD intersect (proper intersection,
 * excluding shared endpoints).
 */
function segmentsIntersect(
    a: [number, number],
    b: [number, number],
    c: [number, number],
    d: [number, number],
): boolean {
    const o1 = orientation(a, b, c);
    const o2 = orientation(a, b, d);
    const o3 = orientation(c, d, a);
    const o4 = orientation(c, d, b);

    // General case: opposite orientations
    if (o1 !== o2 && o3 !== o4) return true;

    return false;
}

function orientation(
    p: [number, number],
    q: [number, number],
    r: [number, number],
): number {
    const val = (q[1] - p[1]) * (r[0] - q[0]) - (q[0] - p[0]) * (r[1] - q[1]);
    if (Math.abs(val) < 1e-10) return 0; // collinear
    return val > 0 ? 1 : 2; // clockwise or counterclockwise
}

/**
 * Haversine distance in degrees (approximate — good enough for "too short" heuristic).
 */
function haversineDistance(a: [number, number], b: [number, number]): number {
    const dLat = (b[1] - a[1]) * (Math.PI / 180);
    const dLon = (b[0] - a[0]) * (Math.PI / 180);
    const lat1 = a[1] * (Math.PI / 180);
    const lat2 = b[1] * (Math.PI / 180);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
    const dist = 2 * 6371000 * Math.asin(Math.sqrt(Math.min(1, h))); // metres
    return dist / 111320; // approximate degrees
}
