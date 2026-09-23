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
/**
 * Compute [w,s,e,n] extent (lon/lat EPSG:4326) from a GeoJSON collection.
 * Returns undefined when the collection has no coordinates.
 */
export function extentFromGeojson(geojson: GeoJSON.FeatureCollection): [number, number, number, number] | undefined {
    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;
    const walk = (coords: number[] | number[][] | number[][][]) => {
        if (typeof coords[0] === 'number') {
            const [x, y] = coords as number[];
            if (Number.isFinite(x) && Number.isFinite(y)) {
                minX = Math.min(minX, x);
                maxX = Math.max(maxX, x);
                minY = Math.min(minY, y);
                maxY = Math.max(maxY, y);
            }
            return;
        }
        (coords as unknown[]).forEach((c) => walk(c as number[][]));
    };
    for (const feature of geojson.features) {
        const geom = feature.geometry;
        if (geom && 'coordinates' in geom) walk(geom.coordinates as never);
    }
    if (!Number.isFinite(minX)) return undefined;
    return [minX, minY, maxX, maxY];
}

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
    if (!coordinatesInWgs84Range((geom as any).coordinates, type)) {
        errors.push('Coordinates are outside WGS84 range (longitude [-180,180], latitude [-90,90])');
    }

    // ── Minimum vertices ───────────────────────────────────────────────────
    const coordLeafCount = countCoordinateLeaves((geom as any).coordinates, type);
    const min = minVertices[type] ?? 1;
    if (coordLeafCount < min) {
        errors.push(`Too few coordinates for ${type} (need at least ${min})`);
    }

    // ── Ring closure (Polygon rings must close) ────────────────────────────
    if (type === 'Polygon' || type === 'MultiPolygon') {
        const rings = type === 'Polygon' ? (geom as any).coordinates : (geom as any).coordinates.flatMap((r: any) => r);
        for (let i = 0; i < rings.length; i++) {
            const ring = rings[i];
            if (!ringClosed(ring as [number, number][])) {
                errors.push(`Polygon ring ${i + 1} is not closed (first !== last vertex)`);
            }
        }
    }

    // ── Self-intersection heuristic (Polygon rings) ────────────────────────
    if (type === 'Polygon' || type === 'MultiPolygon') {
        const rings = type === 'Polygon' ? (geom as any).coordinates : (geom as any).coordinates.flatMap((r: any) => r);
        for (let i = 0; i < rings.length; i++) {
            if (ringHasSelfIntersection(rings[i] as [number, number][])) {
                errors.push(`Polygon ring ${i + 1} appears to self-intersect`);
            }
        }
    }

    // ── LineString minimum length ──────────────────────────────────────────
    if (type === 'LineString') {
        const pts = (geom as any).coordinates as [number, number][];
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
function collectLngLatPairs(coords: any, _type: string): [number, number][] {
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
        const a = ring[i];
        const b = ring[i + 1];
        if (samePoint(a, b)) continue; // degenerate (zero-length) segment
        for (let j = i + 1; j < n - 1; j++) {
            const c = ring[j];
            const d = ring[j + 1];
            if (samePoint(c, d)) continue;
            // Adjacent segments (including the first/last wrap-around pair)
            // legitimately share a vertex — that is not a crossing.
            if (sharesEndpoint(a, b, c, d)) continue;
            if (segmentsIntersect(a, b, c, d)) {
                return true;
            }
        }
    }
    return false;
}

/** Two coordinates are the same vertex within ~0.1 mm. */
function samePoint(a: [number, number], b: [number, number]): boolean {
    return Math.abs(a[0] - b[0]) < 1e-9 && Math.abs(a[1] - b[1]) < 1e-9;
}

/** True if any endpoint is shared between segments AB and CD. */
function sharesEndpoint(
    a: [number, number],
    b: [number, number],
    c: [number, number],
    d: [number, number],
): boolean {
    return samePoint(a, c) || samePoint(a, d) || samePoint(b, c) || samePoint(b, d);
}

/**
 * Check if two line segments AB and CD cross properly. Shared endpoints and
 * collinear/touching configurations are excluded — only a true transversal
 * crossing counts.
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

    // Collinear/touching cases are not proper crossings.
    if (o1 === 0 || o2 === 0 || o3 === 0 || o4 === 0) return false;

    return o1 !== o2 && o3 !== o4;
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

export interface PolygonReadout {
    vertexCount: number;
    perimeterM: number;
    areaSqm: number;
}

/**
 * TASK-072 — live readout for a drawn polygon. Treats a Polygon feature's first
 * ring (or every ring of a MultiPolygon) in EPSG:4326 coordinates and reports
 * vertices, perimeter (m) and area (m²) via the spherical excess formula.
 * Returns undefined when there is no usable ring.
 */
export function polygonReadout(geometry: GeoJSON.Polygon | GeoJSON.MultiPolygon): PolygonReadout | undefined {
    const rings: [number, number][][] =
        geometry.type === 'Polygon'
            ? (geometry.coordinates as [number, number][][])
            : geometry.coordinates.map((poly) => poly[0]);

    let perimeterM = 0;
    let areaSqm = 0;
    let vertexCount = 0;

    for (const ring of rings) {
        if (ring.length < 3) continue;
        const pts = ring[ring.length - 1][0] === ring[0][0] && ring[ring.length - 1][1] === ring[0][1]
            ? ring.slice(0, -1)
            : ring;
        vertexCount += pts.length;
        for (let i = 0; i < pts.length; i++) {
            perimeterM += haversineDistanceMeters(pts[i], pts[(i + 1) % pts.length]);
        }
        areaSqm += sphericalPolygonArea(pts);
    }

    if (vertexCount === 0) return undefined;
    return { vertexCount, perimeterM, areaSqm: Math.abs(areaSqm) };
}

/** Haversine distance in metres between two [lng, lat] points. */
export function haversineDistanceMeters(a: [number, number], b: [number, number]): number {
    const dLat = (b[1] - a[1]) * (Math.PI / 180);
    const dLon = (b[0] - a[0]) * (Math.PI / 180);
    const lat1 = a[1] * (Math.PI / 180);
    const lat2 = b[1] * (Math.PI / 180);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
    return 2 * 6371000 * Math.asin(Math.sqrt(Math.min(1, h)));
}

/**
 * Area of a closed polygon of [lng, lat] vertices (no closing duplicate) using
 * the spherical excess / "shoelace on a sphere" formula. Positive when the ring
 * is wound counter-clockwise, negative otherwise.
 */
function sphericalPolygonArea(pts: [number, number][]): number {
    const R = 6371000;
    let sum = 0;
    for (let i = 0; i < pts.length; i++) {
        const [lng1, lat1] = pts[i];
        const [lng2, lat2] = pts[(i + 1) % pts.length];
        const dLng = (lng2 - lng1) * (Math.PI / 180);
        const phi1 = lat1 * (Math.PI / 180);
        const phi2 = lat2 * (Math.PI / 180);
        sum += dLng * (2 + Math.sin(phi1) + Math.sin(phi2));
    }
    return (R * R * sum) / 2;
}

/**
 * Haversine distance in degrees (approximate — good enough for "too short" heuristic).
 */
function haversineDistance(a: [number, number], b: [number, number]): number {
    return haversineDistanceMeters(a, b) / 111320; // approximate degrees
}
