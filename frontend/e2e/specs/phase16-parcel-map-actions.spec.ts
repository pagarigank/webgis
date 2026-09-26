import { test, expect, login, psql, adminToken, API_BASE, ADMIN } from '../fixtures';

/**
 * TASK-104b — parcel map identify, actions, and multi-select.
 *
 * The map previously had no parcel layer at all: /spatial/identify reads
 * app.gis_features, which is unrelated to app.parcels, so a parcel could not
 * be identified or acted on from the map. These specs cover the replacement
 * path — the overlay drawn from GET /parcels/overlay, the click resolved by
 * GET /parcels/locate, and the action menu that results.
 *
 * The camera is positioned through the dev `__mapCtx.map` handle and the click
 * point is derived with map.project(), so assertions never depend on the
 * default view or on a guessed pixel.
 *
 * Parcels are seeded with real geometry because the ambient rows are all
 * geom IS NULL and would render an empty map.
 */

/** sample_app_admin holds a PROVINCE 990000000 scope, so parcels must sit
 *  under a PSGC code that resolves inside it or the endpoints correctly
 *  return nothing. OUT_OF_SCOPE deliberately does not. */
const IN_SCOPE = '990101000';
const OUT_OF_SCOPE = '041005000';

const A = {
    code: 'E2E_MAP_A',
    ring: 'POLYGON((120.99955 14.49955, 121.00045 14.49955, 121.00045 14.50045, 120.99955 14.50045, 120.99955 14.49955))',
    centre: [121.0, 14.5] as [number, number],
};
const B = {
    code: 'E2E_MAP_B',
    ring: 'POLYGON((121.00055 14.49955, 121.00145 14.49955, 121.00145 14.50045, 121.00055 14.50045, 121.00055 14.49955))',
    centre: [121.001, 14.5] as [number, number],
};
/** Same footprint as A but invisible to the admin. */
const C = {
    code: 'E2E_MAP_C_HIDDEN',
    ring: A.ring,
    centre: A.centre,
};

type Fixture = typeof A & { psgc?: string };

const seed = (p: Fixture) =>
    `INSERT INTO app.parcels (id, parcel_code, lot_number, status, geometry_source, version, geom, psgc_barangay) VALUES (gen_random_uuid(), '${p.code}', 'E2E', 'PUBLISHED', 'SURVEY_COORDINATES', 1, ST_GeomFromText('${p.ring}', 4326), '${p.psgc ?? IN_SCOPE}');`;

const codes = [A.code, B.code, C.code];
const reset = () => `DELETE FROM app.parcels WHERE parcel_code IN (${codes.map((c) => `'${c}'`).join(',')});`;

test.describe('parcel map actions (TASK-104b)', () => {
    test.beforeAll(() => {
        psql(reset());
        psql(seed(A) + seed(B) + seed({ ...C, psgc: OUT_OF_SCOPE }));
    });

    test.afterAll(() => {
        psql(reset());
    });

    /** Open /map, jump to the seeded pair, and wait until the map is live. */
    async function gotoParcels(page: import('@playwright/test').Page) {
        await login(page);
        await page.goto('/map');
        await page.waitForFunction(
            (centre) => {
                const ctx = (
                    window as unknown as { __mapCtx?: { map?: { jumpTo: (o: unknown) => void } } }
                ).__mapCtx;
                if (!ctx?.map) return false;
                ctx.map.jumpTo({ center: centre, zoom: 17 });
                return true;
            },
            [121.0005, 14.5],
            { timeout: 30_000 },
        );
    }

    /** Click the map at a geographic coordinate via the live projection. */
    async function clickAt(page: import('@playwright/test').Page, lngLat: [number, number]) {
        const point = await page.evaluate((c) => {
            const ctx = (
                window as unknown as {
                    __mapCtx?: { map?: { project: (p: number[]) => { x: number; y: number }; getContainer: () => HTMLElement } };
                }
            ).__mapCtx;
            const map = ctx?.map;
            if (!map) return null;
            const p = map.project(c);
            // project() is container-relative but page.mouse uses viewport
            // coordinates, and the map sits inside an offset panel, so the
            // container rect has to be added or the click lands off-parcel.
            const rect = map.getContainer().getBoundingClientRect();
            return { x: rect.left + p.x, y: rect.top + p.y };
        }, lngLat);
        expect(point, 'map.project() must be available for a coordinate click').not.toBeNull();
        await page.mouse.click(point!.x, point!.y);
    }

    /**
     * Wait until the overlay layer is actually painting a parcel at `lngLat`.
     * Clicking earlier races the map layout and the debounced overlay fetch:
     * the projection is then computed against a container that has not settled,
     * the click lands beside the parcel, and GET /parcels/locate correctly
     * returns nothing (tolerance is 25 m). Asserting the precondition is both
     * more robust and a stronger check than sleeping.
     */
    async function waitForParcelRendered(page: import('@playwright/test').Page, lngLat: [number, number]) {
        await page.waitForFunction(
            (c) => {
                const map = (
                    window as unknown as {
                        __mapCtx?: {
                            map?: {
                                project: (p: number[]) => { x: number; y: number };
                                getLayer: (id: string) => unknown;
                                queryRenderedFeatures: (pt: [number, number], layers: string[]) => unknown[];
                            };
                        };
                    }
                ).__mapCtx?.map;
                if (!map) return false;
                if (!map.getLayer('parcel-overlay-fill')) return false;
                const p = map.project(c);
                return map.queryRenderedFeatures([p.x, p.y], ['parcel-overlay-fill']).length > 0;
            },
            lngLat,
            { timeout: 30_000 },
        );
    }

    test('clicking a parcel opens the action menu for that parcel', async ({ page }) => {
        await gotoParcels(page);
        await waitForParcelRendered(page, A.centre);
        await clickAt(page, A.centre);

        const menu = page.locator('[data-testid="parcel-action-menu"]');
        await expect(menu).toBeVisible();
        await expect(page.locator('[data-testid="parcel-action-code"]')).toHaveText(A.code);
        // Identifiable at all is the whole point: the generic identify tool
        // cannot resolve a parcel because it reads app.gis_features.
        await expect(page.locator('[data-testid="parcel-action-area"]')).not.toHaveText('—');
    });

    test('the action menu offers the parcel operations, all as real routes', async ({ page }) => {
        await gotoParcels(page);
        await waitForParcelRendered(page, A.centre);
        await clickAt(page, A.centre);
        await expect(page.locator('[data-testid="parcel-action-menu"]')).toBeVisible();

        for (const testid of [
            'parcel-action-open',
            'parcel-action-split',
            'parcel-action-select',
            'parcel-action-consolidate',
            'parcel-action-lineage',
            'parcel-action-history',
        ]) {
            await expect(page.locator(`[data-testid="${testid}"]`)).toBeVisible();
        }

        // Split is a tab on the editor, never a bare /parcels/split.
        await page.locator('[data-testid="parcel-action-split"]').click();
        await expect(page).toHaveURL(/\/parcels\/[0-9a-f-]{36}\/split$/);
    });

    test('multi-selecting on the map seeds consolidation with both parents', async ({ page }) => {
        await gotoParcels(page);

        await waitForParcelRendered(page, A.centre);

        await clickAt(page, A.centre);
        await expect(page.locator('[data-testid="parcel-action-code"]')).toHaveText(A.code);
        await page.locator('[data-testid="parcel-action-select"]').click();
        await expect(page.locator('[data-testid="parcel-selection-summary"]')).toContainText('1 selected');

        await waitForParcelRendered(page, B.centre);

        await clickAt(page, B.centre);
        await expect(page.locator('[data-testid="parcel-action-code"]')).toHaveText(B.code);
        await page.locator('[data-testid="parcel-action-select"]').click();
        await expect(page.locator('[data-testid="parcel-selection-summary"]')).toContainText('2 selected');

        await page.locator('[data-testid="parcel-action-consolidate"]').click();

        // Consolidation is a tab on a parent parcel. Landing on
        // /parcels/consolidate would match /parcels/:id and 404 on a parcel
        // literally named "consolidate".
        await expect(page).toHaveURL(/\/parcels\/[0-9a-f-]{36}\/consolidate$/);
        await expect(page.locator('[data-testid="consolidation-tab"]')).toBeVisible();
        await expect(page.locator('[data-testid="consolidation-selection-count"]')).toContainText('2 selected');
    });

    test('locate and overlay hide parcels outside the caller data scope', async ({ page, request }) => {
        await gotoParcels(page);
        await waitForParcelRendered(page, A.centre);
        await clickAt(page, A.centre);
        await expect(page.locator('[data-testid="parcel-action-code"]')).toHaveText(A.code);

        // C shares A's exact geometry but sits outside the admin's PROVINCE
        // 990000000 scope. Global RLS is currently bypassed for app_rw
        // (rolsuper/rolbypassrls), so the endpoints must filter on
        // app.fn_user_can_see() themselves or this leaks.
        //
        // The token is fetched through the APIRequestContext, not an in-page
        // fetch(): the SPA is served from :5173 while the API is on :80, so an
        // in-page cross-origin POST is blocked by CORS.
        const token = await adminToken();

        const locate = await request.get(
            `${API_BASE}/api/v1/parcels/locate?lng=${A.centre[0]}&lat=${A.centre[1]}&srid=4326&tolerance_m=50`,
            { headers: { Authorization: `Bearer ${token}` } },
        );
        expect(locate.ok()).toBeTruthy();
        const found = (await locate.json()).data.parcels as { parcel_code: string }[];
        const locatedCodes = found.map((p) => p.parcel_code);

        expect(locatedCodes).toContain(A.code);
        expect(locatedCodes, 'out-of-scope parcel must not be locatable').not.toContain(C.code);

        const overlay = await request.get(
            `${API_BASE}/api/v1/parcels/overlay?bbox=120.9%2C14.4%2C121.1%2C14.6&limit=500`,
            { headers: { Authorization: `Bearer ${token}` } },
        );
        expect(overlay.ok()).toBeTruthy();
        const overlayCodes = ((await overlay.json()).data as { features: { properties: { parcel_code: string } }[] })
            .features.map((f) => f.properties.parcel_code);

        expect(overlayCodes).toContain(A.code);
        expect(overlayCodes, 'out-of-scope parcel must not be rendered').not.toContain(C.code);
    });

    test('the locator answers independently of the identify tool', async ({ request }) => {
        const res = await request.post(`${API_BASE}/api/v1/auth/login`, { data: ADMIN });
        expect(res.ok()).toBeTruthy();
        const token = (await res.json()).data.access_token as string;

        const overlay = await request.get(
            `${API_BASE}/api/v1/parcels/overlay?bbox=120.9%2C14.4%2C121.1%2C14.6&limit=500`,
            { headers: { Authorization: `Bearer ${token}` } },
        );
        expect(overlay.ok()).toBeTruthy();
        const features = (await overlay.json()).data.features as {
            properties: { parcel_code: string; status: string; id: string };
        }[];

        // MapLibre filters on feature.properties, so the id has to be repeated
        // there or the selected-parcel layer silently matches nothing.
        const a = features.find((f) => f.properties.parcel_code === A.code);
        expect(a, 'seeded parcel must be in the overlay').toBeTruthy();
        expect(a!.properties.id).toMatch(/^[0-9a-f-]{36}$/);
        expect(a!.properties.status).toBe('PUBLISHED');
    });
});
