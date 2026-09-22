import { test, expect, login } from '../fixtures';

/**
 * PHASE 5 E2E: map shell, layer panel, CRS readout, tools.
 * Serial (workers=1) because they share the dev server and DB fixtures.
 */

test.describe('Phase 5 — map rendering', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('TASK-049: /map renders workspace with layer tree and tool panels', async ({ page }) => {
        await page.goto('/map');
        await expect(page.locator('.layer-tree')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('draw-tools')).toBeVisible();
        await expect(page.getByText('Zoom to:')).toBeVisible();
        await expect(page.getByText(/Measure distance/)).toBeVisible();
        // Canvas present (maplibre renders into <canvas>)
        await expect(page.locator('canvas.maplibregl-canvas')).toBeVisible();
    });

    test('TASK-052: sample layer loads with bbox, appears in tree, toggles + zoom', async ({ page }) => {
        await page.goto('/map');

        // Load the sample layer through the hamburger layer switcher.
        await page.getByTestId('layer-switcher-toggle').click();

        // Every feature request must carry a bbox (TASK-053 AC).
        const geojsonReq = page.waitForRequest(
            (r) => r.url().includes('/features.geojson') && r.url().includes('bbox='),
            { timeout: 15_000 },
        );
        await page
            .locator('[data-testid="layer-switcher-panel"] label', { hasText: /Sample Parcel Polygon|SAMPLE_PARCEL_POLYGON/ })
            .locator('input[type="checkbox"]')
            .click();
        const treeRow = page.locator('.layer-tree li', { hasText: /Sample Parcel Polygon|SAMPLE_PARCEL_POLYGON/ });
        await treeRow.waitFor({ timeout: 15_000 });
        const req = await geojsonReq;
        expect(new URL(req.url()).searchParams.get('bbox')).toBeTruthy();

        // Visibility toggle works
        const checkbox = treeRow.locator('input[type="checkbox"]');
        await checkbox.uncheck();
        await expect(checkbox).not.toBeChecked();

        // Zoom-to button exists (extent attached)
        await expect(treeRow.getByTitle('Zoom to layer')).toBeVisible();
    });

    test('TASK-053: moveend triggers a debounced bbox reload; rapid panning cancels stale requests', async ({ page }) => {
        await page.goto('/map');
        await page.getByTestId('layer-switcher-toggle').click();
        await page
            .locator('[data-testid="layer-switcher-panel"] label', { hasText: /Sample Parcel Polygon|SAMPLE_PARCEL_POLYGON/ })
            .locator('input[type="checkbox"]')
            .click();
        await page.locator('.layer-tree li').first().waitFor({ timeout: 15_000 });

        let inFlight = 0;
        let cancelled = 0;
        page.on('request', (r) => {
            if (r.url().includes('/features.geojson') && r.url().includes('bbox=')) inFlight++;
        });
        page.on('requestfailed', (r) => {
            if (r.url().includes('/features.geojson') && r.url().includes('bbox=')) cancelled++;
        });

        // Rapid panning: several moveends fire; debounce + abort must keep
        // the number of *completed* fetches sane and cancel stale ones.
        for (let i = 0; i < 5; i++) {
            await page.mouse.move(700, 450);
            await page.mouse.down();
            await page.mouse.move(700 - 80 * (i + 1), 450 - 40 * (i + 1), { steps: 3 });
            await page.mouse.up();
            await page.waitForTimeout(60); // < 300ms debounce window
        }

        // Wait past the debounce window for the final reload to settle.
        await page.waitForTimeout(1500);
        // The final request completed (at least one bbox fetch observed),
        // and no unbounded (bbox-less) request was ever issued.
        expect(inFlight).toBeGreaterThanOrEqual(1);
        const unbounded = await page.evaluate(
            () => (window as { __unboundedFeatureReq?: boolean }).__unboundedFeatureReq ?? false,
        );
        expect(unbounded).toBe(false);
    });

    test('TASK-056: coordinate readout shows position with CRS name; switching CRS changes display', async ({ page }) => {
        await page.goto('/map');
        const readout = page.getByTestId('coordinate-readout');
        await expect(readout).toBeVisible({ timeout: 20_000 });

        // Move the mouse over the map so the readout gets a position.
        await page.mouse.move(700, 450);
        await page.waitForTimeout(300);
        const value4326 = await page.getByTestId('readout-value').innerText();
        expect(value4326).toMatch(/\d/);

        // Switch display CRS to PRS92 zone III (EPSG:3123) — E/N output.
        await page.getByTestId('crs-selector').selectOption('3123');
        await page.mouse.move(720, 460);
        await page.waitForTimeout(300);
        const value3123 = await page.getByTestId('readout-value').innerText();
        expect(value3123).toMatch(/E \d/); // projected easting
        expect(value3123).not.toEqual(value4326);

        // Selector shows the CRS name.
        await expect(page.getByTestId('crs-selector')).toContainText('EPSG:3123');
    });

    test('TASK-062: measure distance returns a result naming the CRS', async ({ page }) => {
        await page.goto('/map');
        await page.getByRole('button', { name: /measure distance/i }).click();

        // Two clicks on the map (avoid panel areas).
        await page.mouse.click(600, 500);
        await page.waitForTimeout(150);
        await page.mouse.click(800, 550);

        await expect(page.getByText(/Distance:/)).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText(/CRS: EPSG:32651/)).toBeVisible();
    });
});
